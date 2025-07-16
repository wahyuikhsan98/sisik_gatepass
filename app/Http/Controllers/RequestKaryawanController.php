<?php

namespace App\Http\Controllers;

use App\Models\RequestKaryawan;
use App\Models\Departemen;
use App\Models\Notification;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class RequestKaryawanController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Menampilkan daftar permohonan izin keluar karyawan
     * 
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $title = 'Permohonan Izin Keluar Karyawan';
        $user = auth()->user();
        $departemens = Departemen::all(); // Ambil semua data departemen

        // Query dasar untuk permohonan karyawan
        $karyawanQuery = RequestKaryawan::with('departemen');

        // Filter berdasarkan role dan departemen
        if ($user->role_id == 2) { // Role Lead
            $karyawanQuery->where('departemen_id', $user->departemen_id);
        } elseif ($user->role_id == 3) { // Role HR GA
            // HR GA bisa melihat semua departemen kecuali Admin dan HR
            $karyawanQuery->whereIn('departemen_id', function($query) {
                $query->select('id')
                    ->from('departemens')
                    ->whereNotIn('id', [1, 2]); // Kecuali departemen Admin dan HR
            });
        } elseif ($user->role_id == 4 || $user->role_id == 5) { // Role Checker dan Head Unit
            // Checker dan Head Unit tidak bisa melihat data karyawan
            $karyawanQuery->whereRaw('1 = 0'); // Query yang selalu false
        }

        // Ambil data permohonan karyawan yang sudah difilter
        $requestKaryawans = $karyawanQuery->get();
        
        // Menghitung total request berdasarkan status dengan urutan persetujuan untuk permohonan yang terlihat oleh user
        $totalMenunggu = 0;
        $totalDisetujui = 0;
        $totalDitolak = 0;
        $totalRequest = 0;

        // Hitung statistik hanya jika user memiliki akses (bukan checker dan head unit)
        if ($user->role_id != 4 && $user->role_id != 5) {
             // Permohonan Menunggu Karyawan
            $totalMenunggu = (clone $karyawanQuery)->where(function($query) {
                $query->where(function($q) {
                    $q->where('acc_lead', 1) // Lead belum menyetujui
                      ->orWhere('acc_hr_ga', 1) // HR GA belum menyetujui setelah Lead acc
                      ->orWhere('acc_security_out', 1); // Security Out belum menyetujui setelah HR GA acc
                })
                ->where('acc_lead', '!=', 3)
                ->where('acc_hr_ga', '!=', 3)
                ->where('acc_security_out', '!=', 3)
                ->where('acc_security_in', '!=', 3);
            })
            ->count();
                    
            // Permohonan Disetujui Karyawan
            $totalDisetujui = (clone $karyawanQuery)->where('acc_lead', 2)
                ->where('acc_hr_ga', 2)
                ->where('acc_security_out', 2)
                ->count();
                    
            // Permohonan Ditolak Karyawan
            $totalDitolak = (clone $karyawanQuery)->where(function($query) {
                $query->where('acc_lead', 3) // Lead menolak
                    ->orWhere('acc_hr_ga', 3) // HR GA menolak
                    ->orWhere('acc_security_out', 3) // Security Out menolak
                    ->orWhere('acc_security_in', 3); // Security In menolak
            })->count();
                    
            // Total semua request karyawan
            $totalRequest = (clone $karyawanQuery)->count();
        }

        // Mengambil tahun-tahun yang tersedia untuk filter
        $years = $this->getAvailableYears();

        return view('superadmin.request-karyawan.index', compact(
            'title',
            'requestKaryawans',
            'departemens',
            'totalMenunggu',
            'totalDisetujui',
            'totalDitolak',
            'totalRequest',
            'years'
        ));
    }

    /**
     * Menampilkan form pengajuan izin keluar karyawan
     * 
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $departemens = Departemen::all();
        $title = 'Form Izin Keluar Karyawan';
        return view('karyawan', compact('departemens', 'title'));
    }

    /**
     * Menyimpan permohonan izin keluar karyawan baru
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nama' => 'required|string|max:255',
                'no_telp' => 'required|string|max:15|regex:/^[0-9]+$/',
                'departemen_id' => 'required|exists:departemens,id',
                'keperluan' => 'required|string',
                'jam_in' => 'required',
                'jam_out' => 'required',
            ]);
    
            $validated += [
                'acc_lead' => 1,
                'acc_hr_ga' => 1,
                'acc_security_in' => 1,
                'acc_security_out' => 1
            ];
    
            $departemen = Departemen::findOrFail($validated['departemen_id']);
            $code = $departemen->code;
    
            $today = now();
            $year = $today->format('y');
            $month = $today->format('m');
            $day = $today->format('d');
    
            $last = RequestKaryawan::where('departemen_id', $validated['departemen_id'])
                ->whereDate('created_at', $today)
                ->orderByDesc('no_surat')
                ->first();
    
            $lastSequence = 0;
            if ($last) {
                preg_match("/SIP\/$code\/(\d{3})\//", $last->no_surat, $match);
                $lastSequence = isset($match[1]) ? (int)$match[1] : 0;
            }
            $next = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            $noSurat = "SIP/$code/$next/$day/$month/$year";
    
            if (RequestKaryawan::where('no_surat', $noSurat)->exists()) {
                throw new \Exception('Nomor surat sudah digunakan, silakan coba kembali.');
            }
    
            $validated['no_surat'] = $noSurat;
            $requestKaryawan = RequestKaryawan::create($validated);
    
            // Format nomor telepon WA
            $phone = preg_replace('/[^0-9]/', '', $validated['no_telp']);
            if (substr($phone, 0, 2) !== '62') {
                $phone = '62' . ltrim($phone, '0');
            }
    
            $message = "🔔 *Notifikasi Permohonan Izin Karyawan*\n\nNo Surat: $noSurat\nNama: {$validated['nama']}\nDepartemen: {$departemen->name}\nKeperluan: {$validated['keperluan']}\nJam Keluar: {$validated['jam_out']}\nJam Kembali: {$validated['jam_in']}\n\nStatus: Menunggu Persetujuan";
    
            $this->sendWhatsAppToKaryawan($phone, $message);
            $this->sendWhatsAppToDepartemenAndHRGA($validated['departemen_id'], $message);
    
            // Simpan notifikasi
            $users = \App\Models\User::whereHas('role', fn($q) => $q->whereIn('slug', ['admin', 'lead', 'hr-ga', 'security']))->get();
            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Permohonan Izin Karyawan - ' . $validated['nama'],
                    'message' => 'Permohonan atas nama ' . $validated['nama'] . ' sedang menunggu persetujuan.',
                    'type' => 'karyawan',
                    'status' => 'pending',
                    'is_read' => false
                ]);
            }
    
            return back()->with('success', 'Pengajuan izin karyawan berhasil dibuat.');
        } catch (\Exception $e) {
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    /**
     * Menangani persetujuan permohonan izin keluar karyawan
     * 
     * @param int $id ID request karyawan
     * @param int $role_id ID role yang menyetujui
     * @return \Illuminate\Http\JsonResponse
     */
    public function accRequest($id, $role_id)
    {
        try {
            $requestKaryawan = RequestKaryawan::with(['departemen'])->find($id);
    
            if (!$requestKaryawan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Request Karyawan tidak ditemukan'
                ], 404);
            }
    
            $notificationTitle = '';
            $notificationMessage = '';
            $users = collect();
    
            switch ($role_id) {
                case 2: // Lead
                    $requestKaryawan->acc_lead = 2;
                    $notificationTitle = 'Disetujui Lead';
                    $notificationMessage = 'telah disetujui oleh Lead dan menunggu persetujuan HR GA';
                    $users = \App\Models\User::whereHas('role', function($query) {
                        $query->where('slug', 'hr-ga');
                    })->get();
                    break;
    
                case 3: // HR GA
                    $requestKaryawan->acc_hr_ga = 2;
                    $notificationTitle = 'Disetujui HR GA';
                    $notificationMessage = 'telah disetujui oleh HR GA dan menunggu persetujuan Security Out';
                    $users = \App\Models\User::whereHas('role', function($query) {
                        $query->where('slug', 'security');
                    })->get();
                    break;
    
                case 6: // Security
                    if ($requestKaryawan->acc_security_out == 1) {
                        $requestKaryawan->acc_security_out = 2;
                        $notificationTitle = 'Disetujui Security Out';
                        $notificationMessage = 'telah disetujui oleh Security Out dan menunggu karyawan kembali';
                    } else {
                        $requestKaryawan->acc_security_in = 2;
                        $notificationTitle = 'Disetujui Security In';
                        $notificationMessage = 'telah disetujui oleh Security In dan permohonan selesai';
                    }
                    break;
    
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Role tidak valid'
                    ], 400);
            }
    
            $requestKaryawan->save();
    
            $karyawanMessage = "🔔 *Persetujuan Permohonan Izin Keluar Karyawan*\n\n" .
                "Nama: {$requestKaryawan->nama}\n" .
                "Departemen: {$requestKaryawan->departemen->name}\n" .
                "Keperluan: {$requestKaryawan->keperluan}\n" .
                "Jam Keluar: {$requestKaryawan->jam_out}\n" .
                "Jam Kembali: {$requestKaryawan->jam_in}\n\n" .
                "Status: {$notificationTitle} — {$notificationMessage}";
    
            // Simpan notifikasi & kirim WhatsApp ke user yang berwenang
            foreach ($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Permohonan Izin Keluar ' . $requestKaryawan->nama . ' ' . $notificationTitle,
                    'message' => 'Permohonan izin keluar atas nama ' . $requestKaryawan->nama .
                        ' dari departemen ' . $requestKaryawan->departemen->name .
                        ' ' . $notificationMessage,
                    'type' => 'karyawan',
                    'status' => 'pending',
                    'is_read' => false
                ]);
    
                if ($user->phone) {
                    $this->whatsappService->sendMessage($user->phone, $karyawanMessage);
                }
            }
    
            // Kirim WA ke karyawan berdasarkan no_telp di form
            if ($requestKaryawan->no_telp) {
                $this->sendWhatsAppToKaryawan($requestKaryawan->no_telp, $karyawanMessage);
            }
    
            return response()->json([
                'success' => true,
                'message' => 'Permohonan izin berhasil disetujui'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status persetujuan permohonan izin keluar karyawan
     * 
     * @param int $id ID request karyawan
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus($id, Request $request)
    {
        try {
            $requestKaryawan = RequestKaryawan::with('departemen')->find($id);
    
            if (!$requestKaryawan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Request Karyawan tidak ditemukan.'
                ], 404);
            }
    
            $statuses = $request->input('statuses');
    
            foreach ($statuses as $role => $status) {
                $notificationTitle = '';
                $notificationMessage = '';
                $targetUsers = collect(); // default kosong
    
                // Update status sesuai role
                switch ($role) {
                    case 'lead':
                        $requestKaryawan->acc_lead = $status;
                        if ($status == 2) {
                            $notificationTitle = 'Disetujui Lead';
                            $notificationMessage = 'telah disetujui oleh Lead dan menunggu persetujuan HR GA';
                            $targetUsers = \App\Models\User::whereHas('role', fn($q) => $q->whereIn('slug', ['hr-ga']))->get();
                        }
                        break;
    
                    case 'hr-ga':
                        $requestKaryawan->acc_hr_ga = $status;
                        if ($status == 2) {
                            $notificationTitle = 'Disetujui HR GA';
                            $notificationMessage = 'telah disetujui oleh HR GA dan menunggu persetujuan Security Out';
                            $targetUsers = \App\Models\User::whereHas('role', fn($q) => $q->whereIn('slug', ['security']))->get();
                        }
                        break;
    
                    case 'security-out':
                        $requestKaryawan->acc_security_out = $status;
                        if ($status == 2) {
                            $notificationTitle = 'Disetujui Security Out';
                            $notificationMessage = 'telah disetujui oleh Security Out dan menunggu karyawan kembali';
                            // Tidak kirim ke user lain
                        }
                        break;
    
                    case 'security-in':
                        $requestKaryawan->acc_security_in = $status;
                        if ($status == 2) {
                            $notificationTitle = 'Disetujui Security In';
                            $notificationMessage = 'telah disetujui oleh Security In dan permohonan selesai';
                            // Tidak kirim ke user lain
                        }
                        break;
                }
    
                // Jika ada notifikasi untuk role lain
                if ($status == 2 && $notificationTitle && $notificationMessage) {
                    foreach ($targetUsers as $user) {
                        Notification::create([
                            'user_id' => $user->id,
                            'title' => 'Permohonan Izin Keluar ' . $requestKaryawan->nama . ' ' . $notificationTitle,
                            'message' => 'Permohonan izin keluar atas nama ' . $requestKaryawan->nama .
                                         ' dari departemen ' . $requestKaryawan->departemen->name .
                                         ' ' . $notificationMessage,
                            'type' => 'karyawan',
                            'status' => 'pending',
                            'is_read' => false
                        ]);
    
                        // Kirim WhatsApp ke user
                        if ($user->phone) {
                            try {
                                $this->whatsappService->sendMessage($user->phone, 
                                    "🔔 *Notifikasi Permohonan Izin Keluar*\n\n" .
                                    "Nama: {$requestKaryawan->nama}\n" .
                                    "Departemen: {$requestKaryawan->departemen->name}\n" .
                                    "Status: {$notificationTitle}\n" .
                                    "Catatan: {$notificationMessage}"
                                );
                            } catch (\Exception $e) {}
                        }
                    }
                }
    
                // Kirim WA ke karyawan
                if ($status == 2 && $requestKaryawan->no_telp) {
                    $karyawanMessage = "🔔 *Update Status Permohonan Izin Keluar Karyawan*\n\n" .
                        "Nama: {$requestKaryawan->nama}\n" .
                        "Departemen: {$requestKaryawan->departemen->name}\n" .
                        "Keperluan: {$requestKaryawan->keperluan}\n" .
                        "Jam Keluar: {$requestKaryawan->jam_out}\n" .
                        "Jam Kembali: {$requestKaryawan->jam_in}\n\n" .
                        "Status: {$notificationTitle}";
    
                    $this->sendWhatsAppToKaryawan($requestKaryawan->no_telp, $karyawanMessage);
                }
            }
    
            $requestKaryawan->save();
    
            return response()->json([
                'success' => true,
                'message' => 'Status permohonan berhasil diperbarui.'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating status for RequestKaryawan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update data permohonan izin keluar karyawan
     * 
     * @param int $id ID request karyawan
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        try {
            Log::info('Attempting to update RequestKaryawan with ID: ' . $id);
            // Validasi input
            $validated = $request->validate([
                'nama' => 'required|string|max:255',
                'departemen_id' => 'required|exists:departemens,id',
                'keperluan' => 'required|string',
                'jam_in' => 'required',
                'jam_out' => 'required',
            ]);

            // Ambil data request karyawan
            $requestKaryawan = RequestKaryawan::find($id);

            // Cek apakah data request karyawan ada
            if (!$requestKaryawan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Request Karyawan tidak ditemukan'
                ], 404);
            }

            // Update data
            $requestKaryawan->update($validated);

            // Format pesan untuk update
            $karyawanMessage = "🔔 *Update Data Permohonan Izin Keluar Karyawan*\n\n" .
                "Nama: {$requestKaryawan->nama}\n" .
                "Departemen: {$requestKaryawan->departemen->name}\n" .
                "Keperluan: {$requestKaryawan->keperluan}\n" .
                "Jam Keluar: {$requestKaryawan->jam_out}\n" .
                "Jam Kembali: {$requestKaryawan->jam_in}\n\n" .
                "Status: Data telah diperbarui";

            // Buat notifikasi
            $users = \App\Models\User::whereHas('role', function($query) {
                $query->whereIn('slug', ['admin', 'lead', 'hr-ga', 'security']);
            })->get();

            foreach($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Update Data Permohonan Izin Karyawan',
                    'message' => 'Data permohonan izin karyawan ' . $requestKaryawan->nama . 
                               ' dari departemen ' . $requestKaryawan->departemen->name . 
                               ' telah diperbarui',
                    'type' => 'karyawan',
                    'status' => 'pending',
                    'is_read' => false
                ]);
            }

            // Kirim pesan WhatsApp ke semua user departemen terkait dan HR-GA
            $this->sendWhatsAppToDepartemenAndHRGA($requestKaryawan->departemen_id, $karyawanMessage);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diperbarui'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengambil data permohonan karyawan untuk ekspor
     * 
     * @param int $month
     * @param int $year
     * @param string $type
     * @param string $exportType
     * @return array
     */
    private function getDataForExport($month, $year, $type, $exportType)
    {
        $user = auth()->user(); // Ambil user yang sedang login
        $query = RequestKaryawan::with(['departemen']);

        // Terapkan filter departemen berdasarkan role user
        if ($user->role_id == 2) { // Role Lead
            $query->where('departemen_id', $user->departemen_id);
        } elseif ($user->role_id == 3) { // Role HR GA
            $query->whereIn('departemen_id', function($q) {
                $q->select('id')
                    ->from('departemens')
                    ->whereNotIn('id', [1, 2]); // Kecuali departemen Admin dan HR
            });
        } elseif ($user->role_id == 4 || $user->role_id == 5) { // Role Checker dan Head Unit
            $query->whereRaw('1 = 0'); // Query yang selalu false
        }

        if ($exportType === 'filtered') {
            $query->whereMonth('created_at', $month)
                  ->whereYear('created_at', $year);
        }

        $requests = $query->orderBy('created_at', 'desc')
                          ->get()
                          ->map(function ($item) {
                                $statusBadge = 'warning';
                                $text = 'Menunggu';
                                
                                if($item->acc_lead == 3) {
                                    $statusBadge = 'danger';
                                    $text = 'Ditolak Lead';
                                } 
                                elseif($item->acc_hr_ga == 3) {
                                    $statusBadge = 'danger';
                                    $text = 'Ditolak HR/GA';
                                }
                                elseif($item->acc_security_out == 3) {
                                    $statusBadge = 'danger';
                                    $text = 'Ditolak Security Out';
                                } 
                                elseif($item->acc_security_in == 3) {
                                    $statusBadge = 'danger';
                                    $text = 'Ditolak Security In';
                                }
                                elseif($item->acc_lead == 1) {
                                    $statusBadge = 'warning';
                                    $text = 'Menunggu Lead';
                                }
                                elseif($item->acc_lead == 2 && $item->acc_hr_ga == 1) {
                                    $statusBadge = 'warning';
                                    $text = 'Menunggu HR/GA';
                                }
                                elseif($item->acc_lead == 2 && $item->acc_hr_ga == 2) {
                                    if($item->acc_security_out == 1) {
                                        if (Carbon::parse($item->jam_in)->isPast()) {
                                            $statusBadge = 'danger';
                                            $text = 'Hangus';
                                        } else {
                                            $statusBadge = 'info';
                                            $text = 'Disetujui (Belum Keluar)';
                                        }
                                    } elseif ($item->acc_security_out == 2) {
                                        if ($item->acc_security_in == 1) {
                                            if (Carbon::parse($item->jam_in)->isPast()) {
                                                $statusBadge = 'warning';
                                                $text = 'Terlambat';
                                            } else {
                                                $statusBadge = 'info';
                                                $text = 'Sudah Keluar (Belum Kembali)';
                                            }
                                        } elseif ($item->acc_security_in == 2) {
                                            $statusBadge = 'success';
                                            $text = 'Sudah Kembali';
                                        }
                                    }
                                }

                                return [
                                    'no_surat' => $item->no_surat ?? '-',
                                    'nama' => $item->nama ?? '-',
                                    'no_telp' => $item->no_telp ?? '-',
                                    'departemen' => $item->departemen->name ?? '-',
                                    'keperluan' => $item->keperluan ?? '-',
                                    'tanggal' => Carbon::parse($item->created_at)->format('Y-m-d'),
                                    'jam_out' => $item->jam_out ?? '-',
                                    'jam_in' => $item->jam_in ?? '-',
                                    'status' => $statusBadge,
                                    'text' => $text,
                                    'tipe' => 'Karyawan'
                                ];
                            });

        // Filter berdasarkan tipe jika bukan 'all' - ini tidak lagi diperlukan karena sudah difilter oleh peran
        // if ($type !== 'all') {
        //     $requests = $requests->filter(function($item) use ($type) {
        //         return $item['tipe'] === $type;
        //     });
        // }

        return $requests->values()->all(); // Reset array keys
    }

    /**
     * Export data permohonan karyawan ke PDF
     * 
     * @param int $month
     * @param int $year
     * @param string $type
     * @return \Illuminate\Http\Response
     */
    public function exportPDF($month, $year, $type = 'all')
    {
        $exportType = request()->query('type', 'filtered');
        $data = $this->getDataForExport($month, $year, $type, $exportType);
        $pdf = PDF::loadView('exports.request-karyawan', compact('data', 'month', 'year', 'type'));
        return $pdf->stream('laporan_karyawan.pdf');
    }

    /**
     * Export data permohonan karyawan ke Excel
     * 
     * @param int $month
     * @param int $year
     * @param string $type
     * @return \Illuminate\Http\Response
     */
    public function exportExcel($month, $year, $type = 'all')
    {
        $exportType = request()->query('type', 'filtered');
        $data = $this->getDataForExport($month, $year, $type, $exportType);
        
        return Excel::download(new \App\Exports\RequestKaryawanExport($data), 'laporan_karyawan.xlsx');
    }

    /**
     * Mengambil tahun-tahun yang tersedia untuk filter
     * 
     * @return array
     */
    private function getAvailableYears()
    {
        $years = [];
        $currentYear = date('Y');
        
        // Ambil tahun dari data permohonan
        $karyawanYears = RequestKaryawan::selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->pluck('year')
            ->toArray();
            
        // Gabungkan dan hapus duplikat
        $years = array_unique($karyawanYears);
        
        // Tambahkan tahun saat ini jika belum ada
        if (!in_array($currentYear, $years)) {
            $years[] = $currentYear;
        }
        
        // Urutkan dari yang terbaru
        rsort($years);
        
        return $years;
    }

    /**
     * Mengambil data permohonan terbaru dengan filter
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestRequests(Request $request)
    {
        $month = $request->input('month', date('m'));
        $year = $request->input('year', date('Y'));
        $user = auth()->user();
        Log::debug('User Role ID in RequestKaryawanController@getLatestRequests: ' . $user->role_id . ' - User Departemen ID: ' . $user->departemen_id . ' - User Departemen Name: ' . ($user->departemen ? $user->departemen->name : 'N/A'));
        $data = [];

        $karyawanQuery = RequestKaryawan::with(['departemen']);

        // Filter berdasarkan role dan departemen untuk karyawan
        if ($user->role_id == 2) { // Role Lead
            Log::debug('Applying Lead filter in RequestKaryawanController@getLatestRequests for departemen_id: ' . $user->departemen_id);
            $karyawanQuery->where('departemen_id', $user->departemen_id);
        } elseif ($user->role_id == 3) { // Role HR GA
            Log::debug('Applying HR GA filter in RequestKaryawanController@getLatestRequests (excluding Admin and HR)');
            $karyawanQuery->whereIn('departemen_id', function($query) {
                $query->select('id')
                    ->from('departemens')
                    ->whereNotIn('id', [1, 2]);
            });
        } elseif ($user->role_id == 4 || $user->role_id == 5) { // Role Checker dan Head Unit
            Log::debug('Applying Checker/Head Unit filter in RequestKaryawanController@getLatestRequests (no karyawan data)');
            $karyawanQuery->whereRaw('1 = 0');
        }

        // Hanya ambil data karyawan jika user memiliki akses (bukan role checker dan head unit)
        if ($user->role_id != 4 && $user->role_id != 5) {
            $requests = (clone $karyawanQuery)
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->get();
                Log::debug('Fetched Karyawan Requests in RequestKaryawanController@getLatestRequests (before map/filter): ' . $requests->count() . ' items');

                $karyawanRequests = $requests->map(function ($item) use ($user) {
                    Log::debug('Processing Karyawan Item in RequestKaryawanController@getLatestRequests ID: ' . $item->id . ' - Departemen ID: ' . $item->departemen_id . ' - Departemen Name: ' . $item->departemen->name);
                    // Log::debug('Original Request Karyawan Item: ' . json_encode($item->toArray()));
                    $statusBadge = 'warning';
                    $text = 'Menunggu';
                    
                    // Cek jika ada yang menolak
                    if($item->acc_lead == 3) {
                        $statusBadge = 'danger';
                        $text = 'Ditolak Lead';
                    } 
                    elseif($item->acc_hr_ga == 3) {
                        $statusBadge = 'danger';
                        $text = 'Ditolak HR/GA';
                    }
                    elseif($item->acc_security_out == 3) {
                        $statusBadge = 'danger';
                        $text = 'Ditolak Security Out';
                    } 
                    elseif($item->acc_security_in == 3) {
                        $statusBadge = 'danger';
                        $text = 'Ditolak Security In';
                    }
                    // Cek urutan persetujuan sesuai alur jika tidak ditolak
                    elseif($item->acc_lead == 1) {
                        $statusBadge = 'warning';
                        $text = 'Menunggu Lead';
                    }
                    elseif($item->acc_lead == 2 && $item->acc_hr_ga == 1) {
                        $statusBadge = 'warning';
                        $text = 'Menunggu HR/GA';
                    }
                    // Jika sudah disetujui Lead dan HR GA
                    elseif($item->acc_lead == 2 && $item->acc_hr_ga == 2) {
                        // Cek status security
                        if($item->acc_security_out == 1) {
                            // Cek status hangus (jam in sudah lewat tapi belum keluar)
                            if (\Carbon\Carbon::parse($item->jam_in)->isPast()) {
                                $statusBadge = 'danger';
                                $text = 'Hangus';
                            } else {
                                $statusBadge = 'info';
                                $text = 'Disetujui (Belum Keluar)';
                            }
                        } elseif ($item->acc_security_out == 2) {
                            // Cek status security in
                            if ($item->acc_security_in == 1) {
                                // Cek status terlambat (sudah keluar tapi belum kembali)
                                if (\Carbon\Carbon::parse($item->jam_in)->isPast()) {
                                    $statusBadge = 'warning';
                                    $text = 'Terlambat';
                                } else {
                                    $statusBadge = 'info';
                                    $text = 'Sudah Keluar (Belum Kembali)';
                                }
                            } elseif ($item->acc_security_in == 2) {
                                $statusBadge = 'success';
                                $text = 'Sudah Kembali';
                            }
                        }
                    }

                    $mappedItem = [
                        'id' => $item->id,
                        'no_surat' => $item->no_surat ?? '-',
                        'nama' => $item->nama,
                        'departemen' => $item->departemen->name,
                        'tanggal' => \Carbon\Carbon::parse($item->created_at)->format('Y-m-d'),
                        'jam_out' => $item->jam_out,
                        'jam_in' => $item->jam_in,
                        'keperluan' => $item->keperluan,
                        'status_badge' => $statusBadge,
                        'status_text' => $text,
                        'tipe' => 'Karyawan',
                        'no_telp' => $item->no_telp ?? '-',
                        'acc_lead' => $item->acc_lead,
                        'acc_hr_ga' => $item->acc_hr_ga,
                        'acc_security_out' => $item->acc_security_out,
                        'acc_security_in' => $item->acc_security_in,
                        'user_role_id' => $user->role_id,
                        'user_role_title' => $user->role->title ?? '',
                        'departemen_id' => $item->departemen_id,
                    ];

                    // Logika filter departemen untuk Lead
                    if ($user->role_id == 2 && $item->departemen_id != $user->departemen_id) {
                        Log::debug('Skipping item for Lead in RequestKaryawanController@getLatestRequests: ' . $item->id . ' - Item Departemen ID: ' . $item->departemen_id . ' vs User Departemen ID: ' . $user->departemen_id);
                        return null;
                    }

                    return $mappedItem;
                })
                ->filter() // Hapus item yang null (tidak lolos filter Lead)
                ->values() // Reset index array
                ->toArray();
            
            $data = array_merge($data, $karyawanRequests);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Export data permohonan karyawan ke PDF per item
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function exportSinglePDF($id)
    {
        $requestKaryawan = RequestKaryawan::with('departemen')->find($id);

        if (!$requestKaryawan) {
            abort(404, 'Data Permohonan Karyawan tidak ditemukan.');
        }

        // Format data sesuai kebutuhan PDF
        $data = [
            'no_surat' => $requestKaryawan->no_surat,
            'nama' => $requestKaryawan->nama,
            'no_telp' => $requestKaryawan->no_telp,
            'departemen' => $requestKaryawan->departemen->name,
            'keperluan' => $requestKaryawan->keperluan,
            'jam_out' => $requestKaryawan->jam_out,
            'jam_in' => $requestKaryawan->jam_in,
            'tanggal' => \Carbon\Carbon::parse($requestKaryawan->created_at)->format('Y-m-d'), // Menambahkan tanggal pengajuan
        ];

        // Logika untuk status persetujuan yang lebih detail
        $statusLead = 'Menunggu';
        if ($requestKaryawan->acc_lead == 2) {
            $statusLead = 'Disetujui';
        } elseif ($requestKaryawan->acc_lead == 3) {
            $statusLead = 'Ditolak';
        }

        $statusHrGa = 'Menunggu';
        if ($requestKaryawan->acc_hr_ga == 2) {
            $statusHrGa = 'Disetujui';
        } elseif ($requestKaryawan->acc_hr_ga == 3) {
            $statusHrGa = 'Ditolak';
        }

        $statusSecurityOut = 'Belum Keluar';
        if ($requestKaryawan->acc_security_out == 2) {
            $statusSecurityOut = 'Sudah Keluar';
        } elseif ($requestKaryawan->acc_security_out == 3) {
            $statusSecurityOut = 'Ditolak Keluar';
        }

        $statusSecurityIn = 'Belum Kembali';
        if ($requestKaryawan->acc_security_in == 2) {
            $statusSecurityIn = 'Sudah Kembali';
        } elseif ($requestKaryawan->acc_security_in == 3) {
            $statusSecurityIn = 'Ditolak Kembali';
        }

        // Tambahkan status ke data
        $data['status_lead'] = $statusLead;
        $data['status_hr_ga'] = $statusHrGa;
        $data['status_security_out'] = $statusSecurityOut;
        $data['status_security_in'] = $statusSecurityIn;

        $pdf = Pdf::loadView('exports.karyawan-single', compact('data'));
        $pdf->setPaper('A4', 'portrait');
        // Bersihkan karakter "/" dan "\" dari no_surat
        $cleanNoSurat = str_replace(['/', '\\'], '-', $requestKaryawan->no_surat);
        return $pdf->stream('surat_izin_karyawan_' . $cleanNoSurat . '.pdf');
    }

    /**
     * Kirim pesan WhatsApp ke semua user departemen terkait dan HR-GA
     */
    private function sendWhatsAppToDepartemenAndHRGA($departemenId, $message)
    {
        // Kirim ke semua user di departemen terkait
        $usersDepartemen = \App\Models\User::where('departemen_id', $departemenId)->get();
        foreach ($usersDepartemen as $user) {
            if ($user->phone) {
                try {
                    $this->whatsappService->sendMessage($user->phone, $message);
                } catch (\Exception $e) {}
            }
        }
        // Kirim ke semua user HR-GA
        $usersHRGA = \App\Models\User::whereHas('role', function($q) {
            $q->where('slug', 'hr-ga');
        })->get();
        foreach ($usersHRGA as $user) {
            if ($user->phone) {
                try {
                    $this->whatsappService->sendMessage($user->phone, $message);
                } catch (\Exception $e) {}
            }
        }
    }

    /**
     * Kirim pesan WhatsApp ke karyawan berdasarkan nomor telepon dari request karyawan.
     *
     * @param string $phone Nomor telepon karyawan (wajib diawali dengan kode negara, misal 6281234567890)
     * @param string $message Pesan WhatsApp yang ingin dikirimkan
     */
    private function sendWhatsAppToKaryawan($phone, $message)
    {
        // Cek jika nomor telepon valid
        if (!$phone || !preg_match('/^[0-9]{10,15}$/', $phone)) {
            // Nomor tidak valid atau kosong, abaikan atau bisa di-log
            \Log::warning("Gagal mengirim WA ke karyawan: Nomor telepon tidak valid - {$phone}");
            return;
        }
    
        try {
            $this->whatsappService->sendMessage($phone, $message);
        } catch (\Exception $e) {
            // Tangkap dan log error jika gagal kirim
            \Log::error("Gagal mengirim WhatsApp ke karyawan: " . $e->getMessage());
        }
    }
}
