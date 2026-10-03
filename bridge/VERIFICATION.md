# Verifikasi integrasi USB — 2 Oktober 2026

Implementasi diperiksa terhadap route API, middleware Bearer perangkat, validasi, model, dan idempotensi proyek sebelum diperluas. Tidak ada proyek baru atau perubahan firmware.

- Migration tambahan berhasil pada MySQL aplikasi dan MySQL pengujian, tanpa reset/drop data. Tabel baru `binsonic_readings`; events mendapat timestamp gateway dan captured_at nullable.
- **21 tes Laravel lulus, 299 assertion** pada `binsonic_terra_testing`, dengan transaksi rollback. Mencakup token dan jenis perangkat, identity tanpa heartbeat, tiga timestamp, sensor null, idempotensi readings/events, HTTP 409/422, pembacaan berkala tidak menambah statistik kejadian, dan backlog tidak mengubah last_seen_at.
- **23 tes Python lulus**. Mencakup JSON parsing, optional null/false/0, status/debug, fragmentasi CRLF, oversize, ID lokal, antrean setelah restart, lock/identitas, backoff termasuk timeout lambat, 429, 422/409 permanen, auth, serta heartbeat saat stale/putus/reconnect.
- Pengujian memakai pySerial nyata dengan pseudo-terminal Linux: baris parsial, disconnect dan reconnect pada path port yang sama, menunggu status baru, dan mengabaikan debug.
- Pengujian CLI nyata dengan pseudo-terminal dan server HTTP lokal: server mensimulasikan commit lalu 503, retry mempertahankan event_id/payload/timestamp dan menghasilkan satu record; 422 dikarantina sekali; heartbeat berhenti setelah disconnect. Token tidak muncul pada log maupun SQLite antrean.
- Seluruh contoh `.jsonl` di direktori examples berhasil diparsing oleh validator bridge.
- `npm run build`, `php artisan view:cache`, dan `vendor/bin/pint --test` berhasil.
- Browser Chromium: tabel sensor BINSONIC dan pembaruan polling tampil tanpa error JavaScript; statistik data nyata tetap kosong. Tidak ada data sintetis yang dimasukkan sebagai data nyata.

Portenta fisik tidak terdeteksi (`/dev/serial/by-id` tidak tersedia; enumerasi hanya port ttyS). USB Portenta, baud firmware aktual, dan data sensor fisik belum diuji. Pengujian serial di atas menggunakan pseudo-terminal, bukan klaim koneksi board nyata. Simulator HTTP pada tes Python terpisah dari tes backend Laravel/MySQL; tidak ada klaim uji perangkat-ke-server fisik lengkap.

Aplikasi tetap MySQL. SQLite hanya dipakai sebagai antrean lokal bridge. Tidak ada deployment internet, pengunggahan firmware, atau kendali aktuator.
