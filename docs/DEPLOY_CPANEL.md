# Deployment cPanel — gemastik.unima.space

Target: **https://gemastik.unima.space**. Menu SSH Access tersedia, tetapi koneksi dan izin shell **belum diverifikasi**. Document root dilaporkan dapat diubah. Panduan utama memakai Terminal/SSH setelah akses berhasil diverifikasi. Pengerjaan ini menyiapkan kode/paket; **belum melakukan upload, konfigurasi akun, atau deployment**. Hostname/IP SSH, port, username cPanel, versi PHP web dan kapasitas hosting belum tersedia untuk diverifikasi. Terminal cPanel cukup untuk langkah di bawah; jangan mengirim kredensial ke repository.

## Kebutuhan aktual

- Laravel **12.69.3** dari composer.lock, PHP **≥8.2**. Pilih PHP yang masih didukung provider, lalu jalankan pemeriksaan platform; CLI dan web harus memakai versi/ekstensi yang sama. Lokal diuji PHP 8.2.4, MariaDB 10.4.28; itu bukti kompatibilitas lokal, bukan rekomendasi memasang versi lama di server.
- Ekstensi Laravel: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML; aplikasi membutuhkan **pdo_mysql**, dan **GD** untuk pemrosesan/pemeriksaan gambar yang diuji. Zip/unzip membantu Composer. Set upload_max_filesize ≥5M dan post_max_size ≥8M.
- Database **MySQL atau MariaDB** dari cPanel, UTF8MB4, InnoDB, DATETIME(6), unique index dan transaksi. Tes dilakukan terhadap MariaDB lokal; jalankan migration di hosting untuk memverifikasi versi/izin sebenarnya. Buat database dan user khusus, beri izin ke database itu saja (termasuk ALTER/CREATE untuk migrasi). cPanel umumnya menambahkan prefix akun; isi nama lengkap yang muncul di panel.
- Apache/LiteSpeed dengan rewrite dan `.htaccess` aktif; SSL valid (AutoSSL) dan Force HTTPS Redirect aktif. PHP-FPM/shared cPanel dapat menjalankan implementasi saat ini. Beban belum diuji untuk jumlah perangkat besar; ukur kapasitas database/PHP dan retensi data sebelum menaikkan laju kiriman.
- Frontend Vite **7.3.6**, Tailwind **4.3.3**, Chart.js **4.5.1**, font lokal. Build di laptop dengan Node **22.12+** (lokal22.23.1; Vite juga menerima20.19+), `npm ci && npm run build`. Node/npm tidak diperlukan saat runtime hosting.
- Composer2 diperlukan di server **atau** gunakan paket dengan vendor dari laptop. Jangan menjalankan `composer update` di production.
- **Tidak ada job aplikasi asinkron atau task scheduler yang dijadwalkan.** Production `QUEUE_CONNECTION=sync`; tidak perlu queue worker, Supervisor, WebSocket, Redis atau cron `schedule:run`. Script pengembangan bawaan Composer menyertakan queue listener, tetapi bukan kebutuhan runtime fitur ini. Polling dashboard berjalan di browser, online/offline dihitung saat membaca timestamp. Database session/cache dipakai; tabel jobs bawaan masih ada namun tidak digunakan fitur aplikasi.
- Foto privat di `storage/app/private/events`, bukan `public/storage`. Tidak perlu symlink. Backup database + folder foto + `.env`/APP_KEY secara privat; jangan menaruh backup ZIP/SQL di document root.

Persyaratan public root, izin tulis dan cache mengikuti [deployment Laravel12](https://laravel.com/framework/docs/12.x/deployment). Lokasi pengaturan domain mengikuti [Manage the Domain cPanel](https://docs.cpanel.net/cpanel/domains/domains/manage-the-domain/); pembatasan lokasi root tetap bergantung provider.

## 1. Siapkan paket di laptop

```bash
composer install
composer validate --no-check-publish
composer check-platform-reqs --no-dev
php artisan test
npm ci
npm run build
python3 scripts/package_cpanel.py
# Jika hosting tidak menyediakan Composer:
python3 scripts/package_cpanel.py --with-vendor
```

Tes PHP memerlukan MySQL **khusus tes** `binsonic_terra_testing`, `.env.testing` lokal, dan migrasi tes sudah dijalankan. Jangan arahkan tes ke database produksi. Panduan setup tes ada di README.

Hasil di `dist/binsonic-terra-cpanel.zip` atau `dist/binsonic-terra-cpanel-with-vendor.zip`, serta checksum `.sha256`. Paket vendor menginstal dependency terkunci `--no-dev` di staging terpisah; vendor pengembangan workspace tidak dihapus. Build frontend sudah termasuk. Verifikasi checksum setelah transfer.

Isi upload: `app/`, `bootstrap/` (cache kosong), `config/`, `database/migrations,seeders,factories/`, `resources/`, `routes/`, `public/` termasuk `.htaccess` dan `build/`, `storage/` kosong dengan direktori kerja, `artisan`, `composer.json`, `composer.lock`, `.env.example`, README, docs/deploy dan sumber bridge/simulator yang tidak dijalankan otomatis; `vendor/` hanya pada paket vendor.

**Tidak ikut upload**: `.env`/`.env.testing` laptop, private credentials/token, data/foto/log/session/cache lokal, database SQLite/SQL, node_modules, .venv, public/hot, .git, test fixtures. Simulator dan bridge dijalankan dari laptop, bukan daemon hosting. Pada update, jangan menimpa/menghapus `.env`, foto, atau storage production dengan folder kosong paket.

## 2. Susunan direktori utama

Ganti `CPANEL_USER` dengan nama akun. Upload dan extract ZIP melalui File Manager/SCP **di luar public_html**, ke:

```text
/home/CPANEL_USER/apps/binsonic-terra/
  .env                  privat
  app/ bootstrap/ config/ database/ resources/ routes/ vendor/ storage/
  artisan
  public/               document root gemastik.unima.space
    index.php .htaccess build/ favicon.ico robots.txt
```

ZIP memiliki satu folder atas `binsonic-terra`; hindari folder bersarang dua kali. Set document root domain ke `/home/CPANEL_USER/apps/binsonic-terra/public` di Domains. Aktifkan sertifikat dan Force HTTPS Redirect. Jangan memindahkan index.php ke root aplikasi atau membuka root aplikasi sebagai document root. Jangan letakkan ZIP upload dalam public. Jika provider hanya mengizinkan root di bawah public_html, gunakan susunan alternatif di akhir panduan.

Pilih PHP domain melalui MultiPHP Manager. Di Terminal periksa `php -v` dan `php -m`; bila PHP default akun berbeda, gunakan executable PHP versi pilihan provider untuk **semua** perintah Composer/Artisan. Path executable cPanel berbeda tiap host; tanyakan provider atau lihat Terminal, jangan menebaknya.

## 3. Instalasi dan environment production

```bash
cd /home/CPANEL_USER/apps/binsonic-terra
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
# Pada paket dengan vendor: lewati install; tetap verifikasi platform jika Composer tersedia.
composer check-platform-reqs --no-dev
# HANYA instalasi baru, ketika .env belum ada:
cp .env.example .env
chmod 600 .env
```

Edit `.env` privat di File Manager/Terminal. `.env.example` sudah berisi:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gemastik.unima.space
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANEL_USER_binsonic_terra
DB_USERNAME=CPANEL_USER_monitor
DB_PASSWORD="ISI_DI_SERVER"
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
CACHE_STORE=database
CACHE_PREFIX=binsonic_terra_
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
LOG_LEVEL=warning
DEVICE_ONLINE_SECONDS=60
SENSOR_STALE_SECONDS=120
```

Isi DB_HOST sesuai panel, bukan asumsi laptop. Kutip password yang mengandung spasi/#. Jangan memasukkan token ke `VITE_*`: semua `VITE_*` berpotensi dibundel ke frontend. Token bukan konfigurasi frontend; hash ada di tabel devices. Jangan mengunggah `.env` lokal atau password admin lokal yang lemah sebagai default production. Pakai `admin:create` dengan password kuat untuk instalasi baru.

```bash
# HANYA untuk instalasi BARU dengan APP_KEY kosong:
php artisan key:generate --force
```

**Jangan mengganti APP_KEY instalasi yang sudah menyimpan data terenkripsi/session.** Jika memindahkan instalasi lama, pindahkan key yang sama secara privat. `.env` harus tersedia bagi proses PHP akun, tetapi tidak pengguna lain. App memaksa URL HTTPS saat APP_ENV=production; Force HTTPS Redirect di cPanel tetap diperlukan untuk request masuk. Bila memakai reverse proxy, konfigurasi trusted proxy sesuai alamat provider setelah diverifikasi; jangan mempercayai semua header proxy tanpa kebutuhan.

## 4. Izin, migrasi dan cache

Pemilik file harus akun cPanel/proses PHP yang benar. Normalnya direktori755/file644 cukup, dengan storage dan bootstrap/cache writable oleh proses PHP akun. Hindari chmod777. Folder storage privat dapat700 jika PHP berjalan sebagai akun yang sama. Perbaiki ownership melalui provider jika berbeda; chmod saja tidak memperbaiki owner yang salah.

```bash
chmod -R u+rwX storage bootstrap/cache
php artisan config:clear
# Untuk UPDATE instalasi aktif: backup database+foto+key, lalu php artisan down.
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate:status
php artisan schedule:list
# Jika sebelumnya menjalankan down:
php artisan up
```

`migrate --force` menjalankan migration tertunda tanpa menjatuhkan tabel. Dua migration terbaru menambah kolom nullable definisi tegangan MQ7 dan mengizinkan captured_at TERRA null. Data yang ada tidak dihapus. **Jangan `migrate:fresh`, `migrate:refresh`, `db:wipe`, atau rollback massal pada data production.** Migration pertama membuat tabel monitor; berikutnya menambah telemetry sensor/gateway. Backup tetap dilakukan sebelum perubahan skema. Hindari mengunggah bootstrap/cache dari laptop; cache harus dibuat ulang di server dengan .env/path server. Jika deploy gagal, pertahankan backup dan perbaiki penyebab; jangan menghapus database.

Instalasi baru:

```bash
php artisan admin:create
php artisan device:create "BINSONIC Portenta 01" binsonic
php artisan device:create "TERRA ESP32 01" terra
php artisan device:list
```

Masing-masing perintah create menampilkan token sekali **di Terminal**. Simpan secara privat ke perangkat atau environment laptop; jangan tangkap output token ke log/rekaman publik. Kedua identitas memiliki token berbeda. Production menolak kiriman perangkat SIMULASI; jangan membuat fixture pengujian di database hosting. Untuk perangkat yang dibuat dari web, jalankan `php artisan device:token ID`. Rotasi token yang aktif meminta konfirmasi dan membatalkan token lama; `php artisan device:token ID --revoke` mencabutnya. Jangan menjalankan create lagi saat update jika identitas sudah ada.

Mode production menyembunyikan SIMULASI dan menolak penulisan API dari token simulasi; data lama tidak dihapus. Simulator tidak dijalankan saat deploy. Tidak menjalankan demo seeder/factory.

Login di `https://gemastik.unima.space/login` memakai akun yang dibuat pada **database hosting**, bukan otomatis akun laptop. Lanjutkan [uji hosting dan simulator](HOSTING_TEST.md).

## Alternatif: document root tidak dapat diarahkan ke folder public aplikasi

Walaupun akun ini mengizinkan perubahan root, gunakan pola berikut bila provider membatasi lokasinya:

```text
/home/CPANEL_USER/apps/binsonic-terra/       aplikasi, .env, vendor, storage privat
/home/CPANEL_USER/public_html/gemastik/     root aktual domain, HANYA isi public/
```

1. Copy **isi** public Laravel (termasuk `.htaccess`, `build/`) ke root aktual domain.
2. Copy `deploy/cpanel/public-index.php` ke root aktual sebagai `index.php`, lalu ubah `$appRoot` menjadi path privat sebenarnya. Template diperiksa dari index Laravel12 proyek: maintenance → vendor/autoload.php → bootstrap/app.php → `handleRequest(Request::capture())`; tidak memakai HttpKernel versi lama.
3. Isi `APP_PUBLIC_PATH=/home/CPANEL_USER/public_html/gemastik` di .env privat. Provider aplikasi menerapkan path ini agar CLI cache/view dan manifest Vite menunjuk public sebenarnya; index juga memanggil `usePublicPath(__DIR__)`.
4. Jalankan ulang config/route/view cache dari root privat. Semua build frontend berikutnya harus disalin ke public root yang sama.
5. Akses `/.env`, `/composer.json`, `/vendor/autoload.php`, `/storage/logs/laravel.log` harus 403/404 dan tidak mengembalikan isi file.

Jika hosting juga **tanpa Composer**, gunakan ZIP with-vendor yang dibangun dengan PHP kompatibel target. Jika **tanpa Terminal/SSH**, minta provider menjalankan langkah privat key/migrate/cache/admin; gunakan jalur ini jika izin shell akun ternyata belum aktif. Jangan membuat endpoint web Artisan untuk mengatasinya. Tidak ada endpoint seperti itu dalam proyek ini.

## Verifikasi SSH sebelum deployment

Menu SSH Access dapat hanya menyediakan manajemen key, bukan izin shell aktif. Informasi yang masih diperlukan: hostname/IP server, portSSH, username cPanel, serta konfirmasi shell diaktifkan provider. Dari laptop gunakan `ssh -p PORT CPANEL_USER@HOST` dengan key pribadi lokal; jangan menaruh key/password dalam proyek. Pastikan hostname/fingerprint sesuai informasi provider. Setelah terhubung jalankan `pwd`, `php -v`, `php -m`, `composer --version`, dan periksa path home/domain. Semua perintah upload/edit hanya untuk aplikasi dan document root gemastik.unima.space; jangan mengubah website lain pada akun. Belum ada koneksi SSH yang diuji dalam pengerjaan ini.

Parameter tambahan tampilan MQ7 ada di [.env.example](../.env.example) dan [metode MQ7](MQ7_TREND.md). Parameter tersebut tidak merepresentasikan ambang kesehatan/keselamatan. Tidak perlu menambah cron untuk perhitungan tren: dihitung read-only saat snapshot diminta.
