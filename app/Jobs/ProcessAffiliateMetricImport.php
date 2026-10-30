<?php

namespace App\Jobs;

use App\Support\AffiliateSnapshotWriter;
use App\Support\StudentDashboardAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Applies queued affiliate metric rows through the same writer as manual snapshots. */
class ProcessAffiliateMetricImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $importJobId) {}

    public function handle(AffiliateSnapshotWriter $writer, StudentDashboardAccess $access): void
    {
        $job = DB::table('import_jobs')->where('id', $this->importJobId)->first();
        if (! $job || $job->type !== 'AFFILIATE_METRICS' || $job->status !== 'QUEUED') return;
        $plan = $access->resolve($job->organization_id, $job->school_id, $job->created_by_id, null);
        if (! $plan || ! $access->enabled($plan, 'affiliate.metrics.manage')) {
            DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'FAILED', 'failure_reason' => 'ENTITLEMENT_NOT_ACTIVE', 'completed_at' => now(), 'updated_at' => now()]);
            return;
        }
        DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'PROCESSING', 'started_at' => now(), 'updated_at' => now()]);
        $imported = 0;
        $failed = 0;
        $context = ['organization' => $job->organization_id, 'school' => $job->school_id, 'user' => $job->created_by_id, 'student' => null];
        try {
            foreach (DB::table('import_rows')->where('import_job_id', $job->id)->where('status', 'PENDING')->orderBy('row_number')->get() as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                try {
                    $video = (string) ($payload['videoId'] ?? '');
                    $writer->create($context, $video, ['sourceReference' => $job->source_name] + $payload, 'IMPORT', $job->id);
                    DB::table('import_rows')->where('id', $row->id)->update(['status' => 'IMPORTED', 'errors' => null, 'updated_at' => now()]);
                    $imported++;
                } catch (HttpException|HttpResponseException $e) {
                    $failed++;
                    // Same per-row codes as the source worker (apps/api/src/worker.ts).
                    $code = $e instanceof HttpException && $e->getStatusCode() === 404 ? 'VIDEO_NOT_FOUND' : match ($e->getMessage()) {
                        'Tanggal snapshot tidak valid' => 'INVALID_SNAPSHOT_DATE',
                        'Periode metrik tidak valid' => 'INVALID_METRIC_PERIOD',
                        'Snapshot yang dikoreksi tidak valid atau sudah memiliki koreksi' => 'INVALID_SUPERSEDED_SNAPSHOT',
                        default => 'ROW_IMPORT_FAILED',
                    };
                    DB::table('import_rows')->where('id', $row->id)->update(['status' => 'FAILED', 'errors' => json_encode(['codes' => [$code]]), 'updated_at' => now()]);
                } catch (\Throwable) {
                    $failed++;
                    DB::table('import_rows')->where('id', $row->id)->update(['status' => 'FAILED', 'errors' => json_encode(['codes' => ['ROW_IMPORT_FAILED']]), 'updated_at' => now()]);
                }
                DB::table('import_jobs')->where('id', $job->id)->update(['processed_rows' => $imported + $failed, 'valid_rows' => $imported, 'failed_rows' => $failed, 'updated_at' => now()]);
            }
            DB::table('import_jobs')->where('id', $job->id)->update(['status' => $failed > 0 ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED', 'processed_rows' => $imported + $failed,
                'valid_rows' => $imported, 'failed_rows' => $failed, 'completed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'FAILED', 'failure_reason' => 'AFFILIATE_IMPORT_FAILED', 'completed_at' => now(), 'updated_at' => now()]);
            throw $e;
        }
    }
}
