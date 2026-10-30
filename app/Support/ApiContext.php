<?php
namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Resolve imported grants within the active tenant; no permissions inferred from UI. */
class ApiContext
{
    private const UNRESTRICTED_ROLES = ['super-admin', 'school-admin'];

    public function roles(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->exists) return [];
        $school = $request->session()->get('selected_school_id', $user->school_id);
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->where('r.is_active', true)
            ->where(fn ($q) => $q->whereNull('r.organization_id')->orWhere('r.organization_id', $user->organization_id))
            ->where(fn ($q) => $q->whereNull('ur.school_id')->orWhere('ur.school_id', $school))
            ->distinct()->pluck('r.slug')->all();
    }

    public function permissions(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->exists) return [];
        // Administrators hold every permission of their organization by default (super-admin as in the
        // source guard; school-admin by product decision). The school scope of resolve() still applies.
        if (array_intersect(self::UNRESTRICTED_ROLES, $this->roles($request))) {
            return DB::table('permissions')->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $user->organization_id))
                ->distinct()->get(['resource', 'action'])->map(fn ($p) => (array) $p)->all();
        }
        $school = $request->session()->get('selected_school_id', $user->school_id);
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $user->id)->where('r.is_active', true)
            ->where(fn ($q) => $q->whereNull('r.organization_id')->orWhere('r.organization_id', $user->organization_id))
            ->where(fn ($q) => $q->whereNull('p.organization_id')->orWhere('p.organization_id', $user->organization_id))
            ->where(fn ($q) => $q->whereNull('ur.school_id')->orWhere('ur.school_id', $school))
            ->distinct()->get(['p.resource', 'p.action'])->map(fn ($p) => (array) $p)->all();
    }

    public function resolve(Request $request, string $resource, string $action): array
    {
        $user = $request->user();
        abort_unless($user && $user->organization_id, 403, 'Konteks organisasi diperlukan');
        $school = $request->session()->get('selected_school_id', $user->school_id);
        abort_unless($school && DB::table('schools')->where('id', $school)->where('organization_id', $user->organization_id)->exists(), 403, 'Pilih sekolah yang valid');
        abort_unless($school === $user->school_id, 403, 'Konteks sekolah tidak sesuai');
        $roles = $this->roles($request);
        // Administrators are unrestricted, so a permission that is not (yet) a row in the table never locks them out.
        abort_unless(array_intersect(self::UNRESTRICTED_ROLES, $roles) || collect($this->permissions($request))->contains(fn ($p) => $p['resource'] === $resource && $p['action'] === $action), 403, 'Anda tidak memiliki izin untuk tindakan ini');
        $student = in_array('student', $roles, true) ? DB::table('students')->where('user_id', $user->id)->where('school_id', $school)->first() : null;
        $teacher = in_array('teacher', $roles, true) ? DB::table('teachers')->where('user_id', $user->id)->where('school_id', $school)->where('is_active', true)->first() : null;
        abort_if(in_array('student', $roles, true) && ! $student, 403, 'Profil siswa tidak tersedia');
        abort_if(in_array('teacher', $roles, true) && ! $teacher, 403, 'Profil guru tidak tersedia');
        return ['organization' => $user->organization_id, 'school' => $school, 'user' => $user->id, 'student' => $student, 'teacher' => $teacher, 'roles' => $roles];
    }
}
