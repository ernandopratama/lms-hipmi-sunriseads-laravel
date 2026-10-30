<?php

namespace App\Support;

/** Recognises the two embeddable providers and builds a privacy-friendly embed URL. */
class VideoSource
{
    /** @return array{provider: string, videoId: string, embedUrl: string}|null */
    public static function parse(?string $url): ?array
    {
        if (! $url) return null;
        $p = parse_url(trim($url));
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) return null;
        $host = preg_replace('/^(www\.|m\.)/', '', strtolower($p['host'] ?? ''));
        $segments = array_values(array_filter(explode('/', $p['path'] ?? ''), fn ($s) => $s !== ''));
        parse_str($p['query'] ?? '', $query);
        $youtube = '/^[A-Za-z0-9_-]{11}$/';
        $id = match (true) {
            $host === 'youtu.be' => $segments[0] ?? null,
            in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && ($segments[0] ?? '') === 'watch' => $query['v'] ?? null,
            in_array($host, ['youtube.com', 'youtube-nocookie.com'], true) && in_array($segments[0] ?? '', ['embed', 'shorts', 'live'], true) => $segments[1] ?? null,
            default => null,
        };
        if (is_string($id) && preg_match($youtube, $id)) {
            return ['provider' => 'YOUTUBE', 'videoId' => $id, 'embedUrl' => "https://www.youtube-nocookie.com/embed/{$id}?enablejsapi=1&rel=0&playsinline=1"];
        }
        $vimeo = match (true) {
            $host === 'vimeo.com' => end($segments) ?: null,
            $host === 'player.vimeo.com' && ($segments[0] ?? '') === 'video' => $segments[1] ?? null,
            default => null,
        };
        if (is_string($vimeo) && preg_match('/^\d{6,12}$/', $vimeo)) {
            return ['provider' => 'VIMEO', 'videoId' => $vimeo, 'embedUrl' => "https://player.vimeo.com/video/{$vimeo}?dnt=1"];
        }
        return null;
    }
}
