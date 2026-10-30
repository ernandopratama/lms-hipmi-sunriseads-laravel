<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortfolioApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'portfolio', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'support.advanced.use'), 403,
            'Portofolio hanya tersedia pada paket Advanced');
        return $c;
    }

    private function output(object $r): array
    {
        $student = DB::table('students')->where('id', $r->student_id)->first();
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'studentId' => $r->student_id, 'createdById' => $r->created_by_id,
            'publicationConsentId' => $r->publication_consent_id, 'sourceType' => $r->source_type,
            'sourceId' => $r->source_id, 'title' => $r->title, 'description' => $r->description,
            'thumbnailUrl' => $r->thumbnail_url, 'publicSlug' => $r->public_slug,
            'status' => $r->status, 'publishedAt' => $r->published_at,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at,
            'student' => $student ? ['fullName' => $student->full_name,
                'studentNumber' => $student->student_number] : null];
    }

    private function students(array $c)
    {
        $q = DB::table('students')->where('school_id', $c['school']);
        if ($c['student']) return $q->where('id', $c['student']->id);
        if (in_array('parent', $c['roles'], true)) {
            $parent = DB::table('parents')->where('user_id', $c['user'])->where('is_active', true)->first();
            $ids = $parent ? DB::table('student_parent_links')->where('parent_id', $parent->id)
                ->where('verification_status', 'VERIFIED')->whereNull('revoked_at')
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now()))->pluck('student_id') : collect();
            return $q->whereIn('id', $ids);
        }
        if ($c['teacher']) {
            $classes = DB::table('teacher_assignments')->where('teacher_id', $c['teacher']->id)
                ->where('school_id', $c['school'])->where('is_active', true)->pluck('class_id');
            $ids = DB::table('class_enrollments')->whereIn('class_id', $classes)
                ->where('school_id', $c['school'])->where('status', 'ACTIVE')->pluck('student_id');
            return $q->whereIn('id', $ids);
        }
        return $q;
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        return response()->json(DB::table('portfolio_items')->where('organization_id', $c['organization'])
            ->whereIn('student_id', $this->students($c)->pluck('id'))
            ->orderByDesc('created_at')->get()->map(fn ($r) => $this->output($r)));
    }

    public function store(Request $request)
    {
        $c = $this->scope($request, 'create');
        $d = $request->validate(['sourceType' => 'required|in:STORE,AFFILIATE,ASSIGNMENT',
            'sourceId' => 'required|string', 'studentId' => 'sometimes|string',
            'title' => 'sometimes|string|max:160', 'description' => 'sometimes|nullable|string|max:1000']);
        $academicStaff = (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal', 'teacher', 'support']);
        $studentId = $c['student']?->id ?? ($academicStaff ? ($d['studentId'] ?? null) : null);
        abort_unless($studentId, 400, 'Siswa portofolio tidak tersedia');
        $student = DB::table('students')->where('id', $studentId)->where('school_id', $c['school'])->first();
        abort_unless($student, 403, 'Siswa berada di luar ruang lingkup');
        $title = $d['title'] ?? '';
        if ($d['sourceType'] === 'STORE') {
            $source = DB::table('student_store_submissions as ss')->leftJoin('student_stores as store', 'store.id', '=', 'ss.student_store_id')
                ->where('ss.id', $d['sourceId'])->where('ss.student_id', $studentId)->where('ss.status', 'APPROVED')
                ->first(['ss.id', 'store.store_name']);
            abort_unless($source, 400, 'Hanya karya toko yang disetujui dapat masuk portofolio');
            $title = $title ?: ($source->store_name ?: 'Karya Student Store');
        } elseif ($d['sourceType'] === 'AFFILIATE') {
            $source = DB::table('affiliate_videos as v')->join('submissions as s', 's.id', '=', 'v.submission_id')
                ->where('v.id', $d['sourceId'])->where('v.student_id', $studentId)->where('s.status', 'APPROVED')
                ->first(['v.product_name']);
            abort_unless($source, 400, 'Hanya konten affiliate yang disetujui dapat masuk portofolio');
            $title = $title ?: ($source->product_name ?: 'Konten Affiliate');
        } else {
            $source = DB::table('submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')
                ->where('s.id', $d['sourceId'])->where('s.student_id', $studentId)->where('s.status', 'APPROVED')
                ->first(['a.title']);
            abort_unless($source, 400, 'Hanya tugas yang disetujui dapat masuk portofolio');
            $title = $title ?: $source->title;
        }
        $existing = DB::table('portfolio_items')->where('student_id', $studentId)
            ->where('source_type', $d['sourceType'])->where('source_id', $d['sourceId'])->first();
        if ($existing) DB::table('portfolio_items')->where('id', $existing->id)
            ->update(['title' => $title, 'description' => $d['description'] ?? null, 'updated_at' => now()]);
        else DB::table('portfolio_items')->insert(['id' => (string) Str::ulid(),
            'organization_id' => $c['organization'], 'school_id' => $c['school'],
            'student_id' => $studentId, 'created_by_id' => $c['user'],
            'source_type' => $d['sourceType'], 'source_id' => $d['sourceId'],
            'title' => $title, 'description' => $d['description'] ?? null,
            'status' => 'PRIVATE', 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('portfolio_items')->where('student_id', $studentId)
            ->where('source_type', $d['sourceType'])->where('source_id', $d['sourceId'])->first();
        return response()->json($this->output($row), $existing ? 200 : 201);
    }

    public function publication(Request $request, string $id)
    {
        $c = $this->scope($request, 'update');
        $d = $request->validate(['publicVisible' => 'required|boolean']);
        abort_unless($c['student'], 403, 'Publikasi hanya dapat diatur oleh siswa pemilik');
        $item = DB::table('portfolio_items')->where('id', $id)->where('student_id', $c['student']->id)
            ->where('organization_id', $c['organization'])->where('school_id', $c['school'])->first();
        abort_unless($item, 404, 'Item portofolio tidak ditemukan');
        if (! $d['publicVisible']) $values = ['status' => 'PRIVATE', 'publication_consent_id' => null,
            'published_at' => null];
        else {
            $consent = DB::table('consents')->where('student_id', $c['student']->id)
                ->where('organization_id', $c['organization'])->where('type', 'PUBLICATION')
                ->where('status', 'ACTIVE')->where('effective_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('granted_at')->first();
            abort_unless($consent, 400, 'Persetujuan publikasi aktif diperlukan');
            $values = ['status' => 'PUBLISHED', 'publication_consent_id' => $consent->id,
                'public_slug' => $item->public_slug ?: 'karya-'.Str::uuid(), 'published_at' => now()];
        }
        DB::table('portfolio_items')->where('id', $id)->update([...$values, 'updated_at' => now()]);
        return response()->json($this->output(DB::table('portfolio_items')->where('id', $id)->first()));
    }
}
