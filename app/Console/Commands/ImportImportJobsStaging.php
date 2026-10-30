<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportImportJobsStaging extends Command
{
    protected $signature = 'sunrise:import-import-jobs {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import source import jobs and their private row history';

    private const FIELDS = [
        'import_jobs' => ['id', 'organizationId', 'schoolId', 'createdById', 'type', 'sourceName', 'status', 'totalRows', 'validRows', 'failedRows', 'processedRows', 'failureReason', 'startedAt', 'completedAt', 'createdAt', 'updatedAt'],
        'import_rows' => ['id', 'importJobId', 'rowNumber', 'status', 'payload', 'errors', 'createdAt', 'updatedAt'],
    ];
    private const DATES = ['startedAt', 'completedAt', 'createdAt', 'updatedAt'];

    public function handle(): int
    {
        if ($this->option('apply') && app()->environment('production')) {
            $this->error('Impor production ditolak; gunakan database staging.');
            return self::FAILURE;
        }
        $path = $this->argument('file');
        $baselinePath = $this->option('baseline');
        if (! is_file($path) || ($baselinePath && ! is_file($baselinePath))) {
            $this->error('Berkas ekspor atau baseline tidak ditemukan.');
            return self::FAILURE;
        }
        try {
            $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $baseline = $baselinePath ? json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $this->error('JSON ekspor atau baseline tidak valid.');
            return self::FAILURE;
        }
        if (($payload['format'] ?? null) !== 'sunrise-import-jobs-staging-v1' || ! is_array($payload['tables'] ?? null)
            || ($baseline !== null && ! is_array($baseline['tables'] ?? null)) || ($this->option('apply') && $baseline === null)) {
            $this->error('Format ekspor tidak sesuai atau --apply tanpa --baseline.');
            return self::FAILURE;
        }
        $tables = $payload['tables'];
        $issues = [];
        $summary = [];
        foreach (self::FIELDS as $table => $fields) {
            if (! Schema::hasTable($table)) $issues[] = "Tabel {$table} belum dimigrasikan.";
            $rows = $tables[$table] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                $issues[] = "Format {$table} tidak valid.";
                continue;
            }
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row) || array_diff($fields, array_keys($row)) !== []) {
                    $issues[] = "Kolom {$table} tidak lengkap.";
                    break;
                }
                $id = $row['id'];
                if (! is_string($id) || $id === '' || strlen($id) > 191 || isset($seen[$id])) {
                    $issues[] = "ID {$table} kosong, ganda atau terlalu panjang.";
                    break;
                }
                $seen[$id] = true;
                foreach (self::DATES as $field) {
                    if (! array_key_exists($field, $row)) continue;
                    $date = $row[$field];
                    if (($date === null && in_array($field, ['createdAt', 'updatedAt'], true))
                        || ($date !== null && (! is_string($date) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $date)))) {
                        $issues[] = "Tanggal {$table}.{$field} tidak valid.";
                        break 2;
                    }
                }
            }
            $ids = array_keys($seen);
            sort($ids, SORT_STRING);
            $summary[$table] = ['count' => count($rows), 'idSha256' => hash('sha256', implode('', array_map(fn ($id) => $id."\n", $ids)))];
            if ($baseline !== null && ($baseline['tables'][$table] ?? null) !== $summary[$table]) $issues[] = "Jumlah atau ID {$table} berbeda dari baseline.";
        }
        if ($issues === []) $this->validateRelations($tables, $issues);
        if ($issues === []) {
            foreach (array_keys(self::FIELDS) as $table) if (DB::table($table)->exists()) $issues[] = "Tabel tujuan {$table} harus kosong.";
        }
        $this->line(json_encode(['tables' => $summary, 'issues' => array_values(array_unique($issues)), 'baselineCompared' => $baseline !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($issues !== []) return self::FAILURE;
        if (! $this->option('apply')) {
            $this->warn('Pratinjau saja. --apply membutuhkan baseline sumber pada staging.');
            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($tables, $summary): void {
                foreach (self::FIELDS as $table => $fields) {
                    foreach (array_chunk($tables[$table], 250) as $chunk) {
                        DB::table($table)->insert(array_map(fn ($row) => $this->mapRow($row, $fields), $chunk));
                    }
                    $hash = hash_init('sha256');
                    $count = 0;
                    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                        hash_update($hash, $row->id."\n");
                        $count++;
                    }
                    if (['count' => $count, 'idSha256' => hash_final($hash)] !== $summary[$table]) throw new \RuntimeException('Post-import ID mismatch');
                }
                $orphans = DB::table('affiliate_metric_snapshots as s')->leftJoin('import_jobs as j', 'j.id', '=', 's.import_job_id')
                    ->whereNotNull('s.import_job_id')->where(fn ($q) => $q->whereNull('j.id')->orWhereColumn('s.school_id', '!=', 'j.school_id')->orWhereColumn('s.organization_id', '!=', 'j.organization_id'))->count();
                if ($orphans) throw new \RuntimeException('Affiliate snapshot import job mismatch');
            });
        } catch (Throwable) {
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi dan kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Pekerjaan impor dan riwayat baris terimpor.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $schools = DB::table('schools')->pluck('organization_id', 'id')->all();
        $users = DB::table('users')->get(['id', 'organization_id', 'school_id'])->keyBy('id');
        $jobs = collect($tables['import_jobs'])->keyBy('id');
        $rowNumbers = [];
        foreach ($tables['import_jobs'] as $row) {
            $school = $row['schoolId'];
            $actor = $users[$row['createdById'] ?? ''] ?? null;
            if (! is_string($school) || ($schools[$school] ?? null) !== $row['organizationId'] || ! $actor
                || $actor->organization_id !== $row['organizationId'] || ($actor->school_id !== null && $actor->school_id !== $school)) {
                $issues[] = 'Pekerjaan impor merujuk organisasi, sekolah, atau pembuat lintas tenant.';
            }
            foreach (['totalRows', 'validRows', 'failedRows', 'processedRows'] as $field) {
                if (! is_int($row[$field]) || $row[$field] < 0) $issues[] = 'Penghitung pekerjaan impor tidak valid.';
            }
        }
        foreach ($tables['import_rows'] as $row) {
            $jobId = $row['importJobId'];
            $number = $row['rowNumber'];
            if (! is_string($jobId) || ! isset($jobs[$jobId]) || ! is_int($number) || $number < 0 || isset($rowNumbers[$jobId][$number])) {
                $issues[] = 'Baris impor merujuk pekerjaan yang tidak ada atau nomor baris ganda.';
                continue;
            }
            $rowNumbers[$jobId][$number] = true;
        }
        $existing = DB::table('affiliate_metric_snapshots')->whereNotNull('import_job_id')->get(['import_job_id', 'organization_id', 'school_id']);
        foreach ($existing as $snapshot) {
            $job = $jobs[$snapshot->import_job_id] ?? null;
            if (! $job || $job['organizationId'] !== $snapshot->organization_id || $job['schoolId'] !== $snapshot->school_id) {
                $issues[] = 'Snapshot affiliate merujuk pekerjaan impor hilang atau lintas tenant.';
            }
        }
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if (in_array($field, ['payload', 'errors'], true) && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[strtolower(preg_replace('/[A-Z]/', '_$0', $field))] = $value;
        }
        return $mapped;
    }
}
