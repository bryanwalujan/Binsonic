# Pembaruan UI/UX — 3 Oktober 2026

Tampilan dashboard, login, riwayat, detail, telemetry TERRA, dan perangkat diperbarui dengan sidebar hijau gelap, aksen mint/biru, tipografi Plus Jakarta Sans lokal, ikon SVG yang konsisten, serta pesan saat data kosong. Font disajikan oleh aplikasi, tanpa permintaan font ke CDN.

Perbaikan interaksi: navigasi drawer pada ponsel dengan dukungan keyboard/Escape dan pengelolaan fokus, pemilihan sumber data yang mempertahankan filter relevan, tombol refresh dashboard, reset filter, tampil/sembunyikan password, dan tombol salin token dengan pesan kegagalan clipboard.

Verifikasi yang dijalankan:

- Build frontend Vite berhasil.
- Cache view Blade berhasil.
- 21 tes Laravel MySQL lulus, 299 assertion.
- Chromium desktop 1440 px dan ponsel 390 px: login, tampil/sembunyikan password, seluruh halaman, data simulasi yang telah ada, refresh/polling, filter/reset, buka/tutup navigasi termasuk Escape, serta logout berhasil.
- Tidak ditemukan overflow horizontal halaman ponsel atau error JavaScript. Tabel berisi data tetap dapat digeser secara horizontal; tabel kosong menampilkan pesan yang terlihat tanpa harus digeser.
- Desain diperiksa melalui screenshot login, dashboard, riwayat, TERRA, perangkat, dan drawer ponsel dalam direktori ini.

Tidak ada perubahan API, schema database, sensor, atau firmware pada pekerjaan UI ini. Tidak menambahkan data contoh ke database.
