<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** CMS, analytics consent events, public statistics, operations overview and health. */
class PublicContentTest extends TestCase
{
    use SupportFixtures;

    private function cms(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/backend/support/cms', [...['slug' => 'Apa Itu Sunrise?', 'type' => 'FAQ',
            'title' => 'Apa itu Sunrise?', 'excerpt' => 'Ringkas', 'body' => ['answer' => 'Program kewirausahaan'], 'position' => 2], ...$overrides]);
    }

    public function test_cms_admin_requires_permission(): void
    {
        $this->getJson('/api/backend/support/cms')->assertUnauthorized();
        $this->loginAs('teacher');
        $this->getJson('/api/backend/support/cms')->assertForbidden();
        $this->cms()->assertForbidden();
        $this->assertSame(0, DB::table('cms_contents')->count());
    }

    public function test_draft_is_hidden_until_published(): void
    {
        $this->loginAs('program-manager');
        $id = $this->cms()->assertCreated()->assertJsonPath('slug', 'apa-itu-sunrise-')->assertJsonPath('status', 'DRAFT')->json('id');
        $this->getJson('/api/backend/support/cms')->assertOk()->assertJsonCount(1)->assertJsonPath('0.body.answer', 'Program kewirausahaan');
        $this->getJson('/api/backend/public/content')->assertOk()->assertJsonCount(0);

        $this->postJson("/api/backend/support/cms/$id/publish")->assertOk()->assertJsonPath('status', 'PUBLISHED')
            ->assertJsonPath('reviewedById', $this->demoUser('program-manager')->id);

        $res = $this->getJson('/api/backend/public/content')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1)->assertJsonPath('0.slug', 'apa-itu-sunrise-')->assertJsonPath('0.body.answer', 'Program kewirausahaan');
        $this->assertEqualsCanonicalizing(['slug', 'type', 'title', 'excerpt', 'body', 'imageUrl', 'publishedAt'], array_keys($res->json('0')));
    }

    public function test_public_content_filters_by_type(): void
    {
        $this->loginAs('school-admin');
        foreach ([['slug' => 'faq-1', 'type' => 'FAQ'], ['slug' => 'mitra-1', 'type' => 'PARTNER']] as $row) {
            $this->postJson('/api/backend/support/cms/'.$this->cms($row)->json('id').'/publish')->assertOk();
        }
        $this->getJson('/api/backend/public/content?type=PARTNER')->assertOk()->assertJsonCount(1)->assertJsonPath('0.slug', 'mitra-1');
        $this->getJson('/api/backend/public/content?type='.str_repeat('A', 51))->assertStatus(400)->assertJsonPath('message', 'Jenis konten tidak valid');
    }

    public function test_cms_validation_and_duplicate_slug(): void
    {
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/support/cms', ['slug' => 'x', 'type' => 'BLOG', 'title' => 'ab'])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'type', 'title', 'body']);
        $this->cms()->assertCreated();
        $this->cms()->assertStatus(409)->assertJsonPath('message', 'Slug sudah digunakan');
        $this->assertSame(1, DB::table('cms_contents')->count());
    }

    public function test_cannot_publish_content_of_another_organization(): void
    {
        $other = Organization::create(['name' => 'Organisasi Lain']);
        $id = (string) Str::ulid();
        DB::table('cms_contents')->insert(['id' => $id, 'organization_id' => $other->id, 'slug' => 'milik-lain', 'type' => 'FAQ',
            'title' => 'Milik lain', 'body' => '{}', 'author_id' => $this->demoUser('super-admin')->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/support/cms')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/backend/support/cms/$id/publish")->assertNotFound()->assertJsonPath('message', 'Konten tidak ditemukan');
        $this->assertSame('DRAFT', DB::table('cms_contents')->where('id', $id)->value('status'));
    }

    public function test_analytics_event_without_consent_is_not_stored(): void
    {
        $this->loginAs('student');
        $this->postJson('/api/backend/support/analytics/events', ['eventName' => 'PAGE_VIEW', 'consentGranted' => false])
            ->assertOk()->assertExactJson(['accepted' => false, 'reason' => 'CONSENT_REQUIRED']);
        $this->postJson('/api/backend/public/analytics/events', ['eventName' => 'PAGE_VIEW', 'consentGranted' => false])
            ->assertOk()->assertJsonPath('accepted', false);
        $this->assertSame(0, DB::table('analytics_events')->count());
    }

    public function test_analytics_event_with_consent_keeps_only_safe_properties(): void
    {
        $user = $this->loginAs('student');
        $this->postJson('/api/backend/support/analytics/events', ['eventName' => 'CTA_CLICK', 'consentGranted' => true, 'anonymousId' => 'anon-1',
            'properties' => ['page' => '/daftar', 'note' => 'otp 123456 telp 081234567890', 'bad key!' => 'x', 'nested' => ['a' => 1], 'n' => 5]])
            ->assertOk()->assertExactJson(['accepted' => true]);

        $row = DB::table('analytics_events')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($this->orgId(), $row->organization_id);
        $this->assertTrue((bool) $row->consent_verified);
        $this->assertEquals(['page' => '/daftar', 'note' => 'otp [DIHAPUS] telp [IDENTIFIER DIHAPUS]', 'n' => 5], json_decode($row->properties, true));
    }

    public function test_public_analytics_is_anonymous_and_validated(): void
    {
        $this->postJson('/api/backend/public/analytics/events', ['eventName' => 'HACK', 'consentGranted' => true])->assertUnprocessable();
        $this->postJson('/api/backend/public/analytics/events', ['eventName' => 'LEAD_STARTED', 'consentGranted' => true, 'anonymousId' => 'anon-2'])
            ->assertOk()->assertJsonPath('accepted', true);
        $row = DB::table('analytics_events')->first();
        $this->assertNull($row->user_id);
        $this->assertNull($row->organization_id);
        $this->assertSame('anon-2', $row->anonymous_id);
    }

    public function test_analytics_requires_permission(): void
    {
        $this->postJson('/api/backend/support/analytics/events', ['eventName' => 'PAGE_VIEW', 'consentGranted' => true])->assertUnauthorized();
        $this->loginAs('support');
        $this->postJson('/api/backend/support/analytics/events', ['eventName' => 'PAGE_VIEW', 'consentGranted' => true])->assertForbidden();
    }

    public function test_public_statistics_hide_small_cohorts(): void
    {
        foreach ([['Kohort besar', 25], ['Kohort kecil', 3]] as [$title, $size]) {
            DB::table('public_statistics')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
                'title' => $title, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'cohort_size' => $size, 'minimum_cohort' => 10,
                'metrics' => json_encode(['completionRate' => 80]), 'status' => 'APPROVED', 'created_by_id' => $this->demoUser('school-admin')->id,
                'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/api/backend/public/statistics')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Kohort besar')->assertJsonPath('0.school.name', 'SMK Contoh Nusantara')
            ->assertJsonPath('0.metrics.completionRate', 80);
    }

    public function test_operations_overview_is_scoped_and_permissioned(): void
    {
        $this->getJson('/api/backend/operations/overview')->assertUnauthorized();
        $this->loginAs('support');
        $this->getJson('/api/backend/operations/overview')->assertForbidden();
        $this->loginAs('school-admin');
        $this->getJson('/api/backend/operations/overview')->assertOk()->assertJsonPath('status', 'healthy')
            ->assertJsonPath('scope.organizationId', $this->orgId())->assertJsonPath('scope.schoolId', $this->schoolId())
            ->assertJsonPath('http.available', false)->assertJsonPath('jobs24h.deadLetters', 0);
    }

    public function test_health_reports_ready_dependencies(): void
    {
        Storage::fake('local');
        foreach (['/api/backend/health', '/api/backend/health/ready'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('database', 'reachable')
                ->assertJsonPath('objectStorage', 'reachable')->assertJsonStructure(['redis', 'timestamp']);
        }
    }
}
