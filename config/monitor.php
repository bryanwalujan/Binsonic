<?php

return [
    'online_seconds' => (int) env('DEVICE_ONLINE_SECONDS', 60),
    'stale_seconds' => (int) env('SENSOR_STALE_SECONDS', 120),
    'timezone' => 'Asia/Makassar',
    'gas' => [
        // Display parameters only: these are NOT health/safety thresholds.
        'window_seconds' => (int) env('MQ7_TREND_WINDOW_SECONDS', 300),
        'gap_seconds' => (int) env('MQ7_TREND_GAP_SECONDS', 45),
        'min_samples_per_half' => (int) env('MQ7_TREND_MIN_SAMPLES_PER_HALF', 3),
        'absolute_delta' => (float) env('MQ7_TREND_ABSOLUTE_DELTA', 30),
        'relative_delta' => (float) env('MQ7_TREND_RELATIVE_DELTA', 0.05),
    ],
];
