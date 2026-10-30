<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

class AccountSecurityTest extends AccessTestCase
{
    private function otherSessionRow(string $userId): string
    {
        $id = (string) Str::ulid();
        DB::table('user_sessions')->insert(['id' => $id, 'user_id' => $userId, 'family_id' => hash('sha256', $id),
            'refresh_token_hash' => hash('sha256', $id.'x'), 'ip_address' => '10.0.0.9', 'user_agent' => 'Perangkat lain',
            'expires_at' => now()->addHour(), 'last_used_at' => now()->subMinute(), 'created_at' => now()]);
        return $id;
    }

    public function test_guest_cannot_read_account(): void
    {
        $this->getJson('/api/backend/auth/account')->assertUnauthorized();
        $this->getJson('/api/backend/auth/me')->assertUnauthorized();
        $this->postJson('/api/backend/auth/change-password', [])->assertUnauthorized();
    }

    public function test_account_returns_profile_fields(): void
    {
        $this->loginAs('teacher');

        $this->getJson('/api/backend/auth/account')->assertOk()
            ->assertJsonPath('email', 'teacher@sunrise.example')
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('mfaEnabled', false)
            ->assertJsonPath('organization.name', 'Sunrise Academy Demo');
    }

    public function test_update_account_persists_name_and_phone(): void
    {
        $user = $this->loginAs('teacher');

        $this->patchJson('/api/backend/auth/account', ['firstName' => ' Budi ', 'lastName' => 'Santoso', 'phone' => '0812'])
            ->assertOk()->assertJsonPath('firstName', 'Budi')->assertJsonPath('lastName', 'Santoso');

        $this->assertSame('Budi Santoso', $user->fresh()->name);
        $this->assertSame('0812', $user->fresh()->phone);
    }

    public function test_update_account_keeps_teacher_profile_in_step(): void
    {
        $user = $this->loginAs('teacher');

        $this->patchJson('/api/backend/auth/account', ['firstName' => 'Budi', 'lastName' => 'Santoso', 'phone' => '0812'])->assertOk();

        $teacher = DB::table('teachers')->where('user_id', $user->id)->first();
        $this->assertSame('Budi Santoso', $teacher->full_name);
        $this->assertSame('0812', $teacher->phone);
    }

    public function test_update_account_requires_first_name(): void
    {
        $user = $this->loginAs('teacher');

        $this->patchJson('/api/backend/auth/account', ['lastName' => 'X'])->assertStatus(422);
        $this->assertSame('Guru Demo', $user->fresh()->name);
    }

    public function test_me_returns_principal_with_student_profile(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/auth/me')->assertOk()
            ->assertJsonPath('email', 'student@sunrise.example')
            ->assertJsonPath('roles', ['student'])
            ->assertJsonPath('studentId', $this->studentId())
            ->assertJsonPath('schoolAccess.organizationWide', false)
            ->assertJsonMissingPath('teacherId');
    }

    public function test_public_registration_is_closed_by_default(): void
    {
        $this->postJson('/api/backend/auth/register', ['email' => 'baru@uji.example'])
            ->assertStatus(400)->assertJsonPath('message', 'Pendaftaran akun dilakukan melalui undangan sekolah');
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->loginAs('teacher');

        $this->postJson('/api/backend/auth/change-password', ['currentPassword' => 'salah-sekali', 'newPassword' => 'KataSandiBaru#1'])
            ->assertStatus(422)->assertJsonPath('message', 'Kata sandi saat ini tidak sesuai');
        $this->assertTrue(Hash::check('DemoLogin#2026', $user->fresh()->password));
    }

    public function test_change_password_rejects_reusing_current_password(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/auth/change-password', ['currentPassword' => 'DemoLogin#2026', 'newPassword' => 'DemoLogin#2026'])
            ->assertStatus(422)->assertJsonPath('message', 'Kata sandi baru harus berbeda dari kata sandi saat ini');
    }

    public function test_change_password_invalidates_pending_reset_link(): void
    {
        $this->postJson('/api/backend/auth/forgot-password', ['email' => 'teacher@sunrise.example'])->assertOk();
        preg_match('/token=([a-f0-9]{64})/', $this->sentMail()[0]->getOriginalMessage()->getTextBody(), $m);
        $user = $this->loginKeepingSession('teacher');

        $this->postJson('/api/backend/auth/change-password', ['currentPassword' => 'DemoLogin#2026', 'newPassword' => 'KataSandiBaru#1'])->assertOk();

        $this->postJson('/api/backend/auth/reset-password', ['token' => $m[1], 'password' => 'KataSandiPenyerang#9'])
            ->assertStatus(422)->assertJsonPath('message', 'Token tidak valid atau telah kedaluwarsa');
        $this->assertTrue(Hash::check('KataSandiBaru#1', $user->fresh()->password));
    }

    public function test_change_password_requires_minimum_length(): void
    {
        $user = $this->loginAs('teacher');

        $this->postJson('/api/backend/auth/change-password', ['currentPassword' => 'DemoLogin#2026', 'newPassword' => 'pendek'])
            ->assertStatus(422)->assertJsonValidationErrors('newPassword');
        $this->assertTrue(Hash::check('DemoLogin#2026', $user->fresh()->password));
    }

    public function test_change_password_keeps_this_device_and_revokes_others(): void
    {
        $user = $this->loginKeepingSession('teacher');
        $this->getJson('/api/backend/auth/account')->assertOk();
        $other = $this->otherSessionRow($user->id);

        $this->postJson('/api/backend/auth/change-password', ['currentPassword' => 'DemoLogin#2026', 'newPassword' => 'KataSandiBaru#1'])
            ->assertOk()->assertJsonPath('message', 'Kata sandi diperbarui')->assertJsonPath('revokedOtherSessions', 1);

        $this->assertSame('PASSWORD_CHANGED', DB::table('user_sessions')->where('id', $other)->value('revoke_reason'));
        $this->assertTrue(Hash::check('KataSandiBaru#1', $user->fresh()->password));
        // The browser receives the regenerated session cookie and stays signed in.
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->getJson('/api/backend/auth/account')->assertOk();
    }

    public function test_sessions_list_marks_current_device(): void
    {
        $user = $this->loginKeepingSession('teacher');
        $this->getJson('/api/backend/auth/account')->assertOk();
        $other = $this->otherSessionRow($user->id);

        $rows = collect($this->getJson('/api/backend/auth/sessions')->assertOk()->json());

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->firstWhere('current', true) !== null);
        $this->assertFalse($rows->firstWhere('id', $other)['current']);
    }

    public function test_revoke_other_session_marks_it_revoked(): void
    {
        $user = $this->loginKeepingSession('teacher');
        $other = $this->otherSessionRow($user->id);

        $this->deleteJson("/api/backend/auth/sessions/$other")->assertOk()->assertJsonPath('success', true);

        $this->assertSame('USER_REVOKED', DB::table('user_sessions')->where('id', $other)->value('revoke_reason'));
        $this->deleteJson("/api/backend/auth/sessions/$other")->assertNotFound()->assertJsonPath('message', 'Sesi tidak ditemukan atau sudah berakhir');
        $this->getJson('/api/backend/auth/account')->assertOk();
    }

    public function test_cannot_revoke_another_users_session(): void
    {
        $foreign = $this->otherSessionRow($this->demoUser('student')->id);
        $this->loginAs('teacher');

        $this->deleteJson("/api/backend/auth/sessions/$foreign")->assertNotFound();
        $this->assertNull(DB::table('user_sessions')->where('id', $foreign)->value('revoked_at'));
    }

    public function test_logout_all_ends_every_session(): void
    {
        $user = $this->loginKeepingSession('teacher');
        $other = $this->otherSessionRow($user->id);
        $version = (int) $user->session_version;

        $this->postJson('/api/backend/auth/logout-all')->assertOk()->assertJsonPath('message', 'Seluruh perangkat telah dikeluarkan');

        $this->assertSame($version + 1, (int) $user->fresh()->session_version);
        $this->assertSame('LOGOUT_ALL', DB::table('user_sessions')->where('id', $other)->value('revoke_reason'));
        $this->getJson('/api/backend/auth/account')->assertUnauthorized();
    }

    public function test_mfa_setup_and_confirm_enables_mfa_and_login_then_needs_code(): void
    {
        $user = $this->loginAs('teacher');
        $secret = $this->postJson('/api/backend/auth/mfa/setup')->assertOk()->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);

        $this->postJson('/api/backend/auth/mfa/confirm', ['code' => $this->totp($secret)])
            ->assertOk()->assertJsonPath('message', 'Verifikasi dua langkah diaktifkan');
        $this->assertTrue($user->fresh()->mfa_enabled);
        $this->assertSame($secret, $user->fresh()->mfa_secret);

        $this->deleteJson('/api/session')->assertOk();
        $this->postJson('/api/session', ['email' => 'teacher@sunrise.example', 'password' => 'DemoLogin#2026'])
            ->assertUnauthorized()->assertJsonPath('message', 'Kode verifikasi diperlukan atau tidak valid');
        $this->postJson('/api/session', ['email' => 'teacher@sunrise.example', 'password' => 'DemoLogin#2026', 'otp' => $this->totp($secret)])
            ->assertOk();
    }

    public function test_mfa_confirm_rejects_wrong_code(): void
    {
        $user = $this->loginAs('teacher');
        $secret = $this->postJson('/api/backend/auth/mfa/setup')->assertOk()->json('secret');
        $wrong = str_pad((string) (((int) $this->totp($secret) + 500000) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->postJson('/api/backend/auth/mfa/confirm', ['code' => $wrong])
            ->assertStatus(422)->assertJsonPath('message', 'Kode verifikasi tidak valid');
        $this->assertFalse($user->fresh()->mfa_enabled);
    }

    public function test_mfa_setup_is_refused_when_already_enabled(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $this->demoUser('teacher')->forceFill(['mfa_enabled' => true, 'mfa_secret' => $secret])->save();
        $this->loginKeepingSession('teacher', $this->totp($secret));

        $this->postJson('/api/backend/auth/mfa/setup')->assertStatus(409)->assertJsonPath('message', 'MFA sudah aktif');
    }

    public function test_mfa_disable_needs_password_and_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $this->demoUser('teacher')->forceFill(['mfa_enabled' => true, 'mfa_secret' => $secret])->save();
        $user = $this->loginKeepingSession('teacher', $this->totp($secret));

        $this->postJson('/api/backend/auth/mfa/disable', ['password' => 'salah-sekali', 'code' => $this->totp($secret)])
            ->assertStatus(422)->assertJsonPath('message', 'Verifikasi akun tidak sesuai');
        $this->assertTrue($user->fresh()->mfa_enabled);

        $this->postJson('/api/backend/auth/mfa/disable', ['password' => 'DemoLogin#2026', 'code' => $this->totp($secret)])
            ->assertOk()->assertJsonPath('message', 'Verifikasi dua langkah dinonaktifkan');
        $this->assertFalse($user->fresh()->mfa_enabled);
        $this->assertNull($user->fresh()->getRawOriginal('mfa_secret'));
    }

    public function test_forgot_password_answers_the_same_for_unknown_email(): void
    {
        $this->postJson('/api/backend/auth/forgot-password', ['email' => 'tidak-ada@uji.example'])
            ->assertOk()->assertJsonPath('message', 'Jika akun terdaftar, petunjuk pemulihan akan dikirim melalui email.');

        $this->assertCount(0, $this->sentMail());
        $this->assertSame(0, DB::table('account_reset_tokens')->count());
    }

    public function test_forgot_then_reset_password_changes_password_once(): void
    {
        $user = $this->demoUser('teacher');
        $other = $this->otherSessionRow($user->id);
        $this->postJson('/api/backend/auth/forgot-password', ['email' => 'teacher@sunrise.example'])->assertOk();

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        preg_match('/token=([a-f0-9]{64})/', $mail[0]->getOriginalMessage()->getTextBody(), $m);
        $token = $m[1];

        $this->postJson('/api/backend/auth/reset-password', ['token' => $token, 'password' => 'KataSandiBaru#1'])
            ->assertOk()->assertJsonPath('message', 'Kata sandi berhasil diperbarui. Silakan masuk kembali.');
        $this->assertTrue(Hash::check('KataSandiBaru#1', $user->fresh()->password));
        $this->assertSame('PASSWORD_RESET', DB::table('user_sessions')->where('id', $other)->value('revoke_reason'));

        $this->postJson('/api/backend/auth/reset-password', ['token' => $token, 'password' => 'KataSandiLain#2'])
            ->assertStatus(422)->assertJsonPath('message', 'Token tidak valid atau telah kedaluwarsa');
        $this->assertTrue(Hash::check('KataSandiBaru#1', $user->fresh()->password));
    }

    public function test_reset_password_rejects_unknown_token(): void
    {
        $this->postJson('/api/backend/auth/reset-password', ['token' => str_repeat('a', 64), 'password' => 'KataSandiBaru#1'])
            ->assertStatus(422)->assertJsonPath('message', 'Token tidak valid atau telah kedaluwarsa');
        $this->postJson('/api/backend/auth/reset-password', ['token' => 'pendek', 'password' => 'KataSandiBaru#1'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
    }
}
