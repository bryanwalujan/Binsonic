@props(['name' => 'grid'])
@php
$icons = [
'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
'leaf'=>'<path d="M20 4c1 11-4 17-10 15C2 17 3 7 10 6c4 0 6 0 10-2Z"/><path d="M4 21 15 10"/>',
'layers'=>'<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
'cpu'=>'<rect x="6" y="6" width="12" height="12" rx="3"/><path d="M9 1v5m6-5v5M9 18v5m6-5v5M1 9h5m-5 6h5m12-6h5m-5 6h5"/><rect x="9" y="9" width="6" height="6" rx="1"/>',
'thermometer'=>'<path d="M9 14.5V5a3 3 0 0 1 6 0v9.5a5 5 0 1 1-6 0Z"/><path d="M12 8v10"/><circle cx="12" cy="18" r="1"/>',
'chart'=>'<path d="M3 3v18h18M7 14l4-4 4 2 6-7"/>',
'refresh'=>'<path d="M20 7a9 9 0 0 0-15-2L2 8m0-6v6h6m-4 9a9 9 0 0 0 15 2l3-3m0 6v-6h-6"/>',
'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
'logout'=>'<path d="M9 4H4v16h5m6-12 4 4-4 4M8 12h12"/>',
'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
'recycle'=>'<path d="m8 7 4-5 4 5m-4-5v9M7 11l-5 8h8m-8 0 5-3m10-5 5 8h-8m8 0-5-3"/>',
'flame'=>'<path d="M12 2c3 6-3 7 1 10 1-3 3-3 4-5 6 8 2 15-5 15S1 15 8 8c0 3 2 4 2 4-1-4 2-6 2-10Z"/>',
'box'=>'<path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm-9 5 9 5 9-5M12 13v9M8 5l9 5"/>',
'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4m0 3h.01"/>',
'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
'radio'=>'<path d="M5 5a10 10 0 0 0 0 14M19 5a10 10 0 0 1 0 14M8 8a6 6 0 0 0 0 8m8-8a6 6 0 0 1 0 8"/><circle cx="12" cy="12" r="1"/>',
'plus'=>'<path d="M12 5v14M5 12h14"/>',
'filter'=>'<path d="M4 7h16M7 12h10M10 17h4"/>',
'copy'=>'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
'check'=>'<path d="m5 12 4 4L19 6"/>',
];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$name] ?? $icons['grid'] !!}</svg>
