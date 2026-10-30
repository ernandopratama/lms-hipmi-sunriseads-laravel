<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/** Encryption and blind index for student identifiers (NIK/NISN). */
class IdentifierCrypto
{
    public static function blindIndex(string $value, string $type): string
    {
        $key = env('BLIND_INDEX_KEY') ?: (env('ENCRYPTION_KEY') ?: config('app.key'));
        return hash_hmac('sha256', $type.':'.strtoupper(preg_replace('/\s+/', '', $value)), $key);
    }

    public static function encrypt(string $value): string
    {
        return Crypt::encryptString($value);
    }

    public static function decrypt(string $value): string
    {
        return Crypt::decryptString($value);
    }
}
