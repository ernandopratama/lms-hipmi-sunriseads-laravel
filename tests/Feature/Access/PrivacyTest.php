<?php

namespace Tests\Feature\Access;

use Illuminate\Support\Facades\DB;

class PrivacyTest extends AccessTestCase
{
    private function documentId(string $type): string
    {
        $docs = collect($this->getJson('/api/backend/privacy/consent-documents')->assertOk()->json());
        return $docs->firstWhere('type', $type)['id'];
    }

    public function test_guest_cannot_use_privacy_endpoints(): void
    {
        $this->getJson('/api/backend/privacy/consents/me')->assertUnauthorized();
        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS'])->assertUnauthorized();
    }

    public function test_student_grants_platform_consent_and_sees_it(): void
    {
        $this->loginAs('student');
        $doc = $this->documentId('PLATFORM');

        $id = $this->postJson('/api/backend/privacy/consents', ['documentId' => $doc, 'type' => 'PLATFORM', 'method' => 'AUTHENTICATED_CHECKBOX'])
            ->assertCreated()->assertJsonPath('status', 'ACTIVE')->json('id');

        $this->getJson('/api/backend/privacy/consents/me')->assertOk()
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.mayWithdraw', true)->assertJsonPath('0.student.id', $this->studentId());
    }

    public function test_identity_consent_cannot_be_granted_through_generic_endpoint(): void
    {
        $this->loginAs('student');
        $doc = $this->documentId('IDENTITY');

        $this->postJson('/api/backend/privacy/consents', ['documentId' => $doc, 'type' => 'IDENTITY', 'method' => 'AUTHENTICATED_CHECKBOX'])
            ->assertStatus(400)->assertJsonPath('message', 'Persetujuan identitas hanya dicatat melalui alur pengajuan identitas');
        $this->assertSame(0, DB::table('consents')->count());
    }

    public function test_minor_student_cannot_self_consent_to_publication(): void
    {
        $this->loginAs('student');
        $doc = $this->documentId('PUBLICATION');

        $this->postJson('/api/backend/privacy/consents', ['documentId' => $doc, 'type' => 'PUBLICATION', 'method' => 'AUTHENTICATED_CHECKBOX'])
            ->assertForbidden()->assertJsonPath('message', 'Publikasi karya siswa di bawah 18 tahun memerlukan persetujuan wali terverifikasi');
    }

    public function test_verified_parent_grants_guardian_consent_for_child(): void
    {
        $this->loginAs('parent');
        $doc = $this->documentId('GUARDIAN');

        $this->getJson('/api/backend/privacy/subjects')->assertOk()->assertJsonPath('0.id', $this->studentId());
        $this->postJson('/api/backend/privacy/consents', ['documentId' => $doc, 'type' => 'GUARDIAN', 'method' => 'AUTHENTICATED_CHECKBOX', 'studentId' => $this->studentId()])
            ->assertCreated();
        $this->assertSame($this->demoUser('parent')->id, DB::table('consents')->value('granted_by_id'));
    }

    public function test_user_without_guardian_relation_cannot_act_for_a_student(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS', 'studentId' => $this->studentId()])
            ->assertForbidden()->assertJsonPath('message', 'Relasi wali terverifikasi diperlukan');
        $this->assertSame(0, DB::table('data_subject_requests')->count());
    }

    public function test_revoked_guardian_relation_is_refused(): void
    {
        DB::table('student_parent_links')->update(['revoked_at' => now()]);
        $this->loginAs('parent');

        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS', 'studentId' => $this->studentId()])
            ->assertForbidden()->assertJsonPath('message', 'Relasi wali tidak aktif atau belum terverifikasi');
    }

    public function test_parent_request_uses_child_school_and_blocks_duplicates(): void
    {
        $this->loginAs('parent');

        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS', 'studentId' => $this->studentId(), 'details' => 'Mohon salinan data'])
            ->assertCreated()->assertJsonPath('status', 'SUBMITTED');
        $row = DB::table('data_subject_requests')->first();
        $this->assertSame($this->demoSchool()->id, $row->school_id);
        $this->assertSame($this->studentId(), $row->student_id);

        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS', 'studentId' => $this->studentId()])
            ->assertStatus(409)->assertJsonPath('message', 'Permintaan sejenis masih dalam proses');
        $this->getJson('/api/backend/privacy/data-requests/me')->assertOk()->assertJsonCount(1);
    }

    public function test_student_of_another_organization_is_not_found(): void
    {
        $foreign = $this->makeUser($this->otherSchool(), 'student', 'student');
        $this->loginAs('parent');

        $this->postJson('/api/backend/privacy/data-requests', ['type' => 'ACCESS', 'studentId' => DB::table('students')->where('user_id', $foreign->id)->value('id')])
            ->assertNotFound();
    }

    public function test_withdrawing_consent_records_a_withdrawal_request(): void
    {
        $this->loginAs('student');
        $doc = $this->documentId('ANALYTICS');
        $id = $this->postJson('/api/backend/privacy/consents', ['documentId' => $doc, 'type' => 'ANALYTICS', 'method' => 'AUTHENTICATED_CHECKBOX'])->json('id');

        $this->deleteJson("/api/backend/privacy/consents/$id", ['reason' => 'Tidak ingin lagi'])->assertOk()->assertJsonPath('status', 'WITHDRAWN');

        $this->assertSame('CONSENT_WITHDRAWAL', DB::table('data_subject_requests')->value('type'));
        $this->deleteJson("/api/backend/privacy/consents/$id", ['reason' => 'Tidak ingin lagi'])->assertNotFound();
    }

    public function test_cannot_withdraw_someone_elses_consent(): void
    {
        $this->loginAs('student');
        $id = $this->postJson('/api/backend/privacy/consents', ['documentId' => $this->documentId('ANALYTICS'), 'type' => 'ANALYTICS', 'method' => 'AUTHENTICATED_CHECKBOX'])->json('id');
        $this->deleteJson('/api/session');
        $this->loginAs('teacher');

        $this->deleteJson("/api/backend/privacy/consents/$id", ['reason' => 'Bukan milik saya'])->assertNotFound();
        $this->assertSame('ACTIVE', DB::table('consents')->where('id', $id)->value('status'));
    }

    public function test_only_privacy_administrators_list_requests(): void
    {
        $this->loginAs('teacher');

        $this->getJson('/api/backend/privacy/data-requests')->assertForbidden();
    }

    public function test_admin_moves_request_through_allowed_transitions_only(): void
    {
        $this->loginAs('parent');
        $id = $this->postJson('/api/backend/privacy/data-requests', ['type' => 'EXPORT', 'studentId' => $this->studentId()])->json('id');
        $this->deleteJson('/api/session');
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/privacy/data-requests')->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.requester.email', 'parent@sunrise.example');
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'COMPLETED', 'resolution' => 'Selesai'])
            ->assertStatus(400)->assertJsonPath('message', 'Transisi status SUBMITTED ke COMPLETED tidak valid');
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'IN_REVIEW'])->assertOk();
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'REJECTED'])
            ->assertStatus(400)->assertJsonPath('message', 'Resolusi wajib diisi untuk status akhir');
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'APPROVED'])->assertOk();
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'COMPLETED', 'resolution' => 'Data dikirim'])
            ->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->putJson("/api/backend/privacy/data-requests/$id", ['status' => 'IN_REVIEW'])->assertStatus(400);
        $this->assertSame('COMPLETED', DB::table('data_subject_requests')->where('id', $id)->value('status'));
    }
}
