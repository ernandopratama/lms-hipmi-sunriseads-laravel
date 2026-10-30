<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use SupportFixtures;

    private function submission(string $studentId, string $status = 'APPROVED'): string
    {
        $id = (string) Str::ulid();
        DB::table('submissions')->insert(['id' => $id, 'assignment_id' => DB::table('assignments')->value('id'),
            'user_id' => DB::table('students')->where('id', $studentId)->value('user_id'), 'student_id' => $studentId,
            'body' => 'Jawaban', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A private portfolio item of the demo student, created through the API. */
    private function item(): string
    {
        $this->grantAdvanced();
        $this->loginAs('student');

        return $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'ASSIGNMENT',
            'sourceId' => $this->submission($this->demoStudent()->id), 'description' => 'Karya pertama saya'])->assertCreated()->json('id');
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/backend/support/portfolio')->assertUnauthorized();
    }

    public function test_free_plan_cannot_use_portfolio(): void
    {
        $this->loginAs('student');
        $this->getJson('/api/backend/support/portfolio')->assertForbidden()
            ->assertJsonPath('message', 'Portofolio hanya tersedia pada paket Advanced');
    }

    public function test_student_adds_approved_assignment_once(): void
    {
        $this->grantAdvanced();
        $this->loginAs('student');
        $body = ['sourceType' => 'ASSIGNMENT', 'sourceId' => $this->submission($this->demoStudent()->id)];

        $this->postJson('/api/backend/support/portfolio', $body)->assertCreated()
            ->assertJsonPath('status', 'PRIVATE')->assertJsonPath('title', 'Rancang penawaran produk pertama')
            ->assertJsonPath('student.fullName', 'Siswa Demo');
        $this->postJson('/api/backend/support/portfolio', [...$body, 'title' => 'Judul Baru'])->assertOk()->assertJsonPath('title', 'Judul Baru');
        $this->assertSame(1, DB::table('portfolio_items')->count());
        $this->getJson('/api/backend/support/portfolio')->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Judul Baru');
    }

    public function test_unapproved_source_is_rejected(): void
    {
        $this->grantAdvanced();
        $this->loginAs('student');
        $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'ASSIGNMENT',
            'sourceId' => $this->submission($this->demoStudent()->id, 'SUBMITTED')])
            ->assertStatus(400)->assertJsonPath('message', 'Hanya tugas yang disetujui dapat masuk portofolio');
        $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'LAINNYA', 'sourceId' => 'x'])->assertUnprocessable();
        $this->assertSame(0, DB::table('portfolio_items')->count());
    }

    public function test_student_cannot_add_another_students_work(): void
    {
        $this->grantAdvanced();
        $other = $this->makeStudent('Siswa Kedua');
        $this->loginAs('student');
        // studentId is ignored for students, so the other student's submission is not "theirs".
        $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'ASSIGNMENT', 'sourceId' => $this->submission($other), 'studentId' => $other])
            ->assertStatus(400);
        $this->assertSame(0, DB::table('portfolio_items')->count());
    }

    public function test_staff_cannot_add_for_student_of_another_school(): void
    {
        $this->grantAdvanced();
        $other = $this->makeStudent('Siswa Sekolah Lain', $this->otherSchool());
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'ASSIGNMENT', 'sourceId' => $this->submission($other), 'studentId' => $other])
            ->assertForbidden()->assertJsonPath('message', 'Siswa berada di luar ruang lingkup');
    }

    public function test_parent_cannot_create_portfolio(): void
    {
        $this->grantAdvanced();
        $this->loginAs('parent');
        $this->postJson('/api/backend/support/portfolio', ['sourceType' => 'ASSIGNMENT', 'sourceId' => 'x'])->assertForbidden();
    }

    public function test_publish_requires_consent_and_public_page_shows_only_safe_fields(): void
    {
        $id = $this->item();
        $this->patchJson("/api/backend/support/portfolio/$id/publication", ['publicVisible' => true])
            ->assertStatus(400)->assertJsonPath('message', 'Persetujuan publikasi aktif diperlukan');

        $this->publicationConsent($this->demoStudent()->id);
        $slug = $this->patchJson("/api/backend/support/portfolio/$id/publication", ['publicVisible' => true])
            ->assertOk()->assertJsonPath('status', 'PUBLISHED')->json('publicSlug');
        $this->assertStringStartsWith('karya-', $slug);

        $public = $this->getJson("/api/backend/public/portfolio/$slug")->assertOk()
            ->assertJsonPath('title', 'Rancang penawaran produk pertama')->assertJsonPath('studentName', 'Siswa Demo')
            ->assertJsonPath('school', 'SMK Contoh Nusantara')->json();
        $this->assertEqualsCanonicalizing(['title', 'description', 'sourceType', 'studentName', 'school', 'city', 'publishedAt'], array_keys($public));
    }

    public function test_unpublished_item_is_not_public(): void
    {
        $id = $this->item();
        $this->publicationConsent($this->demoStudent()->id);
        $slug = $this->patchJson("/api/backend/support/portfolio/$id/publication", ['publicVisible' => true])->json('publicSlug');

        $this->patchJson("/api/backend/support/portfolio/$id/publication", ['publicVisible' => false])
            ->assertOk()->assertJsonPath('status', 'PRIVATE')->assertJsonPath('publishedAt', null);
        $this->getJson("/api/backend/public/portfolio/$slug")->assertNotFound()
            ->assertJsonPath('message', 'Portofolio publik tidak ditemukan');
    }

    public function test_withdrawn_consent_hides_public_portfolio(): void
    {
        $id = $this->item();
        $consent = $this->publicationConsent($this->demoStudent()->id);
        $slug = $this->patchJson("/api/backend/support/portfolio/$id/publication", ['publicVisible' => true])->json('publicSlug');
        DB::table('consents')->where('id', $consent)->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now()]);

        $this->getJson("/api/backend/public/portfolio/$slug")->assertNotFound();
    }

    public function test_student_cannot_publish_another_students_item(): void
    {
        $this->grantAdvanced();
        $other = $this->makeStudent('Siswa Kedua');
        $itemId = (string) Str::ulid();
        DB::table('portfolio_items')->insert(['id' => $itemId, 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
            'student_id' => $other, 'created_by_id' => $this->demoUser('school-admin')->id, 'source_type' => 'ASSIGNMENT',
            'source_id' => 'x', 'title' => 'Milik orang lain', 'updated_at' => now()]);
        $this->loginAs('student');
        $this->publicationConsent($this->demoStudent()->id);

        $this->patchJson("/api/backend/support/portfolio/$itemId/publication", ['publicVisible' => true])->assertNotFound();
        $this->getJson('/api/backend/support/portfolio')->assertOk()->assertJsonCount(0);
    }

    public function test_teacher_sees_only_students_of_assigned_classes(): void
    {
        $this->item();
        $this->loginAs('teacher');
        $this->getJson('/api/backend/support/portfolio')->assertOk()->assertJsonCount(0);
    }
}
