<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCertificate;
use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CertificateApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'certificates', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'support.advanced.use'), 403,
            'Sertifikat hanya tersedia pada paket Advanced');
        return $c;
    }

    private function studentIds(array $c)
    {
        $q = DB::table('students')->where('school_id', $c['school']);
        if ($c['student']) return $q->where('id', $c['student']->id)->pluck('id');
        if (in_array('parent', $c['roles'], true)) {
            $parent = DB::table('parents')->where('user_id', $c['user'])->where('is_active', true)->first();
            $ids = $parent ? DB::table('student_parent_links')->where('parent_id', $parent->id)
                ->where('verification_status', 'VERIFIED')->whereNull('revoked_at')
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->pluck('student_id') : collect();
            return $q->whereIn('id', $ids)->pluck('id');
        }
        if ($c['teacher']) {
            $classes = DB::table('teacher_assignments')->where('teacher_id', $c['teacher']->id)
                ->where('school_id', $c['school'])->where('is_active', true)->pluck('class_id');
            $ids = DB::table('class_enrollments')->whereIn('class_id', $classes)
                ->where('school_id', $c['school'])->where('status', 'ACTIVE')->pluck('student_id');
            return $q->whereIn('id', $ids)->pluck('id');
        }
        return $q->pluck('id');
    }

    private function template(object $r): array
    {
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'name' => $r->name, 'titleTemplate' => $r->title_template, 'description' => $r->description,
            'eligibilityRule' => json_decode($r->eligibility_rule, true), 'isActive' => (bool) $r->is_active,
            'createdById' => $r->created_by_id, 'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at];
    }

    private function certificate(object $r, bool $relations = false): array
    {
        $data = ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'studentId' => $r->student_id, 'templateId' => $r->template_id, 'issuedById' => $r->issued_by_id,
            'revokedById' => $r->revoked_by_id, 'publicationConsentId' => $r->publication_consent_id,
            'verificationCode' => $r->verification_code, 'title' => $r->title, 'description' => $r->description,
            'status' => $r->status, 'publicVisible' => (bool) $r->public_visible,
            'metadata' => $r->metadata ? json_decode($r->metadata, true) : null,
            'pdfStorageKey' => $r->pdf_storage_key, 'issuedAt' => $r->issued_at,
            'revokedAt' => $r->revoked_at, 'revokeReason' => $r->revoke_reason,
            'failureReason' => $r->failure_reason, 'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at];
        if ($relations) {
            $student = DB::table('students')->where('id', $r->student_id)->first();
            $template = DB::table('certificate_templates')->where('id', $r->template_id)->first();
            $data['student'] = $student ? ['fullName' => $student->full_name, 'studentNumber' => $student->student_number] : null;
            $data['template'] = $template ? ['name' => $template->name] : null;
        }
        return $data;
    }

    public function templates(Request $request)
    {
        $c = $this->scope($request, 'read');
        return response()->json(DB::table('certificate_templates')->where('organization_id', $c['organization'])
            ->where('is_active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))
            ->orderBy('name')->get()->map(fn ($r) => $this->template($r)));
    }

    public function createTemplate(Request $request)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['name' => 'required|string|min:3|max:120', 'titleTemplate' => 'required|string|min:3|max:180',
            'description' => 'sometimes|nullable|string|max:1000', 'eligibilityRule' => 'present|array']);
        $rule = $d['eligibilityRule'];
        foreach (['minApprovedSubmissions', 'minLearningProgress', 'minApprovedStores'] as $key) {
            abort_if(isset($rule[$key]) && (! is_numeric($rule[$key]) || $rule[$key] < 0 || $rule[$key] > 100000), 422,
                'Aturan kelayakan tidak valid');
        }
        abort_if(DB::table('certificate_templates')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->where('name', trim($d['name']))->exists(), 409, 'Nama template sudah digunakan');
        $id = (string) Str::ulid();
        DB::table('certificate_templates')->insert(['id' => $id, 'organization_id' => $c['organization'],
            'school_id' => $c['school'], 'name' => trim($d['name']), 'title_template' => trim($d['titleTemplate']),
            'description' => isset($d['description']) ? trim($d['description']) : null,
            'eligibility_rule' => json_encode($rule), 'created_by_id' => $c['user'],
            'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->template(DB::table('certificate_templates')->where('id', $id)->first()), 201);
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        return response()->json(DB::table('certificates')->where('organization_id', $c['organization'])
            ->whereIn('student_id', $this->studentIds($c))->orderByDesc('created_at')->get()
            ->map(fn ($r) => $this->certificate($r, true)));
    }

    public function issue(Request $request)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['studentId' => 'required|string', 'templateId' => 'required|string']);
        $student = DB::table('students')->where('id', $d['studentId'])->where('school_id', $c['school'])->first();
        $template = DB::table('certificate_templates')->where('id', $d['templateId'])
            ->where('organization_id', $c['organization'])->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))->first();
        abort_unless($student && $template, 404, 'Siswa atau template sertifikat tidak ditemukan');
        $rule = json_decode($template->eligibility_rule, true) ?: [];
        $approvedSubmissions = DB::table('submissions')->where('student_id', $student->id)->where('status', 'APPROVED')->count();
        $progress = (float) DB::table('lesson_progress')->where('student_id', $student->id)->avg('progress_percent');
        $approvedStores = DB::table('student_store_submissions')->where('student_id', $student->id)->where('status', 'APPROVED')->count();
        abort_if($approvedSubmissions < (float) ($rule['minApprovedSubmissions'] ?? 0) ||
            $progress < (float) ($rule['minLearningProgress'] ?? 0) ||
            $approvedStores < (float) ($rule['minApprovedStores'] ?? 0), 400, 'Siswa belum memenuhi aturan kelayakan sertifikat');
        $existing = DB::table('certificates')->where('student_id', $student->id)->where('template_id', $template->id)->first();
        if ($existing) return response()->json($this->certificate($existing));
        $id = (string) Str::ulid();
        DB::table('certificates')->insert(['id' => $id, 'organization_id' => $c['organization'],
            'school_id' => $c['school'], 'student_id' => $student->id, 'template_id' => $template->id,
            'issued_by_id' => $c['user'], 'verification_code' => 'CERT-'.strtoupper(bin2hex(random_bytes(6))),
            'title' => str_replace('{{studentName}}', $student->full_name, $template->title_template),
            'description' => $template->description, 'metadata' => json_encode(['approvedSubmissions' => $approvedSubmissions,
                'averageProgress' => $progress, 'approvedStores' => $approvedStores]),
            'created_at' => now(), 'updated_at' => now()]);
        GenerateCertificate::dispatch($id, $c['user'])->afterResponse();
        return response()->json($this->certificate(DB::table('certificates')->where('id', $id)->first()));
    }

    public function publication(Request $request, string $id)
    {
        $c = $this->scope($request, 'update');
        $d = $request->validate(['publicVisible' => 'required|boolean']);
        abort_unless($c['student'], 403, 'Publikasi hanya dapat diatur oleh siswa pemilik');
        $r = DB::table('certificates')->where('id', $id)->where('student_id', $c['student']->id)
            ->where('organization_id', $c['organization'])->where('school_id', $c['school'])->where('status', 'ISSUED')->first();
        abort_unless($r, 404, 'Sertifikat tidak ditemukan');
        $consent = null;
        if ($d['publicVisible']) {
            $consent = DB::table('consents')->where('student_id', $c['student']->id)
                ->where('organization_id', $c['organization'])->where('type', 'PUBLICATION')
                ->where('status', 'ACTIVE')->where('effective_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('granted_at')->first();
            abort_unless($consent, 400, 'Persetujuan publikasi aktif diperlukan');
        }
        DB::table('certificates')->where('id', $id)->update(['public_visible' => $d['publicVisible'],
            'publication_consent_id' => $consent?->id, 'updated_at' => now()]);
        return response()->json($this->certificate(DB::table('certificates')->where('id', $id)->first()));
    }

    public function revoke(Request $request, string $id)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $r = DB::table('certificates')->where('id', $id)->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->first();
        abort_unless($r, 404, 'Sertifikat tidak ditemukan');
        DB::table('certificates')->where('id', $id)->update(['status' => 'REVOKED', 'revoked_by_id' => $c['user'],
            'revoked_at' => now(), 'revoke_reason' => trim(preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]',
                preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu', '$1 [DIHAPUS]', $d['reason']))),
            'public_visible' => false, 'updated_at' => now()]);
        return response()->json($this->certificate(DB::table('certificates')->where('id', $id)->first()));
    }

    public function download(Request $request, string $id)
    {
        $c = $this->scope($request, 'read');
        $r = DB::table('certificates')->where('id', $id)->where('organization_id', $c['organization'])
            ->whereIn('student_id', $this->studentIds($c))->where('status', 'ISSUED')->whereNotNull('pdf_storage_key')->first();
        abort_unless($r && Storage::disk('certificates')->exists($r->pdf_storage_key), 404, 'File sertifikat belum tersedia');
        return response()->json(['url' => URL::temporarySignedRoute('certificate.download', now()->addSeconds(120), ['id' => $id]),
            'expiresInSeconds' => 120]);
    }

    public function file(Request $request, string $id)
    {
        $c = $this->scope($request, 'read');
        $r = DB::table('certificates')->where('id', $id)->where('organization_id', $c['organization'])
            ->whereIn('student_id', $this->studentIds($c))->where('status', 'ISSUED')->first();
        abort_unless($r && $r->pdf_storage_key && Storage::disk('certificates')->exists($r->pdf_storage_key), 404);
        return Storage::disk('certificates')->download($r->pdf_storage_key, $r->verification_code.'.pdf');
    }
}
