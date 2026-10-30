<?php

namespace Tests\Feature\Access;

use App\Models\{Organization, School, User};
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class AccessTestCase extends TestCase
{
    protected function demoSchool(): School
    {
        return School::where('name', 'SMK Contoh Nusantara')->firstOrFail();
    }

    /** A second school in the demo organization, or in a new organization when $otherOrg. */
    protected function otherSchool(bool $otherOrg = true): School
    {
        $org = $otherOrg ? Organization::create(['name' => 'Org Lain '.Str::random(4)]) : $this->demoSchool()->organization;
        return School::create(['organization_id' => $org->id, 'name' => 'Sekolah Lain '.Str::random(4)]);
    }

    /** An active user with a source role in the given school (role looked up in that school's organization, else the demo one). */
    protected function makeUser(School $school, string $roleSlug = 'teacher', string $legacy = 'teacher'): User
    {
        $u = (new User)->forceFill(['name' => 'Pengguna Uji', 'first_name' => 'Pengguna', 'last_name' => 'Uji',
            'email' => Str::lower(Str::random(8)).'@uji.example', 'password' => Hash::make('DemoLogin#2026'),
            'organization_id' => $school->organization_id, 'school_id' => $school->id, 'role' => $legacy, 'status' => 'active']);
        $u->save();
        $role = DB::table('roles')->where('slug', $roleSlug)->where('organization_id', $school->organization_id)->value('id')
            ?? DB::table('roles')->where('slug', $roleSlug)->value('id');
        DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $u->id, 'role_id' => $role, 'school_id' => $school->id, 'created_at' => now()]);
        if ($roleSlug === 'teacher') DB::table('teachers')->insert(['id' => (string) Str::ulid(), 'user_id' => $u->id, 'school_id' => $school->id,
            'full_name' => 'Guru Uji', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        if ($roleSlug === 'student') DB::table('students')->insert(['id' => (string) Str::ulid(), 'user_id' => $u->id, 'school_id' => $school->id,
            'student_number' => 'UJI-'.Str::random(5), 'full_name' => 'Siswa Uji', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    /**
     * The test client does not carry cookies, so each request would get a fresh session id (and a new
     * user_sessions row). Send the login session's cookie so the device fingerprint stays stable.
     */
    protected function loginKeepingSession(string $role, ?string $otp = null): \App\Models\User
    {
        $this->postJson('/api/session', ['email' => "$role@sunrise.example", 'password' => 'DemoLogin#2026'] + ($otp ? ['otp' => $otp] : []))->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        return $this->demoUser($role);
    }

    protected function studentId(string $role = 'student'): string
    {
        return DB::table('students')->where('user_id', $this->demoUser($role)->id)->value('id');
    }

    /** RFC 6238 code matching App\Support\Totp::verify (SHA1, 6 digits, 30 s). */
    protected function totp(string $base32): string
    {
        $bits = '';
        foreach (str_split($base32) as $ch) $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $ch)), 5, '0', STR_PAD_LEFT);
        $key = '';
        foreach (str_split(substr($bits, 0, strlen($bits) - strlen($bits) % 8), 8) as $byte) $key .= chr(bindec($byte));
        $counter = intdiv(time(), 30);
        $h = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $o = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        $code = str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
        $this->assertTrue(\App\Support\Totp::verify($code, $base32));
        return $code;
    }

    /** Messages captured by the array mail transport. */
    protected function sentMail(): array
    {
        return iterator_to_array(app('mailer')->getSymfonyTransport()->messages());
    }
}
