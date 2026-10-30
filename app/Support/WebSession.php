<?php
namespace App\Support;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebSession
{
    public function fingerprint(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), config('app.key'));
    }
    public function touch(Request $request): object
    {
        $fingerprint = $this->fingerprint($request);
        $user = $request->user();
        $query = DB::table('user_sessions')->where('user_id', $user->id)->where('family_id', $fingerprint);
        $record = (clone $query)->first();
        if ($record) {
            abort_if($record->revoked_at || \Carbon\CarbonImmutable::parse($record->expires_at)->isPast(), 401, 'Sesi telah berakhir');
            $query->update(['last_used_at' => now(), 'expires_at' => now()->addMinutes(config('session.lifetime'))]);
            return $record;
        }
        $row = ['id' => (string) Str::ulid(), 'user_id' => $user->id, 'family_id' => $fingerprint,
            'refresh_token_hash' => hash('sha256', random_bytes(32)), 'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 2000), 'expires_at' => now()->addMinutes(config('session.lifetime')),
            'last_used_at' => now(), 'created_at' => now()];
        DB::table('user_sessions')->insert($row); return (object) $row;
    }
}
