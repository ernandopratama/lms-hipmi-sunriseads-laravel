<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionTest extends TestCase
{
    public function test_login_with_valid_password_starts_a_session(): void
    {
        $this->postJson('/api/session', ['email' => 'teacher@sunrise.example', 'password' => 'DemoLogin#2026'])
            ->assertOk()->assertJsonPath('user.email', 'teacher@sunrise.example');

        $this->getJson('/api/session')->assertOk()
            ->assertJsonPath('email', 'teacher@sunrise.example')
            ->assertJsonPath('roles', ['teacher']);
    }

    public function test_wrong_password_is_rejected_and_counted(): void
    {
        $this->postJson('/api/session', ['email' => 'teacher@sunrise.example', 'password' => 'salah-sekali'])
            ->assertUnauthorized()->assertJsonPath('message', 'Email atau kata sandi belum sesuai');

        $this->assertSame(1, $this->demoUser('teacher')->failed_login_count);
        $this->getJson('/api/session')->assertUnauthorized();
        $this->assertSame('null', $this->getJson('/api/session?optional=1')->assertOk()->getContent());
    }

    public function test_five_failures_lock_the_account(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/session', ['email' => 'student@sunrise.example', 'password' => 'salah-sekali']);
        }

        $this->assertTrue($this->demoUser('student')->locked_until->isFuture());
    }

    public function test_logout_ends_the_session(): void
    {
        $this->loginAs('student');

        $this->deleteJson('/api/session')->assertOk();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard/tugas')->assertRedirect('/login');
    }

    public function test_unknown_backend_endpoint_answers_json_404(): void
    {
        $this->getJson('/api/backend/tidak-ada')->assertNotFound()->assertJsonPath('message', 'Endpoint tidak ditemukan.');
    }
}
