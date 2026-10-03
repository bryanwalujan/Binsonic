import Chart from 'chart.js/auto';

// Responsive navigation with keyboard focus management.
const navToggle = document.querySelector('.nav-toggle');
const sidebar = document.getElementById('main-navigation');
const backdrop = document.querySelector('.nav-backdrop');
const appMain = document.getElementById('app-main');
function closeNavigation(restoreFocus = true) {
    document.body.classList.remove('nav-open');
    if (backdrop) backdrop.hidden = true;
    if (appMain) appMain.inert = false;
    navToggle?.setAttribute('aria-expanded', 'false');
    if (sidebar) sidebar.inert = window.innerWidth < 1024;
    if (restoreFocus) navToggle?.focus();
}
if (sidebar) sidebar.inert = window.innerWidth < 1024;
navToggle?.addEventListener('click', () => {
    sidebar.inert = false;
    document.body.classList.add('nav-open');
    backdrop.hidden = false;
    navToggle.setAttribute('aria-expanded', 'true');
    appMain.inert = true;
    sidebar.querySelector('.nav-close').focus();
});
backdrop?.addEventListener('click', () => closeNavigation());
document.querySelector('.nav-close')?.addEventListener('click', () => closeNavigation());
window.matchMedia('(min-width: 1024px)').addEventListener('change', () => closeNavigation(false));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        if (document.body.classList.contains('nav-open')) closeNavigation();
        document.querySelector('.account-menu')?.removeAttribute('open');
    }
    if (event.key === 'Tab' && document.body.classList.contains('nav-open')) {
        const links = [...sidebar.querySelectorAll('a[href], button')];
        const first = links[0], last = links[links.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
document.addEventListener('click', event => {
    const account = document.querySelector('.account-menu');
    if (account && !account.contains(event.target)) account.removeAttribute('open');
});
document.querySelector('.password-toggle')?.addEventListener('click', event => {
    const input = document.getElementById('login-password');
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    event.currentTarget.setAttribute('aria-pressed', String(reveal));
    event.currentTarget.setAttribute('aria-label', reveal ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
});
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', e => {
    if (!window.confirm(form.dataset.confirm)) e.preventDefault();
}));
const initial = document.getElementById('snapshot-data');
if (initial) {
    let snapshot = JSON.parse(initial.textContent);
    const charts = {};
    Chart.defaults.font.family = '"Plus Jakarta Sans", sans-serif';
    Chart.defaults.color = '#8b9a82';
    const chart = (id, labels, values, color, label, type = 'line') => {
        const el = document.getElementById(id);
        if (!el) return;
        charts[id] = new Chart(el, {type, data: {labels, datasets: [{label, data: values, borderColor: color, backgroundColor: type === 'bar' ? '#86b68c' : color + '0c', fill: true, tension: 0.2, spanGaps: false, borderWidth: 2, pointRadius: 2, borderRadius: 5, maxBarThickness: 38}]}, options: {responsive: true, maintainAspectRatio: false, animation: false, plugins: {legend: {display: false}, tooltip: {backgroundColor: '#163e32', padding: 12, cornerRadius: 8, titleFont: {size: 10}, bodyFont: {size: 11}, displayColors: false}}, scales: {x: {grid: {display: false}, ticks: {maxTicksLimit: 5, font: {size: 9}, callback: function(value) { const s = this.getLabelForValue(value); return s.length > 12 ? s.slice(12,20) : s; }}}, y: {beginAtZero: true, ticks: {precision: type === 'bar' ? 0 : undefined, font: {size: 10}}, grid: {color: '#eef2e9'}}}}});
    };
    chart('activity-chart', snapshot.activity.labels, snapshot.activity.values, '#289879', 'Jumlah kejadian', 'bar');
    chart('temperature-chart', snapshot.terra.labels, snapshot.terra.temperature, '#4f8cbb', 'Suhu °C');
    const gasCanvas = document.getElementById('gas-chart');
    const timeLabel = value => new Intl.DateTimeFormat('id-ID', {timeZone: 'Asia/Makassar', hour: '2-digit', minute: '2-digit', second: '2-digit'}).format(new Date(value));
    if (gasCanvas) {
        charts['gas-chart'] = new Chart(gasCanvas, {type: 'line', data: {datasets: [{label: 'Respons sensor (tampilan dihaluskan)', data: snapshot.gas.points, borderColor: '#4f8cbb', backgroundColor: '#4f8cbb0c', fill: true, spanGaps: false, tension: 0, pointRadius: 2, borderWidth: 2}]}, options: {responsive: true, maintainAspectRatio: false, animation: false, parsing: false, plugins: {legend: {display: false}, tooltip: {callbacks: {title: items => timeLabel(items[0].parsed.x)}}}, scales: {x: {type: 'linear', ticks: {maxTicksLimit: 4, callback: timeLabel}, grid: {display: false}}, y: {title: {display: true, text: 'Respons relatif · ADC dihaluskan'}, ticks: {maxTicksLimit: 4}, grid: {color: '#eef2e9'}}}}});
    }
    function update(id, labels, values) { if (charts[id]) { charts[id].data.labels = labels; charts[id].data.datasets[0].data = values; charts[id].update(); } }
    function render(data) {
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
        document.getElementById('activity-empty')?.classList.toggle('hidden', data.activity.values.some(value => value > 0));
        set('device-count', data.devices.length);
        set('total', data.total); set('temperature', data.temperature ?? '—'); set('temperature-time', data.temperature_time);
        document.querySelectorAll('[data-count]').forEach(el => el.textContent = data.counts[el.dataset.count] ?? 0);
        document.getElementById('sensor-alert')?.classList.toggle('hidden', !data.temperature_stale);
        const devices = document.getElementById('device-status');
        if (devices) {
            devices.replaceChildren();
            if (!data.devices.length) { devices.append(document.getElementById('empty-device-template').content.cloneNode(true)); }
            data.devices.forEach(d => {
                const card = document.createElement('article'); card.className = 'device-card';
                for (const [tag, text, cls] of [['strong', d.name, ''], ['span', d.connection, 'badge ' + (d.online ? '' : 'neutral')], ['p', d.type.toUpperCase() + ' · ' + d.sensor, ''], ['small', 'Terakhir terlihat: ' + d.seen, '']]) { const el = document.createElement(tag); el.textContent = text; el.className = cls; card.append(el); }
                devices.append(card);
            });
        }
        const readings = document.getElementById('binsonic-readings');
        if (readings) {
            readings.replaceChildren();
            const value = v => v === null ? 'Tidak tersedia' : (typeof v === 'boolean' ? (v ? 'Ya' : 'Tidak') : String(v));
            if (!data.binsonic_readings.length) {
                readings.append(document.getElementById('empty-reading-template').content.cloneNode(true));
            }
            data.binsonic_readings.forEach(r => {
                const tr = readings.insertRow();
                [r.device, r.captured_at + ' / Gateway: ' + r.gateway_received_at + ' / Server: ' + r.received_at, value(r.loadcell_raw), value(r.weight_g) + (r.weight_calibrated ? ' · Terkalibrasi' : ' · Belum dikalibrasi'), value(r.moisture_adc), value(r.wet_contact), value(r.metal_detected), JSON.stringify(r.sensor_status)].forEach(text => { tr.insertCell().textContent = text; });
                if (r.simulated) { const badge = document.createElement('span'); badge.className = 'badge'; badge.textContent = 'SIMULASI'; tr.cells[0].append(' ', badge); }
            });
        }
        update('activity-chart', data.activity.labels, data.activity.values);
        update('temperature-chart', data.terra.labels, data.terra.temperature);
        document.getElementById('temperature-empty')?.classList.toggle('hidden', data.terra.temperature.some(value => value !== null));
        if (charts['temperature-chart']) charts['temperature-chart'].canvas.style.opacity = data.terra.temperature.some(value => value !== null) ? 1 : 0;
        if (data.gas) {
            const gas = data.gas;
            set('gas-device', gas.device_name ?? 'Perangkat TERRA belum tersedia');
            set('gas-availability', gas.availability);
            set('gas-direction', gas.direction_label ?? (gas.state === 'stale' ? 'Data tidak terbaru' : 'Tren belum tersedia'));
            set('gas-direction-icon', {increasing: '↗', decreasing: '↘', stable: '→'}[gas.direction] ?? '◷');
            set('gas-message', gas.message); set('gas-last-time', gas.last_time); set('gas-time-source', gas.time_source);
            document.querySelectorAll('[data-gas-detail]').forEach(el => el.textContent = gas.detail[el.dataset.gasDetail] ?? '—');
            document.getElementById('gas-empty')?.classList.toggle('hidden', gas.points.some(point => point.y !== null));
            if (charts['gas-chart']) { charts['gas-chart'].canvas.style.opacity = gas.points.some(point => point.y !== null) ? 1 : 0; charts['gas-chart'].data.datasets[0].data = gas.points; charts['gas-chart'].update(); }
        }
        set('poll-status', 'Terakhir diperbarui ' + data.updated);
    }
    let pollTimer;
    let polling = false;
    const refreshButton = document.querySelector('.refresh-button');
    async function poll() {
        if (polling) return;
        polling = true;
        window.clearTimeout(pollTimer);
        if (refreshButton) { refreshButton.disabled = true; refreshButton.setAttribute('aria-busy', 'true'); }
        try {
            const response = await fetch('/dashboard/snapshot?mode=' + (snapshot.simulated ? 'simulation' : 'real') + (snapshot.terra_device_id ? '&terra_device=' + snapshot.terra_device_id : ''), {headers: {'Accept': 'application/json'}, signal: AbortSignal.timeout(8000), cache: 'no-store'});
            if (response.status === 401) { location.assign('/login'); return; }
            if (!response.ok) throw new Error('Gagal mengambil data');
            render(await response.json());
            document.getElementById('connection-indicator')?.classList.remove('connection-failed');
        } catch {
            document.getElementById('poll-status').textContent = 'Koneksi dashboard terputus · tampilan belum diperbarui';
            document.getElementById('connection-indicator')?.classList.add('connection-failed');
        } finally {
            polling = false;
            if (refreshButton) { refreshButton.disabled = false; refreshButton.removeAttribute('aria-busy'); }
            pollTimer = window.setTimeout(poll, 5000);
        }
    }
    refreshButton?.addEventListener('click', poll);
    pollTimer = window.setTimeout(poll, 5000);
}
