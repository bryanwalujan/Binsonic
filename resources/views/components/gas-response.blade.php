@props(['gas'])
<section class="panel gas-response" aria-labelledby="gas-title">
    <div class="panel-head"><div><h2 id="gas-title">Respons Sensor Gas</h2><p id="gas-device">{{ $gas['device_name'] ?? 'Perangkat TERRA belum tersedia' }}</p></div><span class="badge blue">MQ-7 · belum dikalibrasi</span></div>
    <div class="gas-layout">
        <div class="gas-summary" aria-live="polite">
            <span id="gas-availability" class="gas-availability">{{ $gas['availability'] }}</span>
            <div class="gas-direction"><span id="gas-direction-icon" aria-hidden="true">{{ match($gas['direction']) { 'increasing' => '↗', 'decreasing' => '↘', 'stable' => '→', default => '◷' } }}</span><strong id="gas-direction">{{ $gas['direction_label'] ?? ($gas['state'] === 'stale' ? 'Data tidak terbaru' : 'Tren belum tersedia') }}</strong></div>
            <p id="gas-message">{{ $gas['message'] }}</p>
            <div class="gas-time"><span>Pembacaan terakhir</span><strong id="gas-last-time">{{ $gas['last_time'] }}</strong><small id="gas-time-source">{{ $gas['time_source'] }}</small></div>
        </div>
        <div><div class="chart gas-chart"><canvas style="opacity:{{ collect($gas['points'])->contains(fn($p) => $p['y'] !== null) ? 1 : 0 }}" id="gas-chart" role="img" aria-label="Tren respons sensor gas; bukan konsentrasi CO atau ukuran keamanan udara"></canvas><div id="gas-empty" class="chart-empty {{ collect($gas['points'])->contains(fn($p) => $p['y'] !== null) ? 'hidden' : '' }}"><span><x-icon name="chart" /></span><strong>Menunggu data sensor</strong><p>Belum ada data untuk grafik pada jendela waktu ini.</p></div></div><p class="chart-footnote">Tren {{ (int) ($gas['window_seconds'] / 60) }} menit terakhir · garis diputus saat data terputus.</p></div>
    </div>
    <p class="gas-disclaimer">Grafik menunjukkan perubahan respons sensor terhadap gas. Pembacaan ini belum menunjukkan konsentrasi CO atau keamanan udara.</p>
    <details class="sensor-details"><summary>Detail sensor <span>Angka mentah dan waktu penerimaan</span></summary>
        <dl class="sensor-detail-grid">
            <div><dt>ADC mentah</dt><dd data-gas-detail="adc">{{ $gas['detail']['adc'] ?? '—' }}</dd></div>
            <div><dt>Resolusi ADC (bit)</dt><dd data-gas-detail="resolution_bits">{{ $gas['detail']['resolution_bits'] ?? '—' }}</dd></div>
            <div><dt>Tegangan di pin ADC (V)</dt><dd data-gas-detail="adc_pin_voltage">{{ $gas['detail']['adc_pin_voltage'] ?? '—' }}</dd></div>
            <div><dt>Estimasi tegangan AO sebelum pembagi resistor (V)</dt><dd data-gas-detail="sensor_output_voltage">{{ $gas['detail']['sensor_output_voltage'] ?? '—' }}</dd></div>
            <div><dt>Tegangan dengan definisi belum diketahui (V)</dt><dd data-gas-detail="undefined_voltage">{{ $gas['detail']['undefined_voltage'] ?? '—' }}</dd></div>
            <div><dt>Status MQ-7 yang dilaporkan perangkat</dt><dd data-gas-detail="reported_status">{{ $gas['detail']['reported_status'] ?? 'Belum tersedia' }}</dd></div>
            <div><dt>Waktu pengukuran perangkat (WITA)</dt><dd data-gas-detail="captured_at">{{ $gas['detail']['captured_at'] }}</dd></div>
            <div><dt>Waktu penerimaan server (WITA)</dt><dd data-gas-detail="received_at">{{ $gas['detail']['received_at'] }}</dd></div>
        </dl>
        <p><strong>Belum dikalibrasi untuk konsentrasi CO</strong></p>
        <p>ADC saja tidak membuktikan sensor sehat. Status pemanasan belum diketahui dari perangkat. Tegangan hanya ditampilkan jika dikirim perangkat; tidak dihitung dari asumsi skala ADC.</p>
    </details>
</section>
