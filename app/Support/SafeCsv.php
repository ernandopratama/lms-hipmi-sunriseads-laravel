<?php

namespace App\Support;

/** Spreadsheet-safe CSV; port of apps/api/src/monitoring/monitoring.utils.ts. */
class SafeCsv
{
    public static function cell(mixed $value): string
    {
        $text = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        if (preg_match('/^[=+\-@\t\r]/', $text)) $text = "'".$text;
        return '"'.str_replace('"', '""', $text).'"';
    }

    public static function build(array $rows): string
    {
        if (! $rows) return "\u{FEFF}status\r\n\"Tidak ada data pada filter yang dipilih\"\r\n";
        $headers = [];
        foreach ($rows as $row) foreach (array_keys($row) as $k) $headers[$k] = true;
        $headers = array_keys($headers);
        $lines = array_map(fn ($row) => implode(',', array_map(fn ($h) => self::cell($row[$h] ?? ''), $headers)), $rows);
        return "\u{FEFF}".implode(',', array_map([self::class, 'cell'], $headers))."\r\n".implode("\r\n", $lines)."\r\n";
    }
}
