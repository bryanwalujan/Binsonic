# Verifikasi hosting dan pengujian terpisah

Instruksi terbaru: **jangan memasukkan fixture/simulasi ke database production**. APP_ENV=production menyembunyikan record simulasi lama tanpa menghapusnya dan menolak POST dari token SIMULASI (403). Simulator tetap tersedia untuk database testing/staging terpisah; tidak berjalan otomatis. Contoh JSON dokumentasi bukan data yang disemai saat deploy.

## Pemeriksaan production tanpa data buatan

Setelah [deployment](DEPLOY_CPANEL.md):

```bash
export IOT_BASE_URL=https://gemastik.unima.space
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/up"
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/login"
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/.env"
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/vendor/autoload.php"
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/composer.json"
curl --max-time 15 -sS -o /dev/null -w '%{http_code}\n' "$IOT_BASE_URL/storage/logs/laravel.log"
```

`/up` dan `/login`200, path privat403/404. `/up` hanya framework boot; login/dashboard juga menguji koneksi database. Login dengan administrator **database hosting**. Dashboard kosong harus menampilkan jumlah0, pengukuran tidak tersedia, grafik/tabel belum ada data. Perangkat tanpa komunikasi: Belum terhubung. Simulasi tidak muncul walau URL memakai mode=simulation. CSS/JS harus200, tidak mixed content, HTTP dialihkan ke HTTPS. Jangan pakai `curl -k` atau APP_DEBUG=true untuk menutupi kegagalan.

Jika404 periksa root/rewrite; jika500 baca log privat dan izin storage; jika419 periksa APP_KEY/cookie/session/HTTPS. Jika401 dengan token benar, periksa Authorization diteruskan Apache/WAF. Tidak ada endpoint publik untuk Artisan.

Buat identitas BINSONIC/TERRA nyata dengan Artisan. Dari laptop atau perangkat, GET identitas tanpa membuat online:

```bash
read -rsp 'Token BINSONIC nyata: ' BINSONIC_TOKEN; echo
export BINSONIC_TOKEN
read -rsp 'Token TERRA nyata: ' TERRA_TOKEN; echo
export TERRA_TOKEN
curl --max-time 15 -sS -w '\nHTTP %{http_code}\n' "$IOT_BASE_URL/api/v1/device" \
  -H "Authorization: Bearer $BINSONIC_TOKEN" -H 'Accept: application/json'
curl --max-time 15 -sS -w '\nHTTP %{http_code}\n' "$IOT_BASE_URL/api/v1/device" \
  -H "Authorization: Bearer $TERRA_TOKEN" -H 'Accept: application/json'
```

Verifikasi device_id berbeda, type cocok, is_simulated=false. Jangan menjalankan `set -x`, `curl -v`, atau mencetak environment token. Jangan memakai token nyata untuk simulator. Heartbeat nyata hanya dikirim ketika board benar-benar aktif; bridge Portenta memastikan serial segar sebelum mengirimnya. Tidak ada hardware yang otomatis diklaim terhubung oleh browser.

Saat **data perangkat nyata tersedia**, simpan payload aktual privat untuk inspeksi/retry bila diperlukan. Contoh pengiriman berikut memakai file payload perangkat aktual, bukan JSON contoh yang diisi otomatis:

```bash
curl --max-time 15 -sS -w '\nHTTP %{http_code}\n' "$IOT_BASE_URL/api/v1/binsonic/readings" \
  -H "Authorization: Bearer $BINSONIC_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @/path/privat/pembacaan-portenta-aktual.json
# Kejadian sampah hanya bila pemilahan benar-benar terjadi:
curl --max-time 15 -sS -w '\nHTTP %{http_code}\n' "$IOT_BASE_URL/api/v1/binsonic/events" \
  -H "Authorization: Bearer $BINSONIC_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @/path/privat/kejadian-portenta-aktual.json
curl --max-time 15 -sS -w '\nHTTP %{http_code}\n' "$IOT_BASE_URL/api/v1/terra/readings" \
  -H "Authorization: Bearer $TERRA_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @/path/privat/pembacaan-terra-aktual.json
```

Kontrak dan contoh struktur lengkap: [API_CONTRACT.md](API_CONTRACT.md), [BINSONIC event](examples/binsonic-event.json), [BINSONIC reading](examples/binsonic-reading.json), [TERRA reading](examples/terra-reading.json). Firmware wajib memakai ID unik stabil untuk retry; waktu perangkat boleh null jika belum tersinkronisasi. Tidak mengarang pembacaan/waktu/klasifikasi yang belum tersedia.

Respons baru201; replay **ID dan payload aktual yang sama**200, ID database/received_at tetap. Periksa phpMyAdmin secara read-only atau UI riwayat: device_id benar, null tetap null, jumlah baris tidak bertambah saat retry. Heartbeat/telemetry BINSONIC bukan event sampah. Uji422/conflict/tokenwrong/future timestamps secara lengkap di database testing di bawah, bukan mengisi database production.

Buka dashboard TERRA, pilih perangkat, periksa waktu/status respons gas dan Detail sensor. Sebelum cukup sampel, tren belum tersedia adalah perilaku benar. Biarkan board berhenti heartbeat/data, tunggu lebih dari DEVICE_ONLINE_SECONDS (default60), dashboard tetap boleh terbuka: status harus Offline. Data lama tetap tersimpan dan diberi waktu/penanda kedaluwarsa. Foto aktual jika tersedia dikirim multipart field image melalui endpoint event pemiliknya, lalu hanya bisa dibuka setelah login. Backup dan uji kembali akses foto setelah update.

```bash
unset BINSONIC_TOKEN TERRA_TOKEN DEVICE_TOKEN
```

## Simulator dan fixtures: hanya database testing terpisah

Gunakan database `binsonic_terra_testing`, user MySQL terbatas ke database itu, dan .env.testing privat. Jangan memakai database operasional/production. Environment production memang menolak kiriman simulator; jangan menonaktifkan pengamanan itu pada situs publik untuk menjalankan uji.

```bash
# Terminal lokal A, dari root proyek; database testing sudah disiapkan
php artisan migrate --env=testing
php artisan device:create "BINSONIC uji" binsonic --simulated --env=testing
php artisan device:create "TERRA uji" terra --simulated --env=testing
php artisan serve --env=testing --host=127.0.0.1 --port=8001

# Terminal lokal B
python3 -m venv .venv
.venv/bin/pip install -r simulator/requirements.txt
export IOT_BASE_URL=http://127.0.0.1:8001
read -rsp 'Token BINSONIC testing: ' BINSONIC_TOKEN; echo
export BINSONIC_TOKEN
read -rsp 'Token TERRA testing: ' TERRA_TOKEN; echo
export TERRA_TOKEN
.venv/bin/python simulator/simulate.py --type binsonic --count 2 --interval 5
.venv/bin/python simulator/simulate.py --type binsonic --kind event --untrained --save-payload /tmp/binsonic-test-event.json
.venv/bin/python simulator/simulate.py --type binsonic --kind event --replay /tmp/binsonic-test-event.json
.venv/bin/python simulator/simulate.py --type terra --save-payload /tmp/terra-test-reading.json
.venv/bin/python simulator/simulate.py --type terra --replay /tmp/terra-test-reading.json
```

Gunakan nama file baru jika sudah ada. Default base URL simulator tetap domain target sesuai permintaan awal; **override wajib ke lingkungan testing** untuk uji sintetis. Token dibaca hanya environment, bukan argumen/source/log. Simulator GET identity dulu, memastikan SIMULASI dan jenis benar sebelum POST. Maksimum3 percobaan, timeout koneksi5/baca10detik, jeda1 dan2detik untuk jaringan/408/429/5xx. 422/409/401/403 langsung dilaporkan; redirect tidak diikuti. HTTP status dan JSON respons tersaring dicetak. Retry mempertahankan payload/ID/waktu; save dilakukan sebelum kiriman pertama. Default BINSONIC reading, event hanya dipilih eksplisit. Selesai count berarti heartbeat berhenti.

Fixture CLI manual tersimpan di database testing; jangan menjalankan suite PHPUnit yang mengasumsikan database testing kosong secara bersamaan dengan fixture manual. Untuk pemeriksaan otomatis bersih, jalankan suite sebelum sesi simulator manual, atau siapkan database testing khusus ulang melalui prosedur terkontrol tanpa menyentuh database operasional. Tes otomatis menggunakan transaksi rollback, tidak migrate:fresh:

```bash
php artisan test
.venv/bin/python -m unittest discover -s simulator -v
.venv/bin/python -m unittest discover -s bridge/tests -v
```

Tes mencakup autentikasi/isolasi/duplikasi, data kosong, nullable, foto privat, offline, dan seluruh keadaan tren MQ7. Uji browser memakai fixture hanya di database testing dan membersihkan hanya fixture miliknya. Catat hasil nyata hosting setelah tersedia; pengujian software lokal tidak membuktikan penerimaan Portenta/ESP32 fisik atau keberhasilan hosting.
