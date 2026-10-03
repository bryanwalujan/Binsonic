# Hasil persiapan production dan tampilan MQ7

Tanggal pemeriksaan: **3 Oktober 2026, WITA**. Ini hasil lokal, bukan bukti deployment atau penerimaan sensor fisik.

## Hasil yang benar-benar dijalankan

| Pemeriksaan | Hasil |
|---|---|
| Koneksi aplikasi | PDO MySQL berhasil, MariaDB lokal10.4.28; rahasia tidak dicetak |
| Migrasi tambahan | Berhasil pada database aplikasi lokal dan database testing, tanpa reset/hapus data |
| Laravel/PHPUnit | **35 tes, 450 assertion lulus**, database MySQL `binsonic_terra_testing`, transaksi rollback |
| Bridge Python | **23 tes lulus**, parsing/null/retry/idempotensi, outbox, status/debug, putus/sambung pseudo-serial dan fakeHTTP lokal |
| Simulator Python | **6 tes lulus**, retry terbatas, payload tetap, 422/409 tidak berulang, pemeriksaan SIMULASI sebelum POST, redaksi token |
| Frontend | `npm run build` berhasil, Vite7.3.6; CSS/JS/font lokal di public/build |
| Browser MQ7 | Desktop1440 dan HP390: kosong, meningkat, jeda; tanpa overflow dokumen/error JS, detail dapat dibuka, polling selesai |
| Composer | validate dan check-platform-reqs --no-dev lulus; audit --no-dev tidak menemukan advisory pada saat pemeriksaan |
| npm | audit --omit=dev tidak menemukan vulnerability pada saat pemeriksaan |
| Paket cPanel dengan vendor | Boot production, login200, config/route/view cache, template index alternatif dan manifest public eksternal lulus; tidak memakai database pada smoke test paket |
| Audit isi ZIP | Tidak ada rahasia lokal/.env runtime/data storage, vendor production tersedia, checksum disediakan |
| Gaya PHP | Pint pada source yang diperiksa lulus |
| Artisan | admin:create, device:create/token/revoke diverifikasi dalam tes; API6 routes; schedule:list tidak memiliki task |

Bukti paket: [cpanel-package.json](verification/cpanel-package.json).

Bukti browser: [results.json](verification/mq7/results.json), [kosong HP](verification/mq7/empty-mobile.png), [meningkat HP](verification/mq7/increasing-mobile.png), [jeda desktop](verification/mq7/gap-desktop.png). Data browser berasal dari fixture dalam **database testing**; hanya record fixture milik pemeriksaan itu yang dibersihkan setelahnya, tanpa menyentuh data operasional.

Pemeriksaan HTTP simulator lokal yang dijalankan sebelum instruksi terbaru pembatasan fixture mencatat: heartbeat kedua perangkat, BINSONIC sensor reading terpisah, satu event null/uncalibrated, TERRA reading, replay201→200, total nyata tetap0, online→offline setelah67detik sambil browser polling. Dua identitas SIMULASI uji dibuat sesuai instruksi sebelumnya; token uji dicabut. Bukti historis: [cpanel-local-http.json](verification/cpanel-local-http.json). Pemeriksaan ini **bukan pengiriman perangkat fisik**. Konfigurasi production akhir menolak kiriman simulator; pengujian fixture selanjutnya hanya di database testing.

## Audit sumber data dan perubahan

Tidak ditemukan generator dummy/mock/timer pembuat data pada aplikasi runtime. Timer JavaScript hanya GET snapshot database setiap5detik. DatabaseSeeder kosong; deployment tidak menjalankan seeder/factory/simulator. Angka acak ada pada simulator yang harus dijalankan eksplisit dan fixtures testing, bukan fallback dashboard. Font, ikon dekoratif dan kategori statis bukan pengukuran sensor.

Audit database lokal setelah perubahan: **4 perangkat SIMULASI, 2 event BINSONIC SIMULASI, 1 reading sensor BINSONIC SIMULASI, 2 reading TERRA SIMULASI; data berflag nyata0**. Semua record operasional yang ditemukan memiliki flag asal eksplisit; tidak ada yang ditebak lalu dihapus. Data simulasi dipertahankan. Production menyembunyikan mode/record/detail/foto SIMULASI dan menolak POST perangkat simulasi403. Database kosong ditampilkan jujur; perangkat tanpa last_seen_at “Belum terhubung”.

Perubahan utama:

- `TelemetryRequest`, `TelemetryController`, `TerraReading`, migration `2026_10_03_000001...` dan `000002...`: reading_id alias kompatibel, basis tegangan, timestamp pengukuran nullable dengan waktu server terpisah.
- `DeviceToken`, `DeviceController`, routes console/web, tampilan perangkat: token hanya di Artisan, hash di database, cabut melalui web, tidak ada token frontend; batas simulasi production.
- `GasResponse`, `MonitorController`, config monitor, komponen gas/halaman TERRA, CSS/JS: tren backend per perangkat, median, jeda, null, detail tegangan, tanpa ppm/gauge keselamatan. [Metode lengkap](MQ7_TREND.md).
- `.env.example`, provider/public path, bootstrap JSON errors, public/.htaccess, `deploy/cpanel/public-index.php`, `scripts/package_cpanel.py`: persiapan HTTPS/cPanel, layout privat, paket tanpa rahasia.
- Tes `DeploymentContractTest`, `GasResponseTest`, perubahan `MonitorTest`/`SerialGatewayTest`, `simulator/test_simulate.py`.
- Dokumentasi [deployment](DEPLOY_CPANEL.md), [kontrak API](API_CONTRACT.md), [uji hosting](HOSTING_TEST.md), dan metode MQ7.

## Batas yang belum diverifikasi

- GET `https://gemastik.unima.space/login` dan `/up` saat pemeriksaan mengembalikan **404**. Tidak ada upload, perubahan akun hosting, atau deployment yang dilakukan.
- Menu SSH Access dilaporkan tersedia; hostname/IP, port, username cPanel dan konfirmasi izin shell belum diberikan/diverifikasi. Versi/ekstensi PHP web, DB hosting, document root aktual, AutoSSL, WAF/Authorization dan permission storage perlu diperiksa di akun.
- Tidak ada uji Portenta/ESP32/USB fisik, kalibrasi loadcell/MQ7, hasil audio/vision, pemanasan MQ7 atau pengukuran emisi. Firmware tidak diubah. Bridge diuji dengan pseudo-terminal, bukan board fisik.
- Foto privat diverifikasi pada feature test; persistensi foto setelah **deployment hosting** masih harus diperiksa. Tidak ada benchmark beban besar.
- Payload dengan `mq7_voltage_basis` null tetap kompatibel tetapi tidak cukup untuk menyebut lokasi tegangan. Timestamp perangkat null tetap tidak diketahui; waktu server bukan bukti kapan sensor mengukur.

Kebutuhan runtime: PHP8.2+ yang lolos composer.lock, MySQL/MariaDB, Apache/LiteSpeed rewrite, HTTPS, storage privat writable. Node hanya saat build; tidak perlu queue worker/cron untuk fitur saat ini. Lihat panduan cPanel untuk perintah lengkap, alternatif public root, APP_KEY dan backup.
