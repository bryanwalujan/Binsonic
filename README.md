# BINSONIC–TERRA Monitor

Proyek yang ada: Laravel12, PHP8.2+, MySQL/MariaDB, Blade, Tailwind4, Vite7 dan Chart.js. Antarmuka Indonesia, waktu tampil WITA. Tidak menyediakan kontrol blower, buzzer atau penyalaan. Sensor berkala BINSONIC terpisah dari kejadian sampah; simulasi terpisah dari data nyata.

- [Deployment cPanel gemastik.unima.space](docs/DEPLOY_CPANEL.md): kebutuhan aktual, upload/vendor, .env, public root, backup, migrasi dan cache.
- [Metode dan parameter tampilan MQ7](docs/MQ7_TREND.md).
- [Kontrak API final + JSON](docs/API_CONTRACT.md).
- [Simulator, curl dan verifikasi hosting](docs/HOSTING_TEST.md).
- [Bridge USB Ubuntu](bridge/README.md) dan [JSON serial untuk firmware](bridge/SERIAL_PROTOCOL.md).
- [Hasil pemeriksaan persiapan hosting](docs/DEPLOYMENT_VERIFICATION.md).

## Menjalankan lokal

Jangan menimpa .env instalasi yang sudah ada. Untuk instalasi **baru**:

```bash
composer install
npm ci
cp .env.example .env
```

Contoh .env default disiapkan untuk production. Untuk localhost ubah `APP_ENV=local`, `APP_URL=http://127.0.0.1:8000`, `SESSION_SECURE_COOKIE=false`, `APP_DEBUG=true` hanya saat pengembangan, lalu isi database MySQL lokal milik aplikasi. Tetap jangan gunakan SQLite untuk database Laravel. Jangan mengganti APP_KEY instalasi lama.

```bash
php artisan key:generate
php artisan migrate
php artisan admin:create
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Buka http://127.0.0.1:8000/login. Login dengan admin database lokal. Tidak ada akun bawaan, registrasi publik atau kredensial yang ikut paket deployment. `admin:create` meminta nama/email/password minimal12 karakter secara interaktif. Semua pengguna aplikasi adalah administrator.

`npm run dev` hanya untuk pengembangan frontend. Hosting memakai hasil `npm run build`; tidak menjalankan server Vite atau `artisan serve`. Foto privat tidak membutuhkan `storage:link`.

## Identitas dan token

```bash
php artisan device:create "BINSONIC Portenta" binsonic
php artisan device:create "TERRA ESP32" terra
php artisan device:list
php artisan device:token ID
php artisan device:token ID --revoke
```

Perangkat SIMULASI hanya untuk lingkungan testing terpisah, dengan opsi `--simulated`; production menyembunyikan data simulasi lama dan menolak kiriman simulasi baru, tanpa menghapus data.

Token acak256-bit hanya diterbitkan di Terminal dan disimpan hash di database, tidak dirender browser. UI tetap dapat mendaftarkan perangkat tanpa token dan mencabut akses. Nama tipe/sumber tidak dapat diubah agar data historis konsisten. Token berbeda untuk setiap perangkat, dan untuk perangkat nyata vs simulator. Jangan merekam keluaran token di log publik.

## Pengujian

Buat database MySQL **terpisah** `binsonic_terra_testing` serta user terbatas ke database itu. Salin `.env.testing.example` ke `.env.testing` dan isi kredensial privat. Pada setup baru:

```bash
php artisan key:generate --env=testing
php artisan migrate --env=testing
php artisan test
python3 -m venv .venv
.venv/bin/pip install -r bridge/requirements.txt -r simulator/requirements.txt
.venv/bin/python -m unittest discover -s bridge/tests -v
.venv/bin/python -m unittest discover -s simulator -v
npm run build
```

Tes PHP memakai transaksi rollback dan guard yang menolak database selain MySQL `binsonic_terra_testing`. Tidak memakai migrate:fresh. Bridge memakai SQLite hanya untuk outbox lokal laptop, sesuai kebutuhan retry USB; database web tetap MySQL. Pengujian software tidak menggantikan uji hardware dan hosting nyata.
# Binsonic
