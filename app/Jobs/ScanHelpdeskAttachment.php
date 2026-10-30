<?php

namespace App\Jobs;

use App\Support\FileInspector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Moves a helpdesk attachment out of quarantine once its signature and content pass inspection. */
class ScanHelpdeskAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $messageId) {}

    public function handle(): void
    {
        $m = DB::table('helpdesk_messages')->where('id', $this->messageId)->first();
        if (! $m || ! $m->quarantine_storage_key) return;
        $q = Storage::disk('helpdesk_quarantine');
        $key = $m->quarantine_storage_key;
        try {
            $bytes = $q->exists($key) ? $q->get($key) : '';
            if (! FileInspector::inspect($bytes, (string) $m->mime_type, 8 * 1024 * 1024)['clean']) {
                $q->delete([$key, $key.'.meta']);
                DB::table('helpdesk_messages')->where('id', $m->id)->update(['scan_status' => 'INFECTED', 'scan_completed_at' => now(), 'quarantine_storage_key' => null]);
                return;
            }
            Storage::disk('helpdesk_private')->put($key, $bytes);
            $q->delete([$key, $key.'.meta']);
            DB::table('helpdesk_messages')->where('id', $m->id)->update(['scan_status' => 'CLEAN', 'scan_completed_at' => now(), 'storage_key' => $key, 'quarantine_storage_key' => null]);
        } catch (\Throwable $e) {
            DB::table('helpdesk_messages')->where('id', $m->id)->update(['scan_status' => 'FAILED', 'scan_completed_at' => now()]);
            throw $e;
        }
    }
}
