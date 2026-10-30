<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Consent and data-subject-request flows; mirrors apps/api/src/privacy. */
class PrivacyApiController extends Controller
{
    private const DEFAULT_DOCS = [
        ['PLATFORM', 'Penggunaan platform', 'Persetujuan pemrosesan data yang diperlukan untuk menyediakan akun dan layanan pembelajaran.'],
        ['GUARDIAN', 'Persetujuan orang tua/wali', 'Persetujuan wali terverifikasi untuk pemrosesan data siswa sesuai tujuan program.'],
        ['PUBLICATION', 'Publikasi karya', 'Pilihan sukarela untuk publikasi karya atau materi siswa yang telah disetujui.'],
        ['ANALYTICS', 'Analytics opsional', 'Pilihan penggunaan data analytics non-sensitif untuk peningkatan layanan.'],
        ['IDENTITY', 'Pemrosesan identitas', 'Persetujuan terbatas untuk memproses identifier dan dokumen privat dalam alur verifikasi identitas.'],
    ];
    private const TRANSITIONS = [
        'SUBMITTED' => ['IN_REVIEW', 'CANCELLED'], 'IN_REVIEW' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['COMPLETED', 'CANCELLED'], 'REJECTED' => [], 'COMPLETED' => [], 'CANCELLED' => [],
    ];

    public function __construct(private ApiContext $context) {}

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store, private');
    }

    /** Actor as the source JWT user: tenant, selected school, student and parent profile. */
    private function actor(Request $request): object
    {
        $u = $request->user();
        abort_unless($u->organization_id, 403, 'Konteks organisasi diperlukan');
        return (object) ['userId' => $u->id, 'organizationId' => $u->organization_id,
            'schoolId' => $request->session()->get('selected_school_id', $u->school_id),
            'studentId' => DB::table('students')->where('user_id', $u->id)->value('id'),
            'parentId' => DB::table('parents')->where('user_id', $u->id)->where('is_active', true)->value('id'),
            'roles' => $this->context->roles($request)];
    }

    private function parentRelation(string $studentId, ?string $parentId)
    {
        if (! $parentId) return false;
        return DB::table('student_parent_links')->where('student_id', $studentId)->where('parent_id', $parentId)
            ->where('verification_status', 'VERIFIED')->whereNull('revoked_at')->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))->exists();
    }

    private function adultThreshold(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subYears(18);
    }

    public function documents(Request $request)
    {
        $a = $this->actor($request);
        foreach (self::DEFAULT_DOCS as [$type, $title, $content]) {
            $version = $type === 'IDENTITY' ? '2026-08-27-identity-v1' : '2026-08-04';
            DB::table('consent_documents')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $a->organizationId, 'type' => $type,
                'version' => $version, 'title' => $title, 'content' => $content, 'effective_at' => $type === 'IDENTITY' ? '2026-08-27 00:00:00' : '2026-08-04 00:00:00', 'created_at' => now()]);
        }
        $rows = DB::table('consent_documents')->where('organization_id', $a->organizationId)->where('effective_at', '<=', now())->whereNull('retired_at')
            ->orderByRaw("CASE type WHEN 'PLATFORM' THEN 0 WHEN 'GUARDIAN' THEN 1 WHEN 'PUBLICATION' THEN 2 WHEN 'ANALYTICS' THEN 3 ELSE 4 END")->orderByDesc('effective_at')->get()
            ->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'version' => $d->version, 'title' => $d->title, 'content' => $d->content, 'effectiveAt' => $this->iso($d->effective_at)]);
        return $this->noStore(response()->json($rows));
    }

    public function subjects(Request $request)
    {
        $a = $this->actor($request);
        $cols = ['s.id', 's.full_name', 's.student_number'];
        $map = fn ($s) => ['id' => $s->id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number];
        if ($a->studentId) {
            $rows = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('s.id', $a->studentId)
                ->where('sc.organization_id', $a->organizationId)->get($cols)->map($map);
        } elseif ($a->parentId) {
            $rows = DB::table('student_parent_links as l')->join('students as s', 's.id', '=', 'l.student_id')->join('schools as sc', 'sc.id', '=', 's.school_id')
                ->where('l.parent_id', $a->parentId)->where('l.verification_status', 'VERIFIED')->whereNull('l.revoked_at')->where('l.effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('l.effective_until')->orWhere('l.effective_until', '>', now()))
                ->where('sc.organization_id', $a->organizationId)->get($cols)->map($map);
        } else {
            $rows = collect();
        }
        return $this->noStore(response()->json($rows->values()));
    }

    public function consents(Request $request)
    {
        return $this->myConsents($request);
    }

    public function myConsents(Request $request)
    {
        $a = $this->actor($request);
        $linked = $a->parentId ? DB::table('student_parent_links')->where('parent_id', $a->parentId)->where('verification_status', 'VERIFIED')
            ->whereNull('revoked_at')->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))->pluck('student_id') : collect();
        $rows = DB::table('consents as c')->join('consent_documents as d', 'd.id', '=', 'c.document_id')->leftJoin('students as s', 's.id', '=', 'c.student_id')
            ->where('c.organization_id', $a->organizationId)
            ->where(fn ($q) => $q->where('c.granted_by_id', $a->userId)->orWhere('c.subject_user_id', $a->userId)->orWhereIn('c.student_id', $linked))
            ->orderByDesc('c.created_at')
            ->get(['c.*', 'd.title as doc_title', 'd.version as doc_version', 's.full_name', 's.student_number'])
            ->map(fn ($c) => ['id' => $c->id, 'type' => $c->type, 'status' => $c->status, 'method' => $c->method, 'grantedAt' => $this->iso($c->granted_at),
                'effectiveAt' => $this->iso($c->effective_at), 'expiresAt' => $this->iso($c->expires_at), 'withdrawnAt' => $this->iso($c->withdrawn_at),
                'withdrawalReason' => $c->withdrawal_reason, 'document' => ['title' => $c->doc_title, 'version' => $c->doc_version],
                'student' => $c->student_id ? ['id' => $c->student_id, 'fullName' => $c->full_name, 'studentNumber' => $c->student_number] : null,
                'mayWithdraw' => $c->granted_by_id === $a->userId || $c->subject_user_id === $a->userId || ($c->student_id !== null && $c->student_id === $a->studentId)]);
        return $this->noStore(response()->json($rows->values()));
    }

    private function resolveSubject(object $a, ?string $requested): array
    {
        if (! $requested) return ['studentId' => null, 'subjectUserId' => $a->userId, 'schoolId' => $a->schoolId, 'birthDate' => null];
        $s = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('s.id', $requested)
            ->where('sc.organization_id', $a->organizationId)->first(['s.id', 's.user_id', 's.school_id', 's.birth_date']);
        abort_unless($s, 404, 'Subjek siswa tidak ditemukan');
        if ($a->studentId !== $s->id) {
            abort_unless($a->parentId, 403, 'Relasi wali terverifikasi diperlukan');
            abort_unless($this->parentRelation($s->id, $a->parentId), 403, 'Relasi wali tidak aktif atau belum terverifikasi');
        }
        return ['studentId' => $s->id, 'subjectUserId' => $s->user_id, 'schoolId' => $s->school_id, 'birthDate' => $s->birth_date];
    }

    public function grant(Request $request)
    {
        $a = $this->actor($request);
        $d = $request->validate(['documentId' => 'required|string|max:191', 'type' => 'required|in:PLATFORM,GUARDIAN,PUBLICATION,ANALYTICS,IDENTITY',
            'studentId' => 'nullable|string|max:191', 'method' => 'required|in:AUTHENTICATED_CHECKBOX']);
        abort_if($d['type'] === 'IDENTITY', 400, 'Persetujuan identitas hanya dicatat melalui alur pengajuan identitas');
        $doc = DB::table('consent_documents')->where('id', $d['documentId'])->where('organization_id', $a->organizationId)->where('type', $d['type'])
            ->where('effective_at', '<=', now())->whereNull('retired_at')->first();
        abort_unless($doc, 404, 'Dokumen persetujuan aktif tidak ditemukan');
        $subject = $this->resolveSubject($a, $d['studentId'] ?? $a->studentId);
        abort_if($d['type'] === 'GUARDIAN' && (! $a->parentId || empty($d['studentId'])), 403, 'Persetujuan wali hanya dapat diberikan orang tua/wali dengan relasi terverifikasi');
        if ($d['type'] === 'PUBLICATION' && $subject['studentId']) {
            $minor = ! $subject['birthDate'] || CarbonImmutable::parse($subject['birthDate'], 'UTC')->greaterThan($this->adultThreshold());
            abort_if($minor && ! $a->parentId, 403, 'Publikasi karya siswa di bawah 18 tahun memerlukan persetujuan wali terverifikasi');
        }
        abort_if(! $subject['studentId'] && ! $subject['subjectUserId'], 400, 'Subjek persetujuan tidak ditemukan');
        $id = (string) Str::ulid();
        DB::transaction(function () use ($a, $d, $doc, $subject, $id): void {
            DB::table('consents')->where('organization_id', $a->organizationId)->where('type', $d['type'])->where('status', 'ACTIVE')
                ->where('student_id', $subject['studentId'])->where('subject_user_id', $subject['subjectUserId'])
                ->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now(), 'withdrawal_reason' => 'Digantikan versi terbaru', 'updated_at' => now()]);
            DB::table('consents')->insert(['id' => $id, 'organization_id' => $a->organizationId, 'school_id' => $subject['schoolId'], 'student_id' => $subject['studentId'],
                'subject_user_id' => $subject['subjectUserId'], 'granted_by_id' => $a->userId, 'document_id' => $doc->id, 'type' => $d['type'],
                'method' => 'AUTHENTICATED_CHECKBOX', 'evidence' => json_encode(['authenticated' => true]), 'granted_at' => now(), 'effective_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
        });
        $c = DB::table('consents')->where('id', $id)->first();
        return $this->noStore(response()->json(['id' => $c->id, 'type' => $c->type, 'status' => $c->status, 'grantedAt' => $this->iso($c->granted_at)], 201));
    }

    public function withdraw(Request $request, string $id)
    {
        $a = $this->actor($request);
        $d = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $reason = trim($d['reason']);
        abort_if($reason === '', 400, 'Alasan penarikan wajib diisi');
        $consent = DB::table('consents')->where('id', $id)->where('organization_id', $a->organizationId)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('granted_by_id', $a->userId)->orWhere('subject_user_id', $a->userId)
                ->when($a->studentId, fn ($q) => $q->orWhere('student_id', $a->studentId)))->first();
        abort_unless($consent, 404, 'Persetujuan aktif tidak ditemukan');
        DB::transaction(function () use ($a, $id, $reason, $consent): void {
            DB::table('consents')->where('id', $id)->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now(), 'withdrawal_reason' => $reason, 'updated_at' => now()]);
            DB::table('data_subject_requests')->insert(['id' => (string) Str::ulid(), 'organization_id' => $a->organizationId, 'school_id' => $consent->school_id,
                'student_id' => $consent->student_id, 'requester_id' => $a->userId, 'type' => 'CONSENT_WITHDRAWAL', 'status' => 'SUBMITTED',
                'details' => "Withdrawal recorded for consent {$consent->id}", 'due_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        });
        $c = DB::table('consents')->where('id', $id)->first();
        return $this->noStore(response()->json(['id' => $c->id, 'status' => $c->status, 'withdrawnAt' => $this->iso($c->withdrawn_at)]));
    }

    public function createRequest(Request $request)
    {
        $a = $this->actor($request);
        $d = $request->validate(['type' => 'required|in:ACCESS,CORRECTION,DELETION,RESTRICTION,EXPORT,CONSENT_WITHDRAWAL',
            'studentId' => 'nullable|string|max:191', 'details' => 'nullable|string|max:2000']);
        $subject = $this->resolveSubject($a, $d['studentId'] ?? $a->studentId);
        abort_if(DB::table('data_subject_requests')->where('organization_id', $a->organizationId)->where('requester_id', $a->userId)
            ->where('student_id', $subject['studentId'])->where('type', $d['type'])->whereIn('status', ['SUBMITTED', 'IN_REVIEW', 'APPROVED'])->exists(),
            409, 'Permintaan sejenis masih dalam proses');
        $id = (string) Str::ulid();
        DB::table('data_subject_requests')->insert(['id' => $id, 'organization_id' => $a->organizationId, 'school_id' => $subject['schoolId'], 'student_id' => $subject['studentId'],
            'requester_id' => $a->userId, 'type' => $d['type'], 'status' => 'SUBMITTED', 'details' => $d['details'] ?? null, 'due_at' => now()->addDays(30),
            'created_at' => now(), 'updated_at' => now()]);
        $r = DB::table('data_subject_requests')->where('id', $id)->first();
        return $this->noStore(response()->json(['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'dueAt' => $this->iso($r->due_at), 'createdAt' => $this->iso($r->created_at)], 201));
    }

    public function myRequests(Request $request)
    {
        $a = $this->actor($request);
        $rows = DB::table('data_subject_requests')->where('organization_id', $a->organizationId)->where('requester_id', $a->userId)->orderByDesc('created_at')->get()
            ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'details' => $r->details, 'resolution' => $r->resolution,
                'dueAt' => $this->iso($r->due_at), 'completedAt' => $this->iso($r->completed_at), 'createdAt' => $this->iso($r->created_at)]);
        return $this->noStore(response()->json($rows->values()));
    }

    /** Needs the privacy:manage permission and an administrator role. */
    private function admin(Request $request): object
    {
        $a = $this->actor($request);
        abort_unless(collect($this->context->permissions($request))->contains(fn ($p) => $p['resource'] === 'privacy' && $p['action'] === 'manage'),
            403, 'Anda tidak memiliki izin untuk tindakan ini');
        abort_unless(array_intersect($a->roles, ['super-admin', 'school-admin']), 403, 'Akses pengelolaan privasi hanya untuk administrator');
        return $a;
    }

    public function requests(Request $request)
    {
        $a = $this->admin($request);
        $rows = DB::table('data_subject_requests as r')->join('users as u', 'u.id', '=', 'r.requester_id')->leftJoin('students as s', 's.id', '=', 'r.student_id')
            ->where('r.organization_id', $a->organizationId)->when($a->schoolId, fn ($q) => $q->where('r.school_id', $a->schoolId))
            ->orderBy('r.created_at')->limit(100)
            ->get(['r.*', 'u.email', 'u.first_name', 'u.last_name', 'u.name', 's.full_name', 's.student_number'])
            ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'details' => $r->details, 'dueAt' => $this->iso($r->due_at),
                'createdAt' => $this->iso($r->created_at),
                'requester' => ['id' => $r->requester_id, 'email' => $r->email, 'firstName' => $r->first_name ?? explode(' ', $r->name, 2)[0], 'lastName' => $r->last_name ?? (explode(' ', $r->name, 2)[1] ?? '')],
                'student' => $r->student_id ? ['id' => $r->student_id, 'fullName' => $r->full_name, 'studentNumber' => $r->student_number] : null]);
        return $this->noStore(response()->json($rows->values()));
    }

    public function updateRequest(Request $request, string $id)
    {
        $a = $this->admin($request);
        $d = $request->validate(['status' => 'required|in:SUBMITTED,IN_REVIEW,APPROVED,REJECTED,COMPLETED,CANCELLED', 'resolution' => 'nullable|string|max:4000']);
        $r = DB::table('data_subject_requests')->where('id', $id)->where('organization_id', $a->organizationId)
            ->when($a->schoolId, fn ($q) => $q->where('school_id', $a->schoolId))->first();
        abort_unless($r, 404, 'Permintaan hak data tidak ditemukan');
        abort_unless(in_array($d['status'], self::TRANSITIONS[$r->status], true), 400, "Transisi status {$r->status} ke {$d['status']} tidak valid");
        abort_if(in_array($d['status'], ['REJECTED', 'COMPLETED'], true) && trim($d['resolution'] ?? '') === '', 400, 'Resolusi wajib diisi untuk status akhir');
        DB::table('data_subject_requests')->where('id', $id)->update(['status' => $d['status'], 'resolution' => $d['resolution'] ?? null, 'handled_by_id' => $a->userId,
            'completed_at' => in_array($d['status'], ['REJECTED', 'COMPLETED', 'CANCELLED'], true) ? now() : null, 'updated_at' => now()]);
        $u = DB::table('data_subject_requests')->where('id', $id)->first();
        return $this->noStore(response()->json(['id' => $u->id, 'status' => $u->status, 'resolution' => $u->resolution, 'completedAt' => $this->iso($u->completed_at)]));
    }
}
