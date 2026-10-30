<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

class InvitationTest extends AccessTestCase
{
    private function invite(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/backend/invitations', $body + ['email' => 'Guru.Baru@Uji.example', 'firstName' => 'Guru', 'lastName' => 'Baru',
            'roleSlug' => 'teacher', 'profileData' => ['fullName' => 'Guru Baru', 'nik' => '3201010101010001']]);
    }

    public function test_guest_and_role_without_permission_cannot_manage_invitations(): void
    {
        $this->getJson('/api/backend/invitations')->assertUnauthorized();
        $this->loginAs('teacher');

        $this->getJson('/api/backend/invitations')->assertForbidden();
        $this->invite()->assertForbidden();
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_school_admin_creates_invitation_without_sensitive_identifiers(): void
    {
        $this->loginAs('school-admin');

        $res = $this->invite()->assertCreated()->assertJsonPath('email', 'guru.baru@uji.example')->assertJsonPath('status', 'PENDING');

        $row = DB::table('invitations')->where('id', $res->json('id'))->first();
        $this->assertSame(hash('sha256', $res->json('activationToken')), $row->token_hash);
        $this->assertArrayNotHasKey('nik', json_decode($row->profile_data, true));
        $this->getJson('/api/backend/invitations')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.role.slug', 'teacher')->assertJsonPath('items.0.school.name', 'SMK Contoh Nusantara');
    }

    public function test_school_admin_cannot_invite_privileged_roles(): void
    {
        $this->loginAs('school-admin');

        foreach (['program-manager', 'school-admin', 'super-admin'] as $role) {
            $this->invite(['roleSlug' => $role])->assertForbidden()
                ->assertJsonPath('message', 'Admin sekolah hanya dapat menambahkan siswa, guru, atau orang tua/wali');
        }
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_invitation_cannot_target_another_school(): void
    {
        $other = $this->otherSchool(false);
        $this->loginAs('school-admin');

        $this->invite(['schoolId' => $other->id])->assertForbidden()->assertJsonPath('message', 'Sekolah di luar konteks aktif');
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_student_invitation_needs_profile_and_parent_needs_same_school_student(): void
    {
        $foreignStudent = DB::table('students')->where('user_id', $this->makeUser($this->otherSchool(false), 'student', 'student')->id)->value('id');
        $this->loginAs('school-admin');

        $this->invite(['roleSlug' => 'student', 'profileData' => []])->assertStatus(422)->assertJsonPath('message', 'Nomor dan nama siswa diperlukan');
        $this->invite(['roleSlug' => 'parent', 'profileData' => ['studentId' => $foreignStudent]])
            ->assertStatus(422)->assertJsonPath('message', 'Siswa di luar sekolah undangan');
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_active_email_cannot_be_invited_and_input_is_validated(): void
    {
        $this->loginAs('school-admin');

        $this->invite(['email' => 'Student@sunrise.example'])->assertStatus(409)->assertJsonPath('message', 'Pengguna aktif dengan email tersebut sudah tersedia');
        $this->invite(['email' => 'bukan-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_reinviting_revokes_previous_pending_invitation(): void
    {
        $this->loginAs('school-admin');
        $first = $this->invite()->assertCreated()->json('id');

        $this->invite()->assertCreated();

        $this->assertSame('REVOKED', DB::table('invitations')->where('id', $first)->value('status'));
        $this->assertSame(1, DB::table('invitations')->where('status', 'PENDING')->count());
    }

    public function test_revoke_pending_invitation_once(): void
    {
        $this->loginAs('school-admin');
        $id = $this->invite()->json('id');

        $this->deleteJson("/api/backend/invitations/$id")->assertOk()->assertJsonPath('status', 'REVOKED');
        $this->deleteJson("/api/backend/invitations/$id")->assertStatus(400)->assertJsonPath('message', 'Invitation is no longer pending');
    }

    public function test_invitation_of_another_school_is_invisible(): void
    {
        $other = $this->otherSchool(false);
        $id = (string) Str::ulid();
        DB::table('invitations')->insert(['id' => $id, 'organization_id' => $other->organization_id, 'school_id' => $other->id,
            'role_id' => DB::table('roles')->where('slug', 'teacher')->value('id'), 'invited_by_id' => $this->demoUser('school-admin')->id, 'email' => 'lain@uji.example', 'first_name' => 'A', 'last_name' => 'B',
            'token_hash' => hash('sha256', 'x'), 'profile_data' => '{}', 'status' => 'PENDING', 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/invitations')->assertOk()->assertJsonPath('total', 0);
        $this->deleteJson("/api/backend/invitations/$id")->assertNotFound();
        $this->assertSame('PENDING', DB::table('invitations')->where('id', $id)->value('status'));
    }

    public function test_accepting_invitation_activates_account_and_teacher_profile(): void
    {
        $this->loginAs('school-admin');
        $token = $this->invite()->json('activationToken');
        $this->deleteJson('/api/session');

        $res = $this->postJson('/api/backend/invitations/accept', ['token' => $token, 'password' => 'KataSandiBaru#1'])
            ->assertOk()->assertJsonPath('message', 'Akun berhasil diaktifkan. Silakan masuk.');

        $userId = $res->json('userId');
        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('KataSandiBaru#1', $user->password));
        $this->assertSame('Guru Baru', DB::table('teachers')->where('user_id', $userId)->value('full_name'));
        $this->assertSame('ACCEPTED', DB::table('invitations')->where('user_id', $userId)->value('status'));
        $this->postJson('/api/session', ['email' => 'guru.baru@uji.example', 'password' => 'KataSandiBaru#1'])->assertOk();
        $this->getJson('/api/backend/auth/me')->assertOk()->assertJsonPath('roles', ['teacher']);
    }

    public function test_invitation_token_is_single_use_and_expiry_enforced(): void
    {
        $this->loginAs('school-admin');
        $token = $this->invite()->json('activationToken');
        $expiredToken = $this->invite(['email' => 'kedaluwarsa@uji.example'])->json('activationToken');
        DB::table('invitations')->where('email', 'kedaluwarsa@uji.example')->update(['expires_at' => now()->subMinute()]);
        $this->deleteJson('/api/session');

        $this->postJson('/api/backend/invitations/accept', ['token' => $token, 'password' => 'pendek'])->assertStatus(422);
        $this->postJson('/api/backend/invitations/accept', ['token' => $token, 'password' => 'KataSandiBaru#1'])->assertOk();
        $this->postJson('/api/backend/invitations/accept', ['token' => $token, 'password' => 'KataSandiBaru#1'])
            ->assertStatus(400)->assertJsonPath('message', 'Undangan tidak valid atau sudah kedaluwarsa');
        $this->postJson('/api/backend/invitations/accept', ['token' => $expiredToken, 'password' => 'KataSandiBaru#1'])->assertStatus(400);
        $this->assertFalse(DB::table('users')->where('email', 'kedaluwarsa@uji.example')->exists());
    }

    public function test_accepting_student_invitation_creates_student_profile(): void
    {
        $this->loginAs('school-admin');
        $token = $this->invite(['email' => 'siswa.baru@uji.example', 'roleSlug' => 'student',
            'profileData' => ['studentNumber' => 'NIS-77', 'fullName' => 'Siswa Baru']])->json('activationToken');
        $this->deleteJson('/api/session');

        $userId = $this->postJson('/api/backend/invitations/accept', ['token' => $token, 'password' => 'KataSandiBaru#1'])->assertOk()->json('userId');

        $student = DB::table('students')->where('user_id', $userId)->first();
        $this->assertSame('NIS-77', $student->student_number);
        $this->assertSame($this->demoSchool()->id, $student->school_id);
    }
}
