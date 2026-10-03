# Hasil verifikasi MySQL — 2 Oktober 2026

## Konfigurasi aktif

- Aplikasi: koneksi Laravel `mysql`, database `binsonic_terra`, host `127.0.0.1:3306`.
- Server lokal: MariaDB 10.4.28 bawaan XAMPP, kompatibel dengan driver MySQL.
- Tes: koneksi `mysql`, database khusus `binsonic_terra_testing`. Akun aplikasi dan tes memiliki akses hanya ke database masing-masing.
- SQLite tidak dipakai lagi oleh aplikasi atau tes; berkas/data lama tidak dihapus.
- Password, token, dan APP_KEY tidak ditampilkan dalam laporan. `.env`, `.env.testing`, dan berkas akses lokal bersifat privat.

## Hasil yang dijalankan

1. Koneksi database berhasil diperiksa melalui PDO Laravel.
2. `php artisan migrate --force` dan `php artisan migrate --env=testing --force` berhasil. Migrasi awal terkena masalah default TIMESTAMP MariaDB; diperbaiki menjadi DATETIME(6), dilanjutkan tanpa drop/reset tabel. Pengulangan migrasi menghasilkan `Nothing to migrate` dan data tetap utuh.
3. `php artisan test`: **14 tes lulus, 230 assertion di MySQL**. Tes memakai transaksi dan rollback, bukan `migrate:fresh`. Sesudah tes, keempat tabel domain/database tes tetap kosong.
4. `npm run build`, `php artisan view:cache`, dan `vendor/bin/pint --test` berhasil.
5. Administrator dibuat lewat interaksi nyata dengan `php artisan admin:create`; login melalui browser berhasil.
6. BINSONIC SIMULASI Lokal dan TERRA SIMULASI Lokal dibuat lewat form Perangkat proyek dengan session login dan CSRF.
7. Heartbeat kedua perangkat menghasilkan HTTP 200. Simulator Python mengirim tepat satu kejadian BINSONIC dan satu pembacaan TERRA ke MySQL.
8. BINSONIC tersimpan dengan `weight_calibrated=false`, `weight_g=null`, `loadcell_raw=4724`, `moisture_adc=556`, `wet_contact=false`, dan `metal_detected=false`. TERRA tersimpan dengan `temperature_c=76.82` dan `mq7_adc=223`. Semua berflag `is_simulated=true`.
9. Payload/event_id yang sama dikirim ulang melalui simulator `--replay`, kemudian respons API diverifikasi HTTP 200 dengan ID record yang sama. Jumlah baris tetap **1 BINSONIC + 1 TERRA**, statistik data nyata **0**.
10. Browser Chromium memverifikasi dashboard, grafik, badge SIMULASI pada riwayat kedua jenis perangkat, dan mode data nyata yang tetap kosong. Tidak ada error JavaScript.
11. Semua pengiriman dihentikan tanpa memanipulasi waktu/database. Kedua perangkat awalnya Online dan berubah Offline lewat polling setelah **62 detik**, melewati ambang 60 detik.
12. Simulator diperiksa sintaksnya dan menolak menimpa berkas payload sebelum melakukan request.

## Akses lokal

Server aktif di http://127.0.0.1:8000, hanya bind loopback. Login `admin.local@binsonic.test`; password acak ada di `storage/app/private/local-access.json` (0600, diabaikan Git). Tidak ada password/token dalam dokumen ini.

Untuk menjalankan kembali: `php artisan serve --host=127.0.0.1 --port=8000`. Buat admin tambahan lewat `php artisan admin:create`. Dokumentasi setup dan simulator tersedia di README.

Bukti visual:
- [Dashboard SIMULASI saat online](docs/verification/dashboard-simulasi-online.png)
- [Dashboard SIMULASI setelah offline](docs/verification/dashboard-simulasi-offline.png)
- [Statistik data nyata tetap kosong](docs/verification/dashboard-data-nyata.png)
- [Hasil pemeriksaan terstruktur tanpa kredensial](docs/verification/results.json)

## Batas verifikasi

Server yang diuji adalah MariaDB lokal melalui driver MySQL, bukan instalasi Oracle MySQL terpisah. Perangkat keras dan firmware tidak diubah atau diuji. Uji kiriman serentak berskala besar belum dilakukan. Tidak ada deployment internet.

## Pembaruan integrasi USB

Pengujian bridge dan perluasan API terbaru tercatat di [bridge/VERIFICATION.md](bridge/VERIFICATION.md): 21 tes Laravel MySQL (299 assertion), 23 tes Python termasuk pseudo-terminal USB dan CLI/HTTP, serta build frontend berhasil. Firmware dan perangkat fisik belum diubah/diuji.
