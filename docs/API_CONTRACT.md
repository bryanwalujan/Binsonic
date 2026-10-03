# Kontrak API perangkat — Laravel 12

Base URL production: `https://gemastik.unima.space`. Semua endpoint di bawah membutuhkan `Authorization: Bearer TOKEN_PERANGKAT`. Kirim JSON dengan `Content-Type: application/json`; gunakan `Accept: application/json`. Firmware belum diubah. Kontrak ini mengikuti routes, FormRequest, controller, model dan tes aplikasi, bukan rancangan firmware yang diasumsikan sudah berjalan.

| Metode / path | Token | Fungsi |
|---|---|---|
| GET `/api/v1/device` | Keduanya | Identitas read-only: `device_id`, `type`, `is_simulated`, `online_seconds`; tidak membuat online |
| POST `/api/v1/heartbeat` | Keduanya | Body `{}`; memperbarui waktu terlihat perangkat milik token |
| POST `/api/v1/binsonic/readings` | BINSONIC | Sensor berkala, tabel `binsonic_readings`, **tidak menambah jumlah sampah** |
| POST `/api/v1/binsonic/events` | BINSONIC | Satu kejadian objek/pemilahan, tabel `binsonic_events` |
| POST `/api/v1/terra/readings` | TERRA | Pembacaan TERRA, tabel `terra_readings` |
| POST `/api/v1/binsonic/events/{event_id}/image` | BINSONIC pemilik event | Multipart field `image`, JPEG/PNG/WebP ≤5 MiB; foto kedua ditolak 409 |

Identitas dan mode SIMULASI berasal dari perangkat di database, **bukan isi payload**. Pada APP_ENV=production, kiriman token SIMULASI ditolak403; data simulasi lama disimpan tetapi tidak ditampilkan. Buat dua perangkat dan dua token yang berbeda. Jangan kirim `device_id`, `is_simulated`, `received_at`, `image_path`, atau `image_url`. Field telemetry tidak dikenal menghasilkan 422. Token BINSONIC tidak dapat menulis ke TERRA atau sebaliknya. ID yang sama pada dua perangkat adalah dua record terpisah; token tidak dapat memilih perangkat lain. Token disimpan sebagai hash SHA-256 dan diterbitkan hanya melalui Artisan; tidak dikirim ke dashboard. Admin web dapat mendaftarkan perangkat tanpa token dan mencabut akses.

## JSON BINSONIC

Contoh **kejadian**, hanya ketika ada kejadian pemilahan; file [binsonic-event.json](examples/binsonic-event.json):

```json
{
  "event_id": "portenta01-boot7-event001",
  "captured_at": "2026-10-03T08:00:00+08:00",
  "audio_label": null,
  "audio_confidence": null,
  "vision_label": null,
  "vision_confidence": null,
  "final_label": null,
  "destination": null,
  "weight_calibrated": false,
  "weight_g": null,
  "loadcell_raw": 4724,
  "moisture_adc": 556,
  "wet_contact": false,
  "metal_detected": null,
  "sensor_status": {
    "audio": "unavailable", "vision": "unavailable",
    "loadcell": "uncalibrated", "moisture": "ok", "metal": "unavailable"
  }
}
```

Untuk **sensor berkala**, gunakan `/binsonic/readings` dan hapus keenam field klasifikasi (`audio_label`, `audio_confidence`, `vision_label`, `vision_confidence`, `final_label`, `destination`). Field tersebut tidak diterima pada readings, termasuk jika nilainya null. Lihat [binsonic-reading.json](examples/binsonic-reading.json). `event_id` pada tabel readings merupakan ID pesan telemetry, bukan penghitung sampah. Dashboard hanya menghitung tabel events.

Untuk USB Portenta tanpa jam kalender valid: `captured_at: null`, bridge menambahkan `gateway_received_at` berupa waktu UTC laptop pertama menerima serial. Waktu tersebut tetap sama saat retry. Lihat [kontrak serial](../bridge/SERIAL_PROTOCOL.md) dan [panduan Ubuntu](../bridge/README.md). Envelope serial (`version`, `board_id`, `type`, `payload`) berbeda dari body HTTP: bridge hanya meneruskan payload dan waktu gateway. `board_id` memeriksa sumber USB, bukan ID database. Laptop tidak mengarang waktu perangkat. Data melalui gateway tidak memperbarui online; heartbeat bridge hanya berjalan selama status/data serial segar, berhenti ketika USB terputus atau data kedaluwarsa. Debug bukan bukti board hidup.

## JSON TERRA

File [terra-reading.json](examples/terra-reading.json):

```json
{
  "reading_id": "terra01-boot7-reading001",
  "captured_at": "2026-10-03T08:00:00+08:00",
  "temperature_c": 32.5,
  "thermocouple_ok": true,
  "mq7_adc": 412,
  "mq7_voltage": null,
  "mq7_voltage_basis": null,
  "adc_resolution_bits": 12,
  "sensor_status": {"thermocouple": "ok", "mq7": "uncalibrated"}
}
```

Jika MAX6675 belum tersedia: `temperature_c: null`, `thermocouple_ok: null`, status `unavailable`. Jika sensor terdeteksi gagal: suhu null, `thermocouple_ok: false`, status `error`. Jangan mengganti nilai tidak tersedia menjadi nol.

`mq7_voltage_basis`:

- `adc_pin`: tegangan aktual di pin ADC ESP32, **setelah** rangkaian pembagi tegangan jika digunakan.
- `sensor_output`: tegangan keluaran sensor **sebelum** pembagi tegangan; firmware/gateway harus sudah mengoreksi sesuai rasio rangkaian yang benar.
- `null`: definisi belum diketahui. Format lama tanpa field ini tetap diterima dan UI menyebut definisi belum tersedia. Untuk firmware baru, selalu sertakan basis jika mengirim tegangan. Jika basis diberikan, tegangan wajib tersedia.

Aplikasi tidak menghitung tegangan otomatis dari ADC dan tidak mengubahnya menjadi CO ppm. ADC/volt bukan jaminan emisi aman. `reading_id` menjadi nama utama di wire contract. `event_id` lama tetap diterima sebagai alias demi kompatibilitas; jika keduanya dikirim, nilainya harus sama. Respons memuat keduanya; database tetap memakai kolom/index `event_id` yang ada tanpa mengganti ID data lama. TERRA menerima `captured_at: null` jika jam perangkat belum tersinkronisasi. Server tetap menyimpan `received_at`; tampilan memakai waktu penerimaan dengan label yang jelas jika waktu pengukuran tidak tersedia. Jangan mengirim uptime atau waktu laptop sebagai `captured_at` perangkat.

## Aturan dan hasil pengiriman

| Field | Validasi |
|---|---|
| `event_id` / TERRA `reading_id` | String 1–100 karakter `[A-Za-z0-9_-]`; unik per perangkat dan tabel; gunakan UUID atau board+boot unik+counter |
| `captured_at` | ISO8601 dengan `Z`/offset, pecahan detik ≤6 digit; boleh null jika jam perangkat belum tersedia; server tetap menyimpan `received_at` |
| `sensor_status` | Wajib hadir, objek atau null; key: audio, vision, loadcell, moisture, metal, thermocouple, mq7 |
| Setiap status | `ok`, `error`, `unavailable`, `uncalibrated`, atau null |
| `weight_calibrated` | BINSONIC wajib boolean; **saat ini false** |
| `weight_g` | Nullable, angka ≥0; harus null bila belum terkalibrasi |
| `loadcell_raw` | Nullable integer signed 32-bit |
| `moisture_adc`, `mq7_adc` | Nullable integer 0–65535; MQ7 harus lebih kecil dari 2^resolusi jika resolusi tersedia |
| `wet_contact`, `metal_detected`, `thermocouple_ok` | Boolean atau null |
| Label audio/vision/final | Nullable string ≤100 karakter; hanya events |
| Confidence | Nullable angka 0–1; hanya events |
| `destination` | null, `recycle`, `organic`, `thermal_candidate`, `residue_unknown`; hanya events |
| `temperature_c` | Nullable angka 0–1024 °C |
| `mq7_voltage` | Nullable angka 0–5.5 volt |
| `adc_resolution_bits` | Nullable integer 1–16 |

Kirim tipe JSON asli (angka, boolean, null); field sensor opsional yang dihilangkan disimpan null. Nol/false tetap nilai tersedia. `thermal_candidate` ditampilkan **Kandidat termal — perlu verifikasi**, bukan jaminan aman dibakar. Tidak ada buzzer, kontrol blower atau penyalaan melalui aplikasi.

- Baru: **201** `{"data":{...}}`; payload identik dengan ID sama: **200**, record dan `received_at` pertama tetap sama.
- ID sama dengan isi berbeda: **409**. Jangan mengganti ID otomatis untuk menyembunyikan konflik.
- Token salah/tidak ada: **401**; jenis perangkat salah: **403**; validasi: **422** dengan objek `errors`; rate limit: **429**.
- Payload dinormalisasi (UTC, angka/boolean, null, urutan key). Unique index `(device_id,event_id)` dan transaksi lock perangkat mencegah duplikasi retry serentak. Penghilangan field basis/gateway yang null mempertahankan hash data lama.
- `received_at` dibuat server saat penyimpanan pertama. `captured_at` waktu perangkat; `gateway_received_at` waktu penerimaan laptop, berbeda dari keduanya. Penyimpanan UTC; UI WITA.
- Kartu MQ7 memakai [metode tren backend](MQ7_TREND.md), bukan estimasi konsentrasi.
- Online jika `last_seen_at` dalam 60 detik (`DEVICE_ONLINE_SECONDS`). Hanya heartbeat/data API valid perangkat itu yang menyegarkannya; browsing/polling dashboard tidak. Sensor kedaluwarsa dinilai terpisah setelah 120 detik. Upload backlog BINSONIC melalui gateway tidak membuat board online.
- Limit 120 request/menit/token dan 300/menit/IP. Gunakan heartbeat misalnya tiap 15 detik. Simulator memiliki percobaan terbatas, bridge memiliki outbox SQLite **lokal laptop**; aplikasi dan tes memakai MySQL.
- Foto privat di `storage/app/private/events`, hanya tersedia setelah login melalui `/binsonic/{id_database}/photo`; tidak memerlukan `storage:link`. Cadangkan database bersama folder foto.
