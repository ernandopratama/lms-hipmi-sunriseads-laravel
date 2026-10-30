<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function summary(object $row): array
    {
        return ['id' => $row->id, 'type' => $row->type, 'sourceName' => $row->source_name,
            'status' => $row->status, 'totalRows' => $row->total_rows, 'validRows' => $row->valid_rows,
            'failedRows' => $row->failed_rows, 'processedRows' => $row->processed_rows,
            'failureReason' => $row->failure_reason, 'createdAt' => $row->created_at,
            'completedAt' => $row->completed_at];
    }

    private function jobs(array $c)
    {
        return DB::table('import_jobs')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school']);
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        return response()->json($this->jobs($c)->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($row) => $this->summary($row)));
    }

    public function show(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        $job = $this->jobs($c)->where('id', $id)->first();
        abort_unless($job, 404, 'Import job not found');
        $rows = DB::table('import_rows')->where('import_job_id', $id)->where('status', 'FAILED')
            ->orderBy('row_number')->limit(500)->get()->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];
                unset($payload['nisnEncrypted']);
                return ['rowNumber' => $row->row_number, 'status' => $row->status,
                    'payload' => $payload, 'errors' => $row->errors ? json_decode($row->errors, true) : null];
            });
        return response()->json([...$this->summary($job), 'organizationId' => $job->organization_id,
            'schoolId' => $job->school_id, 'createdById' => $job->created_by_id,
            'startedAt' => $job->started_at, 'updatedAt' => $job->updated_at, 'rows' => $rows]);
    }
}
