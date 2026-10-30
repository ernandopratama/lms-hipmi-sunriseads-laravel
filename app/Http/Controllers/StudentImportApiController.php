<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStudentImport;
use App\Support\IdentifierCrypto;
use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentImportApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'import');
        $d = $request->validate(['sourceName' => 'required|string|max:255', 'rows' => 'required|array|min:1|max:10000', 'rows.*.email' => 'required|email', 'rows.*.studentNumber' => 'required|string|max:50',
            'rows.*.fullName' => 'required|string|max:200', 'rows.*.classCode' => 'nullable|string|max:50', 'rows.*.nisn' => 'nullable|string|max:30']);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($c, $d, $id): void {
            DB::table('import_jobs')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'created_by_id' => $c['user'], 'type' => 'STUDENTS',
                'source_name' => mb_substr($d['sourceName'], 0, 255), 'status' => 'QUEUED', 'total_rows' => count($d['rows']), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($d['rows'], 500, true) as $chunk) {
                DB::table('import_rows')->insert(array_map(function ($row, $i) use ($id) {
                    $nisn = trim($row['nisn'] ?? '');
                    $payload = ['email' => strtolower(trim($row['email'])), 'studentNumber' => trim($row['studentNumber']), 'fullName' => trim($row['fullName'])]
                        + (trim($row['classCode'] ?? '') !== '' ? ['classCode' => trim($row['classCode'])] : [])
                        + ($nisn !== '' ? ['nisnEncrypted' => IdentifierCrypto::encrypt($nisn), 'nisnLast4' => substr($nisn, -4)] : []);
                    return ['id' => (string) Str::ulid(), 'import_job_id' => $id, 'row_number' => $i + 2, 'status' => 'PENDING', 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()];
                }, $chunk, array_keys($chunk)));
            }
        });
        // Read before dispatching: a synchronous queue would already have finished the job.
        $j = DB::table('import_jobs')->where('id', $id)->first();
        ProcessStudentImport::dispatch($id)->afterCommit();
        return response()->json(['id' => $j->id, 'status' => $j->status, 'totalRows' => (int) $j->total_rows, 'sourceName' => $j->source_name,
            'createdAt' => CarbonImmutable::parse($j->created_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z')], 201);
    }
}
