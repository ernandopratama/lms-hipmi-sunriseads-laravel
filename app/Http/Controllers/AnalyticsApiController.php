<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'analytics', 'create');
        $d = $request->validate(['eventName' => 'required|in:PAGE_VIEW,CTA_CLICK,LEAD_STARTED,LEAD_SUBMITTED,PWA_INSTALLED',
            'properties' => 'sometimes|array', 'consentGranted' => 'required|boolean',
            'anonymousId' => 'sometimes|nullable|string|max:100']);
        if (! $d['consentGranted']) return response()->json(['accepted' => false, 'reason' => 'CONSENT_REQUIRED']);
        $safe = [];
        foreach ($d['properties'] ?? [] as $key => $value) {
            if (! preg_match('/^[a-zA-Z0-9_.-]{1,50}$/', (string) $key)) continue;
            if ($value === null || is_numeric($value) || is_bool($value) || is_string($value)) {
                if (is_string($value)) {
                    $value = trim(preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]',
                        preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu',
                            '$1 [DIHAPUS]', $value)));
                    $value = mb_substr(preg_replace('/\s+/u', ' ', $value), 0, 200);
                }
                $safe[$key] = $value;
            }
        }
        DB::table('analytics_events')->insert(['id' => (string) Str::ulid(),
            'organization_id' => $c['organization'], 'school_id' => $c['school'],
            'user_id' => $c['user'], 'anonymous_id' => $d['anonymousId'] ?? null,
            'event_name' => $d['eventName'], 'properties' => json_encode($safe),
            'consent_verified' => true, 'occurred_at' => now(), 'created_at' => now()]);
        return response()->json(['accepted' => true]);
    }
}
