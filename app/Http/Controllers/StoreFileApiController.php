<?php

namespace App\Http\Controllers;

use App\Jobs\ScanStoreFile;
use App\Support\FileInspector;
use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StoreFileApiController extends Controller
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];
    private const MAX = 20 * 1024 * 1024;

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'submissions', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'student_store.submit'), 403);
        $c['plan'] = $plan;
        return $c;
    }

    private function assignment(object $r, array $c, bool $submit): void
    {
        $a = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('a.id', $r->assignment_id)->where('a.type', 'STORE')
            ->where('course.organization_id', $c['organization'])->where('course.school_id', $c['school'])
            ->select('a.*')->first();
        abort_unless($a, 404, 'Tugas toko tidak ditemukan');
        if ($c['teacher']) abort_unless($a->teacher_id === $c['teacher']->id, 404);
        if ($c['student']) abort_unless($a->is_active && $a->published_at && $a->published_at <= now()
            && $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $c['plan'])->contains($a->module_id)
            && DB::table('course_enrollments')->where('course_id', $a->course_id)
                ->where('student_id', $c['student']->id)->where('school_id', $c['school'])
                ->where('status', 'ACTIVE')->exists(), 403, 'Tugas toko tidak dapat diakses');
    }

    private function editable(array $c, string $id): object
    {
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        $r = DB::table('student_store_submissions')->where('id', $id)->where('student_id', $c['student']->id)
            ->where('organization_id', $c['organization'])->where('school_id', $c['school'])
            ->whereIn('status', ['DRAFT', 'REVISION_REQUIRED'])->first();
        abort_unless($r, 403, 'Submission tidak dapat diedit oleh siswa ini');
        $this->assignment($r, $c, true);
        return $r;
    }

    public function prepare(Request $request, string $id)
    {
        $c = $this->scope($request, 'create');
        $this->editable($c, $id);
        $d = $request->validate(['fileName' => 'required|string|max:255', 'mimeType' => 'required|string',
            'sizeBytes' => 'required|integer|min:1|max:'.self::MAX,
            'kind' => 'required|in:LOGO,BANNER,PRODUCT,EVIDENCE,SCREEN_RECORDING,OTHER']);
        $ext = FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        $key = $c['organization'].'/'.$c['school'].'/'.$c['student']->id.'/store/'.$id.'/'.Str::uuid().$ext;
        return response()->json(['storageKey' => $key,
            'uploadUrl' => URL::temporarySignedRoute('store-file.upload', now()->addSeconds(300),
                ['key' => $key, 'mime' => $d['mimeType'], 'size' => $d['sizeBytes']]), 'expiresIn' => 300]);
    }

    public function upload(Request $request)
    {
        $key = (string) $request->query('key');
        abort_unless(preg_match('#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/store/[A-Za-z0-9_-]+/[0-9a-f-]{36}\.[a-z0-9]+$#', $key), 400);
        $mime = (string) $request->query('mime');
        $bytes = $request->getContent();
        abort_unless(in_array($mime, self::MIMES, true)
            && strtolower((string) $request->header('Content-Type')) === $mime
            && strlen($bytes) === (int) $request->query('size'), 400, 'Metadata file tidak sesuai');
        $disk = Storage::disk('store_quarantine');
        abort_if($disk->exists($key), 409, 'Objek sudah diunggah');
        $disk->put($key, $bytes);
        $disk->put($key.'.meta', json_encode(['mime' => $mime, 'size' => strlen($bytes)]));
        return response()->noContent();
    }

    public function register(Request $request, string $id)
    {
        $c = $this->scope($request, 'create');
        $this->editable($c, $id);
        $d = $request->validate(['storageKey' => 'required|string|max:800', 'fileName' => 'required|string|max:255',
            'mimeType' => 'required|string', 'sizeBytes' => 'required|integer|min:1|max:'.self::MAX,
            'kind' => 'required|in:LOGO,BANNER,PRODUCT,EVIDENCE,SCREEN_RECORDING,OTHER',
            'caption' => 'sometimes|nullable|string|max:500', 'position' => 'sometimes|integer|min:0']);
        abort_unless(str_starts_with($d['storageKey'], $c['organization'].'/'.$c['school'].'/'.$c['student']->id.'/store/'.$id.'/'),
            403, 'Storage key file berada di luar scope submission');
        $ext = FileInspector::validateMetadata($d['fileName'], $d['mimeType'], $d['sizeBytes'], self::MIMES, self::MAX);
        abort_unless(str_ends_with($d['storageKey'], $ext), 400, 'Ekstensi storage key tidak sesuai');
        $q = Storage::disk('store_quarantine');
        abort_unless($q->exists($d['storageKey']) && $q->exists($d['storageKey'].'.meta'), 400,
            'File belum tersedia dalam karantina');
        $meta = json_decode($q->get($d['storageKey'].'.meta'), true);
        abort_unless($meta && $meta['mime'] === $d['mimeType'] && (int) $meta['size'] === $d['sizeBytes']
            && $q->size($d['storageKey']) === $d['sizeBytes'], 400,
            'Metadata file tidak sesuai dengan objek yang diunggah');
        abort_if(DB::table('student_store_files')->where('quarantine_key', $d['storageKey'])->exists(), 409,
            'File sudah terdaftar');
        $fileId = (string) Str::ulid();
        DB::table('student_store_files')->insert(['id' => $fileId, 'store_submission_id' => $id,
            'kind' => $d['kind'], 'storage_key' => $d['storageKey'], 'quarantine_key' => $d['storageKey'],
            'original_file_name' => $d['fileName'], 'mime_type' => $d['mimeType'], 'size_bytes' => $d['sizeBytes'],
            'scan_status' => 'PENDING', 'caption' => trim($d['caption'] ?? '') ?: null,
            'position' => $d['position'] ?? 0, 'created_at' => now()]);
        ScanStoreFile::dispatch($fileId)->afterResponse();
        return response()->json(['id' => $fileId, 'scanStatus' => 'PENDING'], 201);
    }

    private function file(array $c, string $id): object
    {
        $r = DB::table('student_store_files as f')->join('student_store_submissions as ss', 'ss.id', '=', 'f.store_submission_id')
            ->where('f.id', $id)->where('ss.organization_id', $c['organization'])
            ->where('ss.school_id', $c['school'])->select('f.*', 'ss.student_id', 'ss.assignment_id',
                'ss.status as submission_status', 'ss.student_store_id')->first();
        abort_unless($r, 404, 'File tidak ditemukan');
        if ($c['student']) abort_unless($r->student_id === $c['student']->id, 404);
        $this->assignment($r, $c, false);
        return $r;
    }

    public function accessFile(Request $request, string $id)
    {
        $c = $this->scope($request, 'read');
        $r = $this->file($c, $id);
        abort_unless($r->scan_status === 'CLEAN' && Storage::disk('store_private')->exists($r->storage_key), 404,
            'File bersih tidak ditemukan');
        return response()->json(['url' => URL::temporarySignedRoute('store-file.download', now()->addSeconds(120),
            ['id' => $id]), 'expiresIn' => 120]);
    }

    public function download(Request $request, string $id)
    {
        $c = $this->scope($request, 'read');
        $r = $this->file($c, $id);
        abort_unless($r->scan_status === 'CLEAN' && Storage::disk('store_private')->exists($r->storage_key), 404);
        return Storage::disk('store_private')->download($r->storage_key, $r->original_file_name);
    }

    public function delete(Request $request, string $id)
    {
        $c = $this->scope($request, 'update');
        abort_unless($c['student'], 403, 'Profil siswa diperlukan');
        $r = $this->file($c, $id);
        abort_unless(in_array($r->submission_status, ['DRAFT', 'REVISION_REQUIRED'], true), 403,
            'File hanya dapat dihapus saat submission dapat diedit');
        Storage::disk($r->quarantine_key ? 'store_quarantine' : 'store_private')->delete(
            $r->quarantine_key ?: $r->storage_key);
        if ($r->quarantine_key) Storage::disk('store_quarantine')->delete($r->quarantine_key.'.meta');
        DB::transaction(function () use ($r) {
            DB::table('student_store_files')->where('id', $r->id)->delete();
            if ($r->student_store_id && in_array($r->kind, ['LOGO', 'BANNER'], true))
                DB::table('student_stores')->where('id', $r->student_store_id)
                    ->where(strtolower($r->kind).'_storage_key', $r->storage_key)
                    ->update([strtolower($r->kind).'_storage_key' => null, 'updated_at' => now()]);
        });
        return response()->json(['deleted' => true]);
    }
}
