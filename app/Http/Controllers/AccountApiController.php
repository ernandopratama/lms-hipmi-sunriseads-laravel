<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\{WebSession, Totp};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Mail};
use Illuminate\Support\Str;

class AccountApiController extends Controller
{
    private function utcDate(?string $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value, 'UTC')->toISOString();
    }
    public function account(Request $r)
    {
        $u = $r->user(); $parts = preg_split('/\s+/', trim($u->name), 2);
        return response()->json(['id' => $u->id, 'email' => $u->email, 'firstName' => $u->first_name ?? $parts[0], 'lastName' => $u->last_name ?? ($parts[1] ?? ''), 'phone' => $u->phone,
            'status' => strtoupper($u->status), 'emailVerified' => (bool) $u->email_verified, 'mfaEnabled' => (bool) $u->mfa_enabled,
            'lastLoginAt' => $u->last_login_at, 'createdAt' => $u->created_at, 'organization' => DB::table('organizations')->where('id', $u->organization_id)->first(['name'])]);
    }
    public function update(Request $r)
    {
        $d = $r->validate(['firstName' => 'required|string|min:1|max:100', 'lastName' => 'present|string|max:100', 'phone' => 'nullable|string|max:30']);
        $name = trim(trim($d['firstName']).' '.trim($d['lastName'])); $phone = trim($d['phone'] ?? '') ?: null;
        DB::transaction(function () use ($r, $d, $name, $phone) {
            $r->user()->forceFill(['first_name' => trim($d['firstName']), 'last_name' => trim($d['lastName']), 'name' => $name, 'phone' => $phone])->save();
            // Source keeps the student/teacher/parent profile name and phone in step with the account.
            foreach (['students', 'teachers', 'parents'] as $table) DB::table($table)->where('user_id', $r->user()->id)->update(['full_name' => $name, 'phone' => $phone]);
        });
        return $this->account($r);
    }
    public function sessions(Request $r, WebSession $sessions)
    {
        $current = $sessions->fingerprint($r);
        return response()->json(DB::table('user_sessions')->where('user_id', $r->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('last_used_at')->get()->map(fn ($s) => [
            'id' => $s->id, 'ipAddress' => $s->ip_address, 'userAgent' => $s->user_agent, 'expiresAt' => $this->utcDate($s->expires_at),
            'lastUsedAt' => $this->utcDate($s->last_used_at), 'mfaVerifiedAt' => $this->utcDate($s->mfa_verified_at), 'createdAt' => $this->utcDate($s->created_at), 'current' => hash_equals($current, $s->family_id),
        ]));
    }
    public function revoke(Request $r, string $id, WebSession $sessions)
    {
        $record = DB::table('user_sessions')->where('id', $id)->where('user_id', $r->user()->id)->whereNull('revoked_at')->first(); abort_unless($record, 404, 'Sesi tidak ditemukan atau sudah berakhir');
        DB::table('user_sessions')->where('id', $id)->update(['revoked_at' => now(), 'revoke_reason' => 'USER_REVOKED']);
        if (hash_equals($sessions->fingerprint($r), $record->family_id)) { Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); }
        return response()->json(['success' => true]);
    }
    public function logoutAll(Request $r)
    {
        DB::transaction(function () use ($r) {
            DB::table('users')->where('id', $r->user()->id)->increment('session_version');
            DB::table('user_sessions')->where('user_id', $r->user()->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'LOGOUT_ALL']);
        });
        Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return response()->json(['message' => 'Seluruh perangkat telah dikeluarkan']);
    }
    public function password(Request $r, WebSession $sessions)
    {
        $d = $r->validate(['currentPassword' => 'required|string|max:4096', 'newPassword' => 'required|string|min:8|max:128']);
        $u = $r->user(); abort_unless(Hash::check($d['currentPassword'], $u->password), 422, 'Kata sandi saat ini tidak sesuai');
        abort_if(Hash::check($d['newPassword'], $u->password), 422, 'Kata sandi baru harus berbeda dari kata sandi saat ini');
        $revoked = DB::transaction(function () use ($r, $u, $d, $sessions) {
            $locked = User::query()->whereKey($u->id)->lockForUpdate()->firstOrFail();
            abort_unless(Hash::check($d['currentPassword'], $locked->password), 422, 'Kata sandi saat ini tidak sesuai');
            $version = (int) $locked->session_version + 1;
            $locked->forceFill(['password' => $d['newPassword'], 'session_version' => $version, 'failed_login_count' => 0, 'locked_until' => null])->save();
            // A reset link requested before the change must not set the password again.
            DB::table('account_reset_tokens')->where('user_id', $u->id)->whereNull('used_at')->update(['used_at' => now()]);
            $revoked = DB::table('user_sessions')->where('user_id', $u->id)->whereNull('revoked_at')->where('family_id', '!=', $sessions->fingerprint($r))->update(['revoked_at' => now(), 'revoke_reason' => 'PASSWORD_CHANGED']);
            $r->session()->put('auth_session_version', $version); return $revoked;
        });
        // Keep this device; the version invalidates untracked older sessions as well.
        DB::table('user_sessions')->where('user_id', $u->id)->where('family_id', $sessions->fingerprint($r))->update(['revoked_at' => now(), 'revoke_reason' => 'SESSION_ROTATED']);
        $u->refresh(); $r->session()->regenerate(); $sessions->touch($r);
        return response()->json(['message' => 'Kata sandi diperbarui', 'revokedOtherSessions' => $revoked]);
    }
    public function forgot(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:255']);
        $u = User::query()->where('email', $d['email'])->whereIn('status', ['active', 'pending_activation'])->first();
        if ($u) {
            $token = bin2hex(random_bytes(32));
            DB::table('account_reset_tokens')->where('user_id', $u->id)->delete();
            DB::table('account_reset_tokens')->insert(['token_hash' => hash('sha256', $token), 'user_id' => $u->id, 'expires_at' => now()->addMinutes(30), 'created_at' => now()]);
            try {
                $url = rtrim(config('app.url'), '/').'/reset-password?token='.$token;
                Mail::raw("Gunakan tautan berikut untuk mengatur ulang kata sandi Sunrise Academy. Berlaku 30 menit:\n".$url, fn ($m) => $m->to($u->email)->subject('Atur ulang kata sandi Sunrise Academy'));
            } catch (\Throwable) {
                // Avoid account enumeration and do not log reset credentials.
                DB::table('account_reset_tokens')->where('token_hash', hash('sha256', $token))->delete();
            }
        }
        return response()->json(['message' => 'Jika akun terdaftar, petunjuk pemulihan akan dikirim melalui email.']);
    }
    public function reset(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:64|regex:/^[a-f0-9]+$/D', 'password' => 'required|string|min:8|max:128']);
        DB::transaction(function () use ($d) {
            $token = DB::table('account_reset_tokens')->where('token_hash', hash('sha256', $d['token']))->lockForUpdate()->first();
            abort_unless($token && ! $token->used_at && \Carbon\CarbonImmutable::parse($token->expires_at)->isFuture(), 422, 'Token tidak valid atau telah kedaluwarsa');
            $u = User::query()->whereKey($token->user_id)->lockForUpdate()->firstOrFail();
            $u->forceFill(['password' => $d['password'], 'session_version' => (int) $u->session_version + 1, 'failed_login_count' => 0, 'locked_until' => null])->save();
            DB::table('account_reset_tokens')->where('user_id', $u->id)->update(['used_at' => now()]);
            DB::table('user_sessions')->where('user_id', $u->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoke_reason' => 'PASSWORD_RESET']);
        });
        return response()->json(['message' => 'Kata sandi berhasil diperbarui. Silakan masuk kembali.']);
    }
    public function setupMfa(Request $r)
    {
        abort_if($r->user()->mfa_enabled, 409, 'MFA sudah aktif');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $secret = '';
        for ($i = 0; $i < 32; $i++) $secret .= $alphabet[random_int(0, 31)];
        $r->session()->put('mfa_setup', ['secret' => $secret, 'expires' => time() + 600]);
        return response()->json(['secret' => $secret, 'otpauthUri' => 'otpauth://totp/'.rawurlencode('Sunrise Academy:'.$r->user()->email).'?secret='.$secret.'&issuer=Sunrise%20Academy&algorithm=SHA1&digits=6&period=30'])->header('Cache-Control', 'no-store');
    }
    public function confirmMfa(Request $r)
    {
        $d = $r->validate(['code' => 'required|string|regex:/^[0-9]{6}$/D']); $setup = $r->session()->get('mfa_setup');
        abort_unless($setup && $setup['expires'] > time() && Totp::verify($d['code'], $setup['secret']), 422, 'Kode verifikasi tidak valid');
        $r->user()->forceFill(['mfa_secret' => $setup['secret'], 'mfa_enabled' => true])->save(); $r->session()->forget('mfa_setup');
        return response()->json(['message' => 'Verifikasi dua langkah diaktifkan']);
    }
    public function disableMfa(Request $r)
    {
        $d = $r->validate(['password' => 'required|string|max:4096', 'code' => 'required|string|regex:/^[0-9]{6}$/D']); $u = $r->user();
        abort_unless($u->mfa_enabled && Hash::check($d['password'], $u->password) && Totp::verify($d['code'], $u->mfa_secret), 422, 'Verifikasi akun tidak sesuai');
        $u->forceFill(['mfa_enabled' => false, 'mfa_secret' => null])->save(); $r->session()->forget('mfa_setup');
        return response()->json(['message' => 'Verifikasi dua langkah dinonaktifkan']);
    }
}
