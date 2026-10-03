<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123b35">
    <title>@yield('title','Pemantauan') · BINSONIC–TERRA</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body>
<a class="skip-link" href="#main-content">Langsung ke konten</a>
<div class="shell">
    <button type="button" class="nav-backdrop" tabindex="-1" aria-label="Tutup navigasi" hidden></button>
    <aside class="sidebar" id="main-navigation" aria-label="Navigasi utama">
        <div class="sidebar-brand"><a class="brand" href="/dashboard"><span class="brand-icon"><x-icon name="leaf" /></span><span>BINSONIC<span class="brand-sub">TERRA MONITOR</span></span></a><button class="icon-button nav-close" type="button" aria-label="Tutup navigasi"><x-icon name="close" /></button></div>
        <div class="nav-label">WORKSPACE</div>
        <nav class="primary-nav">
            @foreach(['/dashboard'=>['grid','Ringkasan','Gambaran seluruh sistem'],'/binsonic'=>['layers','Riwayat BINSONIC','Aktivitas pemilahan'],'/terra'=>['chart','Pemantauan TERRA','Suhu & pembacaan sensor'],'/devices'=>['cpu','Perangkat','Koneksi & akses perangkat']] as $url=>$item)
            <a class="{{ request()->is(ltrim($url,'/').'*') ? 'active' : '' }}" @if(request()->is(ltrim($url,'/').'*')) aria-current="page" @endif href="{{ $url }}{{ $url !== '/devices' ? '?mode='.request('mode','real') : '' }}"><x-icon :name="$item[0]" /><span>{{ $item[1] }}<small>{{ $item[2] }}</small></span></a>
            @endforeach
        </nav>
        <div class="sidebar-note"><span class="sidebar-note-icon"><x-icon name="leaf" /></span><strong>Terpantau lebih baik.</strong><p>Pemilahan dan pembacaan sensor, dalam satu ruang.</p><span class="project-tag">PROYEK GEMASTIK</span></div>
        <div class="sidebar-foot"><x-icon name="shield" /><div>Ruang administrator<small>Akses pemantauan terbatas</small></div></div>
    </aside>
    <div class="main" id="app-main">
        <header class="topbar">
            <div class="breadcrumb"><button class="icon-button nav-toggle" type="button" aria-expanded="false" aria-controls="main-navigation" aria-label="Buka navigasi"><x-icon name="menu" /></button><span class="breadcrumb-root">Workspace <span>/</span></span><strong>@yield('title','Pemantauan')</strong></div>
            <div class="topbar-right"><span class="timezone"><x-icon name="clock" /> WITA <span>UTC+8</span></span><details class="account-menu"><summary><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</span><span class="account-name">{{ auth()->user()->name }}<small>Administrator</small></span><span class="account-chevron">⌄</span></summary><div class="account-dropdown"><strong>{{ auth()->user()->name }}</strong><p>{{ auth()->user()->email }}</p><form method="post" action="/logout">@csrf<button type="submit"><x-icon name="logout" /> Keluar dari akun</button></form></div></details></div>
        </header>
        <main id="main-content" tabindex="-1">
            <div class="page-head"><div><div class="eyebrow"><span></span> RUANG PEMANTAUAN</div><h1>@yield('title')</h1><p class="muted">@yield('subtitle')</p></div>
            @if(request()->is('dashboard','binsonic','terra'))
            <div class="mode-control"><span class="control-caption">Sumber data</span><div class="mode-switch" aria-label="Pilih sumber data"><a class="{{ request('mode','real')==='real'?'selected':'' }}" @if(request('mode','real')==='real') aria-current="true" @endif href="{{ request()->fullUrlWithQuery(['mode'=>'real','page'=>null,'device'=>null,'terra_device'=>null]) }}"><span class="mode-dot"></span> Data nyata</a>@unless(app()->environment('production'))<a class="{{ request('mode')==='simulation'?'selected':'' }}" @if(request('mode')==='simulation') aria-current="true" @endif href="{{ request()->fullUrlWithQuery(['mode'=>'simulation','page'=>null,'device'=>null,'terra_device'=>null]) }}">SIMULASI</a>@endunless</div></div>
            @endif</div>
            @if($errors->any())<div class="notice danger" role="alert"><x-icon name="help" /><div>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div></div>@endif
            @if(session('message'))<div class="notice success" role="status"><x-icon name="check" /><div>{{ session('message') }}</div></div>@endif
            @if(request('mode')==='simulation')<div class="notice simulation-notice"><x-icon name="layers" /><div><strong>Anda sedang melihat data simulasi.</strong><span>SIMULASI — seluruh statistik pada tampilan ini hanya berasal dari perangkat simulasi.</span></div></div>@endif
            @yield('content')
            <footer><span><span class="footer-mark">BT</span> BINSONIC–TERRA <span class="footer-divider">/</span> GEMASTIK</span><span>Dirancang untuk pemantauan yang lebih bermakna.</span></footer>
        </main>
    </div>
</div>
@stack('scripts')
</body></html>
