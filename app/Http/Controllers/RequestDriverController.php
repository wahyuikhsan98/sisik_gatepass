<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\RequestDriver;
use App\Models\Ekspedisi;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;

class RequestDriverController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Menampilkan daftar permohonan izin keluar driver
     * 
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $title = 'Permohonan Izin Keluar Driver';
        $user = auth()->user();
        $requestDrivers = collect(); // Inisialisasi collection kosong
        $ekspedisis = Ekspedisi::all(); // Ambil semua data ekspedisi

        // Ambil data permohonan driver berdasarkan role
        if ($user->role_id != 2 && $user->role_id != 3) { // Bukan role lead dan hr-ga
            $requestDrivers = RequestDriver::with('ekspedisi')->get();
        }
        
        // Menghitung total request berdasarkan status dengan urutan persetujuan untuk permohonan yang terlihat oleh user
        $totalMenunggu = 0;
        $totalDisetujui = 0;
        $totalDitolak = 0;
        $totalRequest = 0;

        if ($user->role_id != 2 && $user->role_id != 3) { // Bukan role lead dan hr-ga
            // Permohonan Menunggu Driver: Belum disetujui semua pihak DAN belum ditolak oleh siapapun DAN belum keluar security
            $totalMenunggu = RequestDriver::where(function($query) {
                $query->where(function($q) {
                    $q->where('acc_admin', 1) // Admin belum menyetujui
                      ->orWhere('acc_head_unit', 1) // Head Unit belum menyetujui setelah Admin acc
                      ->orWhere('acc_security_out', 1); // Security Out belum menyetujui setelah Head Unit acc
                })
                ->where('acc_admin', '!=', 3)
                ->where('acc_head_unit', '!=', 3)
                ->where('acc_security_out', '!=', 3)
                ->where('acc_security_in', '!=', 3);
            })
            ->count();
                
            // Permohonan Disetujui Driver: Sudah disetujui Admin, Head Unit, dan Security Out (baik sudah kembali atau belum)
            $totalDisetujui = RequestDriver::where('acc_admin', 2)
                ->where('acc_head_unit', 2)
                ->where('acc_security_out', 2)
                ->count();
                
            // Permohonan Ditolak Driver: Ditolak oleh salah satu pihak
            $totalDitolak = RequestDriver::where(function($query) {
                $query->where('acc_admin', 3) // Admin menolak
                    ->orWhere('acc_head_unit', 3) // Head Unit menolak
                    ->orWhere('acc_security_out', 3) // Security Out menolak
                    ->orWhere('acc_security_in', 3); // Security In menolak
            })->count();
                
            // Total semua request driver yang terlihat oleh user
            $totalRequest = RequestDriver::count();
        }

        // Mengambil tahun-tahun yang tersedia untuk filter
        $years = $this->getAvailableYears();

        return view('superadmin.request-driver.index', compact(
            'title',
            'requestDrivers',
            'ekspedisis',
            'totalMenunggu',
            'totalDisetujui',
            'totalDitolak',
            'totalRequest',
            'years'
        ));
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
        $driverYears = RequestDriver::selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->pluck('year')
            ->toArray();
            
        // Gabungkan dan hapus duplikat
        $years = array_unique($driverYears);
        
        // Tambahkan tahun saat ini jika belum ada
        if (!in_array($currentYear, $years)) {
            $years[] = $currentYear;
        }
        
        // Urutkan dari yang terbaru
        rsort($years);
        
        return $years;
    }

    /**
     * Menampilkan form pengajuan izin keluar driver
     * 
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $title = 'Form Izin Keluar Driver';
        $ekspedisis = \App\Models\Ekspedisi::all();
        return view('driver', compact('title', 'ekspedisis'));
    }

    /**
     * Menyimpan permohonan izin keluar driver baru
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        try {
            // Validasi input
            $validated = $request->validate([
                'ekspedisi_id' => 'required|exists:ekspedisis,id',
                'nopol_kendaraan' => 'required|string|max:255',
                'nama_driver' => 'required|string|max:255',
                'no_hp_driver' => 'required|string|max:20',
                'nama_kernet' => 'nullable|string|max:255',
                'no_hp_kernet' => 'nullable|string|max:20',
                'keperluan' => 'required|string|max:255',
                'jam_in' => 'required',
                'jam_out' => 'required',
            ]);
    
            // Set status approval default ke "1" (menunggu)
            $validated = array_merge($validated, [
                'acc_admin' => 1,
                'acc_head_unit' => 1,
                'acc_security_in' => 1,
                'acc_security_out' => 1
            ]);
    
            // Buat nomor surat
            $today = now();
            $year = $today->format('y');
            $month = $today->format('m');
            $day = $today->format('d');
    
            $last = RequestDriver::whereDate('created_at', $today)
                ->orderByDesc('id')
                ->first();
    
            $lastSequence = 0;
            if ($last && preg_match('/SID\/(\d{3})\//', $last->no_surat, $match)) {
                $lastSequence = (int) $match[1];
            }
            $nextSequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            $noSurat = "SID/{$nextSequence}/{$day}/{$month}/{$year}";
    
            // Pastikan unik
            if (RequestDriver::where('no_surat', $noSurat)->exists()) {
                throw new \Exception("Nomor surat sudah digunakan. Silakan coba kembali.");
            }
    
            $validated['no_surat'] = $noSurat;
    
            // Simpan data
            $requestDriver = RequestDriver::create($validated);
    
            // Ambil data ekspedisi
            $ekspedisi = Ekspedisi::find($validated['ekspedisi_id']);
    
            // Format pesan WhatsApp
            $message = "🔔 *Permohonan Izin Keluar Driver*\n\n"
                     . "No Surat: $noSurat\n"
                     . "Ekspedisi: {$ekspedisi->nama_ekspedisi}\n"
                     . "Nama Driver: {$validated['nama_driver']}\n"
                     . "Nopol: {$validated['nopol_kendaraan']}\n"
                     . "Keperluan: {$validated['keperluan']}\n"
                     . "Jam Keluar: {$validated['jam_out']}\n"
                     . "Jam Kembali: {$validated['jam_in']}\n\n"
                     . "Status: Menunggu Persetujuan";
    
            // Kirim ke driver
            $phone = preg_replace('/[^0-9]/', '', $validated['no_hp_driver']);
            if (substr($phone, 0, 2) !== '62') {
                $phone = '62' . ltrim($phone, '0');
            }
    
            try {
                if (!$this->whatsappService->checkDeviceStatus()) {
                    throw new \Exception("Perangkat WhatsApp tidak terhubung.");
                }
    
                $this->whatsappService->sendMessage($phone, $message);
                Log::info("Pesan WhatsApp berhasil dikirim ke driver: $phone");
            } catch (\Exception $e) {
                Log::error("Gagal kirim WhatsApp ke driver", ['phone' => $phone, 'error' => $e->getMessage()]);
            }
    
            // Kirim ke admin, checker, head-unit
            $adminUsers = User::whereHas('role', fn($q) => $q->whereIn('slug', ['admin', 'checker', 'head-unit']))->get();
            foreach ($adminUsers as $user) {
                if ($user->no_telp) {
                    $telp = preg_replace('/[^0-9]/', '', $user->no_telp);
                    if (substr($telp, 0, 2) !== '62') {
                        $telp = '62' . ltrim($telp, '0');
                    }
                    try {
                        $this->whatsappService->sendMessage($telp, $message);
                        Log::info("Pesan WhatsApp berhasil dikirim ke admin: {$user->name}");
                    } catch (\Exception $e) {
                        Log::error("Gagal kirim WA ke admin", ['name' => $user->name, 'error' => $e->getMessage()]);
                    }
                }
            }
    
            // Notifikasi sistem
            $notifUsers = User::whereHas('role', fn($q) => $q->whereIn('slug', ['admin', 'checker', 'head-unit', 'security']))->get();
            foreach ($notifUsers as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Permohonan Izin Keluar Driver',
                    'message' => "Driver {$validated['nama_driver']} dari ekspedisi {$ekspedisi->nama_ekspedisi} mengajukan izin keluar.",
                    'type' => 'driver',
                    'status' => 'pending',
                    'is_read' => false,
                ]);
            }
    
            // Pesan sukses
            $successMsg = "Pengajuan izin driver berhasil dikirim.\n"
                        . "No Surat: $noSurat\n"
                        . "Ekspedisi: {$ekspedisi->nama_ekspedisi}\n"
                        . "Driver: {$validated['nama_driver']}\n"
                        . "Nopol: {$validated['nopol_kendaraan']}";
    
            if ($request->ajax()) {
                return response()->json(['success' => true, 'message' => $successMsg]);
            }
    
            return redirect()->back()->with('success', $successMsg);
    
        } catch (\Exception $e) {
            $errorMsg = 'Terjadi kesalahan: ' . $e->getMessage();
            Log::error($errorMsg);
    
            if ($request->ajax()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 500);
            }
    
            return redirect()->back()->with('error', $errorMsg);
        }
    }

    /**
     * Kirim pesan WhatsApp ke semua user dengan role checker dan head unit
     */
    private function sendWhatsAppToCheckerAndHeadUnit($message)
    {
        // Kirim ke semua user dengan role checker
        $usersChecker = \App\Models\User::whereHas('role', function($q) {
            $q->where('slug', 'checker');
        })->get();
        foreach ($usersChecker as $user) {
            if ($user->phone) {
                try {
                    $this->whatsappService->sendMessage($user->phone, $message);
                } catch (\Exception $e) {}
            }
        }
        // Kirim ke semua user dengan role head unit
        $usersHeadUnit = \App\Models\User::whereHas('role', function($q) {
            $q->where('slug', 'head-unit');
        })->get();
        foreach ($usersHeadUnit as $user) {
            if ($user->phone) {
                try {
                    $this->whatsappService->sendMessage($user->phone, $message);
                } catch (\Exception $e) {}
            }
        }
    }

    private function sendWhatsAppToDriver($phone, $message)
    {
        // Validasi nomor telepon driver
        if (!$phone || !preg_match('/^[0-9]{10,15}$/', $phone)) {
            \Log::warning("Gagal mengirim WA ke driver: Nomor telepon tidak valid - {$phone}");
            return;
        }
    
        try {
            $this->whatsappService->sendMessage($phone, $message);
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim WhatsApp ke driver: " . $e->getMessage());
        }
    }

    /**
     * Menangani persetujuan permohonan izin keluar driver
     * 
     * @param int $id ID request driver
     * @param int $role_id ID role yang menyetujui
     * @return \Illuminate\Http\JsonResponse
     */
    public function accRequest($id, $role_id)
    {
        try {
            // Ambil data request driver
            $requestDriver = RequestDriver::find($id);

            // Cek apakah data request driver ada
            if (!$requestDriver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Request Driver tidak ditemukan'
                ], 404);
            }

            // Update status persetujuan berdasarkan role
            switch ($role_id) {
                case 4: // Checker
                    $requestDriver->acc_admin = 2;
                    $notificationTitle = 'Disetujui Checker';
                    $notificationMessage = "🔔 *Persetujuan Permohonan Izin Keluar Driver*\n\n" .
                        "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                        "Nama Driver: {$requestDriver->nama_driver}\n" .
                        "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                        "Keperluan: {$requestDriver->keperluan}\n" .
                        "Jam Keluar: {$requestDriver->jam_out}\n" .
                        "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                        "Status: Permohonan telah diproses";
                    // Cari user dengan role head unit dan admin
                    $users = \App\Models\User::whereHas('role', function($query) {
                        $query->whereIn('slug', ['head-unit', 'admin']);
                    })->get();
                    break;
                case 5: // Head Unit
                    $requestDriver->acc_head_unit = 2;
                    $notificationTitle = 'Disetujui Head Unit';
                    $notificationMessage = "🔔 *Persetujuan Permohonan Izin Keluar Driver*\n\n" .
                        "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                        "Nama Driver: {$requestDriver->nama_driver}\n" .
                        "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                        "Keperluan: {$requestDriver->keperluan}\n" .
                        "Jam Keluar: {$requestDriver->jam_out}\n" .
                        "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                        "Status: Permohonan telah diproses";
                    // Cari user dengan role security dan admin
                    $users = \App\Models\User::whereHas('role', function($query) {
                        $query->whereIn('slug', ['security', 'admin']);
                    })->get();
                    break;
                case 6: // Security
                    if ($requestDriver->acc_security_out == 1) {
                        $requestDriver->acc_security_out = 2;
                        $notificationTitle = 'Disetujui Security Out';
                        $notificationMessage = "🔔 *Persetujuan Permohonan Izin Keluar Driver*\n\n" .
                            "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                            "Nama Driver: {$requestDriver->nama_driver}\n" .
                            "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                            "Keperluan: {$requestDriver->keperluan}\n" .
                            "Jam Keluar: {$requestDriver->jam_out}\n" .
                            "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                            "Status: Permohonan telah diproses";
                        // Cari user dengan role admin
                        $users = \App\Models\User::whereHas('role', function($query) {
                            $query->where('slug', 'admin');
                        })->get();
                    } else {
                        $requestDriver->acc_security_in = 2;
                        $notificationTitle = 'Disetujui Security In';
                        $notificationMessage = "🔔 *Persetujuan Permohonan Izin Keluar Driver*\n\n" .
                            "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                            "Nama Driver: {$requestDriver->nama_driver}\n" .
                            "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                            "Keperluan: {$requestDriver->keperluan}\n" .
                            "Jam Keluar: {$requestDriver->jam_out}\n" .
                            "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                            "Status: Permohonan telah diproses";
                        // Cari user dengan role admin
                        $users = \App\Models\User::whereHas('role', function($query) {
                            $query->where('slug', 'admin');
                        })->get();
                    }
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Role tidak valid'
                    ], 400);
            }

            // Simpan perubahan
            $requestDriver->save();

            // Buat notifikasi untuk setiap user yang ditemukan
            foreach($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => $notificationTitle,
                    'message' => 'Permohonan izin driver ' . $requestDriver->nama_ekspedisi . 
                               ' dengan nopol ' . $requestDriver->nopol_kendaraan . 
                               ' ' . $notificationMessage,
                    'type' => 'driver',
                    'status' => 'pending',
                    'is_read' => false
                ]);
            }

            // Kirim pesan WhatsApp ke semua user dengan role checker dan head unit
            $this->sendWhatsAppToCheckerAndHeadUnit($notificationMessage);

            return response()->json([
                'success' => true,
                'message' => 'Permohonan izin driver berhasil disetujui'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status persetujuan permohonan izin keluar driver
     * 
     * @param int $id ID request driver
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus($id, Request $request)
    {
        try {
            // Ambil data request driver
            $requestDriver = RequestDriver::find($id);

            // Cek apakah data request driver ada
            if (!$requestDriver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Request Driver tidak ditemukan'
                ], 404);
            }

            // Update status berdasarkan input
            $statuses = $request->input('statuses');
            
            if (isset($statuses['admin'])) {
                $requestDriver->acc_admin = $statuses['admin'];
            }
            if (isset($statuses['head-unit'])) {
                $requestDriver->acc_head_unit = $statuses['head-unit'];
            }
            if (isset($statuses['security-out'])) {
                $requestDriver->acc_security_out = $statuses['security-out'];
            }
            if (isset($statuses['security-in'])) {
                $requestDriver->acc_security_in = $statuses['security-in'];
            }

            // Simpan perubahan
            $requestDriver->save();

            // Format pesan untuk update status
            $notificationMessage = "🔔 *Update Status Permohonan Izin Keluar Driver*\n\n" .
                "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                "Nama Driver: {$requestDriver->nama_driver}\n" .
                "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                "Keperluan: {$requestDriver->keperluan}\n" .
                "Jam Keluar: {$requestDriver->jam_out}\n" .
                "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                "Status: Status permohonan telah diperbarui";

            // Buat notifikasi
            $users = \App\Models\User::whereHas('role', function($query) {
                $query->whereIn('slug', ['admin', 'checker', 'head-unit', 'security']);
            })->get();

            foreach($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Update Status Permohonan Izin Driver',
                    'message' => 'Status permohonan izin driver ' . $requestDriver->nama_ekspedisi . 
                               ' dengan nopol ' . $requestDriver->nopol_kendaraan . 
                               ' telah diperbarui',
                    'type' => 'driver',
                    'status' => 'pending',
                    'is_read' => false
                ]);
            }

            // Kirim pesan WhatsApp ke semua user dengan role checker dan head unit
            $this->sendWhatsAppToCheckerAndHeadUnit($notificationMessage);

            return response()->json([
                'success' => true,
                'message' => 'Status berhasil diperbarui'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update data permohonan izin keluar driver
     * 
     * @param int $id ID request driver
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        try {
            Log::info('Attempting to update RequestDriver with ID: ' . $id);
            // Validasi input
            $validated = $request->validate([
                'ekspedisi_id' => 'required|exists:ekspedisis,id',
                'nopol_kendaraan' => 'required|string|max:255',
                'nama_driver' => 'required|string|max:255',
                'no_hp_driver' => 'required|string|max:255',
                'nama_kernet' => 'nullable|string|max:255',
                'no_hp_kernet' => 'nullable|string|max:255',
                'keperluan' => 'required|string',
                'jam_in' => 'required',
                'jam_out' => 'required',
            ]);

            // Ambil data request driver
            $requestDriver = RequestDriver::find($id);

            // Cek apakah data request driver ada
            if (!$requestDriver) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Request Driver tidak ditemukan'
                ], 404);
            }

            // Update data
            $requestDriver->update($validated);

            // Format pesan untuk update
            $notificationMessage = "🔔 *Update Data Permohonan Izin Keluar Driver*\n\n" .
                "Nama Ekspedisi: {$requestDriver->ekspedisi->nama_ekspedisi}\n" .
                "Nama Driver: {$requestDriver->nama_driver}\n" .
                "No Polisi: {$requestDriver->nopol_kendaraan}\n" .
                "Keperluan: {$requestDriver->keperluan}\n" .
                "Jam Keluar: {$requestDriver->jam_out}\n" .
                "Jam Kembali: {$requestDriver->jam_in}\n\n" .
                "Status: Data telah diperbarui";

            // Buat notifikasi
            $users = \App\Models\User::whereHas('role', function($query) {
                $query->whereIn('slug', ['admin', 'checker', 'head-unit', 'security']);
            })->get();

            foreach($users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'Update Data Permohonan Izin Driver',
                    'message' => 'Data permohonan izin driver ' . $requestDriver->nama_ekspedisi . 
                               ' dengan nopol ' . $requestDriver->nopol_kendaraan . 
                               ' telah diperbarui',
                    'type' => 'driver',
                    'status' => 'pending',
                    'is_read' => false
                ]);
            }

            // Kirim pesan WhatsApp ke semua user dengan role checker dan head unit
            $this->sendWhatsAppToCheckerAndHeadUnit($notificationMessage);

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
        $data = [];

        $requests = RequestDriver::with(['ekspedisi'])
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) use ($user) {
                Log::debug('Original Request Driver Item: ' . json_encode($item->toArray()));
                $statusBadge = 'warning';
                $text = 'Menunggu';
                
                // Cek jika ada yang menolak
                if($item->acc_admin == 3) {
                    $statusBadge = 'danger';
                    $text = 'Ditolak Admin';
                } 
                elseif($item->acc_head_unit == 3) {
                    $statusBadge = 'danger';
                    $text = 'Ditolak Head Unit';
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
                elseif($item->acc_admin == 1) {
                    $statusBadge = 'warning';
                    $text = 'Menunggu Admin/Checker';
                }
                elseif($item->acc_admin == 2 && $item->acc_head_unit == 1) {
                    $statusBadge = 'warning';
                    $text = 'Menunggu Head Unit';
                }
                // Jika sudah disetujui Admin dan Head Unit
                elseif($item->acc_admin == 2 && $item->acc_head_unit == 2) {
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
                    'nama_ekspedisi' => $item->ekspedisi ? $item->ekspedisi->nama_ekspedisi : '-',
                    'nopol_kendaraan' => $item->nopol_kendaraan,
                    'nama_driver' => $item->nama_driver,
                    'no_hp_driver' => $item->no_hp_driver ?? '-',
                    'tanggal' => \Carbon\Carbon::parse($item->created_at)->format('Y-m-d'),
                    'jam_out' => $item->jam_out,
                    'jam_in' => $item->jam_in,
                    'keperluan' => $item->keperluan,
                    'status_badge' => $statusBadge,
                    'status_text' => $text,
                    'acc_admin' => $item->acc_admin,
                    'acc_head_unit' => $item->acc_head_unit,
                    'acc_security_out' => $item->acc_security_out,
                    'acc_security_in' => $item->acc_security_in,
                    'user_role_id' => $user->role_id,
                    'user_role_title' => $user->role->title ?? '',
                ];
                Log::debug('Mapped Request Driver Item: ' . json_encode($mappedItem));
                return $mappedItem;
            });

        $data = array_merge($data, $requests->toArray());

        return response()->json($data);
    }

    /**
     * Export data permohonan driver ke PDF
     * @param Request $request
     * @param int $month
     * @param int $year
     * @return \Illuminate\Http\Response
     */
    public function exportPDF(Request $request, $month, $year)
    {
        $type = $request->input('type', 'filtered'); // 'filtered' atau 'all'
        $query = RequestDriver::with(['ekspedisi']);

        if ($type === 'filtered') {
            $query->whereMonth('created_at', $month)
                  ->whereYear('created_at', $year);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        $data = $requests->map(function ($item) {
            $statusBadge = 'warning';
            $text = 'Menunggu';
            
            // Cek jika ada yang menolak
            if($item->acc_admin == 3) {
                $statusBadge = 'danger';
                $text = 'Ditolak Admin';
            } 
            elseif($item->acc_head_unit == 3) {
                $statusBadge = 'danger';
                $text = 'Ditolak Head Unit';
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
            elseif($item->acc_admin == 1) {
                $statusBadge = 'warning';
                $text = 'Menunggu Admin/Checker';
            }
            elseif($item->acc_admin == 2 && $item->acc_head_unit == 1) {
                $statusBadge = 'warning';
                $text = 'Menunggu Head Unit';
            }
            // Jika sudah disetujui Admin dan Head Unit
            elseif($item->acc_admin == 2 && $item->acc_head_unit == 2) {
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
            return [
                'no_surat' => $item->no_surat ?? '-',
                'nama_ekspedisi' => $item->ekspedisi ? $item->ekspedisi->nama_ekspedisi : '-',
                'nopol_kendaraan' => $item->nopol_kendaraan,
                'nama_driver' => $item->nama_driver,
                'no_hp_driver' => $item->no_hp_driver ?? '-',
                'tanggal' => \Carbon\Carbon::parse($item->created_at)->format('Y-m-d'),
                'jam_out' => $item->jam_out,
                'jam_in' => $item->jam_in,
                'keperluan' => $item->keperluan,
                'status_text' => $text
            ];
        });

        $pdf = PdfFacade::loadView('superadmin.exports.driver_pdf', compact('data', 'month', 'year', 'type'));
        return $pdf->stream('laporan-driver-' . ($type === 'all' ? 'all' : $month . '-' . $year) . '.pdf');
    }

    /**
     * Export data permohonan driver ke Excel
     * @param Request $request
     * @param int $month
     * @param int $year
     * @return \Illuminate\Http\Response
     */
    public function exportExcel(Request $request, $month, $year)
    {
        $type = $request->input('type', 'filtered'); // 'filtered' atau 'all'
        $query = RequestDriver::with(['ekspedisi']);

        if ($type === 'filtered') {
            $query->whereMonth('created_at', $month)
                  ->whereYear('created_at', $year);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        $data = $requests->map(function ($item) {
            $text = 'Menunggu';
            
            if($item->acc_admin == 3) $text = 'Ditolak Admin';
            elseif($item->acc_head_unit == 3) $text = 'Ditolak Head Unit';
            elseif($item->acc_security_out == 3) $text = 'Ditolak Security Out'; 
            elseif($item->acc_security_in == 3) $text = 'Ditolak Security In';
            elseif($item->acc_admin == 1) $text = 'Menunggu Admin/Checker';
            elseif($item->acc_admin == 2 && $item->acc_head_unit == 1) $text = 'Menunggu Head Unit';
            elseif($item->acc_admin == 2 && $item->acc_head_unit == 2) {
                if($item->acc_security_out == 1) {
                    if (\Carbon\Carbon::parse($item->jam_in)->isPast()) {
                        $text = 'Hangus';
                    } else {
                        $text = 'Disetujui (Belum Keluar)';
                    }
                } elseif ($item->acc_security_out == 2) {
                    if ($item->acc_security_in == 1) {
                        if (\Carbon\Carbon::parse($item->jam_in)->isPast()) {
                            $text = 'Terlambat';
                        } else {
                            $text = 'Sudah Keluar (Belum Kembali)';
                        }
                    } elseif ($item->acc_security_in == 2) {
                        $text = 'Sudah Kembali';
                    }
                }
            }
            return [
                'No Surat' => $item->no_surat ?? '-',
                'Nama Ekspedisi' => $item->ekspedisi ? $item->ekspedisi->nama_ekspedisi : '-',
                'No Polisi' => $item->nopol_kendaraan,
                'Nama Driver' => $item->nama_driver,
                'No HP Driver' => $item->no_hp_driver ?? '-',
                'Tanggal' => \Carbon\Carbon::parse($item->created_at)->format('Y-m-d'),
                'Jam Keluar' => $item->jam_out,
                'Jam Kembali' => $item->jam_in,
                'Keperluan' => $item->keperluan,
                'Status' => $text
            ];
        });

        return ExcelFacade::download(new class($data) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
            private $data;

            public function __construct($data)
            {
                $this->data = $data;
            }

            public function collection()
            {
                return collect($this->data);
            }

            public function headings(): array
            {
                return [
                    'No Surat', 'Nama Ekspedisi', 'No Polisi', 'Nama Driver', 'No HP Driver', 'Tanggal', 'Jam Keluar', 'Jam Kembali', 'Keperluan', 'Status'
                ];
            }
        }, 'laporan-driver-' . ($type === 'all' ? 'all' : $month . '-' . $year) . '.xlsx');
    }

    /**
     * Export data permohonan driver ke PDF per item
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function exportSinglePDF($id)
    {
        $requestDriver = RequestDriver::with('ekspedisi')->find($id);

        if (!$requestDriver) {
            abort(404, 'Data Permohonan Driver tidak ditemukan.');
        }

        // Format data sesuai kebutuhan PDF
        $data = [
            'no_surat' => $requestDriver->no_surat,
            'nama_ekspedisi' => $requestDriver->ekspedisi->nama_ekspedisi,
            'nopol_kendaraan' => $requestDriver->nopol_kendaraan,
            'nama_driver' => $requestDriver->nama_driver,
            'no_hp_driver' => $requestDriver->no_hp_driver,
            'nama_kernet' => $requestDriver->nama_kernet,
            'no_hp_kernet' => $requestDriver->no_hp_kernet,
            'keperluan' => $requestDriver->keperluan,
            'jam_out' => $requestDriver->jam_out,
            'jam_in' => $requestDriver->jam_in,
            'tanggal' => \Carbon\Carbon::parse($requestDriver->created_at)->format('Y-m-d'), // Menambahkan tanggal pengajuan
        ];

        // Logika untuk status persetujuan yang lebih detail
        $statusAdmin = 'Menunggu';
        if ($requestDriver->acc_admin == 2) {
            $statusAdmin = 'Disetujui';
        } elseif ($requestDriver->acc_admin == 3) {
            $statusAdmin = 'Ditolak';
        }

        $statusHeadUnit = 'Menunggu';
        if ($requestDriver->acc_head_unit == 2) {
            $statusHeadUnit = 'Disetujui';
        } elseif ($requestDriver->acc_head_unit == 3) {
            $statusHeadUnit = 'Ditolak';
        }

        $statusSecurityOut = 'Belum Keluar';
        if ($requestDriver->acc_security_out == 2) {
            $statusSecurityOut = 'Sudah Keluar';
        } elseif ($requestDriver->acc_security_out == 3) {
            $statusSecurityOut = 'Ditolak Keluar';
        }

        $statusSecurityIn = 'Belum Kembali';
        if ($requestDriver->acc_security_in == 2) {
            $statusSecurityIn = 'Sudah Kembali';
        } elseif ($requestDriver->acc_security_in == 3) {
            $statusSecurityIn = 'Ditolak Kembali';
        }

        // Tambahkan status ke data
        $data['status_admin'] = $statusAdmin;
        $data['status_head_unit'] = $statusHeadUnit;
        $data['status_security_out'] = $statusSecurityOut;
        $data['status_security_in'] = $statusSecurityIn;

        $pdf = PdfFacade::loadView('exports.driver-single', compact('data'));
        $pdf->setPaper('A4', 'portrait');
        // Bersihkan karakter "/" dan "\" dari no_surat
        $cleanNoSurat = str_replace(['/', '\\'], '-', $requestDriver->no_surat);
        return $pdf->stream('surat_izin_driver_' . $cleanNoSurat . '.pdf');
    }
}
