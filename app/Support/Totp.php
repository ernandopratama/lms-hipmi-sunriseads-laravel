<?php

namespace App\Support;

final class Totp
{
    public static function verify(string $code, string $base32Secret, ?int $time = null): bool
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) return false;
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($base32Secret, '='))) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) return false;
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split(substr($bits, 0, strlen($bits) - strlen($bits) % 8), 8) as $byte) {
            if ($byte !== '') $secret .= chr(bindec($byte));
        }
        if ($secret === '') return false;
        $counter = intdiv($time ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            $value = $counter + $offset;
            if ($value < 0) continue;
            $digest = hash_hmac('sha1', pack('N2', ($value >> 32) & 0xffffffff, $value & 0xffffffff), $secret, true);
            $position = ord($digest[19]) & 0x0f;
            $binary = ((ord($digest[$position]) & 0x7f) << 24)
                | (ord($digest[$position + 1]) << 16)
                | (ord($digest[$position + 2]) << 8)
                | ord($digest[$position + 3]);
            if (hash_equals(str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT), $code)) return true;
        }
        return false;
    }
}
