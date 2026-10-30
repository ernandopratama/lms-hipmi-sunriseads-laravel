<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Notifications, announcements and offline sync drafts. */
class SupportInboxTest extends AccessTestCase
{
    private function notify(string $userId, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table('notifications')->insert($extra + ['id' => $id, 'organization_id' => $this->demoSchool()->organization_id, 'school_id' => $this->demoSchool()->id,
            'user_id' => $userId, 'event_type' => 'ASSIGNMENT', 'title' => 'Tugas baru', 'body' => 'Isi', 'idempotency_key' => 'k:'.$id, 'created_at' => now()]);
        return $id;
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/backend/support/notifications')->assertUnauthorized();
        $this->getJson('/api/backend/support/announcements')->assertUnauthorized();
        $this->getJson('/api/backend/support/sync-drafts/a')->assertUnauthorized();
    }

    public function test_notifications_list_only_own_active_items(): void
    {
        $mine = $this->notify($this->demoUser('student')->id);
        $this->notify($this->demoUser('student')->id, ['archived_at' => now()]);
        $this->notify($this->demoUser('teacher')->id);
        $this->loginAs('student');

        $this->getJson('/api/backend/support/notifications')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine);
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $theirs = $this->notify($this->demoUser('teacher')->id);
        $this->loginAs('student');

        $this->patchJson("/api/backend/support/notifications/$theirs/read")->assertNotFound();
        $this->patchJson("/api/backend/support/notifications/$theirs/archive")->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
    }

    public function test_read_archive_and_read_all(): void
    {
        $student = $this->demoUser('student')->id;
        $a = $this->notify($student);
        $this->notify($student);
        $this->notify($student);
        $this->loginAs('student');

        $this->patchJson("/api/backend/support/notifications/$a/read")->assertOk()->assertJsonPath('id', $a);
        $this->assertNotNull(DB::table('notifications')->where('id', $a)->value('read_at'));
        $this->postJson('/api/backend/support/notifications/read-all')->assertOk()->assertJsonPath('updated', 2);
        $this->patchJson("/api/backend/support/notifications/$a/archive")->assertOk();
        $this->getJson('/api/backend/support/notifications')->assertOk()->assertJsonCount(2);
    }

    public function test_preferences_keep_in_app_enabled_and_validate(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/support/notification-preferences')->assertOk()->assertJsonPath('inAppEnabled', true);
        $this->putJson('/api/backend/support/notification-preferences', ['inAppEnabled' => false, 'emailEnabled' => false, 'digestFrequency' => 'WEEKLY', 'quietHoursStart' => '21:00'])
            ->assertOk()->assertJsonPath('inAppEnabled', true)->assertJsonPath('emailEnabled', false)->assertJsonPath('digestFrequency', 'WEEKLY');
        $this->putJson('/api/backend/support/notification-preferences', ['quietHoursStart' => '25:00'])->assertStatus(422);
        $this->putJson('/api/backend/support/notification-preferences', ['timezone' => 'Mars/Olympus'])->assertStatus(422);
        $this->assertSame('21:00', DB::table('notification_preferences')->value('quiet_hours_start'));
    }

    public function test_student_cannot_manage_announcements(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/support/announcements/manage')->assertForbidden();
        $this->postJson('/api/backend/support/announcements', ['title' => 'Libur', 'body' => 'Sekolah libur'])->assertForbidden();
        $this->assertSame(0, DB::table('announcements')->count());
    }

    public function test_published_announcement_reaches_audience_and_redacts_secrets(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/support/announcements', ['title' => 'Info akun', 'body' => 'Jangan bagikan password: rahasia123 ke siapa pun',
            'audience' => 'STUDENTS', 'publishNow' => true])->assertCreated()->assertJsonPath('status', 'PUBLISHED')
            ->assertJsonPath('body', 'Jangan bagikan password [DIHAPUS] ke siapa pun');

        $student = $this->demoUser('student')->id;
        $this->assertSame(1, DB::table('notifications')->where('user_id', $student)->where('event_type', 'ANNOUNCEMENT')->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $this->demoUser('teacher')->id)->count());
        $this->deleteJson('/api/session');
        $this->loginAs('student');
        $this->getJson('/api/backend/support/announcements')->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Info akun');
    }

    public function test_draft_announcement_is_hidden_until_published_once(): void
    {
        $this->loginAs('teacher');
        $id = $this->postJson('/api/backend/support/announcements', ['title' => 'Rapat', 'body' => 'Rapat orang tua', 'audience' => 'ALL'])
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->json('id');
        $this->getJson('/api/backend/support/announcements')->assertOk()->assertJsonCount(0);

        $this->postJson("/api/backend/support/announcements/$id/publish")->assertOk()->assertJsonPath('status', 'PUBLISHED');
        $first = DB::table('notifications')->count();
        $this->postJson("/api/backend/support/announcements/$id/publish")->assertOk();

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, DB::table('notifications')->count());
    }

    public function test_announcement_for_teachers_is_not_shown_to_students_or_other_schools(): void
    {
        $other = $this->otherSchool(false);
        $org = $this->demoSchool()->organization_id;
        foreach ([['TEACHERS', $this->demoSchool()->id], ['ALL', $other->id]] as [$audience, $school]) {
            DB::table('announcements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'school_id' => $school,
                'author_id' => $this->demoUser('teacher')->id, 'title' => "Untuk $audience", 'body' => 'Isi', 'audience' => $audience,
                'status' => 'PUBLISHED', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->loginAs('student');

        $this->getJson('/api/backend/support/announcements')->assertOk()->assertJsonCount(0);
    }

    public function test_sync_draft_round_trip_and_version_conflict(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/support/sync-drafts/tugas-1')->assertOk()->assertContent('null');
        $this->putJson('/api/backend/support/sync-drafts/tugas-1', ['content' => ['body' => 'Draf pertama']])->assertOk()->assertJsonPath('version', 1);
        $this->putJson('/api/backend/support/sync-drafts/tugas-1', ['content' => ['body' => 'Draf kedua'], 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/api/backend/support/sync-drafts/tugas-1', ['content' => ['body' => 'Basi'], 'version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'DRAFT_VERSION_CONFLICT')->assertJsonPath('serverDraft.body', 'Draf kedua');
        $this->getJson('/api/backend/support/sync-drafts/tugas-1')->assertOk()->assertJsonPath('content.body', 'Draf kedua');
        $this->putJson('/api/backend/support/sync-drafts/tugas-1', ['content' => 'teks'])->assertStatus(422);

        $this->deleteJson('/api/backend/support/sync-drafts/tugas-1')->assertOk()->assertJsonPath('deleted', true);
        $this->assertSame(0, DB::table('sync_drafts')->count());
    }

    public function test_sync_drafts_are_private_and_need_permission(): void
    {
        $this->loginAs('student');
        $this->putJson('/api/backend/support/sync-drafts/rahasia', ['content' => ['body' => 'Milik siswa']])->assertOk();
        $this->deleteJson('/api/session');

        $this->loginAs('program-manager');
        $this->getJson('/api/backend/support/sync-drafts/rahasia')->assertOk()->assertContent('null');
        $this->deleteJson('/api/backend/support/sync-drafts/rahasia')->assertOk();
        $this->assertSame(1, DB::table('sync_drafts')->count());
        $this->deleteJson('/api/session');

        $this->loginAs('teacher');
        $this->getJson('/api/backend/support/sync-drafts/rahasia')->assertForbidden();
    }
}
