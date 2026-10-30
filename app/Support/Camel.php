<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Table row to the camelCase object a Prisma model would serialise to. */
class Camel
{
    /** @param array<string> $json columns holding JSON text to decode */
    public static function row(object|array|null $row, array $json = [], array $only = []): ?array
    {
        if ($row === null) return null;
        $out = [];
        foreach ((array) $row as $column => $value) {
            if ($only && ! in_array($column, $only, true)) continue;
            $out[Str::camel($column)] = in_array($column, $json, true) && is_string($value) ? json_decode($value, true) : $value;
        }
        return $out;
    }
}
