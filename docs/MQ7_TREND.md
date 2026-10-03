# Respons Sensor Gas: metode tampilan MQ-7

Kartu TERRA memakai pembacaan `terra_readings` di MySQL melalui backend, bukan data acak atau perhitungan tren di browser. Pengguna memilih satu perangkat TERRA; pembacaan dua perangkat/resolusi berbeda tidak dicampur. Default memilih perangkat dengan kiriman paling baru dalam mode yang diizinkan. Production hanya data nyata, termasuk saat parameter URL dimanipulasi.

## Perhitungan yang digunakan

1. Ambil jendela **300 detik (5 menit)** terakhir, maksimum1200 record per perangkat, urut waktu observasi. Gunakan `captured_at` bila tersedia; bila null, gunakan `received_at` **dengan label waktu penerimaan server**, bukan waktu pengukuran. Data dengan waktu pengukuran di masa depan tidak dipakai. Detail selalu mempertahankan kedua timestamp asli.
2. Sampel untuk tren harus memiliki ADC bukan null dan status MQ7 bukan `error`/`unavailable`. Status yang tidak diberikan tidak diasumsikan sehat; nilai ADC masih dapat divisualisasikan sebagai respons mentah. Null bukan0; nilai0 tetap sah. Tidak ada keputusan “Sensor normal”, pemanasan selesai, kesehatan atau keamanan.
3. Jeda **lebih dari45 detik**, ADC null/status error/unavailable, perubahan resolusi ADC (termasuk diketahui↔tidak diketahui), atau perubahan sumber waktu perangkat↔server memutus segmen. Grafik diberi titik null dan `spanGaps=false`, sehingga tidak menyambung melewati jeda. Penghalusan dan perhitungan tren direset saat segmen putus.
4. Untuk arah perubahan gunakan **segmen kontinu paling akhir**. Bagi jendela menjadi dua bagian waktu yang sama: 150 detik sebelumnya dan150 detik terbaru. Wajib sedikitnya **3 waktu observasi berbeda pada setiap bagian** (minimal6 total; pembatasan jeda dapat membutuhkan lebih banyak sampel). Banyak sampel dalam satu bagian saja belum cukup. Timestamp berulang tidak menambah bukti sampel.
5. Bandingkan **median ADC mentah** bagian baru dengan median bagian lama. Ambang tampilan `max(30 hitungan ADC, 5% × |median lama|)`. Selisih di atas ambang → Meningkat, di bawah negatif ambang → Menurun, dalam/tepat batas → Relatif stabil. Teks meningkat: “Pembacaan sensor meningkat dibanding periode sebelumnya.” Tidak menyimpulkan gas tertentu sebagai penyebab.
6. Garis grafik memakai **median bergerak3 sampel** pada segmen yang sama (awal segmen memakai1 atau2 sampel yang tersedia). Penghalusan hanya data respons tampilan; nilai asli database tetap utuh. Arah tren memakai median periode dari data mentah, bukan dari garis yang sudah dihaluskan.
7. Tanpa record → “Menunggu data sensor”. Sampel belum memenuhi kedua periode → “Data belum cukup untuk melihat perubahan”. Waktu observasi terakhir lebih lama dari `SENSOR_STALE_SECONDS` (default120) → “Data tidak terbaru” beserta waktu, tanpa mengklaim arah perubahan terkini. Data null/error terakhir menampilkan ketersediaan terbatas. Waktu perangkat di masa depan ditandai tidak valid. Data lama tetap ada dalam riwayat; grafik hanya jendela saat ini.

Sampel terlambat dengan captured_at lama tetap dianggap lama walaupun baru diterima. Jika captured_at null, umur pengukuran sebenarnya **tidak dapat diketahui**; kartu menyebut sumber waktu penerimaan server dan tidak mengarang jam perangkat. Timestamp pengukuran/penerimaan keduanya dapat diperiksa di Detail sensor.

## Parameter tampilan

Di `.env` (lalu `php artisan config:cache`):

```dotenv
MQ7_TREND_WINDOW_SECONDS=300
MQ7_TREND_GAP_SECONDS=45
MQ7_TREND_MIN_SAMPLES_PER_HALF=3
MQ7_TREND_ABSOLUTE_DELTA=30
MQ7_TREND_RELATIVE_DELTA=0.05
SENSOR_STALE_SECONDS=120
```

Ini **parameter tampilan**, bukan ambang konsentrasi, kesehatan, kebakaran, emisi atau keselamatan. Jendela dibatasi minimal60detik, jeda minimal1detik, minimum per bagian minimal2 agar satu sampel tidak menentukan arah; ambang negatif diperlakukan0. Sesuaikan parameter tampilan dengan cadence kiriman dan resolusi aktual. Nilai default tidak dimaksudkan sebagai hasil kalibrasi MQ7. Bila frekuensi kiriman lebih lambat dari batas jeda, kartu secara konservatif tidak menyatakan arah sampai konfigurasi tampilan disesuaikan.

## Detail tegangan dan kalibrasi

Bagian “Detail sensor” menampilkan ADC asli, bit resolusi, status yang **dilaporkan perangkat**, waktu pengukuran dan waktu penerimaan server. `mq7_voltage_basis=adc_pin` hanya mengisi tegangan pin ADC. `sensor_output` hanya mengisi **estimasi tegangan AO sebelum pembagi resistor**; tidak dikalikan ulang. Tanpa basis, tegangan diberi label definisi belum diketahui. API saat ini menyimpan satu nilai tegangan beserta basisnya per reading; nilai basis lain tetap tidak tersedia. Tidak ada konversi otomatis ADC→volt, persen “polusi”, CO ppm, atau indikator aman/bahaya.

Keterangan kartu: “Grafik menunjukkan perubahan respons sensor terhadap gas. Pembacaan ini belum menunjukkan konsentrasi CO atau keamanan udara.” Status kalibrasi selalu “Belum dikalibrasi untuk konsentrasi CO”. Warna grafik biru netral; label arah berupa teks dan ikon, bukan kode warna aman/bahaya.

## Implementasi dan tes

- `app/Services/GasResponse.php`: query, segmentasi, median dan status ketersediaan.
- `config/monitor.php`: parameter tampilan.
- `app/Http/Controllers/MonitorController.php`: pilihan perangkat, snapshot database dan filter production.
- `resources/views/components/gas-response.blade.php`, `resources/views/terra.blade.php`, `resources/js/app.js`, `resources/css/app.css`: kartu, detail, grafik, polling dan responsivitas.
- `tests/Feature/GasResponseTest.php`: database MySQL testing terpisah, transaksi rollback; kosong, insufficient, tiga arah, parameter, smoothing, data asli, terlambat, terputus, null/zero/status error, perubahan resolusi/jam, definisi tegangan, nullable timestamp, payload tidak lengkap, isolasi perangkat dan production.

Fixtures hanya dibuat dalam database testing. Tidak ada seeder demo atau timer pembuat data di aplikasi. Timer frontend hanya membaca snapshot backend.
