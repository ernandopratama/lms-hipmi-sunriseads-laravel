<?php

namespace Tests\Feature\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AffiliateTest extends TestCase
{
    use CommerceFixtures;

    private function apply(array $body = [])
    {
        return $this->postJson('/api/backend/affiliate/accounts', $body + ['platform' => 'TIKTOK', 'username' => '@siswa.demo',
            'displayName' => 'Siswa Demo', 'profileUrl' => 'https://www.tiktok.com/@siswa.demo/?utm_source=ig&lang=id']);
    }

    private function account(?object $student = null, string $status = 'PENDING_VERIFICATION'): string
    {
        $student ??= $this->studentRow();
        $id = (string) Str::ulid();
        DB::table('affiliate_accounts')->insert(['id' => $id, 'organization_id' => DB::table('schools')->where('id', $student->school_id)->value('organization_id'),
            'school_id' => $student->school_id, 'student_id' => $student->id, 'platform' => 'TIKTOK', 'username' => 'akun'.Str::random(4),
            'profile_url' => 'https://www.tiktok.com/@akun', 'status' => $status, 'connected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function video(?object $student = null): string
    {
        $student ??= $this->studentRow();
        $assignment = $this->makeAssignment('AFFILIATE', [], $student);
        $sub = (string) Str::ulid();
        DB::table('submissions')->insert(['id' => $sub, 'assignment_id' => $assignment, 'user_id' => $student->user_id, 'student_id' => $student->id,
            'body' => '', 'status' => 'DRAFT', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('affiliate_videos')->insert(['id' => $id, 'submission_id' => $sub, 'student_id' => $student->id, 'affiliate_account_id' => $this->account($student, 'VERIFIED'),
            'platform' => 'TIKTOK', 'video_url' => 'https://www.tiktok.com/@akun/video/1', 'normalized_url' => 'https://www.tiktok.com/@akun/video/1',
            'published_at' => now()->subDays(5), 'product_name' => 'Produk', 'data_source' => 'MANUAL', 'verification' => 'UNVERIFIED', 'current_version' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function metricRow(string $videoId, array $overrides = []): array
    {
        return $overrides + ['videoId' => $videoId, 'snapshotDate' => now()->subDay()->toIso8601String(), 'views' => 1000, 'likes' => 50, 'comments' => 5, 'shares' => 2,
            'clicks' => 100, 'orders' => 10, 'unitsSold' => 12, 'refunds' => 0, 'gmv' => 500000, 'commission' => 50000, 'currency' => 'IDR',
            'evidenceUrl' => 'https://drive.example.com/bukti.png'];
    }

    // 4. Account apply and review

    public function test_guest_cannot_use_affiliate_endpoints(): void
    {
        $this->getJson('/api/backend/affiliate/accounts')->assertUnauthorized();
        $this->apply()->assertUnauthorized();
    }

    public function test_student_applies_with_normalized_profile_url_and_pending_status(): void
    {
        $this->loginAs('student');

        $this->apply()->assertCreated()->assertJsonPath('status', 'PENDING_VERIFICATION')
            ->assertJsonPath('username', 'siswa.demo')->assertJsonPath('profileUrl', 'https://www.tiktok.com/@siswa.demo?lang=id')
            ->assertJsonPath('studentId', $this->studentRow()->id)->assertJsonPath('student.studentNumber', 'DEMO-001')
            ->assertJsonPath('_count.videos', 0);
        $this->getJson('/api/backend/affiliate/accounts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.username', 'siswa.demo');
    }

    public function test_account_apply_validation_and_duplicates(): void
    {
        $this->loginAs('student');

        $this->apply(['profileUrl' => 'https://www.youtube.com/@siswa'])->assertBadRequest()->assertJsonPath('message', 'Domain URL tidak sesuai dengan platform');
        $this->apply(['platform' => 'FACEBOOK'])->assertUnprocessable();
        $this->apply(['username' => ''])->assertUnprocessable();
        $this->assertSame(0, DB::table('affiliate_accounts')->count());

        $this->apply()->assertCreated();
        $this->apply(['username' => 'siswa.demo'])->assertBadRequest()->assertJsonPath('message', 'Akun affiliate ini sudah terdaftar');
        $this->assertSame(1, DB::table('affiliate_accounts')->count());
    }

    public function test_teacher_without_create_permission_cannot_apply(): void
    {
        $this->loginAs('teacher');

        $this->apply()->assertForbidden();
        $this->assertSame(0, DB::table('affiliate_accounts')->count());
    }

    public function test_student_sees_only_own_accounts_while_teacher_sees_the_school(): void
    {
        $mine = $this->account();
        $other = DB::table('students')->where('user_id', $this->makeUser('student')->id)->first();
        $theirs = $this->account($other);

        $this->loginAs('student');
        $this->getJson("/api/backend/affiliate/accounts?studentId={$other->id}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine);

        $this->loginAs('teacher');
        $this->getJson('/api/backend/affiliate/accounts')->assertOk()->assertJsonCount(2);
        $this->getJson("/api/backend/affiliate/accounts?studentId={$other->id}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $theirs);
    }

    public function test_teacher_verifies_account_and_decision_is_final(): void
    {
        $id = $this->account();
        $teacher = $this->loginAs('teacher');

        $this->getJson('/api/backend/affiliate/verification-queue')->assertOk()->assertJsonPath('accounts.0.id', $id)
            ->assertJsonPath('accounts.0.student.studentNumber', 'DEMO-001');
        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'VERIFIED', 'notes' => ' Profil publik sesuai. '])->assertOk()
            ->assertJsonPath('status', 'VERIFIED')->assertJsonPath('reviewerNotes', 'Profil publik sesuai.')->assertJsonPath('reviewedById', $teacher->id);

        $review = DB::table('affiliate_account_reviews')->where('account_id', $id)->first();
        $this->assertSame(['VERIFIED', $teacher->id, 'Profil publik sesuai.'], [$review->decision, $review->reviewer_id, $review->notes]);
        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'REJECTED', 'notes' => 'Ubah keputusan'])->assertBadRequest()
            ->assertJsonPath('message', 'Akun affiliate sudah memiliki keputusan final');
        $this->assertSame(1, DB::table('affiliate_account_reviews')->count());
    }

    public function test_rejection_requires_notes(): void
    {
        $id = $this->account();
        $this->loginAs('teacher');

        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'REJECTED', 'notes' => '  '])->assertBadRequest()
            ->assertJsonPath('message', 'Catatan wajib untuk penolakan');
        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'PENDING_VERIFICATION'])->assertUnprocessable();
        $this->assertSame('PENDING_VERIFICATION', DB::table('affiliate_accounts')->where('id', $id)->value('status'));
    }

    public function test_student_cannot_review_and_teacher_of_another_school_gets_404(): void
    {
        $id = $this->account();
        $this->loginAs('student');
        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'VERIFIED'])->assertForbidden();

        $this->loginUser($this->makeUser('teacher', $this->newSchool()));
        $this->postJson("/api/backend/affiliate/accounts/$id/review", ['status' => 'VERIFIED'])->assertNotFound()
            ->assertJsonPath('message', 'Akun affiliate tidak ditemukan');
        $this->assertSame('PENDING_VERIFICATION', DB::table('affiliate_accounts')->where('id', $id)->value('status'));
    }

    // Content + manual snapshot

    public function test_student_submits_affiliate_content_for_open_assignment(): void
    {
        $assignment = $this->makeAssignment('AFFILIATE');
        $account = $this->account(null, 'VERIFIED');
        $username = DB::table('affiliate_accounts')->where('id', $account)->value('username');
        $this->loginAs('student');

        $this->postJson('/api/backend/affiliate/content', ['assignmentId' => $assignment, 'affiliateAccountId' => $account, 'platform' => 'TIKTOK',
            'videoUrl' => "https://www.tiktok.com/@$username/video/7350000000000000001?utm_campaign=x", 'publishedAt' => now()->subHour()->toIso8601String(),
            'productName' => 'Kaos Sablon', 'productUrl' => 'https://shopee.co.id/kaos', 'evidenceUrl' => 'https://drive.example.com/bukti',
            'disclosureAccepted' => true, 'ownershipDeclared' => true])->assertCreated()
            ->assertJsonPath('videoUrl', "https://www.tiktok.com/@$username/video/7350000000000000001")
            ->assertJsonPath('verification', 'UNVERIFIED')->assertJsonPath('currentVersion', 1)->assertJsonPath('submission.status', 'DRAFT');
        $this->assertSame(0, DB::table('affiliate_fraud_flags')->count());
    }

    public function test_student_cannot_record_snapshot_for_another_students_video(): void
    {
        $other = DB::table('students')->where('user_id', $this->makeUser('student')->id)->first();
        $video = $this->video($other);
        $this->loginAs('student');

        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video))->assertNotFound()
            ->assertJsonPath('message', 'Konten affiliate tidak ditemukan');
        $this->assertSame(0, DB::table('affiliate_metric_snapshots')->count());
    }

    public function test_manual_snapshot_derives_ratios_and_flags_suspicious_values(): void
    {
        $video = $this->video();
        $this->loginAs('student');

        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video))->assertCreated()
            ->assertJsonPath('ctr', '10')->assertJsonPath('conversionRate', '10')->assertJsonPath('averageOrderValue', '50000')
            ->assertJsonPath('commissionRate', '10')->assertJsonPath('verification', 'UNVERIFIED')->assertJsonPath('dataQualityFlags', []);

        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['snapshotDate' => now()->toIso8601String(),
            'views' => 10, 'clicks' => 20, 'orders' => 0, 'gmv' => 1000, 'commission' => 900, 'evidenceUrl' => null]))->assertCreated()
            ->assertJsonPath('verification', 'NEEDS_REVIEW')
            ->assertJsonPath('dataQualityFlags', ['CLICK_EXCEEDS_VIEWS', 'GMV_WITHOUT_ORDERS', 'SUSPICIOUS_COMMISSION_RATE', 'EVIDENCE_MISSING', 'LIFETIME_METRIC_DECREASE']);
    }

    public function test_snapshot_money_and_count_validation(): void
    {
        $video = $this->video();
        $this->loginAs('student');

        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['gmv' => -1]))->assertUnprocessable();
        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['commission' => 'banyak']))->assertUnprocessable();
        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['views' => 1.5]))->assertUnprocessable();
        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['orders' => -3]))->assertUnprocessable();
        $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['snapshotDate' => now()->addDay()->toIso8601String()]))
            ->assertBadRequest()->assertJsonPath('message', 'Tanggal snapshot tidak valid');
        $this->assertSame(0, DB::table('affiliate_metric_snapshots')->count());
    }

    // 5. Metric import

    public function test_teacher_imports_metric_rows_and_failures_are_recorded_per_row(): void
    {
        $video = $this->video();
        $foreign = $this->video(DB::table('students')->where('user_id', $this->makeUser('student', $this->newSchool())->id)->first());
        $teacher = $this->loginAs('teacher');

        $job = $this->postJson('/api/backend/affiliate/metric-imports', ['sourceName' => ' tiktok-oktober.csv ', 'rows' => [
            $this->metricRow($video, ['sourceReference' => 'ditimpa']), $this->metricRow('video-tidak-ada'), $this->metricRow($foreign),
            $this->metricRow($video, ['snapshotDate' => now()->addDay()->toIso8601String()]),
        ]])->assertCreated()->assertJsonPath('status', 'QUEUED')->assertJsonPath('totalRows', 4)->assertJsonPath('sourceName', 'tiktok-oktober.csv')->json('id');

        $row = DB::table('import_jobs')->where('id', $job)->first();
        $this->assertSame(['COMPLETED_WITH_ERRORS', 4, 1, 3], [$row->status, (int) $row->processed_rows, (int) $row->valid_rows, (int) $row->failed_rows]);
        $snapshot = DB::table('affiliate_metric_snapshots')->sole();
        $this->assertSame(['IMPORT', $job, $video, $teacher->id, 'tiktok-oktober.csv'],
            [$snapshot->source, $snapshot->import_job_id, $snapshot->affiliate_video_id, $snapshot->created_by_id, $snapshot->source_reference]);
        $this->assertEquals(500000, $snapshot->gmv);

        $this->getJson('/api/backend/affiliate/metric-imports')->assertOk()->assertJsonPath('0.id', $job)->assertJsonPath('0.failedRows', 3)
            ->assertJsonPath('0.rows', [['rowNumber' => 3, 'errors' => ['codes' => ['VIDEO_NOT_FOUND']]], ['rowNumber' => 4, 'errors' => ['codes' => ['VIDEO_NOT_FOUND']]],
                ['rowNumber' => 5, 'errors' => ['codes' => ['INVALID_SNAPSHOT_DATE']]]]);
    }

    public function test_import_rejects_invalid_money_and_quantities_before_queueing(): void
    {
        $video = $this->video();
        $this->loginAs('teacher');

        foreach ([['gmv' => -100], ['commission' => -1], ['views' => 'seribu'], ['unitsSold' => 2.5], ['snapshotDate' => 'kemarin']] as $bad) {
            $this->postJson('/api/backend/affiliate/metric-imports', ['sourceName' => 'x.csv', 'rows' => [$this->metricRow($video), $this->metricRow($video, $bad)]])
                ->assertUnprocessable();
        }
        $this->postJson('/api/backend/affiliate/metric-imports', ['sourceName' => 'x.csv', 'rows' => []])->assertUnprocessable();
        $this->postJson('/api/backend/affiliate/metric-imports', ['sourceName' => '   ', 'rows' => [$this->metricRow($video)]])->assertStatus(422);

        $this->assertSame(0, DB::table('import_jobs')->count());
        $this->assertSame(0, DB::table('affiliate_metric_snapshots')->count());
    }

    public function test_student_cannot_import_or_list_imports(): void
    {
        $video = $this->video();
        $this->loginAs('student');

        $this->postJson('/api/backend/affiliate/metric-imports', ['sourceName' => 'x.csv', 'rows' => [$this->metricRow($video)]])->assertForbidden();
        $this->getJson('/api/backend/affiliate/metric-imports')->assertForbidden();
        $this->assertSame(0, DB::table('import_jobs')->count());
    }

    public function test_teacher_verifies_metric_only_after_open_flags_are_resolved(): void
    {
        $video = $this->video();
        $this->loginAs('student');
        $metric = $this->postJson("/api/backend/affiliate/videos/$video/snapshots", $this->metricRow($video, ['evidenceUrl' => null]))->assertCreated()->json('id');
        $flag = DB::table('affiliate_fraud_flags')->where('metric_snapshot_id', $metric)->value('id');
        $this->loginAs('teacher');

        $this->postJson("/api/backend/affiliate/metrics/$metric/verify", ['decision' => 'VERIFIED'])->assertBadRequest()
            ->assertJsonPath('message', 'Selesaikan seluruh flag terbuka sebelum memverifikasi snapshot');
        $this->postJson("/api/backend/affiliate/flags/$flag/resolve", ['status' => 'RESOLVED', 'notes' => 'Bukti dikirim lewat chat'])->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->postJson("/api/backend/affiliate/metrics/$metric/verify", ['decision' => 'VERIFIED'])->assertOk()->assertJsonPath('verification', 'VERIFIED');
        $this->postJson("/api/backend/affiliate/metrics/$metric/verify", ['decision' => 'REJECTED', 'notes' => 'Ubah keputusan.'])->assertBadRequest()
            ->assertJsonPath('message', 'Snapshot metrik sudah memiliki keputusan final');

        $this->getJson("/api/backend/affiliate/students/{$this->studentRow()->id}/performance")->assertOk()
            ->assertJsonPath('totalViews', 1000)->assertJsonPath('totalOrders', 10)->assertJsonPath('totalVideos', 1);
    }
}
