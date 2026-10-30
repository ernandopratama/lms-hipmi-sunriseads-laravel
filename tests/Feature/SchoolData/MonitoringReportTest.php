<?php

namespace Tests\Feature\SchoolData;

use App\Jobs\GenerateReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Monitoring overview, report export (with its job), parent scope and the school dashboard. */
class MonitoringReportTest extends TestCase
{
    use SchoolDataHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('exports');
    }

    private function demoStudentId(): string
    {
        return DB::table('students')->where('user_id', $this->demoUser('student')->id)->value('id');
    }

    private function exportCsv(string $type = 'STUDENT_PROGRESS', array $extra = []): array
    {
        $id = $this->postJson('/api/backend/monitoring/exports', ['reportType' => $type] + $extra)->assertCreated()->assertJsonPath('status', 'QUEUED')->json('id');
        $job = DB::table('export_jobs')->where('id', $id)->first();

        return [$id, $job, $job->storage_key ? Storage::disk('exports')->get($job->storage_key) : null];
    }

    public function test_guest_cannot_open_monitoring(): void
    {
        $this->getJson('/api/backend/monitoring/overview')->assertUnauthorized();
    }

    public function test_monitoring_needs_the_advanced_plan(): void
    {
        $this->loginAs('school-admin');
        $this->getJson('/api/backend/monitoring/overview')->assertForbidden()->assertJsonPath('message', 'Dashboard lanjutan tidak termasuk dalam paket aktif');
    }

    public function test_role_without_monitoring_permission_is_forbidden(): void
    {
        $this->grantAdvanced();
        $this->login($this->demoUser('reviewer'));
        $this->getJson('/api/backend/monitoring/overview')->assertForbidden();
    }

    public function test_overview_is_scoped_to_the_admins_school(): void
    {
        $this->grantAdvanced();
        $this->makeStudent($this->otherSchool()['school'], 'LAIN-1');
        $this->loginAs('school-admin');

        $r = $this->getJson('/api/backend/monitoring/overview')->assertOk()
            ->assertJsonPath('audience', 'SCHOOL')->assertJsonPath('scope.schoolIds', [$this->demoSchoolId()])
            ->assertJsonPath('kpi.totalStudents', 1)->assertJsonCount(1, 'students')->assertJsonPath('students.0.studentNumber', 'DEMO-001');
        $this->assertStringNotContainsString('LAIN-1', $r->getContent());
    }

    public function test_class_filter_of_another_school_is_forbidden(): void
    {
        $this->grantAdvanced();
        $foreign = $this->makeClass($this->otherSchool()['school'], 'XI-TKJ');
        $this->loginAs('school-admin');
        $this->getJson("/api/backend/monitoring/overview?classId=$foreign")->assertForbidden()->assertJsonPath('message', 'Kelas berada di luar ruang lingkup Anda');
    }

    public function test_teacher_without_assignments_sees_no_students(): void
    {
        $this->grantAdvanced();
        $this->loginAs('teacher');
        $this->getJson('/api/backend/monitoring/overview')->assertOk()->assertJsonPath('audience', 'TEACHER')->assertJsonPath('kpi.totalStudents', 0);
    }

    public function test_parent_sees_only_the_linked_child(): void
    {
        $this->grantAdvanced();
        $sibling = $this->makeStudent($this->demoSchoolId(), 'DEMO-002');
        $this->login($this->demoUser('parent'));

        $this->getJson('/api/backend/monitoring/overview')->assertOk()->assertJsonPath('audience', 'PARENT')
            ->assertJsonPath('kpi.totalStudents', 1)->assertJsonPath('students.0.id', $this->demoStudentId())
            ->assertJsonPath('distributions.identityVerification', []);
        $this->getJson('/api/backend/monitoring/students/'.$this->demoStudentId().'/learning-progress')->assertOk()->assertJsonPath('student.fullName', 'Siswa Demo');
        $this->getJson("/api/backend/monitoring/students/$sibling/learning-progress")->assertNotFound();
    }

    public function test_parent_with_a_revoked_link_sees_nothing(): void
    {
        $this->grantAdvanced();
        DB::table('student_parent_links')->update(['revoked_at' => now()]);
        $this->login($this->demoUser('parent'));

        $this->getJson('/api/backend/monitoring/overview')->assertOk()->assertJsonPath('kpi.totalStudents', 0);
        $this->getJson('/api/backend/monitoring/students/'.$this->demoStudentId().'/learning-progress')->assertNotFound();
    }

    public function test_parent_cannot_read_student_master_data(): void
    {
        $this->login($this->demoUser('parent'));
        $this->getJson('/api/backend/students/'.$this->demoStudentId())->assertForbidden();
    }

    public function test_report_options_need_the_export_entitlement(): void
    {
        $this->loginAs('school-admin');
        $this->getJson('/api/backend/monitoring/reports/options')->assertForbidden()->assertJsonPath('message', 'Laporan tidak termasuk dalam paket aktif');
        $this->postJson('/api/backend/monitoring/exports', ['reportType' => 'STUDENT_PROGRESS'])->assertForbidden();
        $this->assertSame(0, DB::table('export_jobs')->count());
    }

    public function test_teacher_gets_no_governance_report_types(): void
    {
        $this->grantAdvanced();
        $this->loginAs('teacher');
        $types = array_column($this->getJson('/api/backend/monitoring/reports/options')->assertOk()->json('reportTypes'), 'value');
        $this->assertContains('STUDENT_PROGRESS', $types);
        $this->assertNotContains('IDENTITY_VERIFICATION', $types);

        $this->postJson('/api/backend/monitoring/exports', ['reportType' => 'IDENTITY_VERIFICATION'])
            ->assertForbidden()->assertJsonPath('message', 'Jenis laporan ini hanya tersedia untuk pengelola sekolah atau program');
    }

    public function test_unknown_report_type_is_rejected(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/monitoring/exports', ['reportType' => 'EVERYTHING'])->assertUnprocessable()->assertJsonValidationErrors('reportType');
    }

    public function test_report_export_job_writes_a_school_scoped_safe_csv(): void
    {
        $this->grantAdvanced();
        $this->makeStudent($this->otherSchool()['school'], 'LAIN-1');
        DB::table('students')->where('id', $this->demoStudentId())->update(['full_name' => '=HYPERLINK("http://x")']);
        $this->loginAs('school-admin');

        [$id, $job, $csv] = $this->exportCsv();
        $this->assertSame('COMPLETED', $job->status);
        $this->assertSame(1, (int) $job->row_count);
        $this->assertStringStartsWith("\u{FEFF}\"Nomor Siswa\"", $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://x"")"', $csv);
        $this->assertStringNotContainsString('LAIN-1', $csv);

        $this->getJson('/api/backend/monitoring/exports')->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.status', 'COMPLETED');
    }

    public function test_export_download_is_signed_recorded_and_bound_to_the_user(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        [$id, , $csv] = $this->exportCsv();

        $url = $this->getJson("/api/backend/monitoring/exports/$id/download")->assertOk()->json('url');
        $this->assertSame(1, DB::table('export_download_events')->where('export_job_id', $id)->count());
        $this->assertSame($csv, $this->get($url)->assertOk()->getContent());

        $this->login($this->makeUser('school-admin', $this->demoOrgId(), $this->demoSchoolId()));
        $this->get($url)->assertForbidden();
    }

    public function test_export_of_another_school_cannot_be_downloaded(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        [$id] = $this->exportCsv();

        $this->login($this->otherSchool()['admin']);
        $this->getJson("/api/backend/monitoring/exports/$id/download")->assertNotFound();
        $this->getJson('/api/backend/monitoring/exports')->assertOk()->assertJsonCount(0);
    }

    public function test_teacher_sees_only_exports_they_requested(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        [$adminExport] = $this->exportCsv();

        $this->login($this->demoUser('teacher'));
        [$own] = $this->exportCsv();
        $this->assertSame([$own], array_column($this->getJson('/api/backend/monitoring/exports')->assertOk()->json(), 'id'));
        $this->getJson("/api/backend/monitoring/exports/$adminExport/download")->assertForbidden();
    }

    public function test_parent_export_contains_only_the_linked_child(): void
    {
        $this->grantAdvanced();
        $this->makeStudent($this->demoSchoolId(), 'DEMO-002');
        $this->login($this->demoUser('parent'));

        [, $job, $csv] = $this->exportCsv();
        $this->assertSame('COMPLETED', $job->status);
        $this->assertStringContainsString('DEMO-001', $csv);
        $this->assertStringNotContainsString('DEMO-002', $csv);
    }

    public function test_export_job_fails_when_the_entitlement_has_lapsed(): void
    {
        Queue::fake();
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $id = $this->postJson('/api/backend/monitoring/exports', ['reportType' => 'STUDENT_PROGRESS'])->assertCreated()->json('id');
        Queue::assertPushed(GenerateReportExport::class);

        DB::table('access_grants')->update(['status' => 'REVOKED']);
        (new GenerateReportExport($id))->handle();
        $job = DB::table('export_jobs')->where('id', $id)->first();
        $this->assertSame('FAILED', $job->status);
        $this->assertSame('ENTITLEMENT_NOT_ACTIVE', $job->failure_reason);
        $this->assertNull($job->storage_key);
    }

    public function test_creator_cannot_approve_their_own_public_statistic(): void
    {
        $this->grantAdvanced();
        $this->loginAs('school-admin');
        $id = $this->postJson('/api/backend/monitoring/public-statistics', ['title' => 'Capaian semester'])->assertCreated()->assertJsonPath('status', 'PENDING_APPROVAL')->json('id');

        $this->postJson("/api/backend/monitoring/public-statistics/$id/review", ['decision' => 'APPROVED'])
            ->assertForbidden()->assertJsonPath('message', 'Pembuat tidak dapat menyetujui sendiri');
        $this->assertSame('PENDING_APPROVAL', DB::table('public_statistics')->where('id', $id)->value('status'));
    }

    public function test_school_dashboard_counts_only_the_selected_school(): void
    {
        $this->makeStudent($this->otherSchool()['school'], 'LAIN-1');
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/dashboard/school')->assertOk()
            ->assertJsonPath('stats.totalStudents', 1)->assertJsonPath('stats.totalTeachers', 1)->assertJsonPath('advancedAccess', false)
            ->assertJsonPath('performance.totalViews', 0);
    }

    public function test_school_dashboard_requires_dashboard_permission(): void
    {
        $this->getJson('/api/backend/dashboard/school')->assertUnauthorized();
        $this->login($this->demoUser('reviewer'));
        $this->getJson('/api/backend/dashboard/school')->assertForbidden();
    }
}
