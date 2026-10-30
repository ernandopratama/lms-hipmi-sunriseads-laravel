<?php

namespace App\Http\Controllers;

use App\Support\MonitoringScope;
use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monitoring overview, per-student learning progress and early-warning evaluation;
 * mirrors apps/api/src/monitoring. Scope is always one selected school: the source's
 * cross-school "program" audience needs school-switching that this app does not have yet.
 */
class MonitoringApiController extends Controller
{
    private const RULES = [
        ['INACTIVITY_7D', 'Tidak aktif 7 hari', 'Mendeteksi siswa yang memerlukan dukungan untuk kembali aktif.', 'INACTIVITY', ['days' => 7], 'ATTENTION'],
        ['INVITATION_NOT_ACTIVATED', 'Undangan belum diaktifkan', 'Mendeteksi akun siswa yang memerlukan bantuan aktivasi setelah undangan.', 'INVITATION_PENDING', ['days' => 3], 'INFO'],
        ['LEARNING_PROGRESS_STALLED', 'Progress materi tertinggal', 'Mendeteksi materi yang sudah dimulai tetapi lama tidak dilanjutkan.', 'LEARNING_STALLED', ['days' => 7], 'ATTENTION'],
        ['DEADLINE_APPROACHING_3D', 'Tenggat mendekat', 'Mengingatkan tugas yang belum dimulai ketika tenggat semakin dekat.', 'DEADLINE_APPROACHING', ['days' => 3], 'INFO'],
        ['OVERDUE_ASSIGNMENT', 'Tugas melewati tenggat', 'Menandai tugas yang belum selesai setelah tenggat.', 'OVERDUE_ASSIGNMENT', ['days' => 0], 'HIGH'],
        ['STALE_DRAFT_7D', 'Draf tidak diperbarui', 'Mendeteksi draf yang mungkin memerlukan bantuan untuk dilanjutkan.', 'STALE_DRAFT', ['days' => 7], 'ATTENTION'],
        ['REVISION_REQUIRED_REPEAT', 'Revisi berulang', 'Mendorong pendampingan saat beberapa hasil kerja memerlukan revisi.', 'REVISION_REQUIRED', ['count' => 2], 'ATTENTION'],
        ['LOW_GRADE_REPEAT', 'Nilai rendah berulang', 'Menawarkan dukungan belajar setelah beberapa nilai terpublikasi berada di bawah ambang.', 'LOW_GRADE', ['count' => 2, 'percent' => 60], 'ATTENTION'],
        ['STORE_EVIDENCE_INCOMPLETE', 'Bukti Student Store belum lengkap', 'Memeriksa URL dan bukti pendukung toko yang belum lengkap.', 'STORE_INCOMPLETE', ['minimumFiles' => 1], 'INFO'],
        ['AFFILIATE_CONTENT_UNVERIFIED', 'Konten affiliate belum terverifikasi', 'Mendorong tindak lanjut konten yang lama belum terverifikasi.', 'AFFILIATE_CONTENT', ['days' => 7], 'INFO'],
        ['AFFILIATE_CONTENT_NOT_PUBLISHED', 'Konten affiliate belum dipublikasikan', 'Mendeteksi tugas affiliate mendekati tenggat tanpa konten yang tercatat.', 'AFFILIATE_NOT_PUBLISHED', ['days' => 3], 'INFO'],
        ['AFFILIATE_METRIC_STALE', 'Metrik affiliate belum diperbarui', 'Mengingatkan pembaruan snapshot performa.', 'STALE_METRIC', ['days' => 14], 'INFO'],
        ['OPEN_DATA_QUALITY_FLAG', 'Penanda kualitas data terbuka', 'Memerlukan pemeriksaan manusia atas penanda kualitas data.', 'DATA_QUALITY', ['count' => 1], 'HIGH'],
    ];

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access, private MonitoringScope $scope) {}

    private function iso($v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function guard(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'monitoring', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'dashboards.advanced.read'), 403, 'Dashboard lanjutan tidak termasuk dalam paket aktif');
        return $c;
    }

    private function query(Request $request): array
    {
        $d = $request->validate(['periodDays' => 'sometimes|integer|min:7|max:365', 'classId' => 'sometimes|nullable|string']);
        return ['days' => (int) ($d['periodDays'] ?? 30), 'classId' => $d['classId'] ?? null];
    }

    private function countBy(Collection $rows, callable $key): array
    {
        return $rows->groupBy(fn ($r) => (string) ($key($r) ?: 'BELUM_ADA'))->map(fn ($g, $k) => ['key' => (string) $k, 'count' => $g->count()])->values()->all();
    }

    private function grades(Collection $scores): array
    {
        return collect([['0–59', 0, 59], ['60–74', 60, 74], ['75–89', 75, 89], ['90–100', 90, 100]])
            ->map(fn ($b) => ['key' => $b[0], 'count' => $scores->filter(fn ($s) => $s >= $b[1] && $s <= $b[2])->count()])->all();
    }

    public function overview(Request $request)
    {
        $c = $this->guard($request, 'read');
        $q = $this->query($request);
        $now = CarbonImmutable::now('UTC');
        $start = $now->subDays($q['days']);
        $scope = $this->scope->resolve($c, $q['classId']);
        $ids = $scope['studentIds'];
        $audience = $this->scope->audience($c['roles']);

        $students = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->leftJoin('classes as k', 'k.id', '=', 's.class_id')
            ->whereIn('s.id', $ids)->orderBy('s.full_name')->get(['s.id', 's.full_name', 's.student_number', 's.school_id', 's.class_id', 'k.name as class_name', 'u.id as user_id', 'u.last_login_at']);
        $assignments = $this->scope->assignments($c, $scope)->get(['a.id', 'a.title', 'a.type', 'a.end_date', 'a.max_score', 'a.course_id']);
        $lessons = $this->scope->lessons($c, $scope)->get(['l.id', 'l.module_id']);
        $enrol = DB::table('course_enrollments')->whereIn('student_id', $ids)->where('status', 'ACTIVE')->get(['course_id', 'student_id']);
        $courseEnr = DB::table('course_enrollments')->whereIn('student_id', $ids)->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->when($scope['courseIds'], fn ($q) => $q->whereIn('course_id', $scope['courseIds']))->get(['student_id', 'status']);
        $progress = DB::table('lesson_progress')->whereIn('student_id', $ids)->whereIn('lesson_id', $lessons->pluck('id'))->get(['student_id', 'lesson_id', 'state', 'progress_percent', 'updated_at']);
        $assignmentIds = $assignments->pluck('id');
        $subs = DB::table('submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')->whereIn('s.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)
            ->get(['s.id', 's.student_id', 's.status', 's.score', 's.grade_published_at', 's.updated_at', 'a.max_score']);
        $stores = DB::table('student_store_submissions as s')->leftJoin('student_stores as st', 'st.id', '=', 's.student_store_id')->whereIn('s.student_id', $ids)
            ->whereIn('s.assignment_id', $assignmentIds)->get(['s.id', 's.student_id', 's.status', 's.updated_at', 'st.platform']);
        $videos = DB::table('affiliate_videos as v')->join('submissions as s', 's.id', '=', 'v.submission_id')->whereIn('v.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)
            ->get(['v.id', 'v.student_id', 'v.verification']);
        $superseded = DB::table('affiliate_metric_snapshots')->whereNotNull('supersedes_id')->pluck('supersedes_id');
        $snaps = DB::table('affiliate_metric_snapshots as m')->join('affiliate_videos as v', 'v.id', '=', 'm.affiliate_video_id')->join('submissions as s', 's.id', '=', 'v.submission_id')
            ->whereIn('m.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)->where('m.snapshot_date', '>=', $start)->where('m.snapshot_date', '<=', $now)
            ->where('m.verification', 'VERIFIED')->whereNotIn('m.id', $superseded)->orderBy('m.snapshot_date')
            ->get(['m.affiliate_video_id', 'm.snapshot_date', 'm.views', 'm.clicks', 'm.orders', 'm.gmv', 'm.commission']);
        $warnings = DB::table('early_warnings')->where('organization_id', $c['organization'])->whereIn('student_id', $ids)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->orderByDesc('severity')->orderByDesc('triggered_at')->get(['id', 'student_id', 'class_id', 'severity', 'status', 'title', 'message', 'triggered_at']);
        $broad = (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal']);
        $imports = DB::table('import_jobs')->where('organization_id', $c['organization'])->where('school_id', $c['school'])->where('created_at', '>=', $start)
            ->when(! $broad, fn ($q) => $q->where('created_by_id', $c['user']))->get(['status', 'type', 'created_at']);
        $identity = DB::table('identity_documents')->whereIn('student_id', $ids)->selectRaw('verification_status as k, count(*) as n')->groupBy('verification_status')->get();

        $lessonsByModule = $lessons->groupBy('module_id');
        $progressKey = $progress->keyBy(fn ($p) => $p->student_id.':'.$p->lesson_id);
        $summary = $students->mapWithKeys(function ($s) use ($c, $lessons, $progressKey, $scope) {
            $plan = $this->access->resolve($c['organization'], $s->school_id, $s->user_id, $s->id);
            $modules = $plan ? $this->access->moduleIds($c['organization'], $s->school_id, $s->id, $plan) : collect();
            $expected = $lessons->filter(fn ($l) => $modules->contains($l->module_id));
            $items = $expected->map(fn ($l) => $progressKey->get($s->id.':'.$l->id));
            return [$s->id => ['percent' => $expected->count() ? $items->sum(fn ($i) => $i->progress_percent ?? 0) / $expected->count() : 0,
                'completed' => $items->filter(fn ($i) => ($i->state ?? null) === 'COMPLETED')->count(), 'total' => $expected->count()]];
        });
        $activeStudents = $students->filter(fn ($s) => $s->last_login_at && CarbonImmutable::parse($s->last_login_at, 'UTC')->gte($start))->count();
        $avgProgress = $students->count() ? $summary->sum('percent') / $students->count() : 0;
        $approved = $subs->where('status', 'APPROVED')->count();
        $pending = $subs->whereIn('status', ['SUBMITTED', 'RESUBMITTED', 'UNDER_REVIEW'])->count();
        $revision = $subs->where('status', 'REVISION_REQUIRED')->count() + $stores->where('status', 'REVISION_REQUIRED')->count();
        $published = $subs->filter(fn ($s) => $s->grade_published_at && $s->score !== null);
        $pct = fn ($s) => ((float) $s->score / max((int) $s->max_score, 1)) * 100;
        $avgScore = $published->count() ? $published->sum($pct) / $published->count() : null;
        $completedEnr = $courseEnr->where('status', 'COMPLETED')->count();
        $expectedSubs = $assignments->sum(fn ($a) => $enrol->where('course_id', $a->course_id)->count());
        $latest = $snaps->groupBy('affiliate_video_id')->map->last();
        $totals = ['views' => $latest->sum('views'), 'clicks' => $latest->sum('clicks'), 'orders' => $latest->sum('orders'), 'gmv' => $latest->sum(fn ($m) => (float) $m->gmv), 'commission' => $latest->sum(fn ($m) => (float) $m->commission)];
        $trend = $snaps->groupBy(fn ($m) => substr($m->snapshot_date, 0, 10).':'.$m->affiliate_video_id)->map->last()->groupBy(fn ($m) => substr($m->snapshot_date, 0, 10))
            ->map(fn ($g, $date) => ['date' => (string) $date, 'views' => $g->sum('views'), 'clicks' => $g->sum('clicks'), 'orders' => $g->sum('orders')])->sortKeys()->values()->take(-31)->values();

        $activity = $students->map(function ($s) use ($progress, $subs, $warnings, $summary) {
            $lastP = $progress->where('student_id', $s->id)->max('updated_at');
            $lastS = $subs->where('student_id', $s->id)->max('updated_at');
            $last = collect([$s->last_login_at, $lastP, $lastS])->filter()->sortDesc()->first();
            return ['id' => $s->id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number, 'className' => $s->class_name ?: 'Belum ada kelas',
                'progressPercent' => (int) round($summary[$s->id]['percent']), 'completedLessons' => $summary[$s->id]['completed'], 'totalLessons' => $summary[$s->id]['total'],
                'submissionCompleted' => $subs->where('student_id', $s->id)->where('status', 'APPROVED')->count(), 'warningCount' => $warnings->where('student_id', $s->id)->count(),
                'lastActivity' => $this->iso($last)];
        })->sort(fn ($a, $b) => $b['warningCount'] <=> $a['warningCount'] ?: strcmp($a['fullName'], $b['fullName']))->values();
        $r1 = fn ($v) => round($v * 10) / 10;

        return response()->json([
            'audience' => $audience, 'scope' => ['organizationId' => $c['organization'], 'schoolIds' => $scope['schoolIds'], 'classId' => $q['classId']],
            'period' => ['days' => $q['days'], 'start' => $this->iso($start), 'end' => $this->iso($now), 'timezone' => 'Asia/Jakarta'], 'lastUpdated' => $this->iso($now),
            'definitions' => ['activeStudents' => "Siswa dengan login dalam {$q['days']} hari terakhir",
                'learningProgress' => 'Rata-rata progres terhadap seluruh materi yang dapat diakses siswa, termasuk yang belum dimulai',
                'affiliatePerformance' => 'Snapshot terverifikasi terbaru per konten pada periode terpilih; snapshot yang sudah dikoreksi tidak dihitung',
                'earlyWarning' => 'Sinyal pendampingan berbasis aturan; bukan hukuman otomatis'],
            'kpi' => ['totalStudents' => $students->count(), 'activeStudents' => $activeStudents, 'inactiveStudents' => max($students->count() - $activeStudents, 0),
                'totalTeachers' => DB::table('teachers')->where('school_id', $c['school'])->count(), 'totalClasses' => DB::table('classes')->where('school_id', $c['school'])->where('is_active', true)->count(),
                'courseEnrollments' => $courseEnr->count(), 'courseCompletionRate' => $r1($courseEnr->count() ? $completedEnr / $courseEnr->count() * 100 : 0),
                'averageLearningProgress' => $r1($avgProgress), 'startedLessons' => $progress->where('state', '!=', 'NOT_STARTED')->count(), 'completedLessons' => $progress->where('state', 'COMPLETED')->count(),
                'activeAssignments' => $assignments->count(), 'approvedSubmissions' => $approved, 'submissionCompletionRate' => $r1($expectedSubs ? $approved / $expectedSubs * 100 : 0),
                'pendingReviews' => $pending, 'revisionRequired' => $revision, 'storeApproved' => $stores->where('status', 'APPROVED')->count(),
                'affiliateApproved' => $videos->where('verification', 'VERIFIED')->count(), 'averageScore' => $avgScore === null ? null : $r1($avgScore),
                'openWarnings' => $warnings->count(), 'verifiedMetricSnapshots' => $latest->count()] + $totals,
            'distributions' => ['submissions' => $this->countBy($subs, fn ($r) => $r->status), 'stores' => $this->countBy($stores, fn ($r) => $r->status),
                'storePlatforms' => $this->countBy($stores->filter(fn ($r) => $r->platform), fn ($r) => $r->platform), 'affiliateContent' => $this->countBy($videos, fn ($r) => $r->verification),
                'warnings' => $this->countBy($warnings, fn ($r) => $r->severity), 'grades' => $this->grades($published->map($pct)), 'imports' => $this->countBy($imports, fn ($r) => $r->status),
                'identityVerification' => in_array($audience, ['SCHOOL', 'PROGRAM'], true) ? $identity->map(fn ($r) => ['key' => $r->k, 'count' => (int) $r->n])->values()->all() : []],
            'trends' => ['affiliate' => $trend], 'warnings' => $audience === 'PROGRAM' ? [] : $warnings->take(12)->map(fn ($w) => ['id' => $w->id, 'studentId' => $w->student_id, 'classId' => $w->class_id,
                'severity' => $w->severity, 'status' => $w->status, 'title' => $w->title, 'message' => $w->message, 'triggeredAt' => $this->iso($w->triggered_at)])->values(),
            'students' => $audience === 'PROGRAM' ? [] : $activity->take(50)->values(), 'schools' => [],
        ]);
    }

    public function studentProgress(Request $request, string $id)
    {
        $c = $this->guard($request, 'read');
        $scope = $this->scope->resolve($c, null);
        abort_unless(in_array($id, $scope['studentIds']->all(), true), 404, 'Siswa tidak ditemukan dalam ruang lingkup Anda');
        $s = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->leftJoin('classes as k', 'k.id', '=', 's.class_id')->where('s.id', $id)
            ->first(['s.id', 's.full_name', 's.student_number', 's.user_id', 's.school_id', 'sc.name as school_name', 'k.name as class_name']);
        $plan = $this->access->resolve($c['organization'], $s->school_id, $s->user_id, $s->id);
        $moduleIds = $plan ? $this->access->moduleIds($c['organization'], $s->school_id, $s->id, $plan) : collect();
        $modules = DB::table('course_modules as m')->join('courses as co', 'co.id', '=', 'm.course_id')->whereIn('m.id', $moduleIds)->where('m.is_published', true)
            ->when($scope['courseIds'], fn ($q) => $q->whereIn('m.course_id', $scope['courseIds']))->orderBy('m.course_id')->orderBy('m.position')
            ->get(['m.id', 'm.title', 'm.position', 'co.id as course_id', 'co.code', 'co.title as course_name']);
        $lessons = DB::table('lessons')->whereIn('module_id', $modules->pluck('id'))->where('is_published', true)->orderBy('position')->get(['id', 'module_id', 'title', 'position', 'estimated_minutes'])->groupBy('module_id');
        $progress = DB::table('lesson_progress')->where('student_id', $id)->whereIn('lesson_id', $lessons->flatten(1)->pluck('id'))->get()->keyBy('lesson_id');
        $components = app(\App\Support\LessonProgress::class);
        return response()->json(['student' => ['id' => $s->id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number, 'schoolName' => $s->school_name, 'className' => $s->class_name ?: 'Belum ada kelas'],
            'modules' => $modules->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'position' => (int) $m->position, 'course' => ['id' => $m->course_id, 'code' => $m->code, 'name' => $m->course_name],
                'lessons' => ($lessons[$m->id] ?? collect())->map(function ($l) use ($progress, $components, $id) {
                    $p = $progress->get($l->id);
                    return ['id' => $l->id, 'title' => $l->title, 'position' => (int) $l->position, 'estimatedMinutes' => $l->estimated_minutes,
                        'progress' => ($p ? ['state' => $p->state, 'progressPercent' => (int) $p->progress_percent, 'lastPosition' => $p->last_position, 'updatedAt' => $this->iso($p->updated_at)]
                            : ['state' => 'NOT_STARTED', 'progressPercent' => 0, 'lastPosition' => null, 'updatedAt' => null]) + ['components' => $components->components($id, $l->id)]];
                })->values()])->values()]);
    }

    private function rules(array $c): Collection
    {
        $out = collect();
        foreach (self::RULES as [$code, $name, $desc, $signal, $thresholds, $severity]) {
            $row = DB::table('early_warning_rules')->where('organization_id', $c['organization'])->where('school_id', $c['school'])->where('code', $code)->first();
            if (! $row) {
                $id = (string) Str::ulid();
                DB::table('early_warning_rules')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'code' => $code, 'name' => $name,
                    'description' => $desc, 'signal_type' => $signal, 'thresholds' => json_encode($thresholds), 'severity' => $severity, 'is_enabled' => true, 'created_by_id' => $c['user'],
                    'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('early_warning_rules')->where('id', $id)->first();
            }
            if ($row->is_enabled) $out[$code] = $row;
        }
        return $out;
    }

    private function num(?object $rule, string $key, float|int $fallback): float|int
    {
        $t = $rule ? json_decode((string) $rule->thresholds, true) : null;
        return is_array($t) && isset($t[$key]) && is_numeric($t[$key]) && $t[$key] >= 0 ? $t[$key] + 0 : $fallback;
    }

    private function exceeded($last, CarbonImmutable $now, float|int $days): bool
    {
        return ! $last || $now->diffInSeconds(CarbonImmutable::parse($last, 'UTC'), true) >= $days * 86400;
    }

    public function evaluate(Request $request)
    {
        $c = $this->guard($request, 'manage');
        $q = $this->query($request);
        $scope = $this->scope->resolve($c, $q['classId']);
        $rules = $this->rules($c);
        $now = CarbonImmutable::now('UTC');
        $ids = $scope['studentIds'];
        $approaching = $this->num($rules->get('DEADLINE_APPROACHING_3D'), 'days', 3);

        $students = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->whereIn('s.id', $ids)
            ->get(['s.id', 's.school_id', 's.class_id', 'u.id as user_id', 'u.created_at as user_created', 'u.last_login_at', 'u.activated_at']);
        $assignmentQuery = $this->scope->assignments($c, $scope);
        $assignmentIds = (clone $assignmentQuery)->pluck('a.id');
        $relevant = (clone $assignmentQuery)->where('a.end_date', '<=', $now->addSeconds((int) ($approaching * 86400)))->get(['a.id', 'a.title', 'a.type', 'a.end_date', 'a.course_id']);
        $enrol = DB::table('course_enrollments')->whereIn('student_id', $ids)->where('status', 'ACTIVE')->get(['course_id', 'student_id']);
        $lessonIds = $this->scope->lessons($c, $scope)->pluck('l.id');
        $progress = DB::table('lesson_progress as p')->join('lessons as l', 'l.id', '=', 'p.lesson_id')->whereIn('p.student_id', $ids)->whereIn('p.lesson_id', $lessonIds)->get(['p.student_id', 'p.lesson_id', 'p.state', 'p.progress_percent', 'p.updated_at', 'l.title']);
        $subs = DB::table('submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')->whereIn('s.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)
            ->get(['s.id', 's.student_id', 's.assignment_id', 's.status', 's.score', 's.grade_published_at', 's.updated_at', 'a.max_score']);
        $videosBySub = DB::table('affiliate_videos')->whereIn('submission_id', $subs->pluck('id'))->pluck('submission_id')->countBy();
        $stores = DB::table('student_store_submissions as s')->leftJoin('student_stores as st', 'st.id', '=', 's.student_store_id')->whereIn('s.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)
            ->get(['s.id', 's.student_id', 's.status', 's.updated_at', 'st.store_url'])
            ->map(function ($r) { $r->files = DB::table('student_store_files')->where('store_submission_id', $r->id)->count(); return $r; });
        $videos = DB::table('affiliate_videos as v')->join('submissions as s', 's.id', '=', 'v.submission_id')->whereIn('v.student_id', $ids)->whereIn('s.assignment_id', $assignmentIds)
            ->get(['v.id', 'v.student_id', 'v.verification', 'v.updated_at', 's.status as submission_status'])
            ->map(function ($v) {
                $v->latest = DB::table('affiliate_metric_snapshots')->where('affiliate_video_id', $v->id)->max('snapshot_date');
                $v->flags = DB::table('affiliate_fraud_flags')->where('affiliate_video_id', $v->id)->where('status', 'OPEN')->pluck('type')->all();
                return $v;
            });

        $active = [];
        $created = 0;
        $refreshed = 0;
        $warn = function (object $student, string $code, string $type, string $rid, string $title, string $message, array $evidence) use (&$active, &$created, &$refreshed, $rules, $c): void {
            $rule = $rules[$code];
            $r = DB::table('early_warnings')->where('rule_id', $rule->id)->where('student_id', $student->id)->where('resource_type', $type)->where('resource_id', $rid)->first();
            if ($r) {
                $reopen = in_array($r->status, ['RESOLVED', 'DISMISSED'], true) ? ['status' => 'OPEN', 'resolved_at' => null, 'resolved_by_id' => null, 'resolution' => null] : [];
                DB::table('early_warnings')->where('id', $r->id)->update(['title' => $title, 'message' => $message, 'evidence' => json_encode($evidence), 'severity' => $rule->severity,
                    'last_evaluated_at' => now(), 'updated_at' => now()] + $reopen);
                $active[$r->id] = true; $refreshed++;
                return;
            }
            $id = (string) Str::ulid();
            DB::table('early_warnings')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $student->school_id, 'class_id' => $student->class_id, 'student_id' => $student->id,
                'rule_id' => $rule->id, 'resource_type' => $type, 'resource_id' => $rid, 'severity' => $rule->severity, 'status' => 'OPEN', 'title' => $title, 'message' => $message,
                'evidence' => json_encode($evidence), 'triggered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $active[$id] = true; $created++;
        };

        foreach ($students as $s) {
            $inv = $rules->get('INVITATION_NOT_ACTIVATED'); $invDays = $this->num($inv, 'days', 3);
            $ina = $rules->get('INACTIVITY_7D'); $inaDays = $this->num($ina, 'days', 7);
            $lastLogin = $s->last_login_at ?: $s->activated_at;
            if ($inv && ! $s->activated_at && $this->exceeded($s->user_created, $now, $invDays)) {
                $warn($s, 'INVITATION_NOT_ACTIVATED', 'USER', $s->user_id, 'Perlu bantuan mengaktifkan akun', "Akun belum diaktifkan setelah $invDays hari. Periksa kendala undangan atau akses bersama siswa secara suportif.", ['accountCreatedAt' => $this->iso($s->user_created), 'thresholdDays' => $invDays]);
            } elseif ($ina && $this->exceeded($lastLogin, $now, $inaDays)) {
                $warn($s, 'INACTIVITY_7D', 'USER', $s->user_id, 'Perlu dukungan untuk kembali aktif', "Belum ada aktivitas login dalam $inaDays hari. Hubungi siswa secara suportif dan pastikan tidak ada kendala akses.", ['lastLoginAt' => $this->iso($lastLogin), 'thresholdDays' => $inaDays]);
            }
            $stalled = $rules->get('LEARNING_PROGRESS_STALLED'); $stDays = $this->num($stalled, 'days', 7);
            if ($stalled) foreach ($progress->where('student_id', $s->id)->where('state', 'IN_PROGRESS') as $p) if ($this->exceeded($p->updated_at, $now, $stDays))
                $warn($s, 'LEARNING_PROGRESS_STALLED', 'LESSON', $p->lesson_id, 'Materi perlu dilanjutkan', "Materi “{$p->title}” belum dilanjutkan dalam $stDays hari. Tawarkan bantuan untuk mengatasi kendala belajar.", ['state' => $p->state, 'progressPercent' => (int) $p->progress_percent, 'lastUpdatedAt' => $this->iso($p->updated_at), 'thresholdDays' => $stDays]);

            $mine = $subs->where('student_id', $s->id);
            foreach ($relevant->filter(fn ($a) => $enrol->where('student_id', $s->id)->contains('course_id', $a->course_id)) as $a) {
                $sub = $mine->firstWhere('assignment_id', $a->id);
                if ($sub && in_array($sub->status, ['APPROVED', 'ARCHIVED'], true)) continue;
                $end = CarbonImmutable::parse($a->end_date, 'UTC');
                if ($end->gte($now) && $rules->has('DEADLINE_APPROACHING_3D') && ! $sub)
                    $warn($s, 'DEADLINE_APPROACHING_3D', 'ASSIGNMENT', $a->id, 'Tenggat tugas semakin dekat', "Tugas “{$a->title}” akan berakhir dalam beberapa hari. Bantu siswa memulai dari langkah kecil yang realistis.", ['deadline' => $this->iso($a->end_date), 'submissionStatus' => 'NOT_STARTED', 'thresholdDays' => $approaching]);
                if ($end->gte($now) && $rules->has('AFFILIATE_CONTENT_NOT_PUBLISHED') && str_contains((string) $a->type, 'AFFILIATE') && (! $sub || ($videosBySub[$sub->id] ?? 0) === 0))
                    $warn($s, 'AFFILIATE_CONTENT_NOT_PUBLISHED', 'ASSIGNMENT', $a->id, 'Konten affiliate belum dipublikasikan', "Konten untuk tugas “{$a->title}” belum tercatat. Periksa kendala publikasi dan bantu siswa menyiapkan langkah berikutnya.", ['deadline' => $this->iso($a->end_date), 'submissionStatus' => $sub->status ?? 'NOT_STARTED']);
                if (! $rules->has('OVERDUE_ASSIGNMENT') || $end->gte($now)) continue;
                $warn($s, 'OVERDUE_ASSIGNMENT', 'ASSIGNMENT', $a->id, 'Tugas melewati tenggat', "Tugas “{$a->title}” memerlukan tindak lanjut. Bantu siswa menyusun langkah penyelesaian yang realistis.", ['deadline' => $this->iso($a->end_date), 'submissionStatus' => $sub->status ?? 'NOT_STARTED']);
            }

            $draftRule = $rules->get('STALE_DRAFT_7D'); $draftDays = $this->num($draftRule, 'days', 7);
            if ($draftRule) {
                $drafts = $mine->where('status', 'DRAFT')->filter(fn ($r) => $this->exceeded($r->updated_at, $now, $draftDays))->map(fn ($r) => ['SUBMISSION', $r->id])
                    ->concat($stores->where('student_id', $s->id)->where('status', 'DRAFT')->filter(fn ($r) => $this->exceeded($r->updated_at, $now, $draftDays))->map(fn ($r) => ['STORE_SUBMISSION', $r->id]));
                foreach ($drafts as [$kind, $rid]) $warn($s, 'STALE_DRAFT_7D', $kind, $rid, 'Draf perlu dilanjutkan', "Draf belum diperbarui dalam $draftDays hari. Tawarkan bantuan untuk menentukan langkah berikutnya.", ['thresholdDays' => $draftDays]);
            }

            $low = $rules->get('LOW_GRADE_REPEAT'); $lowPct = $this->num($low, 'percent', 60); $lowCount = $this->num($low, 'count', 2);
            $lowGrades = $mine->filter(fn ($r) => $r->grade_published_at && $r->score !== null && (float) $r->score / max((int) $r->max_score, 1) * 100 < $lowPct)->count();
            if ($low && $lowGrades >= $lowCount) $warn($s, 'LOW_GRADE_REPEAT', 'STUDENT', $s->id, 'Perlu dukungan belajar tambahan', 'Beberapa nilai terpublikasi menunjukkan siswa mungkin membutuhkan penjelasan atau latihan tambahan. Gunakan percakapan suportif.', ['lowPublishedGradeCount' => $lowGrades, 'thresholdPercent' => $lowPct, 'thresholdCount' => $lowCount]);

            $storeRule = $rules->get('STORE_EVIDENCE_INCOMPLETE'); $minFiles = $this->num($storeRule, 'minimumFiles', 1);
            if ($storeRule) foreach ($stores->where('student_id', $s->id)->where('status', '!=', 'APPROVED') as $st) if (! $st->store_url || $st->files < $minFiles)
                $warn($s, 'STORE_EVIDENCE_INCOMPLETE', 'STORE_SUBMISSION', $st->id, 'Bukti Student Store belum lengkap', 'URL toko atau bukti pendukung belum lengkap. Bantu siswa memeriksa checklist tanpa memberi hukuman otomatis.', ['hasStoreUrl' => (bool) $st->store_url, 'fileCount' => $st->files, 'minimumFiles' => $minFiles]);

            $myVideos = $videos->where('student_id', $s->id);
            $unv = $rules->get('AFFILIATE_CONTENT_UNVERIFIED'); $unvDays = $this->num($unv, 'days', 7);
            if ($unv) foreach ($myVideos->where('verification', 'UNVERIFIED') as $v) if ($this->exceeded($v->updated_at, $now, $unvDays))
                $warn($s, 'AFFILIATE_CONTENT_UNVERIFIED', 'AFFILIATE_CONTENT', $v->id, 'Konten affiliate perlu ditindaklanjuti', "Konten belum terverifikasi setelah $unvDays hari. Periksa status submission dan bantu siswa menindaklanjuti feedback.", ['submissionStatus' => $v->submission_status, 'thresholdDays' => $unvDays]);

            $rev = $rules->get('REVISION_REQUIRED_REPEAT'); $revMin = $this->num($rev, 'count', 2);
            $revCount = $mine->where('status', 'REVISION_REQUIRED')->count() + $stores->where('student_id', $s->id)->where('status', 'REVISION_REQUIRED')->count();
            if ($rev && $revCount >= $revMin) $warn($s, 'REVISION_REQUIRED_REPEAT', 'STUDENT', $s->id, 'Perlu pendampingan revisi', 'Beberapa hasil kerja masih memerlukan revisi. Tinjau pola feedback dan tawarkan sesi pendampingan singkat.', ['revisionRequiredCount' => $revCount, 'threshold' => $revMin]);

            $stale = $rules->get('AFFILIATE_METRIC_STALE'); $staleDays = $this->num($stale, 'days', 14);
            if ($stale) foreach ($myVideos->where('verification', 'VERIFIED') as $v) if ($this->exceeded($v->latest, $now, $staleDays))
                $warn($s, 'AFFILIATE_METRIC_STALE', 'AFFILIATE_CONTENT', $v->id, 'Performa affiliate perlu diperbarui', "Snapshot metrik belum diperbarui dalam $staleDays hari. Ingatkan siswa untuk mencatat data terbaru yang dapat diverifikasi.", ['latestSnapshotAt' => $this->iso($v->latest), 'thresholdDays' => $staleDays]);

            $flags = $myVideos->flatMap(fn ($v) => $v->flags);
            if ($rules->has('OPEN_DATA_QUALITY_FLAG') && $flags->isNotEmpty())
                $warn($s, 'OPEN_DATA_QUALITY_FLAG', 'STUDENT', $s->id, 'Data affiliate perlu diperiksa', 'Ada penanda kualitas data yang belum diselesaikan. Lakukan verifikasi manusia sebelum mengambil keputusan.', ['openFlagCount' => $flags->count(), 'flagTypes' => $flags->unique()->values()->all()]);
        }

        $staleIds = DB::table('early_warnings')->where('school_id', $c['school'])->whereIn('student_id', $ids)->whereIn('rule_id', $rules->pluck('id'))
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->pluck('id')->reject(fn ($id) => isset($active[$id]))->values();
        if ($staleIds->isNotEmpty()) DB::table('early_warnings')->whereIn('id', $staleIds)->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolution' => 'Sinyal tidak lagi terdeteksi pada evaluasi terbaru.', 'updated_at' => now()]);
        return response()->json(['evaluatedStudents' => $students->count(), 'created' => $created, 'refreshed' => $refreshed, 'autoResolved' => $staleIds->count(), 'evaluatedAt' => $this->iso($now)], 201);
    }
}
