<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmissionApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request): array
    {
        $c = $this->context->resolve($request, 'submissions', 'read');
        $q = DB::table('submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->join('courses as course', 'course.id', '=', 'a.course_id')
            ->join('students as student', 'student.id', '=', 's.student_id')
            ->where('course.school_id', $c['school'])->where('course.organization_id', $c['organization'])
            ->where('student.school_id', $c['school']);
        if ($c['teacher']) $q->where('a.teacher_id', $c['teacher']->id);
        if ($c['student']) {
            $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']->id);
            abort_unless($plan, 503, 'Paket akses belum tersedia');
            $q->where('s.student_id', $c['student']->id)->where('a.is_active', true)
                ->where('a.published_at', '<=', now())
                ->whereIn('a.type', $this->access->assignmentTypes($plan))
                ->whereIn('a.module_id', $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $plan));
        }
        return [$c, $q];
    }

    private function present(object $s, array $c, bool $detail = false): array
    {
        $result = [];
        foreach (['id', 'assignment_id', 'student_id', 'status', 'score', 'feedback', 'reviewed_by_id', 'reviewed_at', 'submitted_at', 'locked_at', 'grade_published_at', 'created_at', 'updated_at'] as $field) $result[Str::camel($field)] = $s->$field;
        $result['currentVersion'] = $s->revision;
        $student = DB::table('students')->where('id', $s->student_id)->first();
        $assignment = DB::table('assignments')->where('id', $s->assignment_id)->first();
        $result['student'] = ['id' => $student->id, 'fullName' => $student->full_name, 'studentNumber' => $student->student_number];
        $result['assignment'] = ['id' => $assignment->id, 'title' => $assignment->title, 'endDate' => $assignment->end_date];
        $result['affiliateVideos'] = DB::table('affiliate_videos')->where('submission_id', $s->id)->get();
        $result['_count'] = ['versions' => DB::table('submission_versions')->where('submission_id', $s->id)->count(),
            'affiliateVideos' => $result['affiliateVideos']->count()];
        if ($detail) {
            $result['versions'] = DB::table('submission_versions')->where('submission_id', $s->id)->orderByDesc('revision')->get()->map(function ($v) {
                $row = (array) $v; $row['version'] = $v->revision; return $row;
            });
            $rubric = $assignment->rubric_id ? DB::table('rubrics')->where('id', $assignment->rubric_id)->where('organization_id', $c['organization'])->first() : null;
            $result['assignment']['rubric'] = $rubric ? ['id' => $rubric->id, 'name' => $rubric->name,
                'criteria' => DB::table('rubric_criteria')->where('rubric_id', $rubric->id)->get()] : null;
            $reviews = DB::table('submission_reviews')->where('submission_id', $s->id)->orderByDesc('created_at');
            if ($c['student']) $reviews->where('is_published', true);
            $result['reviews'] = $reviews->get();
            if (! $c['student']) $result['aiAssessments'] = DB::table('ai_assessments')->where('submission_id', $s->id)->orderByDesc('created_at')->limit(1)->get();
        }
        return $result;
    }

    /** In-app notification as supporting.service createNotification does (idempotent; external channels are not queued here). */
    private function notify(array $c, ?string $userId, string $event, string $title, string $body, string $url, string $key): void
    {
        if (! $userId) return;
        $id = (string) Str::ulid();
        if (DB::table('notifications')->insertOrIgnore(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'user_id' => $userId,
            'event_type' => $event, 'title' => mb_substr($title, 0, 160), 'body' => $body, 'action_url' => $url, 'idempotency_key' => $key, 'created_at' => now()])) {
            DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(), 'notification_id' => $id, 'channel' => 'IN_APP', 'status' => 'SENT',
                'provider' => 'INTERNAL', 'sent_at' => now(), 'scheduled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function index(Request $request)
    {
        [$c, $q] = $this->scope($request);
        if ($request->filled('status')) $q->where('s.status', $request->string('status')->toString());
        $total = (clone $q)->count();
        return response()->json(['data' => $q->select('s.*')->orderByDesc('s.created_at')->limit(50)->get()->map(fn ($s) => $this->present($s, $c)), 'total' => $total]);
    }

    public function show(Request $request, string $id)
    {
        [$c, $q] = $this->scope($request);
        $row = $q->where('s.id', $id)->select('s.*')->first();
        abort_unless($row, 404, 'Pengumpulan tidak ditemukan');
        return response()->json($this->present($row, $c, true));
    }

    private function studentAssignment(array $c, string $assignmentId): object
    {
        abort_unless($c['student'], 403, 'Hanya siswa dapat mengumpulkan tugas');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']->id);
        abort_unless($plan, 503, 'Paket akses belum tersedia');
        $assignment = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('a.id', $assignmentId)->where('course.school_id', $c['school'])
            ->where('course.organization_id', $c['organization'])->where('course.is_active', true)
            ->where('a.is_active', true)->where('a.published_at', '<=', now())
            ->whereIn('a.type', $this->access->assignmentTypes($plan))
            ->whereIn('a.module_id', $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $plan))
            ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $c['student']->id)
                ->where('school_id', $c['school'])->where('status', 'ACTIVE')->select('course_id'))
            ->select('a.*')->first();
        abort_unless($assignment, 403, 'Tugas tidak tersedia untuk siswa ini');
        $entitlement = match ($assignment->type) {
            'STORE' => 'student_store.submit', 'AFFILIATE' => 'affiliate.content.submit',
            default => 'assignments.standard.submit',
        };
        abort_unless($this->access->enabled($plan, $entitlement), 403, 'Paket tidak mengizinkan pengumpulan tugas');
        abort_if($assignment->end_date && now()->gt($assignment->end_date), 422, 'Tenggat pengumpulan telah lewat');
        return $assignment;
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'submissions', 'create');
        $d = $request->validate(['assignmentId' => 'required|string|max:191', 'content' => 'nullable|string|max:10000',
            'notes' => 'nullable|string|max:2000', 'fileUrl' => 'nullable|url:http,https|max:2048',
            'videoUrl' => 'nullable|url:http,https|max:2048']);
        $assignment = $this->studentAssignment($c, $d['assignmentId']);
        abort_if($assignment->type === 'STORE', 422, 'Gunakan alur toko siswa');
        return DB::transaction(function () use ($c, $d, $assignment) {
            DB::table('assignments')->where('id', $assignment->id)->lockForUpdate()->first();
            $existing = DB::table('submissions')->where('assignment_id', $assignment->id)
                ->where('student_id', $c['student']->id)->lockForUpdate()->first();
            abort_if($existing && ! in_array($existing->status, ['DRAFT', 'REVISION_REQUIRED'], true), 409, 'Kiriman tidak dapat diubah pada status ini');
            $id = $existing?->id ?? (string) Str::ulid();
            $version = $existing ? ($existing->revision + 1) : 1;
            if (! $existing) DB::table('submissions')->insert(['id' => $id, 'assignment_id' => $assignment->id,
                'student_id' => $c['student']->id, 'user_id' => $c['user'], 'body' => $d['content'] ?? '',
                'status' => 'DRAFT', 'revision' => $version, 'created_at' => now(), 'updated_at' => now()]);
            else DB::table('submissions')->where('id', $id)->update(['body' => $d['content'] ?? '', 'revision' => $version, 'updated_at' => now()]);
            DB::table('submission_versions')->insert(['id' => (string) Str::ulid(), 'submission_id' => $id,
                'revision' => $version, 'body' => $d['content'] ?? '', 'status' => 'SOURCE_VERSION',
                'content' => $d['content'] ?? null, 'notes' => $d['notes'] ?? null,
                'file_url' => $d['fileUrl'] ?? null, 'video_url' => $d['videoUrl'] ?? null,
                'snapshot_data' => json_encode(['content' => $d['content'] ?? null, 'fileUrl' => $d['fileUrl'] ?? null,
                    'videoUrl' => $d['videoUrl'] ?? null], JSON_THROW_ON_ERROR),
                'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['id' => $id, 'assignmentId' => $assignment->id,
                'studentId' => $c['student']->id, 'status' => $existing?->status ?? 'DRAFT', 'currentVersion' => $version], $existing ? 200 : 201);
        });
    }

    public function submit(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'submissions', 'update');
        abort_unless($c['student'], 403, 'Hanya siswa dapat mengirim tugas');
        return DB::transaction(function () use ($id, $c) {
            $row = DB::table('submissions')->where('id', $id)->where('student_id', $c['student']->id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Kiriman tidak ditemukan');
            $assignment = $this->studentAssignment($c, $row->assignment_id);
            abort_unless(in_array($row->status, ['DRAFT', 'REVISION_REQUIRED'], true), 409, 'Kiriman tidak dapat dikirim pada status ini');
            $latest = DB::table('submission_versions')->where('submission_id', $id)->orderByDesc('revision')->first();
            abort_unless($latest, 422, 'Simpan isi kiriman sebelum mengirim');
            if ($row->status === 'REVISION_REQUIRED') {
                $review = DB::table('submission_reviews')->where('submission_id', $id)->where('status', 'REVISION_REQUIRED')
                    ->where('is_published', true)->orderByDesc('created_at')->first();
                abort_if($review && $latest->created_at <= $review->created_at, 422, 'Simpan versi revisi sebelum mengirim ulang');
            }
            // Affiliate publication requires verified account and evidence, handled by its dedicated workflow.
            abort_if($assignment->type === 'AFFILIATE', 422, 'Verifikasi akun dan bukti affiliate diperlukan sebelum pengiriman');
            abort_if(! trim($latest->content ?? '') && ! trim($latest->notes ?? '') && ! $latest->file_url && ! $latest->video_url, 422, 'Isi atau bukti tugas wajib dilengkapi');
            $status = $row->status === 'REVISION_REQUIRED' ? 'RESUBMITTED' : 'SUBMITTED';
            DB::table('submissions')->where('id', $id)->update(['status' => $status, 'submitted_at' => now(), 'updated_at' => now()]);
            $this->notify($c, DB::table('teachers')->where('id', $assignment->teacher_id)->value('user_id'), 'SUBMISSION_RECEIVED', 'Pengumpulan baru: '.$assignment->title,
                $c['student']->full_name.' telah mengirim tugas untuk direview.', '/dashboard/review', "submission-received:$id:v{$row->revision}");
            return response()->json(['id' => $id, 'assignmentId' => $row->assignment_id,
                'studentId' => $row->student_id, 'status' => $status, 'currentVersion' => $row->revision]);
        });
    }

    public function review(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'submissions', 'review');
        abort_unless($c['teacher'], 403, 'Profil guru diperlukan');
        $d = $request->validate(['status' => 'required|in:APPROVED,REJECTED,REVISION_REQUIRED',
            'score' => 'nullable|integer|min:0|max:1000', 'feedback' => 'nullable|string|max:5000',
            'publishGrade' => 'sometimes|boolean', 'scores' => 'sometimes|array',
            'scores.*.criterionId' => 'required|string|max:191', 'scores.*.score' => 'required|integer|min:0|max:1000',
            'scores.*.comment' => 'nullable|string|max:1000']);
        return DB::transaction(function () use ($id, $c, $d) {
            $submission = DB::table('submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')
                ->join('courses as course', 'course.id', '=', 'a.course_id')
                ->join('students as student', 'student.id', '=', 's.student_id')
                ->where('s.id', $id)->where('a.teacher_id', $c['teacher']->id)
                ->where('course.school_id', $c['school'])->where('course.organization_id', $c['organization'])
                ->where('student.school_id', $c['school'])
                ->select('s.*', 'a.rubric_id', 'a.max_score', 'a.type', 'a.title', 'student.user_id as student_user_id')->first();
            abort_unless($submission, 404, 'Kiriman tidak ditemukan');
            DB::table('submissions')->where('id', $id)->lockForUpdate()->first();
            abort_unless(in_array($submission->status, ['SUBMITTED', 'RESUBMITTED', 'UNDER_REVIEW'], true), 409, 'Kiriman belum dapat dinilai');
            abort_if($d['status'] !== 'APPROVED' && ! trim($d['feedback'] ?? ''), 422, 'Feedback diperlukan untuk penolakan atau revisi');
            $criteria = $submission->rubric_id ? DB::table('rubric_criteria')->where('rubric_id', $submission->rubric_id)->get()->keyBy('id') : collect();
            $scores = $d['scores'] ?? [];
            abort_if($criteria->count() !== count($scores), 422, 'Semua kriteria rubrik wajib dinilai');
            $calculated = 0;
            $seen = [];
            foreach ($scores as $score) {
                $criterion = $criteria->get($score['criterionId']);
                abort_if(! $criterion || isset($seen[$score['criterionId']]) || $score['score'] > $criterion->max_score || $criterion->max_score <= 0, 422, 'Nilai kriteria tidak valid');
                $seen[$score['criterionId']] = true;
                $calculated += $score['score'] / $criterion->max_score * $criterion->weight * ($submission->max_score ?? 100) / 100;
            }
            $value = $criteria->isNotEmpty() ? (int) round($calculated) : ($d['score'] ?? null);
            abort_if($value !== null && $value > ($submission->max_score ?? 100), 422, 'Nilai melebihi maksimal tugas');
            $publish = $d['publishGrade'] ?? false;
            if ($publish && $d['status'] === 'APPROVED' && $submission->type === 'AFFILIATE') {
                abort_if(DB::table('affiliate_fraud_flags as f')->join('affiliate_videos as v', 'v.id', '=', 'f.affiliate_video_id')
                    ->where('v.submission_id', $id)->whereNull('f.metric_snapshot_id')->where('f.status', 'OPEN')->exists(), 422, 'Selesaikan flag konten terlebih dahulu');
            }
            $reviewId = (string) Str::ulid();
            DB::table('submission_reviews')->insert(['id' => $reviewId, 'submission_id' => $id,
                'reviewer_id' => $c['teacher']->id, 'rubric_id' => $submission->rubric_id,
                'status' => $d['status'], 'total_score' => $value, 'feedback' => trim($d['feedback'] ?? '') ?: null,
                'is_published' => $publish, 'published_at' => $publish ? now() : null, 'created_at' => now()]);
            foreach ($scores as $score) DB::table('review_scores')->insert(['id' => (string) Str::ulid(),
                'review_id' => $reviewId, 'criterion_id' => $score['criterionId'], 'score' => $score['score'],
                'comment' => $score['comment'] ?? null]);
            DB::table('submissions')->where('id', $id)->update(['status' => $publish ? $d['status'] : 'UNDER_REVIEW',
                'score' => $publish ? $value : null, 'feedback' => $publish ? ($d['feedback'] ?? null) : null,
                'reviewed_by_id' => $c['teacher']->id, 'reviewer_id' => $c['user'], 'reviewed_at' => now(),
                'grade_published_at' => $publish ? now() : null,
                'locked_at' => $publish && $d['status'] === 'APPROVED' ? now() : null, 'updated_at' => now()]);
            $revision = $d['status'] === 'REVISION_REQUIRED';
            if ($publish) $this->notify($c, $submission->student_user_id, $revision ? 'REVISION_REQUESTED' : 'REVIEW_PUBLISHED',
                ($revision ? 'Revisi diminta: ' : 'Hasil review: ').$submission->title,
                $revision ? 'Guru memberikan catatan perbaikan. Buka tugas untuk melihat feedback.' : "Status tugas Anda kini {$d['status']}.", '/dashboard/tugas', "submission-review:$reviewId:{$submission->student_user_id}");
            return response()->json(['id' => $reviewId, 'submissionId' => $id, 'reviewerId' => $c['teacher']->id,
                'status' => $d['status'], 'totalScore' => $value, 'feedback' => $d['feedback'] ?? null,
                'isPublished' => $publish]);
        });
    }
}
