# Bridge USB Portenta → BINSONIC

Program: [`serial_bridge.py`](serial_bridge.py). Kontrak firmware: [`SERIAL_PROTOCOL.md`](SERIAL_PROTOCOL.md). Bridge hanya membaca serial; tidak menulis perintah/kontrol ke Portenta dan tidak mengubah firmware.

Aplikasi dan pengujian Laravel tetap memakai **MySQL**. SQLite di sini hanya **antrean lokal gateway**, sesuai kebutuhan ketahanan pengiriman; bukan database dashboard.

## Ubuntu

Dari root proyek, gunakan Python 3.10+:

```bash
python3 -m venv .venv
.venv/bin/pip install -r bridge/requirements.txt
.venv/bin/python -m serial.tools.list_ports -v
ls -l /dev/serial/by-id/
```

Jika Python/venv belum tersedia, perintah sistem yang dapat Anda jalankan:

```bash
sudo apt install python3 python3-venv python3-pip
```

Jika akses serial ditolak, periksa `ls -l /dev/ttyACM*` dan keanggotaan `groups`. Pada Ubuntu perangkat biasanya dimiliki grup `dialout`:

```bash
sudo usermod -aG dialout "$USER"
```

Keluar lalu login ulang agar keanggotaan berlaku. Jalankan bridge sebagai pengguna biasa. Tutup Serial Monitor/IDE yang sedang memakai port yang sama. Gunakan path stabil `/dev/serial/by-id/...` jika tersedia; sesuaikan `--port` saat nomor `/dev/ttyACM0` berubah.

Migrasi tambahan proyek dan server:

```bash
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Migration baru menambah `binsonic_readings`, menambahkan `gateway_received_at` pada events, dan mengizinkan `captured_at=null` untuk kejadian tanpa jam perangkat. Data lama dipertahankan; tidak menggunakan `migrate:fresh`.

Login dashboard → Perangkat → daftar **BINSONIC / Perangkat nyata** untuk Portenta fisik. Simpan token yang ditampilkan sekali. Jangan gunakan token TERRA. `board_id` di serial harus sama dengan `--board-id`.

```bash
read -rsp 'Token BINSONIC: ' DEVICE_TOKEN; echo
export DEVICE_TOKEN
.venv/bin/python bridge/serial_bridge.py \
  --port /dev/serial/by-id/GANTI_DENGAN_PORT_PORTENTA \
  --baud 115200 \
  --api-url http://127.0.0.1:8000/api/v1 \
  --board-id portenta-01 \
  --queue storage/app/private/portenta-outbox.sqlite
unset DEVICE_TOKEN
```

Token hanya dibaca dari environment; tidak ada opsi nilai token pada CLI. `--token-env PORTENTA_TOKEN` mengubah nama variabel. Port juga dapat melalui `PORTENTA_PORT`, URL melalui `BINSONIC_API_URL`. Baud default 115200 harus sesuai firmware. `--api-url` mencakup `/api/v1`, tanpa query, fragment, atau kredensial di URL. HTTP redirect ditolak; `.netrc`/proxy environment tidak digunakan. Requests melakukan verifikasi TLS standar untuk HTTPS.

Pengujian tanpa perangkat nyata harus memakai perangkat server **SIMULASI** dan flag `--allow-simulated`. Bridge menolak token simulasi secara default. Contoh JSON tidak otomatis dikirim ke perangkat nyata.

Ctrl+C menghentikan bridge dan menutup antrean. Jalankan kembali dengan port, `board-id`, URL, dan berkas antrean yang sama. Rotasi token perangkat yang sama tidak mengubah identitas antrean. Jangan menggunakan antrean board A untuk board B.

## Aliran dan status koneksi

1. Serial dibaca di thread terpisah dari jaringan; request HTTP lambat tidak menghentikan pembacaan serial.
2. Baris JSON valid bertipe `binsonic.reading` atau `binsonic.event` ditulis dan di-commit ke SQLite **sebelum** pengiriman HTTP.
3. `GET /api/v1/device` memeriksa identitas token tanpa heartbeat. Antrean terikat ke URL, `board_id`, dan ID perangkat server. Token tidak disimpan di SQLite.
4. Heartbeat dikirim hanya saat port terbuka dan data/status valid terakhir masih dalam `--fresh-seconds` (default **10 detik**). `debug`, teks biasa, pesan invalid, atau board_id salah tidak menyegarkan koneksi.
5. Interval heartbeat default **15 detik**, lebih kecil dari ambang online server (default 60 detik). Firmware sebaiknya mengirim status atau telemetry tiap 2–5 detik. `status=sensor_error` tetap membuktikan board hidup; itu tidak berarti sensor sehat.
6. Saat USB terputus, freshness segera dibatalkan dan bridge mencoba membuka kembali port dengan jeda 1, 2, 4… maksimal 30 detik. Tersambung kembali belum cukup: harus ada data/status baru. Port yang terbuka tetapi diam melewati 10 detik juga menghentikan heartbeat.
7. Antrean lama tetap boleh dikirim ketika board offline. Semua kiriman bridge menyertakan `gateway_received_at`, sehingga server **tidak memperbarui `last_seen_at` dari antrean tersebut**. Hanya heartbeat segar yang memperbarui status bridge. Upload foto kejadian gateway juga tidak menyegarkan koneksi.
8. Dashboard menjadi offline setelah ambang server berakhir sejak heartbeat terakhir, ditambah jeda polling hingga 5 detik. Satu request heartbeat yang sudah telanjur berjalan saat kabel dicabut dapat selesai; tidak ada heartbeat lama yang disimpan untuk dikirim ulang.

## Retry dan antrean

- Kedua jenis data memiliki `event_id` **dari firmware**. Bridge tidak menciptakan event pemilahan otomatis.
- `event_id`, payload, dan `gateway_received_at` pertama tetap sama pada retry/restart. Duplikat serial identik memakai record antrean lama. Duplikat ID dengan payload berbeda dilaporkan dan tidak menimpa record pertama.
- Gangguan jaringan, HTTP 408/425/429/5xx: retry 2, 4, 8… maksimal 300 detik. `Retry-After` numerik dipatuhi sampai 24 jam. Maksimum 1 kiriman data/detik, ditambah heartbeat, di bawah batas API 120 request/menit/token. Gunakan satu bridge per perangkat; terlalu banyak perangkat dari IP sama dapat terkena batas IP server 300/menit.
- HTTP 200/201 baru dianggap berhasil jika respons memuat ID record dan event_id yang sesuai.
- HTTP **422** dan **409** masuk status **dead** (karantina), dilaporkan dengan nomor antrean dan kode HTTP, **tidak diulang otomatis**. HTTP permanen lainnya juga dikarantina. Respons/raw serial tidak dicetak agar token dan isi debug tidak bocor lewat log.
- HTTP 401/403 menghentikan bridge; antrean tetap pending. Perbaiki token/jenis perangkat lalu restart.
- SQLite memakai WAL + synchronous FULL, berkas privat, dan lock satu proses. Payload/status `sent` dipertahankan untuk mencegah duplikat saat restart. Tidak ada pembersihan otomatis: pantau ukuran disk, terutama untuk telemetry terus-menerus.
- Jika penyimpanan gagal, bridge berhenti dan melaporkan kegagalan. Firmware belum memakai protokol ACK; jaminan antrean mulai berlaku setelah commit laptop, bukan untuk byte yang hilang sebelum terbaca/tersimpan (misalnya mati listrik saat penerimaan). Kehilangan data saat board mati/USB tercabut sebelum penerimaan tidak dapat dipulihkan oleh laptop.

Periksa antrean tanpa token (stdlib Python, tidak memerlukan sqlite CLI):

```bash
.venv/bin/python - <<'PY'
import sqlite3
con = sqlite3.connect('file:storage/app/private/portenta-outbox.sqlite?mode=ro', uri=True)
for row in con.execute('SELECT id,endpoint,event_id,state,attempts,error_code FROM outbox ORDER BY id'):
    print(row)
PY
```

Untuk 422, bandingkan payload record karantina dengan kontrak. Perbaikan payload harus dikirim firmware sebagai ID pesan baru. Jangan mengubah isi pesan dengan ID yang mungkin sudah tersimpan di server. Jika masalah murni konfigurasi server telah diperbaiki dan payload memang tetap identik, operator dapat menjadwalkan ulang record `dead` tertentu secara manual setelah pemeriksaan; jangan mengaktifkan retry tanpa batas untuk semua record gagal. Cadangkan antrean menggunakan SQLite backup API atau saat bridge berhenti; jangan menyalin hanya berkas utama saat WAL aktif. Menghapus receipt `sent` menghilangkan deduplikasi lokal dan waktu gateway pertama—jangan menghapusnya sembarangan.

## Pengujian

```bash
.venv/bin/python -m unittest discover -s bridge/tests -v
php artisan migrate --env=testing
php artisan test
npm run build
```

Tes Python mencakup parsing, nilai null/false/0, fragmentasi serial, retry identik, restart antrean, 422/409, pembatasan heartbeat, lock/identitas antrean, putus-sambung pySerial dengan pseudo-terminal, serta CLI nyata ke server HTTP lokal yang mensimulasikan respons gagal setelah commit. Tes Laravel berjalan pada database MySQL khusus dengan transaksi, termasuk idempotensi, timestamp gateway, pembatasan token, pemisahan telemetry dari statistik kejadian, dan status offline saat backlog dikirim.

Referensi implementasi: [API pySerial](https://pyserial.readthedocs.io/en/latest/pyserial_api.html) dan [Requests: timeout/redirect](https://requests.readthedocs.io/en/latest/user/quickstart/).
