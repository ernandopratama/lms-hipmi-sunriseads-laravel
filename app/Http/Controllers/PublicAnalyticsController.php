<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicAnalyticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'eventName' => ['required', 'in:PAGE_VIEW,CTA_CLICK,LEAD_STARTED,LEAD_SUBMITTED,PWA_INSTALLED'],
            'consentGranted' => ['required', 'boolean'],
            'anonymousId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'properties' => ['sometimes', 'array'],
        ]);
        if (! $data['consentGranted']) {
            return response()->json(['accepted' => false, 'reason' => 'CONSENT_REQUIRED']);
        }

        $safe = [];
        foreach ($data['properties'] ?? [] as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_.-]{1,50}$/D', $key)) continue;
            if (is_string($value)) {
                $value = preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu', '$1 [DIHAPUS]', $value);
                $value = preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]', $value);
                $safe[$key] = mb_substr(preg_replace('/\s+/u', ' ', trim($value)), 0, 200);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        DB::table('analytics_events')->insert([
            'id' => (string) Str::ulid(),
            'anonymous_id' => $data['anonymousId'] ?? null,
            'event_name' => $data['eventName'],
            'properties' => json_encode($safe, JSON_THROW_ON_ERROR),
            'consent_verified' => true,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        return response()->json(['accepted' => true]);
    }
}
