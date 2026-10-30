<?php

namespace Tests\Feature\SchoolData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityDocumentTest extends TestCase
{
    use SchoolDataHelpers;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF";
    private const NIK = '3174000012345678';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('identity_quarantine');
        Storage::fake('identity_private');
        // Adult student, so no guardian consent is needed unless a test says otherwise.
        DB::table('students')->where('user_id', $this->demoUser('student')->id)->update(['birth_date' => '2000-01-01']);
    }

    private function studentId(): string
    {
        return DB::table('students')->where('user_id', $this->demoUser('student')->id)->value('id');
    }

    /** Signed upload URL + PUT of the bytes, as the browser does; returns the storage key. */
    private function upload(string $bytes = self::PDF, string $name = 'ktp.pdf', string $mime = 'application/pdf'): string
    {
        $r = $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => $name, 'mimeType' => $mime, 'sizeBytes' => strlen($bytes)])
            ->assertOk()->assertJsonPath('expiresIn', 300);
        $this->call('PUT', $r->json('uploadUrl'), [], [], [], ['CONTENT_TYPE' => $mime], $bytes)->assertNoContent();

        return $r->json('storageKey');
    }

    private function submit(string $key, array $overrides = [], string $bytes = self::PDF)
    {
        return $this->postJson('/api/backend/identity-documents', $overrides + ['fileName' => 'ktp.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => strlen($bytes),
            'nik' => self::NIK, 'nisn' => '0012345678', 'type' => 'KTP', 'storageKey' => $key, 'consent' => true]);
    }

    private function submittedDocument(): string
    {
        $this->loginAs('student');
        return $this->submit($this->upload())->assertCreated()->json('id');
    }

    public function test_guest_cannot_request_an_upload_url(): void
    {
        $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 10])->assertUnauthorized();
    }

    public function test_non_student_cannot_request_an_upload_url(): void
    {
        $this->loginAs('teacher');
        $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 10])->assertForbidden();
    }

    public function test_upload_url_rejects_a_disallowed_format(): void
    {
        $this->loginAs('student');
        $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.exe', 'mimeType' => 'application/x-msdownload', 'sizeBytes' => 10])
            ->assertStatus(400)->assertJsonPath('message', 'Format file tidak diizinkan');
    }

    public function test_upload_url_rejects_an_extension_that_does_not_match_the_format(): void
    {
        $this->loginAs('student');
        $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.png', 'mimeType' => 'application/pdf', 'sizeBytes' => 10])
            ->assertStatus(400)->assertJsonPath('message', 'Ekstensi file tidak sesuai dengan format yang dipilih');
    }

    public function test_tampered_upload_signature_is_rejected(): void
    {
        $this->loginAs('student');
        $r = $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => strlen(self::PDF)]);
        $tampered = str_replace(urlencode($this->studentId()), 'someone-else', $r->json('uploadUrl'));
        $this->call('PUT', $tampered, [], [], [], ['CONTENT_TYPE' => 'application/pdf'], self::PDF)->assertForbidden();
    }

    public function test_upload_with_mismatched_size_is_rejected(): void
    {
        $this->loginAs('student');
        $r = $this->postJson('/api/backend/identity-documents/upload-url', ['fileName' => 'ktp.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 5]);
        $this->call('PUT', $r->json('uploadUrl'), [], [], [], ['CONTENT_TYPE' => 'application/pdf'], self::PDF)
            ->assertStatus(400)->assertJsonPath('message', 'Metadata file tidak sesuai dengan objek yang diunggah');
        Storage::disk('identity_quarantine')->assertMissing($r->json('storageKey'));
    }

    public function test_student_submits_a_document_that_is_scanned_clean_and_encrypted(): void
    {
        $this->loginAs('student');
        $key = $this->upload();
        $this->submit($key)->assertCreated()->assertJsonPath('message', 'Dokumen masuk karantina dan sedang dipindai');

        $doc = DB::table('identity_documents')->where('student_id', $this->studentId())->first();
        $this->assertSame('CLEAN', $doc->scan_status);
        $this->assertSame('PENDING_VERIFICATION', $doc->document_status);
        $this->assertSame(hash('sha256', self::PDF), $doc->sha256);
        Storage::disk('identity_private')->assertExists($key);
        Storage::disk('identity_quarantine')->assertMissing($key);

        $nik = DB::table('student_identifiers')->where('student_id', $this->studentId())->where('type', 'NIK')->first();
        $this->assertNotSame(self::NIK, $nik->value_encrypted);
        $this->assertSame('5678', $nik->last4);
        $this->assertTrue(DB::table('consents')->where('student_id', $this->studentId())->where('type', 'IDENTITY')->where('status', 'ACTIVE')->exists());

        $me = $this->getJson('/api/backend/identity-documents/me')->assertOk()
            ->assertJsonPath('document.documentStatus', 'PENDING_VERIFICATION')->assertJsonPath('document.scanStatus', 'CLEAN');
        $this->assertStringNotContainsString(self::NIK, $me->getContent());
        $this->assertContains('******5678', array_column($me->json('identifiers'), 'masked'));
    }

    public function test_submission_without_consent_is_rejected(): void
    {
        $this->loginAs('student');
        $this->submit($this->upload(), ['consent' => false])->assertStatus(400)->assertJsonPath('message', 'Persetujuan pemrosesan identitas wajib diberikan');
        $this->assertSame(0, DB::table('identity_documents')->count());
    }

    public function test_invalid_nik_is_a_validation_error(): void
    {
        $this->loginAs('student');
        $this->submit($this->upload(), ['nik' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('nik');
        $this->assertSame(0, DB::table('student_identifiers')->count());
    }

    public function test_minor_needs_an_active_guardian_consent(): void
    {
        DB::table('students')->where('id', $this->studentId())->update(['birth_date' => now()->subYears(15)->toDateString()]);
        $this->loginAs('student');
        $this->submit($this->upload())->assertForbidden()->assertJsonPath('message', 'Persetujuan wali aktif diperlukan untuk siswa di bawah 18 tahun');
        $this->assertSame(0, DB::table('identity_documents')->count());
    }

    public function test_second_submission_while_one_is_pending_conflicts(): void
    {
        $this->submittedDocument();
        $this->submit($this->upload())->assertStatus(409)->assertJsonPath('message', 'Masih ada dokumen identitas aktif atau menunggu verifikasi');
        $this->assertSame(1, DB::table('identity_documents')->count());
    }

    public function test_storage_key_of_another_student_is_rejected(): void
    {
        $this->loginAs('student');
        $foreign = $this->demoOrgId().'/'.$this->demoSchoolId().'/'.Str::ulid().'/'.Str::uuid().'.pdf';
        $this->submit($foreign)->assertForbidden()->assertJsonPath('message', 'Storage key tidak berada dalam scope pengguna');
    }

    public function test_infected_file_is_rejected_by_the_scan_and_never_reaches_the_verifier(): void
    {
        $bytes = "%PDF-1.4\nX5O!P%@AP[4\\PZX54(P^)7CC)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!\$H+H*";
        $this->loginAs('student');
        $key = $this->upload($bytes);
        $this->submit($key, [], $bytes)->assertCreated();

        $doc = DB::table('identity_documents')->first();
        $this->assertSame('INFECTED', $doc->scan_status);
        $this->assertSame('REJECTED', $doc->document_status);
        $this->assertSame('MALWARE_SIGNATURE', $doc->verification_notes);
        Storage::disk('identity_private')->assertMissing($key);

        $this->login($this->demoUser('identity-verifier'));
        $this->getJson('/api/backend/identity-documents/pending')->assertOk()->assertJsonCount(0);
    }

    public function test_verifier_sees_pending_documents_with_masked_identifiers_only(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('identity-verifier'));

        $r = $this->getJson('/api/backend/identity-documents/pending')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.student.fullName', 'Siswa Demo')->assertJsonPath('0.student.school.name', 'SMK Contoh Nusantara');
        $this->assertStringNotContainsString(self::NIK, $r->getContent());
        $this->assertSame('no-store, private', $r->headers->get('Cache-Control'));
    }

    public function test_roles_without_identity_verify_cannot_list_or_decide(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('teacher'));
        $this->getJson('/api/backend/identity-documents/pending')->assertForbidden();
        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'VALID'])->assertForbidden();
        $this->assertSame('PENDING_VERIFICATION', DB::table('identity_documents')->where('id', $id)->value('document_status'));
    }

    public function test_verifier_approves_a_document(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('identity-verifier'));

        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'VALID'])->assertOk()
            ->assertJsonPath('message', 'Status dokumen diperbarui menjadi VALID')->assertJsonPath('document.verificationStatus', 'VERIFIED');
        $this->assertSame(['VERIFIED'], DB::table('student_identifiers')->where('student_id', $this->studentId())->distinct()->pluck('status')->all());
        $this->assertTrue(DB::table('identity_reviews')->where('document_id', $id)->where('decision', 'VERIFIED')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('resource_id', $id)->where('action', 'VERIFY_IDENTITY_DOCUMENT')->exists());
        // A decided document is no longer pending, so a second decision is not possible.
        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'REJECTED', 'reasonCode' => 'OTHER', 'notes' => 'x'])->assertNotFound();
    }

    public function test_rejection_requires_a_reason_and_notes(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('identity-verifier'));

        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'REJECTED'])
            ->assertStatus(400)->assertJsonPath('message', 'Kode alasan dan catatan wajib untuk penolakan/perbaikan');
        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'REJECTED', 'reasonCode' => 'DOCUMENT_MISMATCH', 'notes' => 'Nama tidak sesuai'])
            ->assertOk()->assertJsonPath('document.documentStatus', 'REJECTED')->assertJsonPath('document.verificationStatus', 'REJECTED');
    }

    public function test_unknown_decision_is_rejected(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('identity-verifier'));
        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'PENDING_VERIFICATION'])
            ->assertStatus(400)->assertJsonPath('message', 'Keputusan verifikasi tidak valid');
    }

    public function test_verifier_of_another_school_cannot_see_or_decide(): void
    {
        $id = $this->submittedDocument();
        $other = $this->otherSchool();
        $this->login($this->makeUser('identity-verifier', $other['org'], $other['school']));

        $this->getJson('/api/backend/identity-documents/pending')->assertOk()->assertJsonCount(0);
        $this->putJson("/api/backend/identity-documents/$id/verify", ['status' => 'VALID'])->assertNotFound();
        $this->postJson("/api/backend/identity-documents/$id/access")->assertNotFound();
    }

    public function test_document_download_link_is_signed_and_bound_to_the_verifier(): void
    {
        $id = $this->submittedDocument();
        $this->login($this->demoUser('identity-verifier'));
        $url = $this->postJson("/api/backend/identity-documents/$id/access")->assertOk()->assertJsonPath('expiresIn', 90)->json('url');

        $file = $this->get($url)->assertOk();
        $this->assertSame(self::PDF, $file->getContent());
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));

        // Another verifier in the same school cannot reuse the link.
        $this->login($this->makeUser('identity-verifier', $this->demoOrgId(), $this->demoSchoolId()));
        $this->get($url)->assertForbidden();
    }
}
