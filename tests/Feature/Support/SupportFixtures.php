<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Rows the support tests need on top of DatabaseSeeder. */
trait SupportFixtures
{
    protected function orgId(): string
    {
        return $this->demoUser('student')->organization_id;
    }

    protected function schoolId(): string
    {
        return $this->demoUser('student')->school_id;
    }

    protected function demoStudent(): object
    {
        return DB::table('students')->where('user_id', $this->demoUser('student')->id)->first();
    }

    /** Certificates and portfolio require the ADVANCED plan; the seed only has FREE. */
    protected function grantAdvanced(?string $organizationId = null): void
    {
        DB::table('access_grants')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organizationId ?? $this->orgId(),
            'target_type' => 'ORGANIZATION', 'access_plan_id' => DB::table('access_plans')->where('code', 'ADVANCED')->value('id'),
            'source' => 'MANUAL', 'status' => 'ACTIVE', 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function publicationConsent(string $studentId, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('consents')->insert([...['id' => $id, 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
            'student_id' => $studentId, 'granted_by_id' => $this->demoUser('parent')->id,
            'document_id' => DB::table('consent_documents')->where('organization_id', $this->orgId())->value('id'),
            'type' => 'PUBLICATION', 'status' => 'ACTIVE', 'method' => 'DIGITAL',
            'granted_at' => now()->subMinute(), 'effective_at' => now()->subMinute(), 'updated_at' => now()], ...$overrides]);

        return $id;
    }

    protected function makeUser(string $email, string $organizationId, string $schoolId, ?string $roleSlug = null): User
    {
        $user = new User;
        $user->forceFill(['name' => $email, 'email' => $email, 'password' => Hash::make('DemoLogin#2026'),
            'organization_id' => $organizationId, 'school_id' => $schoolId, 'role' => 'unmapped', 'status' => 'active', 'plan_rank' => 0])->save();
        if ($roleSlug) DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'school_id' => $schoolId,
            'role_id' => DB::table('roles')->where('organization_id', $organizationId)->where('slug', $roleSlug)->value('id'), 'created_at' => now()]);

        return $user;
    }

    /** A student (with its own user) in the given school, default the demo school. */
    protected function makeStudent(string $name, ?string $schoolId = null): string
    {
        $schoolId ??= $this->schoolId();
        $user = $this->makeUser(Str::slug($name).'@example.test', DB::table('schools')->where('id', $schoolId)->value('organization_id'), $schoolId);
        $id = (string) Str::ulid();
        DB::table('students')->insert(['id' => $id, 'user_id' => $user->id, 'school_id' => $schoolId, 'student_number' => 'S-'.Str::random(6),
            'full_name' => $name, 'status' => 'ACTIVE', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    protected function otherSchool(): string
    {
        return \App\Models\School::create(['organization_id' => $this->orgId(), 'name' => 'Sekolah Lain'])->id;
    }
}
