<?php

namespace App\Support;

/** Port of apps/api/src/affiliate/affiliate.utils.ts URL helpers. */
class AffiliateUrl
{
    private const HOSTS = [
        'TIKTOK' => ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com', 'vm.tiktok.com'],
        'SHOPEE' => ['shopee.co.id', 'www.shopee.co.id', 'shopee.com', 'www.shopee.com', 's.shopee.co.id'],
        'INSTAGRAM' => ['instagram.com', 'www.instagram.com'],
        'YOUTUBE' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
    ];
    private const TRACKING = ['fbclid', 'gclid', 'igshid', 'spm'];

    private static function parse(string $value, string $label): array
    {
        $p = parse_url(trim($value));
        abort_if($p === false || ! isset($p['scheme'], $p['host']), 400, "$label tidak valid");
        abort_unless(in_array(strtolower($p['scheme']), ['http', 'https'], true), 400, "$label harus menggunakan HTTP atau HTTPS");
        abort_if(isset($p['user']) || isset($p['pass']), 400, "$label tidak boleh memuat kredensial");
        return $p;
    }

    public static function normalizeContent(string $value, string $platform): string
    {
        $p = self::parse($value, 'URL konten');
        $host = strtolower($p['host']);
        abort_unless(in_array($host, self::HOSTS[$platform] ?? [], true), 400, "Domain URL tidak sesuai dengan platform $platform");
        $path = preg_replace('#/{2,}#', '/', $p['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $k) {
            if (in_array(strtolower($k), self::TRACKING, true) || str_starts_with(strtolower($k), 'utm_')) unset($query[$k]);
        }
        $url = 'https://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').$path;
        if ($path === '/' && $query) $url .= '?'.http_build_query($query);
        elseif ($query) $url .= '?'.http_build_query($query);
        return rtrim($url, '/');
    }

    public static function normalizePublic(string $value, string $label = 'URL bukti'): string
    {
        $p = self::parse($value, $label);
        $host = strtolower(trim($p['host'], '[]'));
        abort_if(self::isPrivate($host), 400, "$label harus mengarah ke alamat publik");
        return 'https://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    public static function externalId(string $normalized, string $platform): ?string
    {
        $p = parse_url($normalized);
        $segments = array_values(array_filter(explode('/', $p['path'] ?? ''), fn ($s) => $s !== ''));
        parse_str($p['query'] ?? '', $query);
        return match ($platform) {
            'YOUTUBE' => ($p['host'] ?? '') === 'youtu.be' ? ($segments[0] ?? null) : ($query['v'] ?? (end($segments) ?: null)),
            'TIKTOK' => ($i = array_search('video', $segments, true)) !== false ? ($segments[$i + 1] ?? null) : null,
            'INSTAGRAM' => in_array($segments[0] ?? '', ['reel', 'reels', 'p'], true) ? ($segments[1] ?? null) : null,
            default => $segments ? end($segments) : null,
        };
    }

    public static function username(string $normalized, string $platform): ?string
    {
        $segments = array_values(array_filter(explode('/', parse_url($normalized, PHP_URL_PATH) ?: ''), fn ($s) => $s !== ''));
        $first = $segments[0] ?? null;
        if ($platform === 'TIKTOK' && $first && str_starts_with($first, '@')) return strtolower(substr($first, 1));
        if ($platform === 'INSTAGRAM' && $first && ! in_array($first, ['p', 'reel', 'reels'], true)) return strtolower(ltrim($first, '@'));
        return null;
    }

    private static function isPrivate(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) return true;
        if (in_array($host, ['metadata.google.internal', '169.254.169.254'], true)) return true;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b] = array_map('intval', explode('.', $host));
            return $a === 0 || $a === 10 || $a === 127 || ($a === 169 && $b === 254) || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) || $a >= 224;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $l = strtolower($host);
            return $l === '::' || $l === '::1' || preg_match('/^(fc|fd|fe[89ab])/', $l) || preg_match('/^::ffff:(127|10|192\.168)\./', $l);
        }
        return false;
    }
}
