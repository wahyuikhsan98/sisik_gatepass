<!DOCTYPE html>
<html lang="id">
<head>
    @include('layout.auth.head')
</head>
<style>
    .panel-bg-auth {
    background: url('/assets/img/gambar gedung bg') no-repeat center center;
    background-size: cover; /* supaya nutup penuh */
}
</style>
<body>
    <!-- Navbar -->
    @include('layout.auth.navbar')

    <!-- Login Section -->
    <section class="min-vh-100 d-flex align-items-center panel-bg-auth py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-6 col-xl-5">
                    <div class="card shadow-lg border-0 rounded-lg">
                        <div class="card-body p-4 p-sm-5">
                            <div class="text-center mb-4">
                                <h1 class="h3 text-primary mb-2">
                                    <img src="{{ asset('assets/img/icon kalbe - Diedit.png') }}" alt="Logo" width="30" height="30" class="bi bi-door-open-fill"> SISIK
                                </h1>
                                <p class="text-muted">Masuk ke aplikasi SISIK Sistem Surat Izin Keluar</p>
                            </div>
                            <form method="POST" action="{{ route('auth.login') }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="email" class="form-label">Alamat Email</label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autofocus>
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-4">
                                    <label for="password" class="form-label">Kata Sandi</label>
                                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                                    @error('password')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <button type="submit" class="btn btn-primary w-100 mb-3">Masuk</button>
                                <div class="text-center">
                                    <p class="mb-0">Butuh akun? Hubungi administrator.</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    @include('layout.auth.footer')

    <!-- Bootstrap Bundle JS -->
    @include('layout.auth.script')
</body>
</html>
