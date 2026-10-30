<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateMetricApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'affiliate', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'affiliate.metrics.manage'), 403,
            'Metrik affiliate tidak termasuk dalam paket aktif');
        return $c;
    }

    public function imports(Request $request)
    {
        $c = $this->scope($request, 'import');
        return response()->json(DB::table('import_jobs')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->where('type', 'AFFILIATE_METRICS')
            ->orderByDesc('created_at')->limit(50)->get()->map(function ($r) {
                $failed = DB::table('import_rows')->where('import_job_id', $r->id)->where('status', 'FAILED')
                    ->limit(100)->get()->map(fn ($row) => ['rowNumber' => $row->row_number,
                        'errors' => $row->errors ? json_decode($row->errors, true) : null]);
                return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
                    'createdById' => $r->created_by_id, 'type' => $r->type, 'sourceName' => $r->source_name,
                    'status' => $r->status, 'totalRows' => $r->total_rows, 'validRows' => $r->valid_rows,
                    'failedRows' => $r->failed_rows, 'processedRows' => $r->processed_rows,
                    'failureReason' => $r->failure_reason, 'startedAt' => $r->started_at,
                    'completedAt' => $r->completed_at, 'createdAt' => $r->created_at,
                    'updatedAt' => $r->updated_at, 'rows' => $failed];
            }));
    }

    public function queue(Request $request)
    {
        $c = $this->scope($request, 'verify');
        $accounts = DB::table('affiliate_accounts')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->where('status', 'PENDING_VERIFICATION')
            ->orderBy('created_at')->get()->map(function ($r) {
                $s = DB::table('students')->where('id', $r->student_id)->first();
                return [...$this->camel($r), 'student' => $s ? ['fullName' => $s->full_name,
                    'studentNumber' => $s->student_number] : null];
            });
        $metrics = DB::table('affiliate_metric_snapshots')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->whereIn('verification', ['UNVERIFIED', 'NEEDS_REVIEW'])
            ->orderBy('snapshot_date')->limit(300)->get()->map(function ($r) {
                $s = DB::table('students')->where('id', $r->student_id)->first();
                $v = DB::table('affiliate_videos')->where('id', $r->affiliate_video_id)->first();
                return [...$this->camel($r), 'student' => $s ? ['fullName' => $s->full_name,
                    'studentNumber' => $s->student_number] : null,
                    'affiliateVideo' => $v ? ['id' => $v->id, 'videoUrl' => $v->video_url,
                        'productName' => $v->product_name, 'campaignName' => $v->campaign_name] : null,
                    'fraudFlags' => DB::table('affiliate_fraud_flags')->where('metric_snapshot_id', $r->id)
                        ->where('status', 'OPEN')->get()->map(fn ($f) => $this->camel($f))];
            });
        $flags = DB::table('affiliate_fraud_flags as f')->join('affiliate_videos as v', 'v.id', '=', 'f.affiliate_video_id')
            ->join('students as s', 's.id', '=', 'v.student_id')->where('s.school_id', $c['school'])
            ->where('f.status', 'OPEN')->orderBy('f.created_at')->limit(300)->get(['f.*'])
            ->map(function ($r) {
                $v = DB::table('affiliate_videos')->where('id', $r->affiliate_video_id)->first();
                $s = DB::table('students')->where('id', $v->student_id)->first();
                $metric = $r->metric_snapshot_id ? DB::table('affiliate_metric_snapshots')->where('id', $r->metric_snapshot_id)->first() : null;
                return [...$this->camel($r), 'affiliateVideo' => ['videoUrl' => $v->video_url,
                    'student' => ['fullName' => $s->full_name, 'studentNumber' => $s->student_number]],
                    'metricSnapshot' => $metric ? $this->camel($metric) : null];
            });
        return response()->json(compact('accounts', 'metrics', 'flags'));
    }

    private function camel(object $r): array
    {
        return collect((array) $r)->mapWithKeys(fn ($value, $key) => [Str::camel($key) =>
            in_array($key, ['raw_values', 'data_quality_flags', 'evidence'], true) && $value !== null
                ? json_decode($value, true) : $value])->all();
    }

    public function myPerformance(Request $request)
    {
        $c = $this->scope($request, 'read');
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        return $this->performanceFor($c, $c['student']->id);
    }

    public function studentPerformance(Request $request, string $studentId)
    {
        $c = $this->scope($request, 'read');
        return $this->performanceFor($c, $c['student']?->id ?? $studentId);
    }

    private function performanceFor(array $c, string $studentId)
    {
        abort_unless(DB::table('students')->where('id', $studentId)->where('school_id', $c['school'])->exists(),
            404, 'Siswa tidak ditemukan');
        $totals = ['totalViews' => 0, 'totalClicks' => 0, 'totalOrders' => 0,
            'totalGmv' => 0, 'totalCommission' => 0];
        $videos = DB::table('affiliate_videos')->where('student_id', $studentId)
            ->orderByDesc('created_at')->get()->map(function ($v) use ($c, &$totals) {
                $submission = DB::table('submissions')->where('id', $v->submission_id)->first();
                $assignment = $submission ? DB::table('assignments')->where('id', $submission->assignment_id)->first() : null;
                $account = $v->affiliate_account_id ? DB::table('affiliate_accounts')->where('id', $v->affiliate_account_id)->first() : null;
                $snapshots = DB::table('affiliate_metric_snapshots')->where('affiliate_video_id', $v->id)
                    ->where('organization_id', $c['organization'])->where('school_id', $c['school'])
                    ->orderByDesc('snapshot_date')->limit(30)->get()->map(function ($r) {
                        return [...$this->camel($r), 'corrections' => DB::table('affiliate_metric_snapshots')
                            ->where('supersedes_id', $r->id)->get(['id'])->map(fn ($row) => ['id' => $row->id])];
                    });
                $latest = $snapshots->first(fn ($r) => $r['verification'] === 'VERIFIED' && count($r['corrections']) === 0);
                if ($latest) {
                    $totals['totalViews'] += (int) $latest['views'];
                    $totals['totalClicks'] += (int) $latest['clicks'];
                    $totals['totalOrders'] += (int) $latest['orders'];
                    $totals['totalGmv'] += (float) $latest['gmv'];
                    $totals['totalCommission'] += (float) $latest['commission'];
                }
                return [...$this->camel($v),
                    'submission' => $submission ? ['id' => $submission->id, 'status' => $submission->status,
                        'score' => $submission->score, 'feedback' => $submission->feedback,
                        'assignment' => $assignment ? ['title' => $assignment->title] : null] : null,
                    'account' => $account ? ['id' => $account->id, 'username' => $account->username,
                        'platform' => $account->platform, 'status' => $account->status] : null,
                    'metricSnapshots' => $snapshots];
            });
        $views = $totals['totalViews'];
        $clicks = $totals['totalClicks'];
        $orders = $totals['totalOrders'];
        $gmv = $totals['totalGmv'];
        return response()->json(['totalVideos' => $videos->count(), ...$totals,
            'ctr' => $views ? round($clicks / $views * 100, 2) : 0,
            'conversionRate' => $clicks ? round($orders / $clicks * 100, 2) : 0,
            'averageOrderValue' => $orders ? round($gmv / $orders, 2) : 0,
            'commissionRate' => $gmv ? round($totals['totalCommission'] / $gmv * 100, 2) : 0,
            'videos' => $videos]);
    }

    public function workspace(Request $request)
    {
        $c = $this->context->resolve($request, 'affiliate', 'read');
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']->id);
        abort_unless($plan && $this->access->enabled($plan, 'affiliate.content.submit'), 403);
        $studentId = $c['student']->id;
        $accounts = DB::table('affiliate_accounts')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->where('student_id', $studentId)
            ->orderByDesc('created_at')->limit(200)->get()->map(fn ($a) => [...$this->camel($a),
                'student' => ['id' => $studentId, 'fullName' => $c['student']->full_name,
                    'studentNumber' => $c['student']->student_number],
                '_count' => ['videos' => DB::table('affiliate_videos')->where('affiliate_account_id', $a->id)->count()]]);
        $assignments = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('course.organization_id', $c['organization'])->where('course.school_id', $c['school'])
            ->where('a.type', 'AFFILIATE')->where('a.is_active', true)->whereNotNull('a.published_at')
            ->where('a.published_at', '<=', now())
            ->whereIn('a.module_id', $this->access->moduleIds($c['organization'], $c['school'], $studentId, $plan))
            ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $studentId)
                ->where('school_id', $c['school'])->where('status', 'ACTIVE')->select('course_id'))
            ->orderBy('a.end_date')->get(['a.*'])->map(function ($a) use ($studentId) {
                $course = DB::table('courses')->where('id', $a->course_id)->first();
                $rubric = $a->rubric_id ? DB::table('rubrics')->where('id', $a->rubric_id)->first() : null;
                $submissions = DB::table('submissions')->where('assignment_id', $a->id)
                    ->where('student_id', $studentId)->get()->map(function ($s) {
                        $videos = DB::table('affiliate_videos')->where('submission_id', $s->id)->get()
                            ->map(fn ($v) => [...$this->camel($v),
                                'metricSnapshots' => DB::table('affiliate_metric_snapshots')->where('affiliate_video_id', $v->id)
                                    ->orderByDesc('snapshot_date')->limit(10)->get()->map(fn ($m) => $this->camel($m)),
                                'versions' => DB::table('affiliate_content_versions')->where('affiliate_video_id', $v->id)
                                    ->orderByDesc('version_number')->get()->map(fn ($ver) => $this->camel($ver))]);
                        return [...$this->camel($s), 'affiliateVideos' => $videos,
                            'reviews' => DB::table('submission_reviews')->where('submission_id', $s->id)
                                ->where('is_published', true)->orderByDesc('created_at')->get()
                                ->map(fn ($review) => $this->camel($review))];
                    });
                return [...$this->camel($a), 'course' => $course ? ['name' => $course->name, 'code' => $course->code] : null,
                    'rubric' => $rubric ? [...$this->camel($rubric), 'criteria' => DB::table('rubric_criteria')
                        ->where('rubric_id', $rubric->id)->orderBy('position')->get()->map(fn ($r) => $this->camel($r))] : null,
                    'submissions' => $submissions];
            });
        $performance = $this->performanceFor($c, $studentId)->getData(true);
        $providers = collect(['TIKTOK', 'SHOPEE', 'INSTAGRAM', 'YOUTUBE'])->map(fn ($platform) => [
            'platform' => $platform, 'supportsOAuth' => false, 'supportsMetricSync' => false,
            'inputMode' => 'MANUAL_OR_IMPORT']);
        return response()->json(compact('accounts', 'assignments', 'performance', 'providers'));
    }

    public function export(Request $request)
    {
        $c = $this->scope($request, 'read');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'reports.export'), 403,
            'Ekspor laporan tidak termasuk dalam paket aktif');
        $csv = [implode(',', ['Nomor Induk', 'Nama Lengkap', 'Kelas', 'Total Video', 'Views', 'Clicks',
            'Orders', 'GMV (Rupiah)', 'Komisi (Rupiah)', 'CTR', 'Conversion Rate'])];
        foreach (DB::table('students')->where('school_id', $c['school'])->orderBy('id')->get() as $s) {
            $p = $this->performanceFor($c, $s->id)->getData(true);
            $class = $s->class_id ? DB::table('classes')->where('id', $s->class_id)->value('name') : null;
            $values = [$s->student_number, $s->full_name, $class ?: 'N/A', $p['totalVideos'],
                $p['totalViews'], $p['totalClicks'], $p['totalOrders'], $p['totalGmv'],
                $p['totalCommission'], number_format($p['ctr'], 2, '.', '').'%',
                number_format($p['conversionRate'], 2, '.', '').'%'];
            $csv[] = implode(',', array_map(function ($value) {
                $text = (string) $value;
                if (preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $text)) $text = "'".$text;
                return '"'.str_replace('"', '""', $text).'"';
            }, $values));
        }
        return response(implode("\n", $csv), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="laporan-performa-affiliate.csv"']);
    }

    public function resolveFlag(Request $request, string $id)
    {
        $c = $this->scope($request, 'verify');
        $d = $request->validate(['status' => 'required|in:DISMISSED,CONFIRMED,RESOLVED',
            'notes' => 'required|string|min:3|max:2000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = DB::table('affiliate_fraud_flags as f')->join('affiliate_videos as v', 'v.id', '=', 'f.affiliate_video_id')
                ->join('students as s', 's.id', '=', 'v.student_id')
                ->where('f.id', $id)->where('s.school_id', $c['school'])
                ->first(['f.*']);
            abort_unless($r, 404, 'Flag kualitas data tidak ditemukan');
            abort_unless($r->status === 'OPEN', 400, 'Flag sudah diselesaikan');
            DB::table('affiliate_fraud_flags')->where('id', $id)->where('status', 'OPEN')->update([
                'status' => $d['status'], 'resolution_notes' => trim($d['notes']),
                'resolved_by_id' => $c['user'], 'resolved_at' => now()]);
            $r = DB::table('affiliate_fraud_flags')->where('id', $id)->first();
            return response()->json(['id' => $r->id, 'affiliateVideoId' => $r->affiliate_video_id,
                'metricSnapshotId' => $r->metric_snapshot_id, 'type' => $r->type, 'reason' => $r->reason,
                'evidence' => $r->evidence ? json_decode($r->evidence, true) : null,
                'confidence' => $r->confidence, 'status' => $r->status,
                'resolvedById' => $r->resolved_by_id, 'resolutionNotes' => $r->resolution_notes,
                'resolvedAt' => $r->resolved_at, 'createdAt' => $r->created_at]);
        });
    }

    public function verify(Request $request, string $id)
    {
        $c = $this->scope($request, 'verify');
        $d = $request->validate(['decision' => 'required|in:VERIFIED,REJECTED,NEEDS_REVIEW',
            'notes' => 'sometimes|nullable|string|max:2000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = DB::table('affiliate_metric_snapshots')->where('id', $id)
                ->where('organization_id', $c['organization'])->where('school_id', $c['school'])
                ->lockForUpdate()->first();
            abort_unless($r, 404, 'Snapshot metrik tidak ditemukan');
            abort_unless(in_array($r->verification, ['UNVERIFIED', 'NEEDS_REVIEW'], true), 400,
                'Snapshot metrik sudah memiliki keputusan final');
            abort_if($d['decision'] !== 'VERIFIED' && ! trim($d['notes'] ?? ''), 400,
                'Catatan wajib untuk hasil selain terverifikasi');
            abort_if($d['decision'] === 'VERIFIED' && DB::table('affiliate_fraud_flags')
                ->where('metric_snapshot_id', $id)->where('status', 'OPEN')->exists(), 400,
                'Selesaikan seluruh flag terbuka sebelum memverifikasi snapshot');
            DB::table('affiliate_metric_verifications')->insert(['id' => (string) Str::ulid(),
                'metric_snapshot_id' => $id, 'reviewer_id' => $c['user'],
                'decision' => $d['decision'], 'notes' => trim($d['notes'] ?? '') ?: null, 'created_at' => now()]);
            DB::table('affiliate_metric_snapshots')->where('id', $id)->update(['verification' => $d['decision'],
                'verified_by_id' => $c['user'], 'verified_at' => now(),
                'verification_notes' => trim($d['notes'] ?? '') ?: null]);
            $row = DB::table('affiliate_metric_snapshots')->where('id', $id)->first();
            return response()->json(collect((array) $row)->mapWithKeys(fn ($value, $key) => [Str::camel($key) =>
                in_array($key, ['raw_values', 'data_quality_flags', 'evidence'], true) && $value !== null
                    ? json_decode($value, true) : $value])->all());
        });
    }
}
