<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    // DatabaseSeeder creates one active demo user per baseline role (super-admin@sunrise.example, teacher@..., student@...).
    protected bool $seed = true;

    protected function demoUser(string $role): User
    {
        return User::where('email', "$role@sunrise.example")->firstOrFail();
    }

    /** Logs in through the real session endpoint, so the source-session state is set exactly as in the browser. */
    protected function loginAs(string $role): User
    {
        $this->postJson('/api/session', ['email' => "$role@sunrise.example", 'password' => 'DemoLogin#2026'])->assertOk();

        return $this->demoUser($role);
    }
}
