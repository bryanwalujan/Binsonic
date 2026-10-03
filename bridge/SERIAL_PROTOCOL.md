# Kontrak JSON serial Portenta v1

Satu objek JSON UTF-8 per baris, diakhiri LF (`\n`; CRLF juga diterima), maksimal **16.384 byte** sebelum newline. Tanpa pretty-print multiline, NaN, Infinity, atau key duplikat. Baris teks/debug lama tidak menjadi data maupun heartbeat. Bridge tidak mengirim byte/perintah ke serial.

Envelope wajib:

| Field | Nilai |
|---|---|
| `version` | Integer `1` |
| `board_id` | Identitas board yang persis cocok dengan `--board-id` (1–100 karakter `[A-Za-z0-9_-]`) |
| `type` | `status`, `debug`, `binsonic.reading`, atau `binsonic.event` |
| `payload` | Khusus dua jenis data; objek sesuai tabel di bawah |

`board_id` dipakai untuk memeriksa sumber USB; **tidak dipakai sebagai device_id API**. ID database dan simulasi/nyata selalu berasal dari Bearer token server. Jangan kirim token di serial.

## Routing akhir

| Jenis | Endpoint | Efek |
|---|---|---|
| `binsonic.reading` | `POST /api/v1/binsonic/readings` **baru** | Tabel `binsonic_readings`, pembacaan sensor berkala; tidak menambah jumlah sampah |
| `binsonic.event` | `POST /api/v1/binsonic/events` | Tabel `binsonic_events`, hanya saat ada satu kejadian objek/pemilahan nyata |
| `status` | Tidak dikirim sebagai data | Menyegarkan bukti board hidup; memungkinkan heartbeat |
| `debug` | Tidak dikirim | Diabaikan; tidak menyegarkan koneksi dan tidak dicetak |
| Heartbeat bridge | `POST /api/v1/heartbeat`, JSON `{}` | Hanya saat serial segar; tidak masuk antrean |
| Pemeriksaan token | `GET /api/v1/device` **baru** | Read-only: `device_id`, `type`, `is_simulated`, `online_seconds`; tidak memperbarui last_seen |

Endpoint TERRA dan upload foto yang ada tetap tersedia; bridge ini khusus sensor/kejadian **BINSONIC** dan tidak meneruskan TERRA/foto. Seluruh endpoint di tabel memerlukan Bearer token; readings/events hanya menerima token BINSONIC. Envelope `version`, `board_id`, `type` tidak dikirim ke endpoint data—bridge hanya mengirim `payload` plus `gateway_received_at`.

## Telemetry sensor berkala

Contoh lengkap satu baris, tersedia juga di [`examples/sensor-reading.jsonl`](examples/sensor-reading.jsonl):

```json
{"version":1,"board_id":"portenta-01","type":"binsonic.reading","payload":{"event_id":"portenta01-boot7-reading0001","captured_at":null,"weight_calibrated":false,"weight_g":null,"loadcell_raw":4724,"moisture_adc":556,"wet_contact":false,"metal_detected":null,"sensor_status":{"audio":"unavailable","vision":"unavailable","loadcell":"uncalibrated","moisture":"ok","metal":"unavailable"}}}
```

Ini **bukan kejadian sampah**. Nama field `event_id` di telemetry adalah identitas pesan untuk deduplikasi, bukan penambahan statistik pemilahan. Kirim ID baru untuk setiap pembacaan baru, dan ID/payload yang sama ketika mengulang pembacaan yang sama. Gunakan UUID atau kombinasi identitas board + identitas boot yang unik + counter; counter yang kembali ke nol setelah restart tanpa identitas boot unik akan menimbulkan konflik. Jangan membuat ID dari uptime saja.

| Field payload | Aturan |
|---|---|
| `event_id` | Wajib string 1–100 karakter, hanya `[A-Za-z0-9_-]`, unik per perangkat dan endpoint |
| `captured_at` | Wajib hadir. ISO 8601 dengan `Z`/offset dan pecahan detik maks. 6 digit, atau **null** jika jam perangkat belum valid |
| `weight_calibrated` | Wajib boolean JSON. Untuk kondisi saat ini: `false` |
| `weight_g` | Angka ≥ 0 atau null; **wajib null bila weight_calibrated=false** |
| `loadcell_raw` | Integer −2147483648…2147483647 atau null |
| `moisture_adc` | Integer 0…65535 atau null; bukan persentase kadar air |
| `wet_contact` | Boolean JSON atau null; kontak basah |
| `metal_detected` | Boolean JSON atau null; deteksi logam, bukan jenis logam |
| `sensor_status` | Wajib hadir. Objek dengan key yang diizinkan, atau null |

Field sensor opsional yang dihilangkan dinormalisasi menjadi null; firmware dianjurkan mengirim semuanya eksplisit. Bridge menerima angka JSON, bukan string angka, dan boolean JSON `true`/`false`, bukan 0/1/string. Nol dan false adalah pembacaan yang tersedia; null berarti tidak tersedia.

Key `sensor_status`: `audio`, `vision`, `loadcell`, `moisture`, `metal`, `thermocouple`, `mq7`. Nilai setiap key: `ok`, `error`, `unavailable`, `uncalibrated`, atau null. Untuk board ini gunakan key sensor yang relevan; model audio/vision yang belum tersedia ditandai `unavailable`.

Telemetry tidak memiliki label, confidence, atau destination. Field klasifikasi **tidak boleh dikirim pada readings**, termasuk dengan nilai null, karena bukan keputusan pemilahan. Nilai klasifikasi null hanya ada pada jenis kejadian berikut.

## Kejadian pemilahan — hanya berdasarkan trigger objek yang sebenarnya

Contoh lengkap, [`examples/sorting-event.jsonl`](examples/sorting-event.jsonl):

```json
{"version":1,"board_id":"portenta-01","type":"binsonic.event","payload":{"event_id":"portenta01-boot7-sorting0001","captured_at":null,"weight_calibrated":false,"weight_g":null,"loadcell_raw":4724,"moisture_adc":556,"wet_contact":false,"metal_detected":null,"sensor_status":{"audio":"unavailable","vision":"unavailable","loadcell":"uncalibrated","moisture":"ok","metal":"unavailable"},"audio_label":null,"audio_confidence":null,"vision_label":null,"vision_confidence":null,"final_label":null,"destination":null}}
```

Field sensor sama dengan readings. Field tambahan:

- `audio_label`, `vision_label`, `final_label`: string 1–100 karakter atau null.
- `audio_confidence`, `vision_confidence`: angka 0–1 atau null.
- `destination`: `recycle`, `organic`, `thermal_candidate`, `residue_unknown`, atau null. `thermal_candidate` berarti “Kandidat termal — perlu verifikasi”.

Untuk kondisi sebelum training, kirim semua hasil klasifikasi sebagai null. Jika belum ada logika deteksi objek/pemilahan, **jangan kirim `binsonic.event` sama sekali**. Gunakan `binsonic.reading` untuk loop sensor berkala. Bridge tidak mengubah telemetry menjadi event dan tidak menyimpulkan keberadaan sampah dari berat/kontak/logam.

## Status dan debug

[`examples/status-debug.jsonl`](examples/status-debug.jsonl):

```json
{"version":1,"board_id":"portenta-01","type":"status","status":"ready","uptime_ms":123400}
{"version":1,"board_id":"portenta-01","type":"debug","message":"Inisialisasi sensor selesai"}
```

`status`: `ready`, `initializing`, atau `sensor_error`; `uptime_ms` wajib integer 0…9007199254740991 dan **bukan waktu kalender**. `debug.message` string maksimal 1000 karakter. Status/debug tidak memiliki payload sensor. Firmware sebaiknya mengirim status/data setiap 2–5 detik agar ambang segar default 10 detik terpenuhi.

## Tiga waktu yang berbeda

| Field | Sumber | Makna |
|---|---|---|
| `captured_at` | Portenta | Waktu perangkat mengambil data. Tetap null jika tidak diketahui; tidak dibuat laptop |
| `gateway_received_at` | Bridge laptop | Waktu laptop menerima/menyimpan pesan serial pertama, UTC. Tetap sama selama retry/restart |
| `received_at` | API server | Waktu record pertama diterima/disimpan server; tidak berubah saat retry |

Contoh payload HTTP yang dihasilkan dari serial telemetry di atas (nilai waktu gateway hanya ilustrasi):

```json
{"event_id":"portenta01-boot7-reading0001","captured_at":null,"gateway_received_at":"2026-10-02T05:30:00.123456Z","weight_calibrated":false,"weight_g":null,"loadcell_raw":4724,"moisture_adc":556,"wet_contact":false,"metal_detected":null,"sensor_status":{"audio":"unavailable","vision":"unavailable","loadcell":"uncalibrated","moisture":"ok","metal":"unavailable"}}
```

Serial dilarang mengirim `gateway_received_at`, `received_at`, `device_id`, `is_simulated`, image URL/path, atau field tambahan yang tidak dikenal. Bridge selalu menambahkan `gateway_received_at` saat menerima serial, termasuk ketika `captured_at=null`. API juga menerima perangkat langsung dengan captured_at null tanpa gateway; pada jalur langsung waktu server tetap berlabel penerimaan, bukan waktu pengukuran. Klien perangkat langsung yang sudah memakai captured_at valid tetap kompatibel tanpa field gateway. Jam laptop harus disinkronkan; waktu gateway tidak membuktikan waktu pengambilan sensor. UI menampilkan label waktu gateway secara eksplisit, dan grafik aktivitas memakai waktu gateway hanya ketika waktu perangkat tidak tersedia. Semua penyimpanan UTC, tampilan WITA.

## Hasil API dan idempotensi

- 201: record baru; 200: payload sama untuk device+event_id yang sama, mengembalikan record sebelumnya.
- 409: ID sama, payload berbeda; karantina bridge, tidak membuat ID pengganti diam-diam.
- 422: payload tidak valid; karantina bridge, tidak terus diulang.
- 401/403: token/jenis perangkat bermasalah; bridge berhenti, antrean dipertahankan.
- 429/5xx atau gangguan jaringan: retry dengan backoff, payload sama.

Contoh metadata identitas: `{"device_id":12,"type":"binsonic","is_simulated":false,"online_seconds":60}`. Data tetap ditandai nyata/simulasi oleh server, bukan firmware/laptop.

Record readings/events yang memiliki waktu gateway tidak menyegarkan `last_seen_at`: pengiriman backlog bukan bukti USB masih terhubung. Heartbeat adalah bukti koneksi serial segar. Masa offline tetap mengikuti ambang server; bukan deteksi putus seketika pada browser.
