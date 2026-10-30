<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operational overview. Queue figures come from the database queue tables; Laravel keeps
 * no in-process HTTP metrics, so that block is reported as unavailable instead of invented.
 */
class OperationsApiController extends Controller
{
    private const QUEUES = ['system', 'student-import', 'affiliate-metric-import', 'identity-scan', 'store-file-scan', 'report-export', 'helpdesk-attachment-scan', 'notification-delivery', 'certificate-generation'];

    public function __construct(private ApiContext $context) {}

    public function overview(Request $request)
    {
        $c = $this->context->resolve($request, 'monitoring', 'read');
        [$org, $school] = [$c['organization'], $c['school']];
        $since = now()->subDay();
        $queues = array_merge(self::QUEUES, ['default']);
        $stats = collect($queues)->map(fn ($name) => [
            'name' => $name,
            'waiting' => DB::table('jobs')->where('queue', $name)->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->count(),
            'active' => DB::table('jobs')->where('queue', $name)->whereNotNull('reserved_at')->count(),
            'delayed' => DB::table('jobs')->where('queue', $name)->whereNull('reserved_at')->where('available_at', '>', now()->timestamp)->count(),
            'failed' => DB::table('failed_jobs')->where('queue', $name)->count(),
            'completed' => 0,
        ])->all();
        $scope = fn ($q, string $col = 'school_id') => $q->where('organization_id', $org)->when($school, fn ($q) => $q->where($col, $school));
        $failedImports = $scope(DB::table('import_jobs'))->where('status', 'FAILED')->where('created_at', '>=', $since)->count();
        $failedExports = $scope(DB::table('export_jobs'))->where('status', 'FAILED')->where('created_at', '>=', $since)->count();
        $unsafeIdentity = DB::table('identity_documents as d')->join('students as s', 's.id', '=', 'd.student_id')->join('schools as sc', 'sc.id', '=', 's.school_id')
            ->whereIn('d.scan_status', ['FAILED', 'INFECTED'])->where('d.created_at', '>=', $since)->where('sc.organization_id', $org)->when($school, fn ($q) => $q->where('s.school_id', $school))->count();
        $unsafeStore = DB::table('student_store_files as f')->join('student_store_submissions as s', 's.id', '=', 'f.store_submission_id')
            ->whereIn('f.scan_status', ['FAILED', 'INFECTED'])->where('f.created_at', '>=', $since)->where('s.organization_id', $org)->when($school, fn ($q) => $q->where('s.school_id', $school))->count();
        $dead = DB::table('notification_deliveries as d')->join('notifications as n', 'n.id', '=', 'd.notification_id')->where('d.status', 'DEAD_LETTER')
            ->where('d.created_at', '>=', $since)->where('n.organization_id', $org)->when($school, fn ($q) => $q->where('n.school_id', $school))->count();
        $backlog = collect($stats)->sum(fn ($s) => $s['waiting'] + $s['delayed']);
        $alerts = array_values(array_filter([
            $backlog >= (int) env('ALERT_QUEUE_BACKLOG', 100) ? ['severity' => 'WARNING', 'code' => 'QUEUE_BACKLOG', 'message' => 'Antrean pekerjaan menumpuk.'] : null,
            $dead > 0 ? ['severity' => 'WARNING', 'code' => 'DEAD_LETTER', 'message' => 'Ada notifikasi yang masuk dead letter.'] : null,
        ]));
        return response()->json([
            'generatedAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'scope' => ['organizationId' => $org, 'schoolId' => $school ?: null],
            'http' => ['uptimeSeconds' => 0, 'windowMinutes' => 15, 'requests' => 0, 'serverErrors' => 0, 'clientErrors' => 0, 'errorRate' => 0,
                'latencyMs' => ['p50' => 0, 'p95' => 0, 'max' => 0], 'available' => false],
            'queues' => $stats, 'jobs24h' => ['failedImports' => $failedImports, 'failedExports' => $failedExports, 'deadLetters' => $dead],
            'uploads24h' => ['unsafeIdentityFiles' => $unsafeIdentity, 'unsafeSubmissionFiles' => $unsafeStore],
            'alerts' => $alerts, 'status' => collect($alerts)->contains('severity', 'CRITICAL') ? 'critical' : ($alerts ? 'warning' : 'healthy'),
        ]);
    }
}
