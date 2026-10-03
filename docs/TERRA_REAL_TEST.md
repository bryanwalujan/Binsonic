# Pengiriman pertama TERRA nyata — gemastik.unima.space

Diperiksa dari source lokal pada 4 Oktober 2026 (WITA): routes/api.php, TelemetryRequest, DeviceToken, TelemetryController, TerraReading, routes/console.php dan bootstrap/app.php. Tidak ada request dikirim ke hosting pada pemeriksaan ini. Versi kode/migrasi hosting belum dibandingkan dengan source lokal. Tidak membuat terra_reading.json karena belum ada hasil pengukuran ESP32 nyata.

## Endpoint dan identitas

POST `https://gemastik.unima.space/api/v1/terra/readings`

Header wajib untuk pengujian:

```text
Authorization: Bearer <nilai environment TERRA_TOKEN>
Content-Type: application/json
Accept: application/json
```

Token adalah milik satu perangkat jenis terra dengan sumber NYATA. Identitas database ditentukan server dari token. Jangan mengirim device_id, board_id, nama perangkat, is_simulated, received_at, gateway_received_at, image_path atau image_url dalam body TERRA. Key tambahan ditolak. Production menolak token simulasi untuk POST.

Di Terminal/SSH **hosting**, dari root Laravel dan memakai database hosting:

```bash
php artisan device:list
# Hanya jika perangkat TERRA nyata belum terdaftar:
php artisan device:create "TERRA ESP32 01" terra
# Jika perangkat sudah ada tetapi token hilang/belum diterbitkan:
php artisan device:token ID_PERANGKAT
```

Jangan memakai --simulated. Create/token menampilkan token hanya di terminal privat; simpan langsung pada konfigurasi perangkat/environment laptop. Jangan mengirimkan token ke chat, repository atau log. Backend menyimpan hash, sehingga token lama tidak dapat dibaca kembali. Rotasi membatalkan token lama setelah konfirmasi; jangan merotasi jika perangkat sedang memakai token lama tanpa rencana memperbaruinya. Halaman /devices menampilkan ID/status akses, bukan nilai token.

## Body persis sesuai kontrak

Satu objek JSON, bukan envelope serial atau array kumpulan pembacaan. Kirim tipe JSON asli; sensor opsional yang dihilangkan dinormalisasi menjadi null.

| Field | Kehadiran dan tipe | Batas/makna |
|---|---|---|
| reading_id | Wajib string pada format baru; tidak null | 1–100 karakter A–Z/a–z/0–9/_/-; unik per perangkat dan pembacaan |
| captured_at | Wajib hadir; string atau null | ISO8601 dengan Z/offset, pecahan detik maksimal6 digit; null jika jam ESP32 belum sinkron |
| sensor_status | Wajib hadir; objek atau null | Gunakan thermocouple dan mq7 untuk TERRA; aturan key/nilai di bawah |
| temperature_c | Opsional; number atau null | 0–1024, derajat Celsius |
| thermocouple_ok | Opsional; boolean atau null | true hanya jika firmware benar-benar memeriksa keberhasilan sensor; false jika gagal; null jika tidak diketahui |
| mq7_adc | Opsional; integer atau null | 0–65535; harus <2^adc_resolution_bits jika bit diberikan |
| adc_resolution_bits | Opsional; integer atau null | 1–16; hanya isi resolusi yang benar-benar dipakai firmware |
| mq7_voltage | Opsional; number atau null | 0–5.5 volt; hanya hasil pengukuran/perhitungan firmware yang diketahui |
| mq7_voltage_basis | Opsional; string atau null | adc_pin atau sensor_output; jika basis non-null, voltage wajib non-null |

Format lama `event_id` diterima sebagai pengganti reading_id. Minimal salah satu wajib ada; jika keduanya dikirim, harus sama. Gunakan reading_id saja untuk firmware baru. Error ID wajib bisa memakai key event_id karena backend memetakan alias ke kolom lama. Pembacaan baru = ID baru; retry pembacaan yang sama = ID dan seluruh payload tetap.

sensor_status menerima key audio, vision, loadcell, moisture, metal, thermocouple, mq7; untuk TERRA cukup dua sensor relevan. Nilai setiap key: ok, error, unavailable, uncalibrated atau null. Tidak tersedia bukan nol; ADC saja tidak membuktikan sensor sehat. Jangan menulis ok berdasarkan asumsi pemanasan selesai. Backend Laravel juga menerima representasi boolean0/1 dan beberapa string numerik, tetapi kontrak firmware yang dianjurkan memakai JSON true/false dan angka tanpa tanda kutip.

Basis tegangan:

- adc_pin: tegangan di pin ADC ESP32 setelah pembagi resistor (jika ada).
- sensor_output: estimasi tegangan AO sebelum pembagi resistor, sudah dikoreksi firmware. Jangan dikalikan lagi.
- null: basis belum diketahui; jika tegangan juga belum tersedia gunakan keduanya null. Backend tidak mengonversi ADC ke volt/ppm.

## Mengisi terra_reading.json dari Serial Monitor

1. Ambil **satu pembacaan nyata yang koheren** dari ESP32: suhu MAX6675, ADC MQ7, status sensor dan metadata firmware dari siklus yang sama. Jangan mencampur suhu siklus lama dengan ADC siklus baru tanpa informasi waktunya.
2. Salin suhu numerik ke temperature_c tanpa unit/string. Sensor gagal → suhu null, thermocouple_ok=false bila kegagalan diketahui. Jika tidak ada pemeriksaan status, thermocouple_ok=null; jangan menyimpulkan true sendiri. Gunakan JSON null, bukan NaN/Infinity/teks error.
3. Salin ADC mentah ke mq7_adc. Jangan mengasumsikan resolusi12bit dari nama ESP32: gunakan konfigurasi firmware, atau adc_resolution_bits=null.
4. Isi voltage dan basis hanya jika firmware benar-benar mengukur/menghitungnya dan definisinya diketahui. Tidak ada data tegangan → null; jangan hitung sendiri ADC/4095×3.3.
5. Gunakan reading_id dari firmware bila ada. Jika firmware belum membuat ID, laptop boleh menghasilkan UUID **sekali untuk pembacaan nyata yang sudah diambil**, misalnya `python3 -c 'import uuid; print(uuid.uuid4())'`. Simpan UUID itu bersama file; jangan membuat UUID baru pada retry. UUID ini identitas pesan, bukan identitas perangkat.
6. Jika jam ESP32 belum sinkron, captured_at=null. Jangan mengisi waktu laptop sekarang sebagai waktu pengukuran ESP32, dan jangan memasukkan uptime/millis. Server akan membuat received_at sendiri. TERRA tidak menerima gateway_received_at.
7. Bentuk objek JSON dengan nama field pada tabel di atas dalam file terra_reading.json. Jangan membuat/mengirim file sebelum langkah pengambilan data nyata selesai. File tidak berisi token atau device_id. Data mentah yang tidak ada tetap null; jangan mengisi angka contoh.

## Perintah curl — jalankan sendiri hanya setelah file berisi pembacaan nyata

Dari direktori tempat terra_reading.json disimpan, pada Bash laptop. Tidak ada token literal dalam perintah. Header Authorization dibaca curl dari stdin agar token tidak masuk argumen proses curl. Jangan memakai set -x, curl -v, --trace, -k atau -L.

```bash
read -rsp 'Token TERRA nyata: ' TERRA_TOKEN; echo
export TERRA_TOKEN

# Validasi JSON dahulu; hanya lanjut jika benar.
python3 -m json.tool terra_reading.json >/dev/null && (
  : "${TERRA_TOKEN:?Environment TERRA_TOKEN belum diisi}"
  printf 'Authorization: Bearer %s\n' "$TERRA_TOKEN" |
    curl --proto '=https' --request POST \
      --connect-timeout 10 --max-time 30 \
      --silent --show-error --fail-with-body \
      --header @- \
      --header 'Accept: application/json' \
      --header 'Content-Type: application/json' \
      --data-binary @terra_reading.json \
      --write-out '\nHTTP %{http_code} | %{content_type} | redirect=%{redirect_url}\n' \
      'https://gemastik.unima.space/api/v1/terra/readings'
)
unset TERRA_TOKEN
```

Curl memverifikasi TLS dengan trust store sistem. Tidak mengikuti redirect. Jika timeout setelah server mungkin menerima data, jalankan ulang memakai token yang sama dan **file persis sama**; jangan ubah reading_id/waktu/nilai. Tidak ada retry otomatis pada perintah ini. --fail-with-body memerlukan curl7.76+; tersedia pada Ubuntu modern. Periksa `curl --version` di laptop bila opsi tidak dikenal.

Opsional setelah pembacaan nyata tersedia: GET /api/v1/device menggunakan token yang sama untuk memverifikasi type=terra, is_simulated=false dan device_id; GET ini tidak memperbarui last_seen_at. Jangan mengganti Authorization dengan cookie login admin.

## Respons

201 application/json: record baru disimpan. Potongan struktur respons berikut **skematis**, bukan hasil uji hosting (placeholder string menunjukkan nilai dari server):

```json
{
  "data": {
    "reading_id": "<reading_id yang dikirim>",
    "event_id": "<reading_id yang dikirim>",
    "received_at": "<timestamp UTC penerimaan server>",
    "is_simulated": false
  }
}
```

Respons lengkap juga memiliki id dan device_id integer, captured_at, temperature_c, thermocouple_ok, mq7_adc, mq7_voltage, mq7_voltage_basis, adc_resolution_bits dan sensor_status. Nilainya sesuai payload/disimpan null bila tidak tersedia. Token dan payload_hash tidak dikirim. Timestamp dinormalisasi UTC.

200: ID+payload sama sudah tersimpan; ID database dan received_at pertama tidak berubah. 409: ID sama tetapi isi berbeda:

```json
{"message":"event_id sudah digunakan dengan payload berbeda."}
```

401 token tidak ada/salah:

```json
{"message":"Token perangkat tidak valid."}
```

403 untuk token BINSONIC ke endpoint TERRA (`Jenis perangkat tidak diizinkan.`), atau SIMULASI di production. 422 contoh bentuk error validasi, ringkasan message dapat berbeda mengikuti bahasa/versi framework:

```json
{
  "message": "ADC melebihi resolusi yang diberikan.",
  "errors": {"mq7_adc": ["ADC melebihi resolusi yang diberikan."]}
}
```

429 untuk limit120request/menit/token atau300/menit/IP. Error SQL/server bisa500; periksa log privat server, jangan membuka debug production.

Route ini memakai autentikasi perangkat, bukan middleware login web. bootstrap/app.php memaksa JSON untuk api/* termasuk saat Accept tidak diberikan. Pemeriksaan lokal pada4Oktober2026:6tes terkait,72assertion lulus, database testing terpisah. **Belum ada respons hosting yang diuji pada tahap ini.** Bila pengiriman nyata mengembalikan302/HTML, hentikan; periksa versi route yang diupload, rewrite/docroot, WAF dan penerusan Authorization. Jangan mengikuti redirect ke login atau menonaktifkan auth/TLS. Jangan tampilkan debug/stack trace production.

## Online dan tampilan web

Setiap kiriman TERRA valid (201 maupun replay200) memperbarui last_seen_at menggunakan waktu server. Heartbeat terpisah POST /api/v1/heartbeat dengan body{} tersedia tetapi tidak wajib jika sensor rutin mengirim lebih sering dari ambang online. Default ambang60detik; .env hosting DEVICE_ONLINE_SECONDS dapat berbeda. Heartbeat hanya saat ESP32 hidup, bukan timer laptop yang tetap berjalan setelah board mati. Replay manual pembacaan lama juga membuat online sementara karena backend melihat koneksi klien pengirim, bukan bukti fisik board.

Login admin di https://gemastik.unima.space/login, kemudian buka https://gemastik.unima.space/terra dan pilih perangkat TERRA yang benar. Kartu Respons Sensor Gas serta grafik diperbarui via polling; tabel riwayat/detail perangkat dimuat ulang untuk baris terbaru. /dashboard untuk ringkasan dan koneksi; /devices untuk daftar identitas/status token.

Periksa201→200 pada retry, ID database/received_at tetap, nilai/null sesuai file, perangkat cocok dan sumber DATA NYATA. Satu sampel belum menghasilkan arah tren: dibutuhkan minimal3waktu berbeda pada masing-masing separuh jendela5menit, kontinuitas tanpa jeda>45detik; data stale setelah120detik default. Jangan membuat kiriman berkala dari **sampel fisik yang sama** dengan ID baru untuk memenuhi syarat tren. Berhenti mengirim ketika board berhenti; tunggu ambang online dan lihat Offline. Database production tidak dihapus atau diisi fixture pengujian.
