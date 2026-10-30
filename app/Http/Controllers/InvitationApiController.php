<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function scoped(array $c)
    {
        return DB::table('invitations')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school']);
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        $d = $request->validate(['query' => 'sometimes|string|max:120',
            'status' => 'sometimes|in:PENDING,ACCEPTED,REVOKED,EXPIRED',
            'page' => 'sometimes|integer|min:1', 'pageSize' => 'sometimes|integer|min:1|max:100']);
        $page = (int) ($d['page'] ?? 1);
        $size = (int) ($d['pageSize'] ?? 20);
        $query = $this->scoped($c);
        if (! empty($d['status'])) $query->where('status', $d['status']);
        if (trim($d['query'] ?? '') !== '') {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($d['query'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(email) LIKE LOWER(?)', [$search])
                ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$search])
                ->orWhereRaw('LOWER(last_name) LIKE LOWER(?)', [$search]));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset(($page - 1) * $size)->limit($size)->get()
            ->map(function ($r) {
                $school = $r->school_id ? DB::table('schools')->where('id', $r->school_id)->first() : null;
                $role = DB::table('roles')->where('id', $r->role_id)->first();
                return ['id' => $r->id, 'email' => $r->email, 'firstName' => $r->first_name,
                    'lastName' => $r->last_name, 'status' => $r->status, 'expiresAt' => $r->expires_at,
                    'acceptedAt' => $r->accepted_at, 'createdAt' => $r->created_at,
                    'school' => $school ? ['id' => $school->id, 'name' => $school->name] : null,
                    'role' => $role ? ['slug' => $role->slug, 'name' => $role->name] : null];
            });
        return response()->json(['items' => $rows, 'page' => $page, 'pageSize' => $size,
            'total' => $total, 'totalPages' => max(1, (int) ceil($total / $size))]);
    }

    public function revoke(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        return DB::transaction(function () use ($c, $id) {
            $invitation = $this->scoped($c)->where('id', $id)->lockForUpdate()->first();
            abort_unless($invitation, 404, 'Invitation not found');
            abort_unless($invitation->status === 'PENDING', 400, 'Invitation is no longer pending');
            $now = now();
            $this->scoped($c)->where('id', $id)->update(['status' => 'REVOKED', 'revoked_at' => $now]);
            return response()->json(['id' => $id, 'status' => 'REVOKED', 'revokedAt' => $now]);
        });
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'create');
        $d = $request->validate(['email' => 'required|email|max:191', 'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100', 'roleSlug' => 'required|string|max:100',
            'schoolId' => 'sometimes|string', 'profileData' => 'sometimes|array']);
        $school = $d['schoolId'] ?? $c['school'];
        abort_unless($school === $c['school'], 403, 'Sekolah di luar konteks aktif');
        abort_unless(DB::table('schools')->where('id', $school)->where('organization_id', $c['organization'])
            ->where('is_active', true)->exists(), 404, 'School not found in this organization');
        $role = DB::table('roles')->where('organization_id', $c['organization'])
            ->where('slug', $d['roleSlug'])->where('is_active', true)->first();
        abort_unless($role, 404, 'Role not found in this organization');
        abort_if(! in_array('super-admin', $c['roles'], true) && ! in_array($role->slug, ['student', 'teacher', 'parent'], true),
            403, 'Admin sekolah hanya dapat menambahkan siswa, guru, atau orang tua/wali');
        $profile = $d['profileData'] ?? [];
        unset($profile['nik'], $profile['nisn']);
        foreach (['phone' => 30, 'fullName' => 200, 'studentNumber' => 50] as $field => $limit)
            if (isset($profile[$field]) && is_string($profile[$field])) $profile[$field] = mb_substr(trim($profile[$field]), 0, $limit);
        if ($role->slug === 'student') {
            abort_unless(! empty($profile['studentNumber']) && ! empty($profile['fullName']), 422, 'Nomor dan nama siswa diperlukan');
            if (! empty($profile['classId'])) abort_unless(DB::table('classes')->where('id', $profile['classId'])
                ->where('school_id', $school)->exists(), 422, 'Kelas di luar sekolah undangan');
        }
        if ($role->slug === 'parent' && ! empty($profile['studentId']))
            abort_unless(DB::table('students')->where('id', $profile['studentId'])->where('school_id', $school)->exists(),
                422, 'Siswa di luar sekolah undangan');
        $email = mb_strtolower(trim($d['email']));
        abort_if(DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->where('status', 'active')->exists(),
            409, 'Pengguna aktif dengan email tersebut sudah tersedia');
        $configured = config('mail.default') === 'smtp' && config('mail.mailers.smtp.host')
            && config('mail.from.address');
        abort_if(app()->environment('production') && ! $configured, 503, 'Pengiriman email aktivasi belum dikonfigurasi');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = (string) Str::ulid();
        $expires = now()->addHours(48);
        DB::transaction(function () use ($c, $d, $school, $role, $profile, $email, $token, $id, $expires) {
            DB::table('invitations')->where('organization_id', $c['organization'])->where('email', $email)
                ->where('status', 'PENDING')->update(['status' => 'REVOKED', 'revoked_at' => now()]);
            DB::table('invitations')->insert(['id' => $id, 'organization_id' => $c['organization'],
                'school_id' => $school, 'role_id' => $role->id, 'invited_by_id' => $c['user'],
                'email' => $email, 'first_name' => $d['firstName'], 'last_name' => $d['lastName'],
                'token_hash' => hash('sha256', $token), 'profile_data' => json_encode($profile),
                'status' => 'PENDING', 'expires_at' => $expires, 'created_at' => now()]);
        });
        if ($configured) {
            try {
                $url = rtrim(config('app.url'), '/').'/aktivasi?token='.rawurlencode($token);
                Mail::raw("Halo {$d['firstName']}, aktifkan akun Sunrise Academy Anda melalui {$url}. Tautan berlaku 48 jam.",
                    fn ($message) => $message->to($email)->subject('Aktifkan akun Sunrise Academy Anda'));
            } catch (\Throwable $e) {
                DB::table('invitations')->where('id', $id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
                abort(503, 'Email aktivasi belum dapat dikirim. Undangan dibatalkan agar dapat dicoba kembali.');
            }
        }
        return response()->json(['id' => $id, 'email' => $email, 'status' => 'PENDING',
            'expiresAt' => $expires, 'createdAt' => DB::table('invitations')->where('id', $id)->value('created_at'),
            ...(! app()->environment('production') ? ['activationToken' => $token] : [])], 201);
    }

    public function accept(Request $request)
    {
        $d = $request->validate(['token' => 'required|string', 'password' => 'required|string|min:8']);
        return DB::transaction(function () use ($d) {
            $invitation = DB::table('invitations')->where('token_hash', hash('sha256', $d['token']))
                ->lockForUpdate()->first();
            abort_unless($invitation && $invitation->status === 'PENDING' && $invitation->expires_at > now(),
                400, 'Undangan tidak valid atau sudah kedaluwarsa');
            $role = DB::table('roles')->where('id', $invitation->role_id)
                ->where('organization_id', $invitation->organization_id)->where('is_active', true)->first();
            abort_unless($role, 400, 'Peran undangan tidak tersedia');
            $profile = json_decode($invitation->profile_data ?: '{}', true) ?: [];
            $existing = DB::table('users')->where('email', $invitation->email)->lockForUpdate()->first();
            abort_if($existing && $existing->organization_id && $existing->organization_id !== $invitation->organization_id,
                409, 'Email sudah terikat pada organisasi lain');
            abort_if($existing && ! in_array($existing->source_status ?? strtoupper($existing->status),
                ['DRAFT', 'PENDING_ACTIVATION', 'INACTIVE'], true), 409,
                'Akun sudah aktif. Hubungi admin untuk perubahan akses.');
            if ($existing) {
                $userId = $existing->id;
                DB::table('users')->where('id', $userId)->update([
                    'first_name' => $invitation->first_name, 'last_name' => $invitation->last_name,
                    'name' => trim($invitation->first_name.' '.$invitation->last_name),
                    'phone' => $profile['phone'] ?? $existing->phone, 'password' => Hash::make($d['password']),
                    'status' => 'active', 'source_status' => 'ACTIVE', 'activated_at' => now(), 'updated_at' => now()]);
            } else {
                $userId = (string) Str::ulid();
                DB::table('users')->insert(['id' => $userId, 'organization_id' => $invitation->organization_id,
                    'school_id' => $invitation->school_id, 'role' => $role->slug,
                    'email' => $invitation->email, 'name' => trim($invitation->first_name.' '.$invitation->last_name),
                    'first_name' => $invitation->first_name, 'last_name' => $invitation->last_name,
                    'phone' => $profile['phone'] ?? null, 'password' => Hash::make($d['password']),
                    'status' => 'active', 'source_status' => 'ACTIVE', 'activated_at' => now(),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            if (! DB::table('user_roles')->where('user_id', $userId)->where('role_id', $role->id)
                ->where('school_id', $invitation->school_id)->exists())
                DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $userId,
                    'role_id' => $role->id, 'school_id' => $invitation->school_id, 'created_at' => now()]);
            $this->activateProfile($role->slug, $invitation, $userId, $profile);
            DB::table('invitations')->where('id', $invitation->id)->update(['status' => 'ACCEPTED',
                'accepted_at' => now(), 'user_id' => $userId]);
            return response()->json(['message' => 'Akun berhasil diaktifkan. Silakan masuk.', 'userId' => $userId]);
        });
    }

    private function activateProfile(string $role, object $invitation, string $userId, array $profile): void
    {
        $school = $invitation->school_id;
        if ($role === 'student' && $school) {
            abort_unless(! empty($profile['studentNumber']) && ! empty($profile['fullName']), 422, 'Profil siswa tidak lengkap');
            if (! empty($profile['classId'])) abort_unless(DB::table('classes')->where('id', $profile['classId'])
                ->where('school_id', $school)->exists(), 422, 'Kelas di luar sekolah undangan');
            $existing = DB::table('students')->where('user_id', $userId)->first();
            abort_if($existing && $existing->school_id !== $school, 409, 'Profil siswa terikat pada sekolah lain');
            $values = ['class_id' => $profile['classId'] ?? null, 'student_number' => $profile['studentNumber'],
                'full_name' => $profile['fullName'], 'phone' => $profile['phone'] ?? null,
                'status' => 'ACTIVE', 'updated_at' => now()];
            $studentId = $existing?->id ?? (string) Str::ulid();
            if ($existing) DB::table('students')->where('id', $studentId)->update($values);
            else DB::table('students')->insert([...$values, 'id' => $studentId, 'user_id' => $userId,
                'school_id' => $school, 'activated_at' => now(), 'created_at' => now()]);
            if (! empty($profile['classId']) && ! DB::table('class_enrollments')->where('student_id', $studentId)
                ->where('class_id', $profile['classId'])->where('school_id', $school)->where('status', 'ACTIVE')->exists())
                DB::table('class_enrollments')->insert(['id' => (string) Str::ulid(), 'student_id' => $studentId,
                    'class_id' => $profile['classId'], 'school_id' => $school, 'status' => 'ACTIVE',
                    'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($role === 'teacher' && $school) {
            $existing = DB::table('teachers')->where('user_id', $userId)->first();
            abort_if($existing && $existing->school_id !== $school, 409, 'Profil guru terikat pada sekolah lain');
            $values = ['nip' => $profile['nip'] ?? null, 'full_name' => $profile['fullName'] ?? 'Guru',
                'phone' => $profile['phone'] ?? null, 'is_active' => true, 'updated_at' => now()];
            if ($existing) DB::table('teachers')->where('id', $existing->id)->update($values);
            else DB::table('teachers')->insert([...$values, 'id' => (string) Str::ulid(), 'user_id' => $userId,
                'school_id' => $school, 'created_at' => now()]);
        } elseif ($role === 'parent' && $school) {
            if (! empty($profile['studentId'])) abort_unless(DB::table('students')->where('id', $profile['studentId'])
                ->where('school_id', $school)->exists(), 422, 'Siswa di luar sekolah undangan');
            $existing = DB::table('parents')->where('user_id', $userId)->first();
            $values = ['full_name' => $profile['fullName'] ?? 'Orang Tua/Wali',
                'phone' => $profile['phone'] ?? null, 'is_active' => true, 'updated_at' => now()];
            $parentId = $existing?->id ?? (string) Str::ulid();
            if ($existing) DB::table('parents')->where('id', $parentId)->update($values);
            else DB::table('parents')->insert([...$values, 'id' => $parentId, 'user_id' => $userId, 'created_at' => now()]);
            if (! empty($profile['studentId'])) DB::table('student_parent_links')->updateOrInsert(
                ['student_id' => $profile['studentId'], 'parent_id' => $parentId],
                ['relationship' => $profile['relationship'] ?? 'WALI', 'verification_status' => 'VERIFIED',
                    'verified_at' => now(), 'verified_by' => $invitation->invited_by_id,
                    'revoked_at' => null, 'created_at' => now()]);
        }
    }
}
