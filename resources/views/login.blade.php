<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#123b35"><title>Masuk · BINSONIC–TERRA</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="login-page">
<section class="login-story" aria-label="Tentang BINSONIC–TERRA">
    <a class="brand" href="/login"><span class="brand-icon"><x-icon name="leaf" /></span><span>BINSONIC<span class="brand-sub">TERRA MONITOR</span></span></a>
    <div class="login-story-content"><span class="story-label">PEMILAHAN CERDAS. PEMANTAUAN TERPADU.</span><h1>Setiap data,<br>selangkah lebih<br><em>bermakna.</em></h1><p>Kenali aktivitas pemilahan dan kondisi sensor melalui satu ruang pemantauan yang terhubung.</p>
    <div class="system-illustration" aria-hidden="true"><div class="illustration-orbit orbit-one"></div><div class="illustration-orbit orbit-two"></div><div class="illustration-core"><x-icon name="leaf" /></div><span class="floating-system system-one"><x-icon name="layers" /><span>BINSONIC<small>Pemilahan</small></span></span><span class="floating-system system-two"><x-icon name="chart" /><span>TERRA<small>Telemetry</small></span></span></div></div>
    <div class="login-story-foot"><span>PROYEK GEMASTIK</span><span>BINSONIC × TERRA</span></div>
</section>
<main class="login-form-side"><div class="login-card"><span class="login-symbol"><x-icon name="shield" /></span><div class="eyebrow">AKSES ADMINISTRATOR</div><h2>Selamat datang kembali.</h2><p class="muted">Masuk untuk melihat aktivitas dan kondisi perangkat Anda.</p>
@if($errors->any())<div role="alert" class="notice danger"><x-icon name="help" /><div>{{ $errors->first() }}</div></div>@endif
<form method="post" action="/login">@csrf<label>Email administrator<input type="email" name="email" value="{{ old('email') }}" placeholder="nama@email.com" required autofocus autocomplete="username"></label><label for="login-password">Kata sandi</label><div class="password-field"><input id="login-password" type="password" name="password" placeholder="Masukkan kata sandi" required autocomplete="current-password"><button type="button" class="password-toggle icon-button" aria-label="Tampilkan kata sandi" aria-pressed="false"><x-icon name="eye" /></button></div><button class="button wide" type="submit">Masuk ke dashboard <x-icon name="arrow" /></button></form>
<div class="login-access-note"><x-icon name="shield" /><span>Ruang ini khusus administrator terdaftar.<br>Hubungi pengelola untuk mendapatkan akses.</span></div></div><div class="login-footer">BINSONIC–TERRA <span>Sensor terhubung. Data terpantau.</span></div></main>
</body></html>
