<?php

namespace App\Support;

class Display
{
    const CATEGORIES = ['recycle' => 'Daur ulang', 'organic' => 'Organik', 'thermal_candidate' => 'Kandidat termal — perlu verifikasi', 'residue_unknown' => 'Residu / belum diketahui'];

    public static function time($date): string
    {
        return $date ? $date->setTimezone(config('monitor.timezone'))->format('d M Y H:i:s').' WITA' : 'Belum tersedia';
    }

    public static function value($value): string
    {
        return $value === null ? 'Tidak tersedia' : (is_bool($value) ? ($value ? 'Ya' : 'Tidak') : (string) $value);
    }
}
