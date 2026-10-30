<?php

namespace App\Support;

/**
 * Port of apps/api/src/common/utils/file-security.utils.ts. This is a signature and
 * active-content check, not an antivirus engine; shared hosting has no ClamAV.
 */
class FileInspector
{
    private const EXTENSIONS = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'video/mp4' => ['mp4']];

    /** @return string extension including the dot; aborts 400 on invalid metadata. */
    public static function validateMetadata(string $fileName, string $mime, int $size, array $allowed, int $max): string
    {
        abort_unless(in_array($mime, $allowed, true), 400, 'Format file tidak diizinkan');
        abort_if($size < 1 || $size > $max, 400, 'Ukuran file maksimal '.intdiv($max, 1048576).' MB');
        $name = trim(\Normalizer::isNormalized($fileName) ? $fileName : (\Normalizer::normalize($fileName, \Normalizer::FORM_KC) ?: $fileName));
        abort_if($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name), 400, 'Nama file tidak valid');
        $ext = preg_match('/\.([a-z0-9]+)$/', strtolower($name), $m) ? $m[1] : null;
        abort_unless($ext && in_array($ext, self::EXTENSIONS[$mime] ?? [], true), 400, 'Ekstensi file tidak sesuai dengan format yang dipilih');
        return '.'.$ext;
    }

    /** @return array{clean: bool, reason?: string} */
    public static function inspect(string $bytes, string $mime, int $max): array
    {
        $len = strlen($bytes);
        if ($len === 0) return ['clean' => false, 'reason' => 'EMPTY_FILE'];
        if ($len > $max) return ['clean' => false, 'reason' => 'INVALID_FILE_SIZE'];
        $ok = match ($mime) {
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'image/webp' => $len >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP',
            'video/mp4' => $len >= 12 && substr($bytes, 4, 4) === 'ftyp',
            default => false,
        };
        if (! $ok) return ['clean' => false, 'reason' => 'FILE_SIGNATURE_MISMATCH'];
        $sample = substr($bytes, 0, 1048576);
        if (str_contains($sample, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) return ['clean' => false, 'reason' => 'MALWARE_SIGNATURE'];
        if (preg_match('/<script[\s>]/i', $sample) || preg_match('/javascript\s*:/i', $sample)) return ['clean' => false, 'reason' => 'ACTIVE_CONTENT'];
        if ($mime === 'application/pdf' && preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile)\b/i', $sample)) return ['clean' => false, 'reason' => 'ACTIVE_CONTENT'];
        return ['clean' => true];
    }
}
