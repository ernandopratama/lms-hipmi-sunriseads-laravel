<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Contracts\Encryption\DecryptException;

class SessionController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store');
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active' || (int) $request->session()->get('auth_session_version', 0) !== (int) $user->session_version) {
            return response()->json(['message' => 'Sesi tidak valid'], 401)->header('Cache-Control', 'no-store');
        }

        if ($user->exists) app(\App\Support\WebSession::class)->touch($request);
        $parts = preg_split('/\s+/', trim($user->name), 2);
        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'firstName' => $parts[0] ?? '',
            'lastName' => $parts[1] ?? '',
            'roles' => app(\App\Support\ApiContext::class)->roles($request) ?: [$user->role],
            'permissions' => app(\App\Support\ApiContext::class)->permissions($request),
            'schoolId' => $request->session()->get('selected_school_id', $user->school_id),
            'organizationId' => $user->organization_id,
        ])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'otp' => ['sometimes', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);
        $ip = (string) $request->ip();
        $key = 'ui-login:'.hash('sha256', Str::lower($data['email']).'|'.$ip);
        $ipKey = 'ui-login-ip:'.hash('sha256', $ip);
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            return response()->json(['message' => 'Terlalu banyak percobaan masuk. Coba lagi nanti.'], 429);
        }
        RateLimiter::hit($ipKey, 60);

        $user = User::query()->where('email', $data['email'])->first();
        $eligibleStatus = $user && ($user->status === 'active'
            || ($user->status === 'pending_activation' && $user->source_status === 'PENDING_ACTIVATION'));
        if (! $user || ($user->locked_until && $user->locked_until->isFuture())
            || ! $eligibleStatus
            || ! $user->canUseApplication()
            || ! Hash::check($data['password'], $user->password)) {
            if ($user && ! $user->locked_until?->isFuture()) {
                $count = $user->failed_login_count + 1;
                $user->forceFill([
                    'failed_login_count' => $count,
                    'locked_until' => $count >= 5 ? now()->addMinutes(30) : null,
                ])->save();
            }
            RateLimiter::hit($key, 60);
            return response()->json(['message' => 'Email atau kata sandi belum sesuai'], 401);
        }

        if ($user->mfa_enabled) {
            // Imported secrets must be decrypted from the source and re-encrypted
            // with Laravel's key during ETL. Missing secrets never bypass MFA.
            try {
                $secret = $user->mfa_secret;
            } catch (DecryptException) {
                $secret = null;
            }
            if (! $secret || ! Totp::verify($data['otp'] ?? '', $secret)) {
                RateLimiter::hit($key, 60);
                return response()->json(['message' => 'Kode verifikasi diperlukan atau tidak valid'], 401);
            }
        }

        RateLimiter::clear($key);
        $changes = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()];
        if ($user->status === 'pending_activation') {
            $changes += ['status' => 'active', 'source_status' => 'ACTIVE', 'activated_at' => now()];
        }
        $user->forceFill($changes)->save();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', (int) Auth::user()->session_version);
        $request->session()->forget('selected_school_id');

        return response()->json(['user' => ['id' => $user->id, 'email' => $user->email]])
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()?->exists) {
            \Illuminate\Support\Facades\DB::table('user_sessions')->where('user_id', $request->user()->id)
                ->where('family_id', app(\App\Support\WebSession::class)->fingerprint($request))
                ->update(['revoked_at' => now(), 'revoke_reason' => 'LOGOUT']);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['success' => true])->header('Cache-Control', 'no-store');
    }

    public function schools(Request $request): JsonResponse
    {
        $user = $request->user();
        // Multi-school switching must wait for every dashboard query to honor
        // the selected context. Do not imply that broader data has changed.
        $query = School::query()->where('organization_id', $user->organization_id)
            ->whereKey($user->school_id ?: '__none__');
        return response()->json([
            'selectedSchoolId' => $request->session()->get('selected_school_id', $user->school_id),
            'schools' => $query->orderBy('name')->get(['id', 'name', 'code', 'level', 'status', 'city', 'province']),
        ])->header('Cache-Control', 'no-store');
    }

    public function selectSchool(Request $request): JsonResponse
    {
        $data = $request->validate(['schoolId' => ['required', 'string', 'max:191']]);
        $user = $request->user();
        $school = School::query()->whereKey($data['schoolId'])
            ->where('organization_id', $user->organization_id)
            ->whereKey($user->school_id ?: '__none__')
            ->first();
        if (! $school) return response()->json(['message' => 'Anda tidak memiliki akses ke sekolah tersebut'], 403);
        $request->session()->put('selected_school_id', $school->id);
        return response()->json(['school' => $school->only(['id', 'name'])]);
    }
}
