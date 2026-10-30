<?php

namespace App\Http\Controllers;

use App\Jobs\ScanIdentityDocument;
use App\Support\FileInspector;
use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Identity documents; mirrors apps/api/src/identity. Presigned object-storage URLs are
 * replaced by Laravel signed URLs, and the antivirus scan by FileInspector (see job).
 */
class IdentityApiController extends Controller
{
    private const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];
    private const MAX = 8 * 1024 * 1024;
    private const CONSENT_VERSION = '2026-08-27-identity-v1';

    public function __construct(private ApiContext $context) {}

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function private($response)
    {
        return $response->header('Cache-Control', 'no-store, private');
    }

    private function blindIndex(string $value, string $type): string
    {
        $key = env('BLIND_INDEX_KEY') ?: (env('ENCRYPTION_KEY') ?: config('app.key'));
        return hash_hmac('sha256', $type.':'.strtoupper(preg_replace('/\s+/', '', $value)), $key);
    }

    private function audit(Request $request, array $c, string $action, string $id, array $details): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'actor_id' => $c['user'],
            'action' => $action, 'resource' => 'identity_document', 'resource_id' => $id, 'details' => json_encode($details),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now()]);
    }

    private function student(array $c)
    {
        abort_unless($c['student'], 403, 'Hanya siswa aktif dalam konteks sekolah yang dapat mengunggah identitas');
        return DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('s.id', $c['student']->id)->where('s.user_id', $c['user'])
            ->where('s.school_id', $c['school'])->where('sc.organization_id', $c['organization'])->where('sc.is_active', true)->first(['s.id', 's.birth_date'])
            ?? abort(403, 'Konteks siswa tidak valid');
    }

    private function masked($identifiers)
    {
        return $identifiers->map(fn ($i) => ['type' => $i->type, 'last4' => $i->last4, 'status' => $i->status, 'masked' => '******'.$i->last4])->values()->all();
    }

    public function me(Request $request)
    {
        $c = $this->context->resolve($request, 'identity', 'create');
        abort_unless($c['student'], 403, 'Hanya siswa yang memiliki status identitas');
        $d = DB::table('identity_documents')->where('student_id', $c['student']->id)->orderByDesc('created_at')->first();
        $ids = DB::table('student_identifiers')->where('student_id', $c['student']->id)->get(['type', 'last4', 'status']);
        return $this->private(response()->json(['document' => $d ? ['id' => $d->id, 'documentType' => $d->document_type, 'documentStatus' => $d->document_status,
            'verificationStatus' => $d->verification_status, 'verificationNotes' => $d->verification_notes, 'scanStatus' => $d->scan_status,
            'createdAt' => $this->iso($d->created_at), 'verifiedAt' => $this->iso($d->verified_at)] : null, 'identifiers' => $this->masked($ids)]));
    }

    public function uploadUrl(Request $request)
    {
        $c = $this->context->resolve($request, 'identity', 'create');
        $this->student($c);
        $d = $request->validate(['fileName' => 'required|string|max:255', 'mimeType' => 'required|string|max:100', 'sizeBytes' => 'required|integer|min:1|max:'.self::MAX]);
        $ext = FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        $key = "{$c['organization']}/{$c['school']}/{$c['student']->id}/".Str::uuid().$ext;
        $url = URL::temporarySignedRoute('identity.upload', now()->addSeconds(300), ['key' => $key, 'mime' => $d['mimeType'], 'size' => $d['sizeBytes']]);
        return $this->private(response()->json(['storageKey' => $key, 'uploadUrl' => $url, 'expiresIn' => 300]));
    }

    /** Target of the signed upload URL; the signature stands in for the presigned PUT. */
    public function upload(Request $request)
    {
        $key = (string) $request->query('key');
        abort_if(! preg_match('#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[0-9a-f-]{36}\.[a-z0-9]+$#', $key), 400, 'Storage key tidak valid');
        $mime = (string) $request->query('mime');
        $body = $request->getContent();
        abort_unless(strtolower((string) $request->header('Content-Type')) === $mime && strlen($body) === (int) $request->query('size'), 400, 'Metadata file tidak sesuai dengan objek yang diunggah');
        $disk = Storage::disk('identity_quarantine');
        abort_if($disk->exists($key), 409, 'Objek sudah diunggah');
        $disk->put($key, $body);
        $disk->put($key.'.meta', json_encode(['mime' => $mime, 'size' => strlen($body), 'at' => now()->timestamp]));
        return response()->noContent();
    }

    private function consentDocument(string $org): string
    {
        DB::table('consent_documents')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $org, 'type' => 'IDENTITY', 'version' => self::CONSENT_VERSION,
            'title' => 'Persetujuan pemrosesan identitas', 'content' => 'Data identitas digunakan terbatas untuk verifikasi kepesertaan dan dilindungi sesuai kebijakan privasi platform.',
            'effective_at' => '2026-08-27 00:00:00', 'created_at' => now()]);
        return DB::table('consent_documents')->where('organization_id', $org)->where('type', 'IDENTITY')->where('version', self::CONSENT_VERSION)->value('id');
    }

    public function submit(Request $request)
    {
        $c = $this->context->resolve($request, 'identity', 'create');
        $d = $request->validate(['fileName' => 'required|string|max:255', 'mimeType' => 'required|string|max:100', 'sizeBytes' => 'required|integer|min:1|max:'.self::MAX,
            'nik' => ['required', 'regex:/^\d{16}$/D'], 'nisn' => ['nullable', 'regex:/^\d{10}$/D'], 'type' => 'required|in:KTP,KIA,KK,KARTU_PELAJAR',
            'storageKey' => 'required|string|max:700', 'consent' => 'required|boolean']);
        abort_unless($d['consent'], 400, 'Persetujuan pemrosesan identitas wajib diberikan');
        $student = $this->student($c);
        abort_if(DB::table('identity_documents')->where('student_id', $student->id)->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('document_status', 'PENDING_VERIFICATION')->whereIn('scan_status', ['PENDING', 'CLEAN']))
            ->orWhere('document_status', 'VALID'))->exists(), 409, 'Masih ada dokumen identitas aktif atau menunggu verifikasi');
        $minor = ! $student->birth_date || CarbonImmutable::parse($student->birth_date, 'UTC')->greaterThan(CarbonImmutable::now('UTC')->subYears(18));
        abort_if($minor && ! DB::table('consents')->where('organization_id', $c['organization'])->where('student_id', $student->id)->where('type', 'GUARDIAN')
            ->where('status', 'ACTIVE')->where('effective_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists(),
            403, 'Persetujuan wali aktif diperlukan untuk siswa di bawah 18 tahun');
        $prefix = "{$c['organization']}/{$c['school']}/{$student->id}/";
        abort_unless(str_starts_with($d['storageKey'], $prefix) && ! str_contains($d['storageKey'], '..'), 403, 'Storage key tidak berada dalam scope pengguna');
        $q = Storage::disk('identity_quarantine');
        $meta = $q->exists($d['storageKey']) && $q->exists($d['storageKey'].'.meta') ? json_decode($q->get($d['storageKey'].'.meta'), true) : null;
        abort_unless($meta, 400, 'File belum berhasil diunggah atau sudah kedaluwarsa');
        FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        abort_unless((int) $meta['size'] === (int) $d['sizeBytes'] && $meta['mime'] === $d['mimeType'], 400, 'Metadata file tidak sesuai dengan objek yang diunggah');

        $docId = (string) Str::ulid();
        $consentDoc = $this->consentDocument($c['organization']);
        try {
            DB::transaction(function () use ($c, $d, $student, $docId, $consentDoc): void {
                foreach (array_filter(['NIK' => $d['nik'], 'NISN' => $d['nisn'] ?? null]) as $type => $value) {
                    $values = ['value_encrypted' => Crypt::encryptString($value), 'blind_index' => $this->blindIndex($value, $type), 'last4' => substr($value, -4), 'status' => 'NEEDS_REVIEW', 'updated_at' => now()];
                    $row = DB::table('student_identifiers')->where('student_id', $student->id)->where('type', $type);
                    $row->exists() ? $row->update($values) : DB::table('student_identifiers')->insert($values + ['id' => (string) Str::ulid(), 'student_id' => $student->id, 'type' => $type, 'created_at' => now()]);
                }
                DB::table('consents')->where('student_id', $student->id)->where('type', 'IDENTITY')->where('status', 'ACTIVE')
                    ->update(['status' => 'WITHDRAWN', 'withdrawn_at' => now(), 'withdrawal_reason' => 'Digantikan versi persetujuan terbaru', 'updated_at' => now()]);
                $consentId = (string) Str::ulid();
                DB::table('consents')->insert(['id' => $consentId, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'student_id' => $student->id,
                    'subject_user_id' => $c['user'], 'granted_by_id' => $c['user'], 'document_id' => $consentDoc, 'type' => 'IDENTITY', 'method' => 'AUTHENTICATED_CHECKBOX',
                    'evidence' => json_encode(['purpose' => 'IDENTITY_VERIFICATION']), 'granted_at' => now(), 'effective_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('identity_documents')->insert(['id' => $docId, 'student_id' => $student->id, 'uploaded_by_id' => $c['user'], 'document_type' => $d['type'],
                    'quarantine_storage_key' => $d['storageKey'], 'original_file_name' => mb_substr($d['fileName'], 0, 255), 'mime_type' => $d['mimeType'], 'size_bytes' => $d['sizeBytes'],
                    'scan_status' => 'PENDING', 'document_status' => 'PENDING_VERIFICATION', 'verification_status' => 'NEEDS_REVIEW', 'consent_id' => $consentId,
                    'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'NIK atau NISN sudah terdaftar pada siswa lain');
        }
        $this->audit($request, $c, 'SUBMIT_IDENTITY_DOCUMENT', $docId, ['documentType' => $d['type'], 'consent' => true]);
        ScanIdentityDocument::dispatch($docId)->afterCommit();
        return $this->private(response()->json(['id' => $docId, 'message' => 'Dokumen masuk karantina dan sedang dipindai'], 201));
    }

    private function scoped(array $c)
    {
        return DB::table('identity_documents as d')->join('students as s', 's.id', '=', 'd.student_id')->join('schools as sc', 'sc.id', '=', 's.school_id')
            ->where('d.scan_status', 'CLEAN')->where('d.document_status', 'PENDING_VERIFICATION')->where('s.school_id', $c['school'])->where('sc.organization_id', $c['organization']);
    }

    public function pending(Request $request)
    {
        $c = $this->context->resolve($request, 'identity', 'verify');
        $docs = $this->scoped($c)->orderBy('d.created_at')->get(['d.*', 's.full_name', 's.student_number', 'sc.name as school_name']);
        $ids = DB::table('student_identifiers')->whereIn('student_id', $docs->pluck('student_id'))->get(['student_id', 'type', 'last4', 'status'])->groupBy('student_id');
        return $this->private(response()->json($docs->map(fn ($d) => ['id' => $d->id, 'documentType' => $d->document_type, 'documentStatus' => $d->document_status,
            'verificationStatus' => $d->verification_status, 'scanStatus' => $d->scan_status, 'createdAt' => $this->iso($d->created_at),
            'student' => ['fullName' => $d->full_name, 'studentNumber' => $d->student_number, 'school' => ['name' => $d->school_name],
                'identifiers' => $this->masked($ids->get($d->student_id, collect()))]])->values()));
    }

    public function access(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'identity.document', 'read');
        $doc = $this->scoped($c)->where('d.id', $id)->first(['d.clean_storage_key']);
        abort_unless($doc?->clean_storage_key, 404, 'Dokumen bersih tidak ditemukan');
        $url = URL::temporarySignedRoute('identity.download', now()->addSeconds(90), ['doc' => $id, 'u' => $c['user']]);
        $this->audit($request, $c, 'ACCESS_IDENTITY_DOCUMENT', $id, ['expiresIn' => 90]);
        return $this->private(response()->json(['url' => $url, 'expiresIn' => 90]));
    }

    /** Signed, short-lived, and bound to the requesting user and tenant scope. */
    public function download(Request $request)
    {
        $c = $this->context->resolve($request, 'identity.document', 'read');
        abort_unless($request->query('u') === $c['user'], 403, 'Tautan tidak berlaku untuk pengguna ini');
        $doc = $this->scoped($c)->where('d.id', $request->query('doc'))->first(['d.clean_storage_key', 'd.mime_type', 'd.original_file_name']);
        abort_unless($doc?->clean_storage_key, 404, 'Dokumen bersih tidak ditemukan');
        return response(Storage::disk('identity_private')->get($doc->clean_storage_key), 200, ['Content-Type' => $doc->mime_type, 'Content-Disposition' => 'attachment',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    public function verify(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'identity', 'verify');
        $d = $request->validate(['status' => 'required|string|max:64', 'reasonCode' => 'nullable|in:DOCUMENT_MISMATCH,FILE_UNREADABLE,DOCUMENT_EXPIRED,OTHER', 'notes' => 'nullable|string|max:1000']);
        abort_unless(in_array($d['status'], ['VALID', 'REJECTED', 'NEEDS_CORRECTION'], true), 400, 'Keputusan verifikasi tidak valid');
        abort_if($d['status'] !== 'VALID' && (empty($d['reasonCode']) || trim($d['notes'] ?? '') === ''), 400, 'Kode alasan dan catatan wajib untuk penolakan/perbaikan');
        $doc = $this->scoped($c)->where('d.id', $id)->first(['d.id', 'd.student_id']);
        abort_unless($doc, 404, 'Dokumen identitas tidak ditemukan');
        $vs = $d['status'] === 'VALID' ? 'VERIFIED' : ($d['status'] === 'REJECTED' ? 'REJECTED' : 'NEEDS_REVIEW');
        DB::transaction(function () use ($id, $doc, $d, $vs, $c): void {
            DB::table('identity_reviews')->insert(['id' => (string) Str::ulid(), 'document_id' => $id, 'reviewer_id' => $c['user'], 'decision' => $vs,
                'reason_code' => $d['reasonCode'] ?? null, 'notes' => $d['notes'] ?? null, 'created_at' => now()]);
            DB::table('student_identifiers')->where('student_id', $doc->student_id)->update(['status' => $vs, 'verified_at' => $d['status'] === 'VALID' ? now() : null, 'updated_at' => now()]);
            DB::table('identity_documents')->where('id', $id)->update(['document_status' => $d['status'], 'verification_status' => $vs, 'verification_notes' => $d['notes'] ?? null,
                'verified_by' => $c['user'], 'verified_at' => now(), 'updated_at' => now()]);
        });
        $this->audit($request, $c, 'VERIFY_IDENTITY_DOCUMENT', $id, ['status' => $d['status'], 'reasonCode' => $d['reasonCode'] ?? null]);
        $u = DB::table('identity_documents')->where('id', $id)->first();
        return $this->private(response()->json(['message' => "Status dokumen diperbarui menjadi {$d['status']}",
            'document' => ['id' => $u->id, 'documentStatus' => $u->document_status, 'verificationStatus' => $u->verification_status, 'verifiedAt' => $this->iso($u->verified_at)]]));
    }
}
