<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\{ApiContext, WebSession};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Source /auth/{login,logout,refresh,register,me} issued JWT pairs to the Next.js BFF.
 * Laravel keeps the cookie session instead, so these delegate to the session flow and
 * never return accessToken/refreshToken.
 */
class AuthApiController extends Controller
{
    public function __construct(private SessionController $session) {}

    /** Same fields as the original JWT principal (req.user), not the UI session payload. */
    public function me(Request $request, ApiContext $context, WebSession $sessions)
    {
        $current = $this->session->current($request);
        if ($current->getStatusCode() !== 200) return $current;
        $u = $request->user();
        $student = DB::table('students')->where('user_id', $u->id)->first(['id', 'school_id']);
        $teacher = DB::table('teachers')->where('user_id', $u->id)->first(['id', 'school_id']);
        $parent = DB::table('parents')->where('user_id', $u->id)->where('is_active', true)->value('id');
        $grants = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $u->id)->get(['ur.school_id', 'r.slug']);
        $orgWideRoles = ['super-admin', 'program-manager', 'school-admin', 'support'];
        $schoolIds = collect([$student->school_id ?? null, $teacher->school_id ?? null])->merge($grants->pluck('school_id'))->filter()->unique()->values()->all();
        $session = DB::table('user_sessions')->where('user_id', $u->id)->where('family_id', $sessions->fingerprint($request))->first(['id', 'mfa_verified_at']);
        $body = ['userId' => $u->id, 'email' => $u->email, 'organizationId' => $u->organization_id,
            'schoolId' => $request->session()->get('selected_school_id', $u->school_id), 'studentId' => $student->id ?? null, 'teacherId' => $teacher->id ?? null, 'parentId' => $parent,
            'roles' => $context->roles($request) ?: [$u->role], 'permissions' => $context->permissions($request),
            'schoolAccess' => ['organizationWide' => $grants->contains(fn ($g) => $g->slug === 'super-admin' || (! $g->school_id && in_array($g->slug, $orgWideRoles, true))), 'schoolIds' => $schoolIds],
            'sessionId' => $session->id ?? null, 'mfaVerified' => (bool) ($session->mfa_verified_at ?? false), 'mfaEnabled' => (bool) $u->mfa_enabled];
        // A JSON body omits undefined fields, so a student has no teacherId and vice versa.
        return response()->json(array_filter($body, fn ($v, $k) => ! in_array($k, ['studentId', 'teacherId', 'parentId'], true) || $v !== null, ARRAY_FILTER_USE_BOTH))->header('Cache-Control', 'no-store');
    }

    public function login(Request $request)
    {
        return $this->session->login($request);
    }

    /** Valid session extends itself via the source-session middleware; otherwise 401. */
    public function refresh(Request $request)
    {
        return $this->session->current($request);
    }

    public function logout(Request $request)
    {
        if ($request->user()) $this->session->logout($request);
        return response()->noContent();
    }

    public function register(Request $request)
    {
        abort_unless(env('ALLOW_PUBLIC_REGISTRATION') === 'true' || env('ALLOW_PUBLIC_REGISTRATION') === true,
            400, 'Pendaftaran akun dilakukan melalui undangan sekolah');
        $d = $request->validate([
            'email' => 'required|email|max:255', 'password' => 'required|string|min:8|max:4096',
            'firstName' => 'required|string|max:100', 'lastName' => 'required|string|max:100',
            'phone' => 'nullable|string|max:30', 'organizationId' => 'required|string|max:191',
        ]);
        abort_if(User::query()->where('email', $d['email'])->exists(), 409, 'Email already registered');
        abort_unless(DB::table('organizations')->where('id', $d['organizationId'])->where('is_active', true)->exists(), 400, 'Invalid organization');
        $user = (new User)->forceFill([
            'organization_id' => $d['organizationId'], 'name' => trim($d['firstName'].' '.$d['lastName']), 'first_name' => $d['firstName'],
            'last_name' => $d['lastName'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'], 'password' => Hash::make($d['password']),
            'role' => 'student', 'status' => 'pending_activation', 'source_status' => 'PENDING_ACTIVATION',
        ]);
        $user->save();
        // No session is opened: activation must complete through the login flow.
        return response()->json(['user' => ['id' => $user->id, 'email' => $user->email, 'firstName' => $d['firstName'],
            'lastName' => $d['lastName'], 'status' => 'PENDING_ACTIVATION']], 201);
    }
}
