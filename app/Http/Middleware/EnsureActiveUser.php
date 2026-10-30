<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            if ($request->expectsJson()) {
                abort(401, 'Silakan masuk terlebih dahulu.');
            }

            return redirect()->guest('/login');
        }

        if ((int) $request->session()->get('auth_session_version', 0) !== (int) $user->session_version) {
            Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
            abort(401, 'Sesi berakhir. Silakan masuk kembali.');
        }

        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(403, 'Akun tidak aktif. Hubungi pengelola sekolah.');
            }

            return redirect('/login')->withErrors([
                'email' => 'Akun tidak aktif. Hubungi pengelola sekolah.',
            ]);
        }

        abort_unless($user->canUseApplication(), 403, 'Akun ini belum memiliki peran yang dapat digunakan.');
        abort_unless($user->organization_id, 403);
        if ($user->school_id !== null) {
            abort_unless(\App\Models\School::query()->whereKey($user->school_id)
                ->where('organization_id', $user->organization_id)->exists(), 403);
        }

        return $next($request);
    }
}
