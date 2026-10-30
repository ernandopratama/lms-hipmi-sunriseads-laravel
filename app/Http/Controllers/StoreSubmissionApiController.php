<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreSubmissionApiController extends Controller
{
    private const HOSTS = [
        'SHOPEE' => ['shopee.co.id', 'shopee.com'], 'TIKTOK' => ['tiktok.com'],
        'TOKOPEDIA' => ['tokopedia.com'], 'LAZADA' => ['lazada.co.id', 'lazada.com'],
        'INSTAGRAM_SHOP' => ['instagram.com'], 'WHATSAPP_BUSINESS' => ['wa.me', 'whatsapp.com'],
    ];

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $resource, string $action): array
    {
        $c = $this->context->resolve($request, $resource, $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'student_store.submit'), 403,
            'Student Store tidak termasuk dalam paket aktif');
        $c['plan'] = $plan;
        return $c;
    }

    private function assignment(array $c, string $id, bool $student): object
    {
        $q = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('a.id', $id)->where('a.type', 'STORE')->where('course.organization_id', $c['organization'])
            ->where('course.school_id', $c['school'])->select('a.*');
        if ($student) {
            abort_unless($c['student'], 403, 'Profil siswa diperlukan');
            $q->where('a.is_active', true)->whereNotNull('a.published_at')->where('a.published_at', '<=', now())
                ->whereIn('a.module_id', $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $c['plan']))
                ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $c['student']->id)
                    ->where('school_id', $c['school'])->where('status', 'ACTIVE')->select('course_id'));
        } elseif ($c['teacher']) $q->where('a.teacher_id', $c['teacher']->id);
        $a = $q->first();
        abort_unless($a, 404, 'Tugas toko tidak tersedia');
        return $a;
    }

    private function row(array $c, string $id, bool $studentOnly = false): object
    {
        $q = DB::table('student_store_submissions')->where('id', $id)
            ->where('organization_id', $c['organization'])->where('school_id', $c['school']);
        if ($studentOnly || $c['student']) {
            abort_unless($c['student'], 403, 'Profil siswa diperlukan');
            $q->where('student_id', $c['student']->id);
        }
        $r = $q->first();
        abort_unless($r, 404, 'Submission toko tidak ditemukan dalam scope pengguna');
        $this->assignment($c, $r->assignment_id, (bool) ($studentOnly || $c['student']));
        return $r;
    }

    private function camel(object $r): array
    {
        return collect((array) $r)->mapWithKeys(fn ($v, $k) => [Str::camel($k) =>
            in_array($k, ['draft_data', 'store_snapshot_data', 'evidence'], true) && $v !== null
                ? json_decode($v, true) : $v])->all();
    }

    private function template(?string $id): ?array
    {
        if (! $id) return null;
        $r = DB::table('store_checklist_templates')->where('id', $id)->first();
        if (! $r) return null;
        return [...$this->camel($r), 'items' => DB::table('store_checklist_items')->where('template_id', $id)
            ->orderBy('position')->get()->map(fn ($i) => $this->camel($i))];
    }

    private function assignmentOutput(object $a): array
    {
        $course = DB::table('courses')->where('id', $a->course_id)->first();
        $rubric = $a->rubric_id ? DB::table('rubrics')->where('id', $a->rubric_id)->first() : null;
        return [...$this->camel($a), 'course' => $course ? $this->camel($course) : null,
            'checklistTemplate' => $this->template($a->checklist_template_id),
            'rubric' => $rubric ? [...$this->camel($rubric), 'criteria' => DB::table('rubric_criteria')
                ->where('rubric_id', $rubric->id)->orderBy('position')->get()->map(fn ($r) => $this->camel($r))] : null];
    }

    public function assignments(Request $request)
    {
        $c = $this->scope($request, 'assignments', 'read');
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        $ids = $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $c['plan']);
        $rows = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('course.organization_id', $c['organization'])->where('course.school_id', $c['school'])
            ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $c['student']->id)
                ->where('school_id', $c['school'])->where('status', 'ACTIVE')->select('course_id'))
            ->where('a.type', 'STORE')->where('a.is_active', true)->whereNotNull('a.published_at')
            ->where('a.published_at', '<=', now())->whereIn('a.module_id', $ids)
            ->orderBy('a.end_date')->get(['a.*']);
        return response()->json($rows->map(function ($a) use ($c) {
            $submissions = DB::table('student_store_submissions')->where('assignment_id', $a->id)
                ->where('student_id', $c['student']->id)->get(['id', 'status', 'row_version',
                    'current_version', 'score', 'feedback', 'updated_at']);
            return [...$this->assignmentOutput($a), 'storeSubmissions' => $submissions->map(fn ($r) => $this->camel($r))];
        }));
    }

    public function show(Request $request, string $id)
    {
        $c = $this->scope($request, 'submissions', 'read');
        $r = $this->row($c, $id);
        $a = DB::table('assignments')->where('id', $r->assignment_id)->first();
        $s = DB::table('students')->where('id', $r->student_id)->first();
        $store = $r->student_store_id ? DB::table('student_stores')->where('id', $r->student_store_id)->first() : null;
        $reviews = DB::table('store_reviews')->where('store_submission_id', $id);
        if ($c['student']) $reviews->where('is_published', true);
        return response()->json([...$this->camel($r), 'assignment' => $this->assignmentOutput($a),
            'student' => $s ? ['id' => $s->id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number] : null,
            'studentStore' => $store ? $this->camel($store) : null,
            'checklistResponses' => DB::table('store_checklist_responses')->where('store_submission_id', $id)
                ->get()->map(fn ($v) => $this->camel($v)),
            'files' => DB::table('student_store_files')->where('store_submission_id', $id)
                ->orderBy('position')->get(['id', 'kind', 'original_file_name', 'mime_type', 'size_bytes',
                    'scan_status', 'caption', 'position'])->map(fn ($v) => $this->camel($v)),
            'versions' => DB::table('student_store_submission_versions')->where('store_submission_id', $id)
                ->orderByDesc('version_number')->get()->map(fn ($v) => $this->camel($v)),
            'reviews' => $reviews->orderByDesc('created_at')->get()->map(function ($v) {
                $teacher = DB::table('teachers')->where('id', $v->reviewer_id)->first();
                return [...$this->camel($v), 'reviewer' => $teacher ? ['fullName' => $teacher->full_name] : null,
                    'scores' => DB::table('store_review_scores')->where('review_id', $v->id)
                        ->get()->map(fn ($score) => $this->camel($score))];
            }),
            'fraudFlags' => $c['student'] ? [] : DB::table('store_fraud_flags')->where('store_submission_id', $id)
                ->orderByDesc('created_at')->get()->map(fn ($v) => $this->camel($v))]);
    }

    private function normalizedUrl(string $url, string $platform): string
    {
        $parts = parse_url(trim($url));
        abort_unless($parts && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && isset($parts['host']), 400, 'URL toko tidak valid');
        $host = rtrim(strtolower($parts['host']), '.');
        abort_if($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) || $host === 'metadata.google.internal', 400,
            'URL internal atau privat tidak diizinkan');
        $allowed = self::HOSTS[$platform] ?? null;
        abort_if($allowed && ! collect($allowed)->contains(fn ($domain) => $host === $domain || str_ends_with($host, '.'.$domain)),
            400, 'Domain URL tidak sesuai platform');
        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')
            .(rtrim($parts['path'] ?? '/', '/') ?: '/');
    }

    public function draft(Request $request)
    {
        $c = $this->scope($request, 'submissions', 'create');
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        $d = $request->validate(['assignmentId' => 'required|string', 'rowVersion' => 'sometimes|integer|min:1',
            'platform' => 'required|in:SHOPEE,TIKTOK,TOKOPEDIA,LAZADA,INSTAGRAM_SHOP,WHATSAPP_BUSINESS,WEBSITE,OTHER',
            'storeName' => 'required|string|min:2|max:160', 'storeUsername' => 'sometimes|nullable|string|max:160',
            'storeExternalId' => 'sometimes|nullable|string|max:160', 'storeUrl' => 'required|url:http,https',
            'category' => 'sometimes|nullable|string|max:120', 'description' => 'sometimes|nullable|string|max:3000',
            'createdDate' => 'sometimes|nullable|date', 'productCount' => 'required|integer|min:0|max:100000',
            'products' => 'sometimes|array', 'studentNotes' => 'sometimes|nullable|string|max:3000',
            'obstacles' => 'sometimes|nullable|string|max:3000', 'declarationAccepted' => 'required|boolean',
            'checklist' => 'required|array', 'checklist.*.itemId' => 'required|string',
            'checklist.*.completed' => 'required|boolean', 'checklist.*.value' => 'sometimes|nullable|string|max:1000',
            'checklist.*.comment' => 'sometimes|nullable|string|max:1000']);
        $a = $this->assignment($c, $d['assignmentId'], true);
        abort_unless($a->checklist_template_id, 400, 'Checklist tugas belum dikonfigurasi');
        $valid = DB::table('store_checklist_items')->where('template_id', $a->checklist_template_id)->pluck('id')->all();
        $ids = array_column($d['checklist'], 'itemId');
        abort_if(array_diff($ids, $valid) || count(array_unique($ids)) !== count($ids), 400,
            'Checklist memuat item di luar template tugas atau duplikat');
        $url = $this->normalizedUrl($d['storeUrl'], $d['platform']);
        return DB::transaction(function () use ($c, $d, $a, $url) {
            $r = DB::table('student_store_submissions')->where('assignment_id', $a->id)
                ->where('student_id', $c['student']->id)->lockForUpdate()->first();
            abort_if($r && ! in_array($r->status, ['DRAFT', 'REVISION_REQUIRED'], true), 400,
                'Submission tidak dapat diubah pada status saat ini');
            abort_if($r && isset($d['rowVersion']) && (int) $r->row_version !== $d['rowVersion'], 409,
                'Draf telah berubah di perangkat lain. Muat ulang sebelum menyimpan.');
            $storeId = $r?->student_store_id ?: (string) Str::ulid();
            $storeData = ['platform' => $d['platform'], 'store_name' => trim($d['storeName']),
                'store_username' => trim($d['storeUsername'] ?? '') ?: null,
                'store_external_id' => trim($d['storeExternalId'] ?? '') ?: null,
                'store_url' => trim($d['storeUrl']), 'normalized_store_url' => $url,
                'category' => trim($d['category'] ?? '') ?: null, 'description' => trim($d['description'] ?? '') ?: null,
                'created_date' => $d['createdDate'] ?? null, 'product_count' => $d['productCount'], 'updated_at' => now()];
            if ($r?->student_store_id) DB::table('student_stores')->where('id', $storeId)->update($storeData);
            else DB::table('student_stores')->insert(['id' => $storeId, 'organization_id' => $c['organization'],
                'school_id' => $c['school'], 'student_id' => $c['student']->id, 'activation_status' => 'DRAFT',
                ...$storeData, 'created_at' => now()]);
            $draft = json_encode(['products' => $d['products'] ?? [], 'studentNotes' => $d['studentNotes'] ?? '',
                'obstacles' => $d['obstacles'] ?? '', 'declarationAccepted' => $d['declarationAccepted']]);
            $id = $r?->id ?: (string) Str::ulid();
            if ($r) DB::table('student_store_submissions')->where('id', $id)->update([
                'student_store_id' => $storeId, 'draft_data' => $draft, 'row_version' => $r->row_version + 1,
                'updated_at' => now()]);
            else DB::table('student_store_submissions')->insert(['id' => $id, 'organization_id' => $c['organization'],
                'school_id' => $c['school'], 'assignment_id' => $a->id, 'student_id' => $c['student']->id,
                'student_store_id' => $storeId, 'status' => 'DRAFT', 'current_version' => 0, 'row_version' => 1,
                'draft_data' => $draft, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($d['checklist'] as $answer) DB::table('store_checklist_responses')->updateOrInsert(
                ['store_submission_id' => $id, 'item_id' => $answer['itemId']],
                ['id' => DB::table('store_checklist_responses')->where('store_submission_id', $id)
                    ->where('item_id', $answer['itemId'])->value('id') ?: (string) Str::ulid(),
                    'completed' => $answer['completed'], 'value' => trim($answer['value'] ?? '') ?: null,
                    'comment' => trim($answer['comment'] ?? '') ?: null, 'updated_at' => now()]);
            foreach (['DUPLICATE_URL' => ['normalized_store_url', $url],
                'DUPLICATE_EXTERNAL_ID' => ['store_external_id', $d['storeExternalId'] ?? null]] as $type => [$field, $value]) {
                if (! $value) continue;
                $matched = DB::table('student_stores')->where('school_id', $c['school'])->where('platform', $d['platform'])
                    ->where('student_id', '!=', $c['student']->id)->where($field, $value)->first();
                if ($matched && ! DB::table('store_fraud_flags')->where('store_submission_id', $id)
                    ->where('type', $type)->where('status', 'OPEN')->exists())
                    DB::table('store_fraud_flags')->insert(['id' => (string) Str::ulid(), 'store_submission_id' => $id,
                        'type' => $type, 'reason' => $type === 'DUPLICATE_URL' ? 'URL toko juga digunakan oleh siswa lain'
                            : 'ID toko juga digunakan oleh siswa lain', 'evidence' => json_encode(['matchedStoreId' => $matched->id]),
                        'confidence' => .85, 'status' => 'OPEN', 'created_at' => now()]);
            }
            $row = DB::table('student_store_submissions')->where('id', $id)->first();
            return response()->json(['id' => $id, 'status' => $row->status, 'rowVersion' => $row->row_version,
                'storeId' => $storeId, 'savedAt' => now()->toIso8601String()]);
        });
    }

    public function submit(Request $request, string $id)
    {
        $c = $this->scope($request, 'submissions', 'update');
        $d = $request->validate(['declarationAccepted' => 'required|boolean']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = $this->row($c, $id, true);
            abort_unless(in_array($r->status, ['DRAFT', 'REVISION_REQUIRED'], true), 400,
                'Submission tidak dapat dikirim pada status ini');
            abort_unless($d['declarationAccepted'], 400, 'Deklarasi keamanan dan kepemilikan wajib diterima');
            $a = DB::table('assignments')->where('id', $r->assignment_id)->first();
            $store = $r->student_store_id ? DB::table('student_stores')->where('id', $r->student_store_id)->first() : null;
            abort_unless($store, 400, 'Informasi toko belum lengkap');
            $required = DB::table('store_checklist_items')->where('template_id', $a->checklist_template_id)
                ->where('is_required', true)->pluck('id');
            $completed = DB::table('store_checklist_responses')->where('store_submission_id', $id)
                ->where('completed', true)->pluck('item_id');
            abort_if($required->diff($completed)->isNotEmpty(), 400, 'Checklist wajib belum lengkap');
            $template = DB::table('store_checklist_templates')->where('id', $a->checklist_template_id)->first();
            abort_if($store->product_count < ($template?->minimum_products ?? 1), 400,
                'Jumlah produk belum memenuhi minimum');
            $files = DB::table('student_store_files')->where('store_submission_id', $id)->get();
            abort_unless($files->contains(fn ($file) => $file->scan_status === 'CLEAN'), 400,
                'Minimal satu bukti yang lolos pemindaian wajib tersedia');
            abort_if($files->contains(fn ($file) => $file->scan_status !== 'CLEAN'), 400,
                'Tunggu seluruh file selesai dipindai');
            $next = $r->current_version + 1;
            $draft = json_decode($r->draft_data, true) ?: [];
            DB::table('student_store_submission_versions')->insert(['id' => (string) Str::ulid(),
                'store_submission_id' => $id, 'version_number' => $next,
                'store_snapshot_data' => json_encode(['store' => $this->camel($store),
                    'checklist' => DB::table('store_checklist_responses')->where('store_submission_id', $id)->get()
                        ->map(fn ($v) => $this->camel($v))->all(),
                    'files' => $files->map(fn ($f) => ['id' => $f->id, 'kind' => $f->kind,
                        'fileName' => $f->original_file_name])->all(), 'draft' => $draft]),
                'student_notes' => trim($draft['studentNotes'] ?? '') ?: null,
                'obstacles' => trim($draft['obstacles'] ?? '') ?: null,
                'declaration_accepted' => true, 'created_at' => now()]);
            DB::table('student_store_submissions')->where('id', $id)->update(['current_version' => $next,
                'row_version' => $r->row_version + 1, 'status' => $r->status === 'REVISION_REQUIRED' ? 'RESUBMITTED' : 'SUBMITTED',
                'submitted_at' => now(), 'updated_at' => now()]);
            return response()->json($this->camel(DB::table('student_store_submissions')->where('id', $id)->first()));
        });
    }

    public function review(Request $request, string $id)
    {
        $c = $this->scope($request, 'submissions', 'review');
        abort_unless($c['teacher'], 403, 'Profil guru diperlukan');
        $d = $request->validate(['decision' => 'required|in:APPROVED,REJECTED,REVISION_REQUIRED',
            'feedback' => 'sometimes|nullable|string|max:5000', 'publishGrade' => 'required|boolean',
            'scores' => 'sometimes|array', 'scores.*.criterionId' => 'required|string',
            'scores.*.score' => 'required|integer|min:0|max:1000',
            'scores.*.comment' => 'sometimes|nullable|string|max:1000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = $this->row($c, $id);
            abort_unless(in_array($r->status, ['SUBMITTED', 'RESUBMITTED', 'UNDER_REVIEW'], true), 400,
                'Submission tidak berada dalam antrean review');
            abort_if($d['decision'] !== 'APPROVED' && ! trim($d['feedback'] ?? ''), 400,
                'Feedback wajib untuk penolakan atau revisi');
            $a = DB::table('assignments')->where('id', $r->assignment_id)->first();
            $criteria = $a->rubric_id ? DB::table('rubric_criteria')->where('rubric_id', $a->rubric_id)->get() : collect();
            $scores = $d['scores'] ?? [];
            abort_if($criteria->count() && count($scores) !== $criteria->count(), 400,
                'Semua kriteria rubrik wajib dinilai');
            abort_if(count(array_unique(array_column($scores, 'criterionId'))) !== count($scores), 400,
                'Kriteria rubrik tidak boleh dinilai lebih dari sekali');
            abort_if(! $criteria->count() && $scores, 400, 'Kriteria rubrik tidak valid');
            $total = null;
            if ($criteria->count()) {
                $map = $criteria->keyBy('id');
                $points = 0;
                foreach ($scores as $score) {
                    $criterion = $map->get($score['criterionId']);
                    abort_if(! $criterion || $score['score'] > $criterion->max_score || $criterion->max_score < 1,
                        400, 'Nilai rubrik tidak valid');
                    $points += $score['score'] / $criterion->max_score * $criterion->weight * $a->max_score / 100;
                }
                $total = (int) round($points);
            }
            $reviewId = (string) Str::ulid();
            DB::table('store_reviews')->insert(['id' => $reviewId, 'store_submission_id' => $id,
                'reviewer_id' => $c['teacher']->id, 'rubric_id' => $a->rubric_id,
                'decision' => $d['decision'], 'total_score' => $total,
                'feedback' => trim($d['feedback'] ?? '') ?: null, 'is_published' => $d['publishGrade'],
                'created_at' => now()]);
            foreach ($scores as $score) DB::table('store_review_scores')->insert(['id' => (string) Str::ulid(),
                'review_id' => $reviewId, 'criterion_id' => $score['criterionId'], 'score' => $score['score'],
                'comment' => trim($score['comment'] ?? '') ?: null]);
            DB::table('student_store_submissions')->where('id', $id)->update([
                'status' => $d['publishGrade'] ? $d['decision'] : 'UNDER_REVIEW', 'reviewed_at' => now(),
                'score' => $d['publishGrade'] ? $total : null,
                'feedback' => $d['publishGrade'] ? ($d['feedback'] ?? null) : null,
                'locked_at' => $d['publishGrade'] && $d['decision'] === 'APPROVED' ? now() : null,
                'updated_at' => now()]);
            if ($d['publishGrade'] && $d['decision'] === 'APPROVED' && $r->student_store_id)
                DB::table('student_stores')->where('id', $r->student_store_id)->update([
                    'activation_status' => 'ACTIVE', 'updated_at' => now()]);
            return response()->json([...$this->camel(DB::table('store_reviews')->where('id', $reviewId)->first()),
                'scores' => DB::table('store_review_scores')->where('review_id', $reviewId)
                    ->get()->map(fn ($v) => $this->camel($v))]);
        });
    }
}
