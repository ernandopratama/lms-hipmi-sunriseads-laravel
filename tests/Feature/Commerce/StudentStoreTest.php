<?php

namespace Tests\Feature\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentStoreTest extends TestCase
{
    use CommerceFixtures;

    private const PNG = "\x89PNG\r\n\x1a\n0000IHDR-bukti-toko";

    private string $assignment;
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('store_quarantine');
        Storage::fake('store_private');
        $template = (string) Str::ulid();
        DB::table('store_checklist_templates')->insert(['id' => $template, 'organization_id' => $this->orgId(), 'school_id' => $this->schoolId(),
            'name' => 'Checklist Shopee', 'platform' => 'SHOPEE', 'minimum_products' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['PROFIL', true, 60, 1], ['PROMO', false, 40, 2]] as [$code, $required, $weight, $position]) {
            $this->items[$code] = (string) Str::ulid();
            DB::table('store_checklist_items')->insert(['id' => $this->items[$code], 'template_id' => $template, 'code' => $code, 'label' => "Item $code",
                'weight' => $weight, 'is_required' => $required, 'position' => $position]);
        }
        $this->assignment = $this->makeAssignment('STORE', ['checklist_template_id' => $template]);
    }

    private function draft(array $overrides = [])
    {
        return $this->postJson('/api/backend/student-store/submissions/draft', $overrides + ['assignmentId' => $this->assignment, 'platform' => 'SHOPEE',
            'storeName' => 'Toko Siswa', 'storeUrl' => 'https://shopee.co.id/toko-siswa/', 'productCount' => 5, 'declarationAccepted' => true,
            'studentNotes' => 'Catatan', 'checklist' => [['itemId' => $this->items['PROFIL'], 'completed' => true], ['itemId' => $this->items['PROMO'], 'completed' => false]]]);
    }

    /** Prepare, PUT and register one PNG; the scan job runs after the response. Returns the file id. */
    private function uploadEvidence(string $submission, string $bytes = self::PNG): string
    {
        $signed = $this->postJson("/api/backend/student-store/submissions/$submission/files/upload-url",
            ['fileName' => 'bukti.png', 'mimeType' => 'image/png', 'sizeBytes' => strlen($bytes), 'kind' => 'EVIDENCE'])->assertOk()->assertJsonPath('expiresIn', 300)->json();
        $this->call('PUT', $signed['uploadUrl'], [], [], [], ['CONTENT_TYPE' => 'image/png'], $bytes)->assertNoContent();
        return $this->postJson("/api/backend/student-store/submissions/$submission/files", ['storageKey' => $signed['storageKey'], 'fileName' => 'bukti.png',
            'mimeType' => 'image/png', 'sizeBytes' => strlen($bytes), 'kind' => 'EVIDENCE'])->assertCreated()->assertJsonPath('scanStatus', 'PENDING')->json('id');
    }

    private function submitted(): string
    {
        $this->loginAs('student');
        $id = $this->draft()->assertOk()->json('id');
        $this->uploadEvidence($id);
        $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => true])->assertOk();
        return $id;
    }

    private function review(string $id, array $body)
    {
        return $this->postJson("/api/backend/student-store/submissions/$id/review", $body + ['publishGrade' => true]);
    }

    // Templates

    public function test_teacher_creates_checklist_template_whose_weights_total_100(): void
    {
        $this->loginAs('teacher');
        $items = [['code' => 'logo', 'label' => 'Logo toko', 'weight' => 50, 'isRequired' => true, 'position' => 1],
            ['code' => 'produk', 'label' => 'Tiga produk', 'weight' => 50, 'isRequired' => false, 'position' => 2]];

        $this->postJson('/api/backend/student-store/checklist-templates', ['name' => 'Checklist TikTok', 'platform' => 'TIKTOK', 'minimumProducts' => 3, 'items' => $items])
            ->assertCreated()->assertJsonPath('items.0.code', 'LOGO')->assertJsonPath('items.1.isRequired', false)->assertJsonPath('minimumProducts', 3);

        $items[1]['weight'] = 40;
        $this->postJson('/api/backend/student-store/checklist-templates', ['name' => 'Checklist Kurang', 'minimumProducts' => 3, 'items' => $items])
            ->assertBadRequest()->assertJsonPath('message', 'Checklist wajib memiliki item dengan total bobot tepat 100%');
        $this->postJson('/api/backend/student-store/checklist-templates', ['name' => 'Checklist Nol', 'minimumProducts' => 0, 'items' => $items])->assertUnprocessable();
        $this->getJson('/api/backend/student-store/checklist-templates')->assertOk()->assertJsonCount(2);
    }

    public function test_student_cannot_create_checklist_template(): void
    {
        $this->loginAs('student');

        $this->postJson('/api/backend/student-store/checklist-templates', ['name' => 'Checklist Siswa', 'minimumProducts' => 1,
            'items' => [['code' => 'AA', 'label' => 'Item', 'weight' => 100, 'isRequired' => true, 'position' => 1]]])->assertForbidden();
        $this->assertSame(1, DB::table('store_checklist_templates')->count());
    }

    // 6. Draft -> submit -> review

    public function test_guest_and_teacher_cannot_save_store_draft(): void
    {
        $this->draft()->assertUnauthorized();
        $this->loginAs('teacher');
        $this->draft()->assertForbidden();
        $this->assertSame(0, DB::table('student_store_submissions')->count());
    }

    public function test_student_lists_store_assignment_with_checklist(): void
    {
        $this->loginAs('student');

        $this->getJson('/api/backend/student-store/assignments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->assignment)
            ->assertJsonPath('0.checklistTemplate.items.0.code', 'PROFIL')->assertJsonPath('0.storeSubmissions', []);
    }

    public function test_draft_is_saved_and_versioned_with_optimistic_lock(): void
    {
        $this->loginAs('student');

        $first = $this->draft()->assertOk()->assertJsonPath('status', 'DRAFT')->assertJsonPath('rowVersion', 1)->json();
        $this->draft(['rowVersion' => 1, 'storeName' => 'Toko Baru'])->assertOk()->assertJsonPath('id', $first['id'])->assertJsonPath('rowVersion', 2);
        $this->draft(['rowVersion' => 1])->assertConflict()->assertJsonPath('message', 'Draf telah berubah di perangkat lain. Muat ulang sebelum menyimpan.');

        $store = DB::table('student_stores')->sole();
        $this->assertSame(['Toko Baru', 'https://shopee.co.id/toko-siswa', 'DRAFT'], [$store->store_name, $store->normalized_store_url, $store->activation_status]);
        $this->getJson("/api/backend/student-store/submissions/{$first['id']}")->assertOk()->assertJsonPath('studentStore.storeName', 'Toko Baru')
            ->assertJsonCount(2, 'checklistResponses')->assertJsonPath('fraudFlags', []);
    }

    public function test_draft_validation_rejects_bad_urls_counts_and_foreign_checklist_items(): void
    {
        $this->loginAs('student');

        $this->draft(['storeUrl' => 'https://tokopedia.com/toko'])->assertBadRequest()->assertJsonPath('message', 'Domain URL tidak sesuai platform');
        $this->draft(['platform' => 'WEBSITE', 'storeUrl' => 'http://192.168.1.10/toko'])->assertBadRequest()->assertJsonPath('message', 'URL internal atau privat tidak diizinkan');
        $this->draft(['productCount' => -1])->assertUnprocessable();
        $this->draft(['productCount' => 100001])->assertUnprocessable();
        $this->draft(['productCount' => 2.5])->assertUnprocessable();
        $this->draft(['checklist' => [['itemId' => 'item-lain', 'completed' => true]]])->assertBadRequest()
            ->assertJsonPath('message', 'Checklist memuat item di luar template tugas atau duplikat');

        $this->assertSame(0, DB::table('student_store_submissions')->count());
    }

    public function test_student_without_enrollment_cannot_draft_for_the_assignment(): void
    {
        $this->loginUser($this->makeUser('student'));

        $this->draft()->assertNotFound()->assertJsonPath('message', 'Tugas toko tidak tersedia');
        $this->assertSame(0, DB::table('student_store_submissions')->count());
    }

    public function test_duplicate_store_url_from_another_student_raises_fraud_flag(): void
    {
        $other = $this->makeUser('student');
        $this->enroll($this->assignment, DB::table('students')->where('user_id', $other->id)->first());
        $this->loginUser($other);
        $this->draft()->assertOk();
        $this->loginAs('student');

        $id = $this->draft(['storeUrl' => 'https://shopee.co.id/toko-siswa?ref=1'])->assertOk()->json('id');

        $this->assertSame('DUPLICATE_URL', DB::table('store_fraud_flags')->where('store_submission_id', $id)->value('type'));
        $this->getJson("/api/backend/student-store/submissions/$id")->assertOk()->assertJsonPath('fraudFlags', []);
    }

    public function test_submit_requires_declaration_minimum_products_and_clean_evidence(): void
    {
        $this->loginAs('student');
        $id = $this->draft(['productCount' => 2])->assertOk()->json('id');
        $submit = fn (bool $declared = true) => $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => $declared]);

        $submit(false)->assertBadRequest()->assertJsonPath('message', 'Deklarasi keamanan dan kepemilikan wajib diterima');
        $submit()->assertBadRequest()->assertJsonPath('message', 'Jumlah produk belum memenuhi minimum');
        $this->draft(['productCount' => 3, 'checklist' => [['itemId' => $this->items['PROFIL'], 'completed' => false]]])->assertOk();
        $submit()->assertBadRequest()->assertJsonPath('message', 'Checklist wajib belum lengkap');
        $this->draft(['productCount' => 3])->assertOk();
        $submit()->assertBadRequest()->assertJsonPath('message', 'Minimal satu bukti yang lolos pemindaian wajib tersedia');

        $this->assertSame('DRAFT', DB::table('student_store_submissions')->where('id', $id)->value('status'));
    }

    public function test_upload_scan_submit_and_download_evidence(): void
    {
        $this->loginAs('student');
        $id = $this->draft()->assertOk()->json('id');

        $file = $this->uploadEvidence($id);

        $row = DB::table('student_store_files')->where('id', $file)->first();
        $this->assertSame(['CLEAN', null, hash('sha256', self::PNG)], [$row->scan_status, $row->quarantine_key, $row->sha256]);
        Storage::disk('store_private')->assertExists($row->storage_key);
        Storage::disk('store_quarantine')->assertMissing($row->storage_key);

        $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => true])->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('currentVersion', 1);
        $this->assertSame(1, DB::table('student_store_submission_versions')->where('store_submission_id', $id)->count());

        $url = $this->postJson("/api/backend/student-store/files/$file/access")->assertOk()->assertJsonPath('expiresIn', 120)->json('url');
        $this->assertSame(self::PNG, $this->get($url)->assertOk()->streamedContent());
        $this->get(preg_replace('/signature=[^&]+/', 'signature=palsu', $url))->assertForbidden();

        $this->deleteJson("/api/backend/student-store/files/$file")->assertForbidden()
            ->assertJsonPath('message', 'File hanya dapat dihapus saat submission dapat diedit');
    }

    public function test_upload_rejects_wrong_type_size_and_foreign_storage_key(): void
    {
        $this->loginAs('student');
        $id = $this->draft()->assertOk()->json('id');
        $prepare = fn (array $body) => $this->postJson("/api/backend/student-store/submissions/$id/files/upload-url", $body + ['fileName' => 'bukti.png',
            'mimeType' => 'image/png', 'sizeBytes' => 100, 'kind' => 'EVIDENCE']);

        $prepare(['mimeType' => 'text/html', 'fileName' => 'a.html'])->assertBadRequest()->assertJsonPath('message', 'Format file tidak diizinkan');
        $prepare(['fileName' => 'bukti.jpg'])->assertBadRequest()->assertJsonPath('message', 'Ekstensi file tidak sesuai dengan format yang dipilih');
        $prepare(['sizeBytes' => 20 * 1024 * 1024 + 1])->assertUnprocessable();
        $prepare(['sizeBytes' => 0])->assertUnprocessable();

        $this->postJson("/api/backend/student-store/submissions/$id/files", ['storageKey' => 'org-lain/sekolah/siswa/store/x/'.Str::uuid().'.png',
            'fileName' => 'bukti.png', 'mimeType' => 'image/png', 'sizeBytes' => 100, 'kind' => 'EVIDENCE'])->assertForbidden()
            ->assertJsonPath('message', 'Storage key file berada di luar scope submission');
        $this->assertSame(0, DB::table('student_store_files')->count());
    }

    public function test_file_with_bad_signature_is_marked_infected_and_blocks_submit(): void
    {
        $this->loginAs('student');
        $id = $this->draft()->assertOk()->json('id');

        $file = $this->uploadEvidence($id, 'bukan-png-sama-sekali');

        $this->assertSame('INFECTED', DB::table('student_store_files')->where('id', $file)->value('scan_status'));
        $this->postJson("/api/backend/student-store/files/$file/access")->assertNotFound();
        $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => true])->assertBadRequest()
            ->assertJsonPath('message', 'Minimal satu bukti yang lolos pemindaian wajib tersedia');
    }

    public function test_student_can_delete_own_file_while_draft(): void
    {
        $this->loginAs('student');
        $id = $this->draft()->assertOk()->json('id');
        $file = $this->uploadEvidence($id);
        $key = DB::table('student_store_files')->where('id', $file)->value('storage_key');

        $this->deleteJson("/api/backend/student-store/files/$file")->assertOk()->assertJsonPath('deleted', true);
        $this->assertSame(0, DB::table('student_store_files')->count());
        // The scanned object lives on the private disk; deleting the row must not leave it behind.
        Storage::disk('store_private')->assertMissing($key);
    }

    public function test_other_student_cannot_read_submission_or_file(): void
    {
        $id = $this->submitted();
        $file = DB::table('student_store_files')->value('id');
        $other = $this->makeUser('student');
        $this->enroll($this->assignment, DB::table('students')->where('user_id', $other->id)->first());
        $this->loginUser($other);

        $this->getJson("/api/backend/student-store/submissions/$id")->assertNotFound();
        $this->postJson("/api/backend/student-store/files/$file/access")->assertNotFound();
        $this->deleteJson("/api/backend/student-store/files/$file")->assertNotFound();
        $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => true])->assertNotFound();
    }

    public function test_teacher_sees_submission_in_queue_and_requests_revision(): void
    {
        $id = $this->submitted();
        $this->loginAs('teacher');

        $this->getJson('/api/backend/student-store/review/queue')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.studentStore.storeName', 'Toko Siswa')->assertJsonPath('0._count.files', 1);
        $this->review($id, ['decision' => 'REVISION_REQUIRED'])->assertBadRequest()->assertJsonPath('message', 'Feedback wajib untuk penolakan atau revisi');
        $this->review($id, ['decision' => 'DRAFT', 'feedback' => 'x'])->assertUnprocessable();
        $this->review($id, ['decision' => 'REVISION_REQUIRED', 'feedback' => 'Tambah foto produk'])->assertOk()->assertJsonPath('decision', 'REVISION_REQUIRED');
        $this->assertSame('REVISION_REQUIRED', DB::table('student_store_submissions')->where('id', $id)->value('status'));

        $this->loginAs('student');
        $this->draft(['productCount' => 8])->assertOk();
        $this->postJson("/api/backend/student-store/submissions/$id/submit", ['declarationAccepted' => true])->assertOk()
            ->assertJsonPath('status', 'RESUBMITTED')->assertJsonPath('currentVersion', 2);
        $this->getJson("/api/backend/student-store/submissions/$id")->assertOk()->assertJsonPath('feedback', 'Tambah foto produk')
            ->assertJsonPath('reviews.0.reviewer.fullName', 'Guru Demo');
    }

    public function test_approval_with_rubric_scores_locks_submission_and_activates_store(): void
    {
        $rubric = (string) Str::ulid();
        DB::table('rubrics')->insert(['id' => $rubric, 'organization_id' => $this->orgId(), 'name' => 'Rubrik Toko', 'created_at' => now(), 'updated_at' => now()]);
        $criteria = [(string) Str::ulid(), (string) Str::ulid()];
        DB::table('rubric_criteria')->insert([['id' => $criteria[0], 'rubric_id' => $rubric, 'name' => 'Profil', 'weight' => 60, 'max_score' => 10, 'position' => 1],
            ['id' => $criteria[1], 'rubric_id' => $rubric, 'name' => 'Produk', 'weight' => 40, 'max_score' => 10, 'position' => 2]]);
        DB::table('assignments')->where('id', $this->assignment)->update(['rubric_id' => $rubric]);
        $id = $this->submitted();
        $this->loginAs('teacher');

        $this->review($id, ['decision' => 'APPROVED', 'scores' => [['criterionId' => $criteria[0], 'score' => 10]]])->assertBadRequest()
            ->assertJsonPath('message', 'Semua kriteria rubrik wajib dinilai');
        $this->review($id, ['decision' => 'APPROVED', 'scores' => [['criterionId' => $criteria[0], 'score' => 11], ['criterionId' => $criteria[1], 'score' => 5]]])
            ->assertBadRequest()->assertJsonPath('message', 'Nilai rubrik tidak valid');
        $this->review($id, ['decision' => 'APPROVED', 'scores' => [['criterionId' => $criteria[0], 'score' => 10], ['criterionId' => $criteria[1], 'score' => 5]]])
            ->assertOk()->assertJsonPath('totalScore', 80)->assertJsonCount(2, 'scores');

        $row = DB::table('student_store_submissions')->where('id', $id)->first();
        $this->assertSame(['APPROVED', 80], [$row->status, (int) $row->score]);
        $this->assertNotNull($row->locked_at);
        $this->assertSame('ACTIVE', DB::table('student_stores')->value('activation_status'));
        $this->review($id, ['decision' => 'APPROVED', 'scores' => []])->assertBadRequest()->assertJsonPath('message', 'Submission tidak berada dalam antrean review');

        $this->loginAs('student');
        $this->draft()->assertBadRequest()->assertJsonPath('message', 'Submission tidak dapat diubah pada status saat ini');
    }

    public function test_unpublished_review_stays_hidden_from_student(): void
    {
        $id = $this->submitted();
        $this->loginAs('teacher');
        $this->review($id, ['decision' => 'APPROVED', 'publishGrade' => false])->assertOk();
        $this->assertSame('UNDER_REVIEW', DB::table('student_store_submissions')->where('id', $id)->value('status'));

        $this->loginAs('student');
        $this->getJson("/api/backend/student-store/submissions/$id")->assertOk()->assertJsonPath('reviews', [])->assertJsonPath('score', null);
    }

    public function test_student_cannot_review_and_other_teacher_cannot_see_submission(): void
    {
        $id = $this->submitted();
        $this->review($id, ['decision' => 'APPROVED'])->assertForbidden();

        $this->loginUser($this->makeUser('teacher'));
        $this->getJson('/api/backend/student-store/review/queue')->assertOk()->assertJsonCount(0);
        $this->review($id, ['decision' => 'APPROVED'])->assertNotFound();
        $this->getJson("/api/backend/student-store/submissions/$id")->assertNotFound();
        $this->assertSame('SUBMITTED', DB::table('student_store_submissions')->where('id', $id)->value('status'));
    }
}
