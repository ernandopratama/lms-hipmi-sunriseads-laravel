<?php

namespace App\Http\Controllers;

use App\Jobs\ScanHelpdeskAttachment;
use App\Support\FileInspector;
use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Helpdesk attachments; signed Laravel URLs stand in for presigned object-storage URLs. */
class HelpdeskAttachmentApiController extends Controller
{
    private const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX = 8 * 1024 * 1024;

    public function __construct(private ApiContext $context) {}

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function staff(array $c): bool
    {
        return (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal', 'support']);
    }

    private function ticket(array $c, string $id): object
    {
        $q = DB::table('helpdesk_tickets')->where('organization_id', $c['organization'])->where('id', $id);
        $this->staff($c) ? $q->where(fn ($q) => $q->where('school_id', $c['school'])->orWhereNull('school_id')) : $q->where('requester_id', $c['user']);
        return $q->first() ?? abort(404, 'Tiket tidak ditemukan');
    }

    private function prefix(array $c, object $t): string
    {
        return "{$c['organization']}/".($t->school_id ?: 'organization')."/helpdesk/{$t->id}/";
    }

    public function uploadUrl(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'create');
        $t = $this->ticket($c, $id);
        abort_if(in_array($t->status, ['RESOLVED', 'CLOSED'], true), 409, 'Tiket sudah ditutup');
        $d = $request->validate(['fileName' => 'required|string|max:255', 'mimeType' => 'required|string|max:100', 'sizeBytes' => 'required|integer|min:1|max:'.self::MAX]);
        $ext = FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        $key = $this->prefix($c, $t).Str::uuid().$ext;
        $url = URL::temporarySignedRoute('helpdesk.upload', now()->addSeconds(300), ['key' => $key, 'mime' => $d['mimeType'], 'size' => $d['sizeBytes']]);
        return response()->json(['storageKey' => $key, 'uploadUrl' => $url, 'expiresIn' => 300]);
    }

    /** Target of the signed upload URL. */
    public function upload(Request $request)
    {
        $key = (string) $request->query('key');
        abort_if(! preg_match('#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/helpdesk/[A-Za-z0-9_-]+/[0-9a-f-]{36}\.[a-z0-9]+$#', $key), 400, 'Lokasi lampiran tidak valid');
        $mime = (string) $request->query('mime');
        $body = $request->getContent();
        abort_unless(strtolower((string) $request->header('Content-Type')) === $mime && strlen($body) === (int) $request->query('size'), 400, 'Metadata lampiran tidak sesuai dengan file yang diunggah');
        $disk = Storage::disk('helpdesk_quarantine');
        abort_if($disk->exists($key), 409, 'Objek sudah diunggah');
        $disk->put($key, $body);
        $disk->put($key.'.meta', json_encode(['mime' => $mime, 'size' => strlen($body)]));
        return response()->noContent();
    }

    public function complete(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'create');
        $t = $this->ticket($c, $id);
        abort_if(in_array($t->status, ['RESOLVED', 'CLOSED'], true), 409, 'Tiket sudah ditutup');
        $d = $request->validate(['storageKey' => 'required|string|max:800', 'fileName' => 'required|string|max:255', 'mimeType' => 'required|string|max:100',
            'sizeBytes' => 'required|integer|min:1|max:'.self::MAX, 'body' => 'nullable|string|max:5000', 'isInternal' => 'sometimes|boolean']);
        abort_if(($d['isInternal'] ?? false) && ! $this->staff($c), 403, 'Lampiran internal hanya untuk petugas');
        $ext = FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        abort_if(! str_starts_with($d['storageKey'], $this->prefix($c, $t)) || str_contains($d['storageKey'], '..'), 403, 'Lokasi lampiran tidak valid');
        $q = Storage::disk('helpdesk_quarantine');
        $meta = $q->exists($d['storageKey']) && $q->exists($d['storageKey'].'.meta') ? json_decode($q->get($d['storageKey'].'.meta'), true) : null;
        abort_unless($meta, 404, 'File belum berhasil diunggah atau sudah kedaluwarsa');
        abort_unless((int) $meta['size'] === (int) $d['sizeBytes'] && $meta['mime'] === $d['mimeType'], 400, 'Metadata lampiran tidak sesuai dengan file yang diunggah');
        $body = trim($d['body'] ?? '') !== '' ? $d['body'] : 'Lampiran dikirim';
        $body = trim(preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]', preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu', '$1 [DIHAPUS]', $body)));
        $mid = (string) Str::ulid();
        DB::table('helpdesk_messages')->insert(['id' => $mid, 'ticket_id' => $t->id, 'author_id' => $c['user'], 'body' => $body, 'is_internal' => (bool) ($d['isInternal'] ?? false),
            'quarantine_storage_key' => $d['storageKey'], 'file_name' => mb_substr(trim($d['fileName']), 0, 255), 'mime_type' => $d['mimeType'], 'size_bytes' => $d['sizeBytes'],
            'scan_status' => 'PENDING', 'created_at' => now()]);
        // Read before dispatching: a synchronous queue would already have updated the scan status.
        $m = DB::table('helpdesk_messages')->where('id', $mid)->first();
        ScanHelpdeskAttachment::dispatch($mid)->afterCommit();
        return response()->json(['id' => $m->id, 'ticketId' => $m->ticket_id, 'authorId' => $m->author_id, 'body' => $m->body, 'isInternal' => (bool) $m->is_internal,
            'storageKey' => $m->storage_key, 'quarantineStorageKey' => $m->quarantine_storage_key, 'fileName' => $m->file_name, 'mimeType' => $m->mime_type,
            'sizeBytes' => $m->size_bytes, 'scanStatus' => $m->scan_status, 'scanCompletedAt' => $this->iso($m->scan_completed_at), 'createdAt' => $this->iso($m->created_at)], 201);
    }

    private function downloadable(array $c, string $messageId): object
    {
        $m = DB::table('helpdesk_messages')->where('id', $messageId)->first();
        abort_unless($m, 404, 'Lampiran tidak ditemukan');
        $this->ticket($c, $m->ticket_id);
        abort_if($m->is_internal && ! $this->staff($c), 403, 'Lampiran internal hanya untuk petugas');
        abort_if($m->scan_status !== 'CLEAN' || ! $m->storage_key, 409, 'Lampiran masih diperiksa atau tidak aman');
        return $m;
    }

    public function link(Request $request, string $messageId)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'read');
        $this->downloadable($c, $messageId);
        return response()->json(['url' => URL::temporarySignedRoute('helpdesk.download', now()->addSeconds(120), ['message' => $messageId, 'u' => $c['user']]), 'expiresInSeconds' => 120]);
    }

    public function download(Request $request)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'read');
        abort_unless($request->query('u') === $c['user'], 403, 'Tautan tidak berlaku untuk pengguna ini');
        $m = $this->downloadable($c, (string) $request->query('message'));
        return response(Storage::disk('helpdesk_private')->get($m->storage_key), 200, ['Content-Type' => $m->mime_type, 'Content-Disposition' => 'attachment',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }
}
