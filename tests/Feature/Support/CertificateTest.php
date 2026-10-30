<?php

namespace Tests\Feature\Support;

use App\Jobs\GenerateCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('certificates');
    }

    private function template(array $rule = []): string
    {
        return $this->postJson('/api/backend/support/certificate-templates', ['name' => 'Lulus Kelas Bisnis '.Str::random(4),
            'titleTemplate' => 'Sertifikat {{studentName}}', 'description' => 'Menyelesaikan kelas', 'eligibilityRule' => $rule])
            ->assertCreated()->json('id');
    }

    /** Issues through the API as school-admin; the sync queue generates the PDF after the response. */
    private function issued(?string $studentId = null): object
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $id = $this->postJson('/api/backend/support/certificates', ['studentId' => $studentId ?? $this->demoStudent()->id,
            'templateId' => $this->template()])->assertOk()->json('id');

        return DB::table('certificates')->where('id', $id)->first();
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/backend/support/certificates')->assertUnauthorized();
    }

    public function test_role_without_certificate_permission_gets_403(): void
    {
        $this->grantAdvanced();
        $this->loginAs('support');
        $this->getJson('/api/backend/support/certificates')->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk tindakan ini');
    }

    public function test_free_plan_cannot_use_certificates(): void
    {
        $this->loginAs('school-admin');
        $this->getJson('/api/backend/support/certificates')->assertForbidden()
            ->assertJsonPath('message', 'Sertifikat hanya tersedia pada paket Advanced');
    }

    public function test_issue_generates_pdf_and_notifies_student(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $res = $this->postJson('/api/backend/support/certificates', ['studentId' => $this->demoStudent()->id, 'templateId' => $this->template()])
            ->assertOk()->assertJsonPath('status', 'QUEUED')->assertJsonPath('title', 'Sertifikat Siswa Demo');

        $row = DB::table('certificates')->where('id', $res->json('id'))->first();
        $this->assertSame('ISSUED', $row->status);
        $this->assertNotNull($row->issued_at);
        $this->assertStringStartsWith('%PDF-', Storage::disk('certificates')->get($row->pdf_storage_key));
        $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser('student')->id, 'event_type' => 'CERTIFICATE_ISSUED',
            'idempotency_key' => 'certificate-issued:'.$row->id]);

        $this->getJson('/api/backend/support/certificates')->assertOk()
            ->assertJsonPath('0.student.fullName', 'Siswa Demo')->assertJsonPath('0.status', 'ISSUED');
    }

    public function test_issuing_twice_returns_the_same_certificate(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $body = ['studentId' => $this->demoStudent()->id, 'templateId' => $this->template()];
        $first = $this->postJson('/api/backend/support/certificates', $body)->json('id');

        $this->postJson('/api/backend/support/certificates', $body)->assertOk()->assertJsonPath('id', $first)->assertJsonPath('status', 'ISSUED');
        $this->assertSame(1, DB::table('certificates')->count());
    }

    public function test_ineligible_student_is_rejected(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/support/certificates', ['studentId' => $this->demoStudent()->id,
            'templateId' => $this->template(['minApprovedSubmissions' => 1])])
            ->assertStatus(400)->assertJsonPath('message', 'Siswa belum memenuhi aturan kelayakan sertifikat');
        $this->assertSame(0, DB::table('certificates')->count());
    }

    public function test_cannot_issue_for_student_of_another_school(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $other = $this->makeStudent('Siswa Sekolah Lain', $this->otherSchool());
        $this->postJson('/api/backend/support/certificates', ['studentId' => $other, 'templateId' => $this->template()])
            ->assertNotFound()->assertJsonPath('message', 'Siswa atau template sertifikat tidak ditemukan');
    }

    public function test_template_validation_and_duplicate_name(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/support/certificate-templates', ['name' => 'ab', 'titleTemplate' => 'x'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'titleTemplate', 'eligibilityRule']);
        $body = ['name' => 'Template Sama', 'titleTemplate' => 'Sertifikat', 'eligibilityRule' => []];
        $this->postJson('/api/backend/support/certificate-templates', $body)->assertCreated();
        $this->postJson('/api/backend/support/certificate-templates', $body)->assertStatus(409)->assertJsonPath('message', 'Nama template sudah digunakan');
        $this->assertSame(1, DB::table('certificate_templates')->count());
    }

    public function test_publication_requires_active_consent_and_shows_name_publicly(): void
    {
        $cert = $this->issued();
        $this->loginAs('student');

        $this->patchJson("/api/backend/support/certificates/{$cert->id}/publication", ['publicVisible' => true])
            ->assertStatus(400)->assertJsonPath('message', 'Persetujuan publikasi aktif diperlukan');
        $this->getJson("/api/backend/public/certificates/{$cert->verification_code}")->assertOk()
            ->assertJsonPath('valid', true)->assertJsonMissingPath('studentName')->assertJsonPath('title', DB::table('certificate_templates')->value('name'));

        $this->publicationConsent($this->demoStudent()->id);
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/publication", ['publicVisible' => true])
            ->assertOk()->assertJsonPath('publicVisible', true);
        $this->getJson("/api/backend/public/certificates/{$cert->verification_code}")->assertOk()
            ->assertJsonPath('studentName', 'Siswa Demo')->assertJsonPath('title', 'Sertifikat Siswa Demo')
            ->assertJsonPath('school', 'SMK Contoh Nusantara');

        $this->patchJson("/api/backend/support/certificates/{$cert->id}/publication", ['publicVisible' => false])
            ->assertOk()->assertJsonPath('publicVisible', false)->assertJsonPath('publicationConsentId', null);
        $this->getJson("/api/backend/public/certificates/{$cert->verification_code}")->assertJsonMissingPath('studentName');
    }

    public function test_only_the_owning_student_sets_publication(): void
    {
        $cert = $this->issued();
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/publication", ['publicVisible' => true])
            ->assertForbidden()->assertJsonPath('message', 'Publikasi hanya dapat diatur oleh siswa pemilik');
    }

    public function test_student_cannot_publish_another_students_certificate(): void
    {
        $other = $this->makeStudent('Siswa Kedua');
        $cert = $this->issued($other);
        $this->loginAs('student');
        $this->publicationConsent($this->demoStudent()->id);
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/publication", ['publicVisible' => true])->assertNotFound();
    }

    public function test_student_lists_only_own_certificates(): void
    {
        $other = $this->makeStudent('Siswa Kedua');
        $this->issued($other);
        $this->loginAs('student');
        $this->getJson('/api/backend/support/certificates')->assertOk()->assertJsonCount(0);
    }

    public function test_revoke_redacts_reason_and_invalidates_verification(): void
    {
        $cert = $this->issued();
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/revoke", ['reason' => 'abc'])->assertUnprocessable();
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/revoke", ['reason' => 'Data salah NISN 1234567890 password=rahasia telp 081234567890'])
            ->assertOk()->assertJsonPath('status', 'REVOKED')->assertJsonPath('publicVisible', false)
            ->assertJsonPath('revokeReason', 'Data salah NISN [DIHAPUS] password [DIHAPUS] telp [IDENTIFIER DIHAPUS]');

        $this->getJson("/api/backend/public/certificates/{$cert->verification_code}")->assertOk()
            ->assertJsonPath('valid', false)->assertJsonPath('status', 'REVOKED');
        $this->getJson("/api/backend/support/certificates/{$cert->id}/download")->assertNotFound();
    }

    public function test_student_cannot_revoke(): void
    {
        $cert = $this->issued();
        $this->loginAs('student');
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/revoke", ['reason' => 'Ingin dicabut saja'])->assertForbidden();
        $this->assertSame('ISSUED', DB::table('certificates')->where('id', $cert->id)->value('status'));
    }

    public function test_cannot_revoke_certificate_of_another_school(): void
    {
        $cert = $this->issued();
        DB::table('certificates')->where('id', $cert->id)->update(['school_id' => $this->otherSchool()]);
        $this->patchJson("/api/backend/support/certificates/{$cert->id}/revoke", ['reason' => 'Dicabut dari luar'])->assertNotFound();
    }

    public function test_unknown_code_is_not_valid(): void
    {
        $this->getJson('/api/backend/public/certificates/CERT-TIDAKADA')->assertOk()
            ->assertExactJson(['valid' => false, 'status' => 'NOT_FOUND']);
    }

    public function test_student_downloads_own_certificate_through_signed_url(): void
    {
        $cert = $this->issued();
        $this->loginAs('student');
        $url = $this->getJson("/api/backend/support/certificates/{$cert->id}/download")->assertOk()
            ->assertJsonPath('expiresInSeconds', 120)->json('url');

        $res = $this->get($url)->assertOk();
        $this->assertStringContainsString($cert->verification_code.'.pdf', $res->headers->get('Content-Disposition'));
        $this->get(preg_replace('/signature=[^&]+/', 'signature=salah', $url))->assertForbidden();
    }

    public function test_other_student_cannot_download(): void
    {
        $other = $this->makeStudent('Siswa Kedua');
        $cert = $this->issued($other);
        $this->loginAs('student');
        $this->getJson("/api/backend/support/certificates/{$cert->id}/download")->assertNotFound()
            ->assertJsonPath('message', 'File sertifikat belum tersedia');
    }

    public function test_job_fails_when_requester_lost_permission(): void
    {
        $this->grantAdvanced();
        $id = (string) Str::ulid();
        $template = (string) Str::ulid();
        DB::table('certificate_templates')->insert(['id' => $template, 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
            'name' => 'T', 'title_template' => 'T', 'eligibility_rule' => '{}', 'created_by_id' => $this->demoUser('school-admin')->id, 'updated_at' => now()]);
        DB::table('certificates')->insert(['id' => $id, 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
            'student_id' => $this->demoStudent()->id, 'template_id' => $template, 'issued_by_id' => $this->demoUser('support')->id,
            'verification_code' => 'CERT-JOBTEST', 'title' => 'T', 'updated_at' => now()]);

        GenerateCertificate::dispatchSync($id, $this->demoUser('support')->id);

        $row = DB::table('certificates')->where('id', $id)->first();
        $this->assertSame('FAILED', $row->status);
        $this->assertSame('ACCESS_REVOKED_BEFORE_PROCESSING', $row->failure_reason);
        $this->assertNull($row->pdf_storage_key);
    }
}
