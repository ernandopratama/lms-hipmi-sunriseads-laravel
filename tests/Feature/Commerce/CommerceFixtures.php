<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Shared rows for the Commerce area tests (second tenant, extra users, store/affiliate assignments). */
trait CommerceFixtures
{
    protected function orgId(): string
    {
        return DB::table('organizations')->where('name', 'Sunrise Academy Demo')->value('id');
    }

    protected function schoolId(): string
    {
        return DB::table('schools')->where('name', 'SMK Contoh Nusantara')->value('id');
    }

    protected function studentRow(string $role = 'student'): object
    {
        return DB::table('students')->where('user_id', $this->demoUser($role)->id)->first();
    }

    protected function teacherRow(): object
    {
        return DB::table('teachers')->where('user_id', $this->demoUser('teacher')->id)->first();
    }

    protected function newSchool(?string $orgId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('schools')->insert(['id' => $id, 'organization_id' => $orgId ?? $this->orgId(), 'name' => 'Sekolah Lain '.$id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    protected function newOrganization(): array
    {
        $org = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Organisasi Lain '.$org, 'created_at' => now(), 'updated_at' => now()]);
        return [$org, $this->newSchool($org)];
    }

    /** Creates an active user holding $role in the given school (role copied into a foreign org when needed). */
    protected function makeUser(string $role, ?string $schoolId = null): User
    {
        $schoolId ??= $this->schoolId();
        $orgId = DB::table('schools')->where('id', $schoolId)->value('organization_id');
        $roleId = DB::table('roles')->where('organization_id', $orgId)->where('slug', $role)->value('id');
        if (! $roleId) {
            $base = DB::table('roles')->where('organization_id', $this->orgId())->where('slug', $role)->first();
            $roleId = (string) Str::ulid();
            DB::table('roles')->insert(['id' => $roleId, 'organization_id' => $orgId, 'slug' => $role, 'name' => $base->name, 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $base->id)->get(['p.resource', 'p.action']) as $p) {
                $permId = DB::table('permissions')->where('organization_id', $orgId)->where('resource', $p->resource)->where('action', $p->action)->value('id');
                if (! $permId) {
                    $permId = (string) Str::ulid();
                    DB::table('permissions')->insert(['id' => $permId, 'organization_id' => $orgId, 'resource' => $p->resource, 'action' => $p->action, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('role_permissions')->insert(['id' => (string) Str::ulid(), 'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now()]);
            }
        }
        $legacy = ['super-admin' => 'admin', 'school-admin' => 'admin', 'teacher' => 'teacher', 'student' => 'student'];
        $user = new User;
        $user->forceFill(['name' => ucfirst($role).' Lain', 'email' => Str::lower(Str::random(10))."@lain.example", 'password' => Hash::make('DemoLogin#2026'),
            'organization_id' => $orgId, 'school_id' => $schoolId, 'role' => $legacy[$role] ?? 'unmapped', 'status' => 'active', 'plan_rank' => 0])->save();
        DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'role_id' => $roleId, 'school_id' => $schoolId, 'created_at' => now()]);
        if ($role === 'student') DB::table('students')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'school_id' => $schoolId,
            'student_number' => 'LAIN-'.Str::random(6), 'full_name' => 'Siswa Lain', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        if ($role === 'teacher') DB::table('teachers')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'school_id' => $schoolId,
            'full_name' => 'Guru Lain', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $user;
    }

    protected function loginUser(User $user): User
    {
        $this->postJson('/api/session', ['email' => $user->email, 'password' => 'DemoLogin#2026'])->assertOk();
        return $user;
    }

    /** A course of the school with one published module, the given student enrolled, and an assignment of $type in it. */
    protected function makeAssignment(string $type, array $overrides = [], ?object $student = null): string
    {
        $student ??= $this->studentRow();
        $school = $student->school_id;
        $org = DB::table('schools')->where('id', $school)->value('organization_id');
        $course = (string) Str::ulid();
        DB::table('courses')->insert(['id' => $course, 'organization_id' => $org, 'school_id' => $school, 'title' => 'Kursus '.$type, 'name' => 'Kursus '.$type,
            'code' => 'K-'.Str::random(4), 'status' => 'published', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $module = (string) Str::ulid();
        DB::table('course_modules')->insert(['id' => $module, 'course_id' => $course, 'title' => 'Modul 1', 'position' => 1, 'minimum_rank' => 0, 'is_published' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_enrollments')->insert(['id' => (string) Str::ulid(), 'course_id' => $course, 'student_id' => $student->id, 'school_id' => $school,
            'status' => 'ACTIVE', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('assignments')->insert($overrides + ['id' => $id, 'organization_id' => $org, 'course_id' => $course, 'module_id' => $module, 'title' => 'Tugas '.$type,
            'instructions' => 'Kerjakan tugas.', 'status' => 'published', 'type' => $type, 'is_active' => true, 'published_at' => now()->subDay(),
            'start_date' => now()->subDays(10), 'end_date' => now()->addDays(10), 'max_score' => 100, 'teacher_id' => $this->teacherRow()->id,
            'created_at' => now(), 'updated_at' => now()]);
        return $overrides['id'] ?? $id;
    }

    protected function enroll(string $assignmentId, object $student): void
    {
        DB::table('course_enrollments')->insert(['id' => (string) Str::ulid(), 'course_id' => DB::table('assignments')->where('id', $assignmentId)->value('course_id'),
            'student_id' => $student->id, 'school_id' => $student->school_id, 'status' => 'ACTIVE', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
