<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportExport;
use App\Support\MonitoringScope;
use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Report export requests, signed downloads and public-statistic snapshots. */
class MonitoringExportApiController extends Controller
{
    private const PRIVILEGED = ['USER_ENROLLMENTS', 'IDENTITY_VERIFICATION', 'CONSENT_STATUS', 'TEACHER_WORKLOAD', 'IMPORT_HISTORY', 'AUDIT_SUMMARY'];
    private const TYPES = ['USER_ENROLLMENTS', 'LEARNING_ACTIVITY', 'STUDENT_PROGRESS', 'ASSIGNMENT_SUBMISSIONS', 'GRADES_RUBRICS', 'STUDENT_STORES', 'STORE_REVIEW_REVISIONS',
        'AFFILIATE_CONTENT', 'AFFILIATE_PERFORMANCE', 'EARLY_WARNINGS', 'IDENTITY_VERIFICATION', 'CONSENT_STATUS', 'TEACHER_WORKLOAD', 'IMPORT_HISTORY', 'AUDIT_SUMMARY'];

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access, private MonitoringScope $scope) {}

    private function iso($v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function entitled(Request $request, string $resource, string $action, string $key, string $message): array
    {
        $c = $this->context->resolve($request, $resource, $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, $key), 403, $message);
        return $c;
    }

    private function broad(array $c): bool
    {
        return (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal']);
    }

    public function create(Request $request)
    {
        $c = $this->entitled($request, 'reports', 'export', 'reports.export', 'Laporan dan export tidak termasuk dalam paket aktif');
        $d = $request->validate(['reportType' => 'required|in:'.implode(',', self::TYPES), 'format' => 'sometimes|in:CSV', 'timezone' => 'sometimes|string|max:64',
            'periodDays' => 'sometimes|integer|min:7|max:365', 'classId' => 'sometimes|nullable|string']);
        abort_if(in_array($d['reportType'], self::PRIVILEGED, true) && ! $this->broad($c), 403, 'Jenis laporan ini hanya tersedia untuk pengelola sekolah atau program');
        $scope = $this->scope->resolve($c, $d['classId'] ?? null);
        $id = (string) Str::ulid();
        DB::table('export_jobs')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'requested_by_id' => $c['user'], 'report_type' => $d['reportType'],
            'status' => 'QUEUED', 'format' => $d['format'] ?? 'CSV', 'timezone' => $d['timezone'] ?? 'Asia/Jakarta',
            'filters' => json_encode(['periodDays' => (int) ($d['periodDays'] ?? 30), 'classId' => $d['classId'] ?? null, 'studentIds' => $scope['studentIds']->values()->all()]),
            'created_at' => now(), 'updated_at' => now()]);
        // Read before dispatching: a synchronous queue would already have finished the job.
        $j = DB::table('export_jobs')->where('id', $id)->first();
        GenerateReportExport::dispatch($id)->afterCommit();
        return response()->json(['id' => $j->id, 'organizationId' => $j->organization_id, 'schoolId' => $j->school_id, 'requestedById' => $j->requested_by_id, 'reportType' => $j->report_type,
            'status' => $j->status, 'format' => $j->format, 'filters' => json_decode($j->filters, true), 'timezone' => $j->timezone, 'storageKey' => null, 'fileName' => null, 'rowCount' => null,
            'failureReason' => null, 'startedAt' => null, 'completedAt' => null, 'expiresAt' => null, 'createdAt' => $this->iso($j->created_at), 'updatedAt' => $this->iso($j->updated_at)], 201);
    }

    private function downloadable(array $c, string $id): object
    {
        $j = DB::table('export_jobs')->where('id', $id)->where('organization_id', $c['organization'])->where('school_id', $c['school'])->first();
        abort_unless($j, 404, 'Export tidak ditemukan');
        abort_if(! $this->broad($c) && $j->requested_by_id !== $c['user'], 403, 'Export berada di luar ruang lingkup Anda');
        abort_if($j->status !== 'COMPLETED' || ! $j->storage_key || ! $j->expires_at || CarbonImmutable::parse($j->expires_at, 'UTC')->lessThanOrEqualTo(now()), 400, 'File export belum siap atau sudah kedaluwarsa');
        return $j;
    }

    public function link(Request $request, string $id)
    {
        $c = $this->entitled($request, 'reports', 'read', 'reports.export', 'Laporan dan export tidak termasuk dalam paket aktif');
        $j = $this->downloadable($c, $id);
        DB::table('export_download_events')->insert(['id' => (string) Str::ulid(), 'export_job_id' => $j->id, 'organization_id' => $c['organization'], 'school_id' => $j->school_id, 'actor_id' => $c['user'], 'created_at' => now()]);
        return response()->json(['fileName' => $j->file_name, 'expiresAt' => $this->iso($j->expires_at),
            'url' => URL::temporarySignedRoute('monitoring.export-file', now()->addSeconds(120), ['job' => $id, 'u' => $c['user']])]);
    }

    public function file(Request $request)
    {
        $c = $this->entitled($request, 'reports', 'read', 'reports.export', 'Laporan dan export tidak termasuk dalam paket aktif');
        abort_unless($request->query('u') === $c['user'], 403, 'Tautan tidak berlaku untuk pengguna ini');
        $j = $this->downloadable($c, (string) $request->query('job'));
        return response(Storage::disk('exports')->get($j->storage_key), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$j->file_name.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function createStatistic(Request $request)
    {
        $c = $this->context->resolve($request, 'public-statistics', 'approve');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'dashboards.advanced.read'), 403, 'Dashboard lanjutan tidak termasuk paket aktif');
        $d = $request->validate(['title' => 'required|string|min:3|max:140', 'periodDays' => 'sometimes|integer|min:7|max:365', 'classId' => 'sometimes|nullable|string']);
        // The snapshot uses the same figures a monitoring user sees, so reuse the overview.
        $overview = app(MonitoringApiController::class)->overview($request)->getData(true);
        $days = (int) ($d['periodDays'] ?? 30);
        $now = now();
        $id = (string) Str::ulid();
        DB::table('public_statistics')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'title' => trim($d['title']),
            'period_start' => $now->copy()->subDays($days), 'period_end' => $now, 'cohort_size' => $overview['kpi']['totalStudents'],
            'metrics' => json_encode(['participatingStudents' => $overview['kpi']['totalStudents'], 'averageLearningProgress' => $overview['kpi']['averageLearningProgress'],
                'approvedStores' => $overview['kpi']['storeApproved'], 'approvedAffiliateContent' => $overview['kpi']['affiliateApproved']]),
            'status' => 'PENDING_APPROVAL', 'created_by_id' => $c['user'], 'created_at' => $now, 'updated_at' => $now]);
        $s = DB::table('public_statistics')->where('id', $id)->first();
        return response()->json(['id' => $s->id, 'organizationId' => $s->organization_id, 'schoolId' => $s->school_id, 'title' => $s->title, 'periodStart' => $this->iso($s->period_start),
            'periodEnd' => $this->iso($s->period_end), 'cohortSize' => (int) $s->cohort_size, 'minimumCohort' => (int) $s->minimum_cohort, 'metrics' => json_decode($s->metrics, true), 'status' => $s->status,
            'createdById' => $s->created_by_id, 'reviewedById' => null, 'reviewNotes' => null, 'approvedAt' => null, 'publishedAt' => null,
            'createdAt' => $this->iso($s->created_at), 'updatedAt' => $this->iso($s->updated_at)], 201);
    }
}
