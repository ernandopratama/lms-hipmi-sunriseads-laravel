<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;

/** Users, organizations, schools, access grants and effective access. */
class TenantDirectoryTest extends AccessTestCase
{
    public function test_guest_gets_401_on_directory_endpoints(): void
    {
        $this->getJson('/api/backend/users')->assertUnauthorized();
        $this->getJson('/api/backend/organizations')->assertUnauthorized();
        $this->getJson('/api/backend/schools')->assertUnauthorized();
        $this->postJson('/api/backend/access/grants', [])->assertUnauthorized();
        $this->getJson('/api/backend/access/effective')->assertUnauthorized();
    }

    public function test_role_without_master_data_permission_gets_403(): void
    {
        $this->loginAs('teacher');

        $this->getJson('/api/backend/users')->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki izin untuk tindakan ini');
        $this->getJson('/api/backend/organizations')->assertForbidden();
        $this->putJson('/api/backend/users/'.$this->demoUser('student')->id.'/status', ['status' => 'SUSPENDED'])->assertForbidden();
        $this->assertSame('active', $this->demoUser('student')->status);
    }

    public function test_user_list_is_limited_to_the_selected_school(): void
    {
        $sameOrg = $this->makeUser($this->otherSchool(false));
        $otherOrg = $this->makeUser($this->otherSchool());
        $this->loginAs('school-admin');

        $res = $this->getJson('/api/backend/users?pageSize=100')->assertOk()->assertJsonStructure(['items', 'page', 'pageSize', 'total', 'totalPages']);
        $emails = collect($res->json('items'))->pluck('email');

        $this->assertContains('student@sunrise.example', $emails);
        $this->assertNotContains($sameOrg->email, $emails);
        $this->assertNotContains($otherOrg->email, $emails);
        $this->getJson('/api/backend/users/'.$otherOrg->id)->assertNotFound()->assertJsonPath('message', 'Pengguna tidak ditemukan');
    }

    public function test_user_search_filters_by_email(): void
    {
        $this->loginAs('school-admin');

        $items = $this->getJson('/api/backend/users?query=teacher@')->assertOk()->json('items');

        $this->assertSame(['teacher@sunrise.example'], array_column($items, 'email'));
        $this->assertSame('teacher', $items[0]['userRoles'][0]['role']['slug']);
    }

    public function test_user_detail_includes_school_profile(): void
    {
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/users/'.$this->demoUser('student')->id)->assertOk()
            ->assertJsonPath('email', 'student@sunrise.example')
            ->assertJsonPath('student.student_number', 'DEMO-001');
    }

    public function test_suspending_a_user_revokes_their_sessions(): void
    {
        $student = $this->demoUser('student');
        $this->loginAs('student');
        $this->getJson('/api/backend/auth/account')->assertOk();
        $this->deleteJson('/api/session');
        $this->loginAs('school-admin');

        $this->putJson("/api/backend/users/{$student->id}/status", ['status' => 'SUSPENDED'])
            ->assertOk()->assertJsonPath('status', 'SUSPENDED');

        $fresh = $student->fresh();
        $this->assertSame('inactive', $fresh->status);
        $this->assertSame('SUSPENDED', $fresh->source_status);
        $this->assertSame(1, (int) $fresh->session_version);
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $student->id)->whereNull('revoked_at')->count());
        $this->postJson('/api/session', ['email' => 'student@sunrise.example', 'password' => 'DemoLogin#2026'])->assertUnauthorized();
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->loginAs('school-admin');

        $this->putJson("/api/backend/users/{$admin->id}/status", ['status' => 'SUSPENDED'])
            ->assertStatus(422)->assertJsonPath('message', 'Anda tidak dapat menonaktifkan akun sendiri');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_status_update_outside_school_is_not_found_and_invalid_status_rejected(): void
    {
        $other = $this->makeUser($this->otherSchool(false));
        $this->loginAs('school-admin');

        $this->putJson("/api/backend/users/{$other->id}/status", ['status' => 'SUSPENDED'])->assertNotFound();
        $this->assertSame('active', $other->fresh()->status);
        $this->putJson('/api/backend/users/'.$this->demoUser('student')->id.'/status', ['status' => 'HILANG'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_organization_endpoints_only_expose_the_own_organization(): void
    {
        $foreign = $this->otherSchool()->organization;
        $this->loginAs('school-admin');
        $own = $this->demoSchool()->organization_id;

        $this->getJson('/api/backend/organizations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $own);
        $this->getJson("/api/backend/organizations/$own")->assertOk()->assertJsonPath('schools.0.name', 'SMK Contoh Nusantara');
        $this->getJson("/api/backend/organizations/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/backend/organizations/{$foreign->id}", ['name' => 'Diambil alih'])->assertNotFound();
        $this->assertNotSame('Diambil alih', $foreign->fresh()->name);
    }

    public function test_organization_update_persists(): void
    {
        $this->loginAs('school-admin');
        $own = $this->demoSchool()->organization_id;

        $this->putJson("/api/backend/organizations/$own", ['name' => 'Sunrise Baru', 'description' => 'Deskripsi'])
            ->assertOk()->assertJsonPath('name', 'Sunrise Baru');
        $this->assertSame('Deskripsi', DB::table('organizations')->where('id', $own)->value('description'));
    }

    public function test_school_endpoints_are_scoped_to_the_organization(): void
    {
        $foreign = $this->otherSchool();
        $sibling = $this->otherSchool(false);
        $this->loginAs('school-admin');

        $names = collect($this->getJson('/api/backend/schools')->assertOk()->json())->pluck('name');
        $this->assertContains('SMK Contoh Nusantara', $names);
        $this->assertContains($sibling->name, $names);
        $this->assertNotContains($foreign->name, $names);
        $this->getJson("/api/backend/schools/{$foreign->id}")->assertNotFound()->assertJsonPath('message', 'Sekolah tidak ditemukan');
        $this->putJson("/api/backend/schools/{$foreign->id}", ['name' => 'Diambil alih'])->assertNotFound();
        $this->assertSame($foreign->name, $foreign->fresh()->name);
    }

    public function test_school_create_and_update_persist(): void
    {
        $this->loginAs('school-admin');

        $id = $this->postJson('/api/backend/schools', ['name' => 'SMA Baru', 'code' => 'SMA-01', 'level' => 'SMA'])
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->json('id');
        $this->putJson("/api/backend/schools/$id", ['city' => 'Bandung', 'status' => 'ACTIVE'])
            ->assertOk()->assertJsonPath('city', 'Bandung')->assertJsonPath('status', 'ACTIVE');

        $this->postJson('/api/backend/schools', ['name' => 'Duplikat', 'code' => 'SMA-01', 'level' => 'SMA'])
            ->assertStatus(422)->assertJsonPath('message', 'Kode sekolah sudah digunakan');
        $this->postJson('/api/backend/schools', ['name' => 'Tanpa kode'])->assertStatus(422)->assertJsonValidationErrors(['code', 'level']);
        $this->assertSame(1, DB::table('schools')->where('code', 'SMA-01')->count());
    }

    public function test_student_cannot_use_school_administration(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/schools')->assertForbidden();
    }

    public function test_access_grant_requires_permission(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/access/grants', ['targetType' => 'ORGANIZATION', 'planCode' => 'MEDIUM', 'source' => 'MANUAL', 'reason' => 'Uji coba program'])
            ->assertForbidden();
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_access_grant_for_a_user_is_created_with_event(): void
    {
        $student = $this->demoUser('student');
        $this->loginAs('school-admin');

        $res = $this->postJson('/api/backend/access/grants', ['targetType' => 'USER', 'userId' => $student->id, 'planCode' => 'MEDIUM',
            'source' => 'SCHOLARSHIP', 'reason' => 'Beasiswa semester ganjil'])
            ->assertCreated()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('userId', $student->id)->assertJsonPath('planCode', 'MEDIUM');

        $this->assertSame('CREATED', DB::table('access_grant_events')->where('access_grant_id', $res->json('id'))->value('event_type'));
    }

    public function test_access_grant_rejects_foreign_target_and_bad_shapes(): void
    {
        $foreign = $this->makeUser($this->otherSchool());
        $this->loginAs('school-admin');
        $base = ['planCode' => 'MEDIUM', 'source' => 'MANUAL', 'reason' => 'Uji coba program'];

        $this->postJson('/api/backend/access/grants', $base + ['targetType' => 'USER', 'userId' => $foreign->id])
            ->assertForbidden()->assertJsonPath('message', 'Target access grant berada di luar organisasi aktif');
        $this->postJson('/api/backend/access/grants', $base + ['targetType' => 'ORGANIZATION', 'userId' => $foreign->id])
            ->assertStatus(400)->assertJsonPath('message', 'Grant organisasi tidak boleh memiliki target tambahan');
        $this->postJson('/api/backend/access/grants', $base + ['targetType' => 'ORGANIZATION', 'startsAt' => '2026-12-01', 'endsAt' => '2026-11-01'])
            ->assertStatus(400)->assertJsonPath('message', 'Tanggal berakhir harus setelah tanggal mulai');
        $this->postJson('/api/backend/access/grants', ['targetType' => 'ORGANIZATION', 'planCode' => 'MEDIUM', 'source' => 'MANUAL', 'reason' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_future_access_grant_is_scheduled(): void
    {
        $this->loginAs('school-admin');

        $this->postJson('/api/backend/access/grants', ['targetType' => 'ORGANIZATION', 'planCode' => 'ADVANCED', 'source' => 'PILOT',
            'reason' => 'Pilot tahun depan', 'startsAt' => now()->addMonth()->toIso8601String()])
            ->assertCreated()->assertJsonPath('status', 'SCHEDULED');
    }

    public function test_effective_access_for_student_uses_user_grant(): void
    {
        $student = $this->demoUser('student');
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/access/grants', ['targetType' => 'USER', 'userId' => $student->id, 'planCode' => 'ADVANCED',
            'source' => 'MANUAL', 'reason' => 'Akses penuh siswa'])->assertCreated();
        $this->deleteJson('/api/session');
        $this->loginAs('student');

        $this->getJson('/api/backend/access/effective')->assertOk()
            ->assertJsonPath('plan.code', 'ADVANCED')
            ->assertJsonStructure(['plan', 'grant', 'source', 'entitlements', 'resolvedAt']);
    }
}
