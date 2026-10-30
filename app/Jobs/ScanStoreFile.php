<?php

namespace App\Jobs;

use App\Support\FileInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanStoreFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $fileId) {}

    public function handle(): void
    {
        $file = DB::table('student_store_files')->where('id', $this->fileId)->first();
        if (! $file || $file->scan_status !== 'PENDING' || ! $file->quarantine_key) return;
        $q = Storage::disk('store_quarantine');
        try {
            $bytes = $q->exists($file->quarantine_key) ? $q->get($file->quarantine_key) : null;
            $check = $bytes === null ? ['clean' => false, 'reason' => 'EMPTY_FILE']
                : FileInspector::inspect($bytes, $file->mime_type, 20 * 1024 * 1024);
            if (! $check['clean']) {
                $q->delete([$file->quarantine_key, $file->quarantine_key.'.meta']);
                DB::table('student_store_files')->where('id', $file->id)->update(['scan_status' => 'INFECTED',
                    'quarantine_key' => null]);
                return;
            }
            $submission = DB::table('student_store_submissions')->where('id', $file->store_submission_id)->first();
            $sha = hash('sha256', $bytes);
            $duplicate = DB::table('student_store_files as f')
                ->join('student_store_submissions as ss', 'ss.id', '=', 'f.store_submission_id')
                ->where('f.id', '!=', $file->id)->where('f.store_submission_id', '!=', $file->store_submission_id)
                ->where('f.sha256', $sha)->where('f.scan_status', 'CLEAN')->where('ss.school_id', $submission->school_id)
                ->select('f.id', 'f.store_submission_id')->first();
            if ($duplicate && ! DB::table('store_fraud_flags')->where('store_submission_id', $file->store_submission_id)
                ->where('type', 'DUPLICATE_FILE')->where('status', 'OPEN')->exists())
                DB::table('store_fraud_flags')->insert(['id' => (string) Str::ulid(),
                    'store_submission_id' => $file->store_submission_id, 'type' => 'DUPLICATE_FILE',
                    'reason' => 'Bukti file identik dengan submission siswa lain dan memerlukan pemeriksaan manusia',
                    'evidence' => json_encode(['matchedFileId' => $duplicate->id,
                        'matchedSubmissionId' => $duplicate->store_submission_id]),
                    'confidence' => .9, 'status' => 'OPEN', 'created_at' => now()]);
            Storage::disk('store_private')->put($file->storage_key, $bytes);
            $q->delete([$file->quarantine_key, $file->quarantine_key.'.meta']);
            DB::transaction(function () use ($file, $sha, $bytes, $submission) {
                DB::table('student_store_files')->where('id', $file->id)->update([
                    'scan_status' => 'CLEAN', 'sha256' => $sha, 'quarantine_key' => null,
                    'size_bytes' => strlen($bytes)]);
                if ($submission->student_store_id && in_array($file->kind, ['LOGO', 'BANNER'], true))
                    DB::table('student_stores')->where('id', $submission->student_store_id)->update([
                        strtolower($file->kind).'_storage_key' => $file->storage_key, 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            DB::table('student_store_files')->where('id', $this->fileId)->update(['scan_status' => 'FAILED']);
            throw $e;
        }
    }
}
