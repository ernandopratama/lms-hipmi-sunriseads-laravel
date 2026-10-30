<?php

namespace Tests\Feature\SchoolData;

use App\Models\{Organization, School, User};
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;

/** Tenant fixtures shared by the SchoolData feature tests. */
trait SchoolDataHelpers
{
    protected function login(User $user): User
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/session', ['email' => $user->email, 'password' => 'DemoLogin#2026'])->assertOk();

        return $user;
    }

    protected function demoSchoolId(): string
    {
        return $this->demoUser('school-admin')->school_id;
    }

    protected function demoOrgId(): string
    {
        return $this->demoUser('school-admin')->organization_id;
    }

    protected function makeUser(string $role, string $org, string $school, ?string $email = null): User
    {
        $user = new User();
        $user->forceFill(['name' => "User $role", 'email' => $email ?? Str::lower(Str::random(8))."-$role@other.example", 'password' => 'DemoLogin#2026',
            'organization_id' => $org, 'school_id' => $school, 'role' => in_array($role, ['school-admin', 'super-admin'], true) ? 'admin' : $role, 'status' => 'active', 'plan_rank' => 0])->save();
        $roleId = DB::table('roles')->where('organization_id', $org)->where('slug', $role)->value('id');
        DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'role_id' => $roleId, 'school_id' => $school, 'created_at' => now()]);

        return $user;
    }

    /** A second school in the demo organization, with its own school-admin. */
    protected function otherSchool(): array
    {
        $school = School::create(['organization_id' => $this->demoOrgId(), 'name' => 'SMK Lain']);

        return ['school' => $school->id, 'org' => $this->demoOrgId(), 'admin' => $this->makeUser('school-admin', $this->demoOrgId(), $school->id)];
    }

    /** A separate organization with baseline roles, one school and its school-admin. */
    protected function otherOrganization(): array
    {
        $org = Organization::create(['name' => 'Organisasi Lain']);
        Artisan::call('sunrise:seed-baseline', ['--organization' => $org->id]);
        $school = School::create(['organization_id' => $org->id, 'name' => 'SMK Organisasi Lain']);

        return ['school' => $school->id, 'org' => $org->id, 'admin' => $this->makeUser('school-admin', $org->id, $school->id)];
    }

    protected function makeStudent(string $school, string $number, ?string $userId = null, array $extra = []): string
    {
        $userId ??= $this->makeUser('student', DB::table('schools')->where('id', $school)->value('organization_id'), $school)->id;
        $id = (string) Str::ulid();
        DB::table('students')->insert(['id' => $id, 'user_id' => $userId, 'school_id' => $school, 'student_number' => $number, 'full_name' => "Siswa $number",
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()] + $extra);

        return $id;
    }

    protected function makeClass(string $school, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('classes')->insert(['id' => $id, 'school_id' => $school, 'name' => "Kelas $code", 'code' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** Organization-wide ADVANCED plan: unlocks monitoring dashboards and report export. */
    protected function grantAdvanced(?string $org = null): void
    {
        DB::table('access_grants')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org ?? $this->demoOrgId(), 'target_type' => 'ORGANIZATION',
            'access_plan_id' => DB::table('access_plans')->where('code', 'ADVANCED')->value('id'), 'source' => 'MANUAL', 'status' => 'ACTIVE',
            'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
