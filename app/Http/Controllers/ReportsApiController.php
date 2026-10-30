<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsApiController extends Controller
{
    private const TYPES = [
        ['USER_ENROLLMENTS', 'User dan enrollment', true],
        ['LEARNING_ACTIVITY', 'Aktivitas pembelajaran', false],
        ['STUDENT_PROGRESS', 'Progres course dan siswa', false],
        ['ASSIGNMENT_SUBMISSIONS', 'Tugas dan submission', false],
        ['GRADES_RUBRICS', 'Nilai dan rubrik terpublikasi', false],
        ['STUDENT_STORES', 'Student Store dan checklist', false],
        ['STORE_REVIEW_REVISIONS', 'Review dan revisi Student Store', false],
        ['AFFILIATE_CONTENT', 'Konten affiliate', false],
        ['AFFILIATE_PERFORMANCE', 'Riwayat metrik affiliate', false],
        ['EARLY_WARNINGS', 'Early warning', false],
        ['IDENTITY_VERIFICATION', 'Status verifikasi identitas', true],
        ['CONSENT_STATUS', 'Status consent', true],
        ['TEACHER_WORKLOAD', 'Beban kerja guru/reviewer', true],
        ['IMPORT_HISTORY', 'Riwayat import', true],
        ['AUDIT_SUMMARY', 'Ringkasan audit', true],
    ];

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request): array
    {
        $c = $this->context->resolve($request, 'reports', 'read');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'reports.export'), 403, 'Laporan tidak termasuk dalam paket aktif');
        return $c;
    }

    private function broad(array $c): bool
    {
        return (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal']);
    }

    public function options(Request $request)
    {
        $c = $this->scope($request);
        $types = collect(self::TYPES)->filter(fn ($item) => $this->broad($c) || ! $item[2])
            ->map(fn ($item) => ['value' => $item[0], 'label' => $item[1]])->values();
        return response()->json(['formats' => [['value' => 'CSV', 'label' => 'CSV aman untuk spreadsheet']],
            'reportTypes' => $types,
            'notes' => ['Data sensitif tidak disertakan', 'Nilai hanya yang sudah dipublikasikan', 'File privat dan tautan unduh memiliki masa berlaku']]);
    }

    public function exports(Request $request)
    {
        $c = $this->scope($request);
        DB::table('export_jobs')->where('organization_id', $c['organization'])->where('school_id', $c['school'])
            ->where('status', 'COMPLETED')->where('expires_at', '<=', now())->update(['status' => 'EXPIRED', 'updated_at' => now()]);
        $rows = DB::table('export_jobs')->where('organization_id', $c['organization'])->where('school_id', $c['school']);
        if (! $this->broad($c)) $rows->where('requested_by_id', $c['user']);
        return response()->json($rows->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($r) => ['id' => $r->id, 'reportType' => $r->report_type, 'format' => $r->format,
                'status' => $r->status, 'rowCount' => $r->row_count, 'fileName' => $r->file_name,
                'failureReason' => $r->failure_reason, 'expiresAt' => $r->expires_at,
                'createdAt' => $r->created_at, 'completedAt' => $r->completed_at]));
    }

    private function statisticScope(Request $request): array
    {
        $c = $this->context->resolve($request, 'public-statistics', 'approve');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'dashboards.advanced.read'), 403, 'Dashboard lanjutan tidak termasuk paket aktif');
        return $c;
    }

    private function statistic(object $r): array
    {
        $school = DB::table('schools')->where('id', $r->school_id)->first();
        $created = DB::table('users')->where('id', $r->created_by_id)->first();
        $reviewed = $r->reviewed_by_id ? DB::table('users')->where('id', $r->reviewed_by_id)->first() : null;
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'title' => $r->title, 'periodStart' => $r->period_start, 'periodEnd' => $r->period_end,
            'cohortSize' => $r->cohort_size, 'minimumCohort' => $r->minimum_cohort,
            'metrics' => json_decode($r->metrics, true), 'status' => $r->status, 'createdById' => $r->created_by_id,
            'reviewedById' => $r->reviewed_by_id, 'reviewNotes' => $r->review_notes,
            'approvedAt' => $r->approved_at, 'publishedAt' => $r->published_at,
            'school' => $school ? ['name' => $school->name] : null,
            'createdBy' => $created ? ['firstName' => $created->first_name, 'lastName' => $created->last_name] : null,
            'reviewedBy' => $reviewed ? ['firstName' => $reviewed->first_name, 'lastName' => $reviewed->last_name] : null];
    }

    public function statistics(Request $request)
    {
        $c = $this->statisticScope($request);
        return response()->json(DB::table('public_statistics')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($r) => $this->statistic($r)));
    }

    public function publishedStatistics()
    {
        return response()->json(DB::table('public_statistics as p')->join('schools as s', 's.id', '=', 'p.school_id')
            ->where('p.status', 'APPROVED')->whereNotNull('p.published_at')
            ->orderByDesc('p.published_at')->limit(12)
            ->get(['p.id', 'p.title', 'p.period_start', 'p.period_end', 'p.cohort_size',
                'p.minimum_cohort', 'p.metrics', 'p.published_at', 's.name as school_name',
                's.city as school_city', 's.province as school_province'])
            ->filter(fn ($r) => $r->cohort_size >= $r->minimum_cohort)->values()->map(fn ($r) => [
                'id' => $r->id, 'title' => $r->title, 'periodStart' => $r->period_start,
                'periodEnd' => $r->period_end, 'cohortSize' => $r->cohort_size,
                'metrics' => json_decode($r->metrics, true), 'publishedAt' => $r->published_at,
                'school' => ['name' => $r->school_name, 'city' => $r->school_city,
                    'province' => $r->school_province],
            ]));
    }

    public function reviewStatistic(Request $request, string $id)
    {
        $c = $this->statisticScope($request);
        $d = $request->validate(['decision' => 'required|in:APPROVED,REJECTED', 'notes' => 'nullable|string|max:1000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = DB::table('public_statistics')->where('id', $id)->where('organization_id', $c['organization'])
                ->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($r, 404, 'Statistik tidak ditemukan');
            abort_unless($r->status === 'PENDING_APPROVAL', 409, 'Statistik sudah memiliki keputusan final');
            abort_if($d['decision'] === 'APPROVED' && $r->created_by_id === $c['user'], 403, 'Pembuat tidak dapat menyetujui sendiri');
            abort_if($d['decision'] === 'REJECTED' && ! trim($d['notes'] ?? ''), 422, 'Catatan diperlukan untuk penolakan');
            abort_if($d['decision'] === 'APPROVED' && $r->cohort_size < $r->minimum_cohort, 422, 'Jumlah peserta belum memenuhi batas privasi');
            $approved = $d['decision'] === 'APPROVED';
            DB::table('public_statistics')->where('id', $id)->update(['status' => $d['decision'],
                'reviewed_by_id' => $c['user'], 'review_notes' => trim($d['notes'] ?? '') ?: null,
                'approved_at' => $approved ? now() : null, 'published_at' => $approved ? now() : null,
                'updated_at' => now()]);
            return response()->json($this->statistic(DB::table('public_statistics')->where('id', $id)->first()));
        });
    }
}
