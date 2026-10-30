<?php

namespace App\Jobs;

use App\Support\SafeCsv;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** CSV generation for all report types; port of the report-export worker. */
class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const PRIVILEGED = ['USER_ENROLLMENTS', 'IDENTITY_VERIFICATION', 'CONSENT_STATUS', 'TEACHER_WORKLOAD', 'IMPORT_HISTORY', 'AUDIT_SUMMARY'];

    public function __construct(public string $exportJobId) {}

    private static function d($v): mixed
    {
        return $v === null || $v === '' ? '' : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function fail(string $id, string $reason): void
    {
        DB::table('export_jobs')->where('id', $id)->update(['status' => 'FAILED', 'failure_reason' => $reason, 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function handle(): void
    {
        $job = DB::table('export_jobs')->where('id', $this->exportJobId)->first();
        if (! $job || $job->status !== 'QUEUED') return;
        $user = DB::table('users')->where('id', $job->requested_by_id)->first();
        $plan = app(\App\Support\StudentDashboardAccess::class)->resolve($job->organization_id, $job->school_id, $job->requested_by_id, DB::table('students')->where('user_id', $job->requested_by_id)->value('id'));
        $can = $plan && app(\App\Support\StudentDashboardAccess::class)->enabled($plan, 'reports.export');
        if (! $user || ! $can) { $this->fail($job->id, 'ENTITLEMENT_NOT_ACTIVE'); return; }
        if (in_array($job->report_type, self::PRIVILEGED, true)) {
            $broad = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $job->requested_by_id)
                ->whereIn('r.slug', ['super-admin', 'program-manager', 'school-admin', 'principal'])
                ->where(fn ($q) => $q->whereNull('ur.school_id')->orWhere('ur.school_id', $job->school_id))->exists();
            if (! $broad) { $this->fail($job->id, 'ROLE_SCOPE_NOT_ACTIVE'); return; }
        }
        DB::table('export_jobs')->where('id', $job->id)->update(['status' => 'PROCESSING', 'started_at' => now(), 'updated_at' => now()]);
        try {
            $filters = json_decode((string) $job->filters, true) ?: [];
            $ids = array_values(array_filter($filters['studentIds'] ?? [], 'is_string'));
            $since = CarbonImmutable::now('UTC')->subDays(max(7, min((int) ($filters['periodDays'] ?? 30), 365)));
            $rows = $this->rows($job, $ids, $since);
            $csv = SafeCsv::build($rows);
            $name = strtolower(str_replace('_', '-', $job->report_type)).'-'.now()->format('Y-m-d').'.csv';
            $key = $job->organization_id.'/'.$job->id.'/'.bin2hex(random_bytes(16)).'.csv';
            Storage::disk('exports')->put($key, $csv);
            DB::table('export_jobs')->where('id', $job->id)->update(['status' => 'COMPLETED', 'storage_key' => $key, 'file_name' => $name, 'row_count' => count($rows),
                'completed_at' => now(), 'expires_at' => now()->addDay(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $this->fail($job->id, 'REPORT_EXPORT_FAILED');
            throw $e;
        }
    }

    private function students(array $ids)
    {
        return DB::table('students as s')->leftJoin('classes as k', 'k.id', '=', 's.class_id')->whereIn('s.id', $ids);
    }

    private function rows(object $job, array $ids, CarbonImmutable $since): array
    {
        $org = $job->organization_id;
        $school = $job->school_id;
        $kelas = fn ($r) => $r->class_name ?: 'Belum ada kelas';
        $inSchool = fn ($q, string $col) => $q->when($school, fn ($q) => $q->where($col, $school));
        $base = fn () => ['s.student_number', 's.full_name', 'k.name as class_name'];

        return match ($job->report_type) {
            'USER_ENROLLMENTS' => $this->students($ids)->join('schools as sc', 'sc.id', '=', 's.school_id')->where('sc.organization_id', $org)->when($school, fn ($q) => $q->where('sc.id', $school))
                ->orderBy('s.full_name')->get(['s.id', 's.student_number', 's.full_name', 'sc.name as school_name', 'k.name as class_name'])
                ->flatMap(function ($s) use ($kelas) {
                    $enr = DB::table('course_enrollments as e')->join('courses as c', 'c.id', '=', 'e.course_id')->where('e.student_id', $s->id)->get(['c.code', 'c.title', 'e.status', 'e.enrolled_at', 'e.completed_at']);
                    $b = ['Nomor Siswa' => $s->student_number, 'Nama Siswa' => $s->full_name, 'Sekolah' => $s->school_name, 'Kelas' => $kelas($s)];
                    return $enr->isEmpty() ? [$b + ['Kode Course' => '', 'Course' => '', 'Status Enrollment' => 'BELUM_TERDAFTAR', 'Tanggal Enrollment' => '', 'Tanggal Selesai' => '']]
                        : $enr->map(fn ($e) => $b + ['Kode Course' => $e->code, 'Course' => $e->title, 'Status Enrollment' => $e->status, 'Tanggal Enrollment' => self::d($e->enrolled_at), 'Tanggal Selesai' => self::d($e->completed_at)])->all();
                })->values()->all(),
            'LEARNING_ACTIVITY' => $this->students($ids)->join('lesson_progress as p', 'p.student_id', '=', 's.id')->join('lessons as l', 'l.id', '=', 'p.lesson_id')
                ->join('course_modules as m', 'm.id', '=', 'l.module_id')->join('courses as c', 'c.id', '=', 'm.course_id')->where('p.updated_at', '>=', $since)->orderByDesc('p.updated_at')
                ->get(['s.student_number', 's.full_name', 'k.name as class_name', 'c.code', 'c.title as course', 'm.title as module', 'l.title as lesson', 'p.state', 'p.progress_percent', 'p.started_at', 'p.completed_at', 'p.updated_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Kode Course' => $r->code, 'Course' => $r->course, 'Modul' => $r->module,
                    'Materi' => $r->lesson, 'Status' => $r->state, 'Progress (%)' => $r->progress_percent, 'Mulai Pada' => self::d($r->started_at), 'Selesai Pada' => self::d($r->completed_at), 'Diperbarui' => self::d($r->updated_at)])->all(),
            'STUDENT_PROGRESS' => $this->students($ids)->join('users as u', 'u.id', '=', 's.user_id')->orderBy('s.full_name')->get(['s.id', 's.student_number', 's.full_name', 's.status', 'k.name as class_name', 'u.last_login_at'])
                ->map(function ($s) use ($kelas) {
                    $lp = DB::table('lesson_progress')->where('student_id', $s->id)->get(['progress_percent', 'state']);
                    $sub = DB::table('submissions')->where('student_id', $s->id)->whereNotNull('grade_published_at')->get(['score', 'status']);
                    $scored = $sub->whereNotNull('score');
                    return ['Nomor Siswa' => $s->student_number, 'Nama Siswa' => $s->full_name, 'Kelas' => $kelas($s), 'Status' => $s->status, 'Login Terakhir' => self::d($s->last_login_at),
                        'Progress Rata-rata (%)' => $lp->count() ? (int) round($lp->sum('progress_percent') / $lp->count()) : 0, 'Materi Selesai' => $lp->where('state', 'COMPLETED')->count(),
                        'Submission Disetujui' => $sub->where('status', 'APPROVED')->count(), 'Nilai Rata-rata Terpublikasi' => $scored->count() ? (int) round($sub->sum(fn ($i) => (float) ($i->score ?? 0)) / $scored->count()) : ''];
                })->all(),
            'ASSIGNMENT_SUBMISSIONS' => $this->students($ids)->join('submissions as sub', 'sub.student_id', '=', 's.id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')
                ->where('sub.updated_at', '>=', $since)->orderByDesc('sub.updated_at')->get([...$base(), 'a.title', 'a.type', 'a.end_date', 'sub.status', 'sub.submitted_at', 'sub.grade_published_at', 'sub.score', 'sub.updated_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Tugas' => $r->title, 'Jenis Tugas' => $r->type, 'Tenggat' => self::d($r->end_date),
                    'Status' => $r->status, 'Waktu Pengumpulan' => self::d($r->submitted_at), 'Nilai Terpublikasi' => $r->grade_published_at ? $r->score : '', 'Diperbarui' => self::d($r->updated_at)])->all(),
            'GRADES_RUBRICS' => $this->students($ids)->join('submissions as sub', 'sub.student_id', '=', 's.id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->leftJoin('rubrics as ru', 'ru.id', '=', 'a.rubric_id')
                ->whereNotNull('sub.grade_published_at')->where('sub.grade_published_at', '>=', $since)->orderByDesc('sub.grade_published_at')
                ->get([...$base(), 'sub.id as sub_id', 'a.title', 'a.max_score', 'ru.name as rubric', 'sub.score', 'sub.grade_published_at'])
                ->map(function ($r) use ($kelas) {
                    $review = DB::table('submission_reviews')->where('submission_id', $r->sub_id)->where('is_published', true)->orderByDesc('published_at')->first();
                    $scores = $review ? DB::table('review_scores as rs')->join('rubric_criteria as c', 'c.id', '=', 'rs.criterion_id')->where('rs.review_id', $review->id)->get(['c.name', 'rs.score', 'c.max_score']) : collect();
                    return ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Tugas' => $r->title, 'Rubrik' => $r->rubric ?? '', 'Nilai' => $r->score ?? '',
                        'Nilai Maksimum' => $r->max_score, 'Nilai (%)' => $r->score === null ? '' : round((float) $r->score / max((int) $r->max_score, 1) * 1000) / 10,
                        'Kriteria Dinilai' => $scores->map(fn ($s) => "{$s->name}: {$s->score}/{$s->max_score}")->implode('; '), 'Dipublikasikan' => self::d($r->grade_published_at)];
                })->all(),
            'STUDENT_STORES' => $this->students($ids)->join('student_store_submissions as ss', 'ss.student_id', '=', 's.id')->join('assignments as a', 'a.id', '=', 'ss.assignment_id')->leftJoin('student_stores as st', 'st.id', '=', 'ss.student_store_id')
                ->where('ss.organization_id', $org)->when($school, fn ($q) => $q->where('ss.school_id', $school))->where('ss.updated_at', '>=', $since)->orderByDesc('ss.updated_at')
                ->get([...$base(), 'a.title', 'st.platform', 'st.store_name', 'st.store_url', 'st.product_count', 'ss.status', 'ss.score', 'ss.updated_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Tugas' => $r->title, 'Platform' => $r->platform ?? '', 'Nama Toko' => $r->store_name ?? '',
                    'URL Toko' => $r->store_url ?? '', 'Jumlah Produk' => $r->product_count ?? 0, 'Status' => $r->status, 'Nilai Terpublikasi' => $r->status === 'APPROVED' ? $r->score : '', 'Diperbarui' => self::d($r->updated_at)])->all(),
            'STORE_REVIEW_REVISIONS' => DB::table('store_reviews as rv')->join('student_store_submissions as ss', 'ss.id', '=', 'rv.store_submission_id')->join('students as s', 's.id', '=', 'ss.student_id')->leftJoin('classes as k', 'k.id', '=', 's.class_id')
                ->join('assignments as a', 'a.id', '=', 'ss.assignment_id')->join('teachers as t', 't.id', '=', 'rv.reviewer_id')->leftJoin('rubrics as ru', 'ru.id', '=', 'rv.rubric_id')
                ->whereIn('ss.student_id', $ids)->where('ss.organization_id', $org)->when($school, fn ($q) => $q->where('ss.school_id', $school))->where('rv.created_at', '>=', $since)->orderByDesc('rv.created_at')
                ->get([...$base(), 'a.title', 't.full_name as reviewer', 'rv.decision', 'ru.name as rubric', 'rv.is_published', 'rv.total_score', 'rv.feedback', 'rv.created_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Tugas' => $r->title, 'Reviewer' => $r->reviewer, 'Keputusan' => $r->decision, 'Rubrik' => $r->rubric ?? '',
                    'Nilai Terpublikasi' => $r->is_published ? ($r->total_score ?? '') : '', 'Feedback Terpublikasi' => $r->is_published ? ($r->feedback ?? '') : '', 'Waktu Review' => self::d($r->created_at)])->all(),
            'AFFILIATE_CONTENT' => $this->students($ids)->join('affiliate_videos as v', 'v.student_id', '=', 's.id')->join('submissions as sub', 'sub.id', '=', 'v.submission_id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')
                ->where('v.published_at', '>=', $since)->orderByDesc('v.published_at')->get([...$base(), 'a.title', 'v.platform', 'v.video_url', 'v.product_name', 'v.campaign_name', 'v.published_at', 'sub.status as sub_status', 'v.verification', 'v.current_version'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Tugas' => $r->title, 'Platform' => $r->platform, 'URL Konten' => $r->video_url, 'Produk' => $r->product_name ?? '',
                    'Campaign' => $r->campaign_name ?? '', 'Tanggal Publikasi' => self::d($r->published_at), 'Status Submission' => $r->sub_status, 'Verifikasi' => $r->verification, 'Versi' => $r->current_version])->all(),
            'AFFILIATE_PERFORMANCE' => $this->students($ids)->join('affiliate_metric_snapshots as m', 'm.student_id', '=', 's.id')->join('affiliate_videos as v', 'v.id', '=', 'm.affiliate_video_id')
                ->where('m.organization_id', $org)->when($school, fn ($q) => $q->where('m.school_id', $school))->where('m.snapshot_date', '>=', $since)->orderByDesc('m.snapshot_date')
                ->get([...$base(), 'v.product_name', 'v.caption', 'v.platform', 'v.video_url', 'm.snapshot_date', 'm.views', 'm.clicks', 'm.orders', 'm.gmv', 'm.commission', 'm.verification', 'm.source'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Konten' => $r->product_name ?: ($r->caption ?: 'Konten affiliate'), 'Platform' => $r->platform, 'URL Konten' => $r->video_url,
                    'Tanggal Snapshot' => self::d($r->snapshot_date), 'Views' => $r->views, 'Clicks' => $r->clicks, 'Orders' => $r->orders, 'GMV' => (float) $r->gmv, 'Komisi' => (float) $r->commission, 'Verifikasi' => $r->verification, 'Sumber' => $r->source])->all(),
            'EARLY_WARNINGS' => $this->students($ids)->join('early_warnings as w', 'w.student_id', '=', 's.id')->join('early_warning_rules as r', 'r.id', '=', 'w.rule_id')
                ->where('w.organization_id', $org)->when($school, fn ($q) => $q->where('w.school_id', $school))->where('w.triggered_at', '>=', $since)->orderByDesc('w.triggered_at')
                ->get([...$base(), 'r.name as rule', 'w.severity', 'w.status', 'w.message', 'w.triggered_at', 'w.action_note', 'w.resolution'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Rule' => $r->rule, 'Severity' => $r->severity, 'Status' => $r->status, 'Pesan' => $r->message,
                    'Dipicu Pada' => self::d($r->triggered_at), 'Catatan Tindakan' => $r->action_note ?? '', 'Resolusi' => $r->resolution ?? ''])->all(),
            'IDENTITY_VERIFICATION' => $this->students($ids)->join('identity_documents as d', 'd.student_id', '=', 's.id')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('sc.organization_id', $org)
                ->when($school, fn ($q) => $q->where('sc.id', $school))->where('d.updated_at', '>=', $since)->orderByDesc('d.updated_at')
                ->get([...$base(), 'd.document_type', 'd.document_status', 'd.verification_status', 'd.scan_status', 'd.verified_at', 'd.updated_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number, 'Nama Siswa' => $r->full_name, 'Kelas' => $kelas($r), 'Jenis Dokumen' => $r->document_type, 'Status Dokumen' => $r->document_status,
                    'Status Verifikasi' => $r->verification_status, 'Status Pemindaian' => $r->scan_status, 'Diverifikasi Pada' => self::d($r->verified_at), 'Diperbarui' => self::d($r->updated_at)])->all(),
            'CONSENT_STATUS' => DB::table('consents as c')->join('consent_documents as d', 'd.id', '=', 'c.document_id')->leftJoin('students as s', 's.id', '=', 'c.student_id')->leftJoin('classes as k', 'k.id', '=', 's.class_id')
                ->where('c.organization_id', $org)->when($school, fn ($q) => $q->where('c.school_id', $school))->where('c.updated_at', '>=', $since)->orderByDesc('c.updated_at')
                ->get(['s.student_number', 's.full_name', 'k.name as class_name', 'c.type', 'd.title', 'd.version', 'c.status', 'c.method', 'c.effective_at', 'c.expires_at', 'c.withdrawn_at'])
                ->map(fn ($r) => ['Nomor Siswa' => $r->student_number ?? '', 'Nama Siswa' => $r->full_name ?? '', 'Kelas' => $r->class_name ?? '', 'Consent' => $r->type, 'Dokumen' => $r->title, 'Versi' => $r->version,
                    'Status' => $r->status, 'Metode' => $r->method, 'Berlaku Mulai' => self::d($r->effective_at), 'Kedaluwarsa' => self::d($r->expires_at), 'Ditarik Pada' => self::d($r->withdrawn_at)])->all(),
            'TEACHER_WORKLOAD' => DB::table('teachers as t')->join('schools as sc', 'sc.id', '=', 't.school_id')->where('sc.organization_id', $org)->when($school, fn ($q) => $q->where('t.school_id', $school))->where('t.is_active', true)
                ->orderBy('t.full_name')->get(['t.id', 't.full_name', 'sc.name as school_name'])->map(function ($t) {
                    $ta = DB::table('teacher_assignments')->where('teacher_id', $t->id)->where('is_active', true)->get(['class_id', 'course_id']);
                    $aIds = DB::table('assignments')->where('teacher_id', $t->id)->pluck('id');
                    $open = ['SUBMITTED', 'RESUBMITTED', 'UNDER_REVIEW'];
                    return ['Guru' => $t->full_name, 'Sekolah' => $t->school_name, 'Kelas Aktif' => $ta->pluck('class_id')->unique()->count(), 'Course Aktif' => $ta->pluck('course_id')->filter()->unique()->count(),
                        'Tugas Dikelola' => $aIds->count(), 'Antrean Review Tugas' => DB::table('submissions')->whereIn('assignment_id', $aIds)->whereIn('status', $open)->count(),
                        'Antrean Review Store' => DB::table('student_store_submissions')->whereIn('assignment_id', $aIds)->whereIn('status', $open)->count()];
                })->all(),
            'IMPORT_HISTORY' => DB::table('import_jobs as i')->join('schools as sc', 'sc.id', '=', 'i.school_id')->leftJoin('users as u', 'u.id', '=', 'i.created_by_id')->where('i.organization_id', $org)
                ->when($school, fn ($q) => $q->where('i.school_id', $school))->where('i.created_at', '>=', $since)->orderByDesc('i.created_at')
                ->get(['sc.name as school_name', 'i.type', 'i.source_name', 'i.status', 'i.total_rows', 'i.valid_rows', 'i.failed_rows', 'i.processed_rows', 'u.first_name', 'u.last_name', 'i.created_at', 'i.completed_at', 'i.failure_reason'])
                ->map(fn ($r) => ['Sekolah' => $r->school_name, 'Jenis' => $r->type, 'Sumber' => $r->source_name, 'Status' => $r->status, 'Total Baris' => $r->total_rows, 'Valid' => $r->valid_rows, 'Gagal' => $r->failed_rows,
                    'Diproses' => $r->processed_rows, 'Pembuat' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')), 'Dibuat Pada' => self::d($r->created_at), 'Selesai Pada' => self::d($r->completed_at), 'Alasan Gagal' => $r->failure_reason ?? ''])->all(),
            'AUDIT_SUMMARY' => DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.actor_id')->where('l.organization_id', $org)->when($school, fn ($q) => $q->where('l.school_id', $school))
                ->where('l.created_at', '>=', $since)->orderByDesc('l.created_at')->limit(50000)->get(['l.created_at', 'u.first_name', 'u.last_name', 'u.name', 'l.action', 'l.resource', 'l.resource_id', 'l.correlation_id'])
                ->map(fn ($r) => ['Waktu' => self::d($r->created_at), 'Aktor' => $r->name === null ? 'SYSTEM' : (trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: $r->name), 'Aksi' => $r->action, 'Resource' => $r->resource,
                    'Resource ID' => $r->resource_id ?? '', 'Correlation ID' => $r->correlation_id ?? ''])->all(),
            default => [],
        };
    }
}
