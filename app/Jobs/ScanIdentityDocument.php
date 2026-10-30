<?php

namespace App\Jobs;

use App\Support\FileInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Moves an uploaded identity file from quarantine to the private disk once it passes inspection. */
class ScanIdentityDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $documentId) {}

    private function reject(object $doc, string $reason): void
    {
        $quarantine = Storage::disk('identity_quarantine');
        $quarantine->delete([$doc->quarantine_storage_key, $doc->quarantine_storage_key.'.meta']);
        DB::table('identity_documents')->where('id', $doc->id)->update(['scan_status' => 'INFECTED', 'scan_completed_at' => now(), 'document_status' => 'REJECTED',
            'verification_status' => 'REJECTED', 'verification_notes' => $reason, 'quarantine_storage_key' => null, 'updated_at' => now()]);
    }

    public function handle(): void
    {
        try {
            $this->scan();
        } catch (\Throwable $e) {
            DB::table('identity_documents')->where('id', $this->documentId)->update(['scan_status' => 'FAILED', 'scan_completed_at' => now(), 'verification_notes' => 'FILE_SCAN_FAILED', 'updated_at' => now()]);
            throw $e;
        }
    }

    private function scan(): void
    {
        $doc = DB::table('identity_documents')->where('id', $this->documentId)->first();
        if (! $doc || ! $doc->quarantine_storage_key) return;
        $quarantine = Storage::disk('identity_quarantine');
        $key = $doc->quarantine_storage_key;
        $bytes = $quarantine->exists($key) ? $quarantine->get($key) : null;
        if ($bytes === null) { $this->reject($doc, 'EMPTY_FILE'); return; }
        $check = FileInspector::inspect($bytes, (string) $doc->mime_type, 8 * 1024 * 1024);
        if (! $check['clean']) { $this->reject($doc, $check['reason'] ?? 'FILE_SECURITY_REJECTED'); return; }

        $clean = $bytes;
        // Re-encoding drops EXIF and embedded payloads; skipped when GD is unavailable.
        if (in_array($doc->mime_type, ['image/jpeg', 'image/png'], true) && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($bytes);
            if ($img === false) { $this->reject($doc, 'IMAGE_SANITIZATION_FAILED'); return; }
            ob_start();
            $doc->mime_type === 'image/jpeg' ? imagejpeg($img, null, 90) : imagepng($img, null, 9);
            $clean = (string) ob_get_clean();
            imagedestroy($img);
            if (! FileInspector::inspect($clean, $doc->mime_type, 8 * 1024 * 1024)['clean']) { $this->reject($doc, 'IMAGE_SANITIZATION_FAILED'); return; }
        }
        Storage::disk('identity_private')->put($key, $clean);
        $quarantine->delete([$key, $key.'.meta']);
        DB::table('identity_documents')->where('id', $doc->id)->update(['scan_status' => 'CLEAN', 'scan_completed_at' => now(), 'sha256' => hash('sha256', $clean),
            'clean_storage_key' => $key, 'document_storage_key' => $key, 'quarantine_storage_key' => null, 'updated_at' => now()]);
    }
}
