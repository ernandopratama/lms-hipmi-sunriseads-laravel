<?php

namespace App\Console\Commands;

use App\Services\Migration\SourceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportSourceSnapshot extends Command
{
    protected $signature = 'sunrise:import-snapshot {file : Encrypted full PostgreSQL snapshot} {--apply : Import into empty staging tables}';
    protected $description = 'Validate all 100 source models and import one consistent encrypted snapshot atomically';

    private const STAGES = [
        ['foundation', ImportFoundationStaging::class, 'sunrise:import-foundation'],
        ['accounts', ImportAccountsStaging::class, 'sunrise:import-accounts'],
        ['enrollments', ImportEnrollmentsStaging::class, 'sunrise:import-enrollments'],
        ['learning', ImportLearningStaging::class, 'sunrise:import-learning'],
        ['assignment', ImportAssignmentsStaging::class, 'sunrise:import-assignments'],
        ['reviews', ImportReviewsStaging::class, 'sunrise:import-reviews'],
        ['student-store', ImportStudentStoreStaging::class, 'sunrise:import-student-store'],
        ['affiliate', ImportAffiliateStaging::class, 'sunrise:import-affiliate'],
        ['affiliate-reviews', ImportAffiliateReviewsStaging::class, 'sunrise:import-affiliate-reviews'],
        ['import-jobs', ImportImportJobsStaging::class, 'sunrise:import-import-jobs'],
        ['access', ImportAccessStaging::class, 'sunrise:import-access'],
    ];
    // Active legacy authentication tokens are deliberately not accepted by Laravel.
    private const INVALIDATED = ['user_sessions', 'password_reset_tokens'];

    public function handle(SourceSnapshot $service): int
    {
        if ($this->option('apply') && app()->environment('production')) {
            $this->error('Gunakan database staging kosong; impor production ditolak.'); return self::FAILURE;
        }
        $work = null;
        try {
            $snapshot = $service->read($this->argument('file'));
            $manifest = $service->manifest();
            foreach ($manifest['tables'] as $table => $_spec) {
                if (! Schema::hasTable($table)) throw new RuntimeException("Jalankan migration: tabel {$table} belum tersedia.");
                if (DB::table($table)->exists()) throw new RuntimeException("Tabel staging {$table} harus kosong; tidak ada data ditimpa.");
            }
            $count = array_sum(array_column($snapshot['baseline']['tables'], 'count'));
            $this->info('Snapshot valid: '.count($manifest['tables'])." tabel, {$count} baris sumber.");
            if (! $this->option('apply')) {
                $this->warn('Validasi saja; tidak ada data ditulis. Gunakan --apply pada staging kosong.');
                return self::SUCCESS;
            }
            $work = storage_path('app/private/migration-work/'.Str::uuid());
            if (! mkdir($work, 0700, true)) throw new RuntimeException('Direktori kerja privat tidak dapat dibuat.');
            $baseline = $work.'/baseline.json';
            $this->privateJson($baseline, $snapshot['baseline']);
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction(function () use ($snapshot, $manifest, $service, $work, $baseline): void {
                // All stage commands share this connection/transaction. A late failure rolls back every stage.
                $handled = [];
                foreach (self::STAGES as [$format, $class, $command]) {
                    $fields = (new \ReflectionClass($class))->getReflectionConstant('FIELDS')->getValue();
                    $tables = [];
                    foreach ($fields as $table => $names) {
                        $tables[$table] = array_map(fn ($row) => array_intersect_key($row, array_flip($names)), $snapshot['tables'][$table]);
                        $handled[$table] = true;
                    }
                    $file = $work.'/'.$format.'.json';
                    $this->privateJson($file, ['format' => 'sunrise-'.$format.'-staging-v1', 'tables' => $tables]);
                    if ($this->callSilent($command, ['file' => $file, '--baseline' => $baseline, '--apply' => true]) !== self::SUCCESS) {
                        throw new RuntimeException("Tahap {$format} gagal; seluruh impor dibatalkan. Jalankan perintah tahap pada staging terisolasi untuk diagnosis.");
                    }
                    $this->line("[OK] {$format}");
                }
                foreach ($manifest['tables'] as $table => $spec) {
                    if (isset($handled[$table]) || in_array($table, self::INVALIDATED, true)) continue;
                    $columns = array_flip(Schema::getColumnListing($table));
                    foreach ($spec['fields'] as $field) {
                        if (! isset($columns[$field['column']])) throw new RuntimeException("Kolom {$table}.{$field['column']} belum tersedia.");
                    }
                    foreach (array_chunk($snapshot['tables'][$table], 100) as $rows) {
                        DB::table($table)->insert(array_map(fn ($row) => $this->mapSupplemental($table, $row, $spec, $service), $rows));
                    }
                }
                // The access stage runs before order items exist, so it cannot keep the purchase link; restore it now.
                foreach ($snapshot['tables']['access_grants'] ?? [] as $grant) {
                    if (($grant['orderItemId'] ?? null) === null && ($grant['reason'] ?? null) === null && ($grant['grantedBy'] ?? null) === null) continue;
                    DB::table('access_grants')->where('id', $grant['id'])->update(['order_item_id' => $grant['orderItemId'] ?? null, 'reason' => $grant['reason'] ?? null, 'granted_by' => $grant['grantedBy'] ?? null]);
                }
                $batchId = (string) Str::ulid();
                DB::table('migration_batches')->insert([
                    'id' => $batchId, 'schema_sha256' => $manifest['schemaSha256'],
                    'baseline' => json_encode($snapshot['baseline'], JSON_THROW_ON_ERROR),
                    'source_exported_at' => CarbonImmutable::parse($snapshot['exportedAt'])->utc()->format('Y-m-d H:i:s.v'), 'imported_at' => now(),
                ]);
                foreach ($snapshot['tables'] as $table => $rows) {
                    $ids = array_column($rows, 'id'); sort($ids, SORT_STRING);
                    if (! in_array($table, self::INVALIDATED, true)) {
                        $spec = $manifest['tables'][$table];
                        $primaryColumns = array_map(fn ($name) => collect($spec['fields'])->firstWhere('name', $name)['column'], $spec['primaryKey']);
                        $actualIds = DB::table($table)->get($primaryColumns)->map(fn ($record) => count($primaryColumns) === 1 ? $record->{$primaryColumns[0]}
                            : hash('sha256', json_encode(array_map(fn ($column) => $record->$column, $primaryColumns), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))->all();
                        sort($actualIds, SORT_STRING);
                        if ($actualIds !== $ids) throw new RuntimeException("Rekonsiliasi ID {$table} gagal.");
                    }
                    // Preserve every source scalar field, including metadata not yet used by runtime adapters.
                    // The archive is encrypted by APP_KEY and never exposed via HTTP.
                    foreach (array_chunk($rows, 100) as $chunk) {
                        DB::table('migration_source_records')->insert(array_map(fn ($row) => [
                            'table_name' => $table, 'source_id' => $row['id'], 'batch_id' => $batchId,
                            'encrypted_payload' => Crypt::encryptString(json_encode($service->forArchive($table, $row), JSON_THROW_ON_ERROR)),
                        ], $chunk));
                    }
                    if (DB::table('migration_source_records')->where('table_name', $table)->count() !== count($ids)) throw new RuntimeException("Arsip sumber {$table} tidak lengkap.");
                }
                if ($this->callSilent('sunrise:audit-dashboard', ['--baseline' => $baseline]) !== self::SUCCESS) throw new RuntimeException('Audit akhir dashboard gagal; transaksi dibatalkan.');
            }));
            $this->info('Impor staging selesai. ID dan jumlah tabel direkonsiliasi; sesi/token reset lama dinonaktifkan.');
            $this->warn('Arsip lengkap tidak membuktikan kesetaraan fungsi. Verifikasi berkas privat, kontrak API, dan UAT sebelum cutover.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Never print driver exceptions, decrypted records, SQL bindings, or keys.
            $this->error($e instanceof RuntimeException && ! $e instanceof \Illuminate\Database\QueryException ? $e->getMessage() : 'Impor gagal; transaksi dibatalkan. Periksa skema, kapasitas, dan koneksi staging.');
            return self::FAILURE;
        } finally {
            if ($work && is_dir($work)) {
                foreach (glob($work.'/*.json') ?: [] as $path) unlink($path);
                rmdir($work);
            }
        }
    }

    private function privateJson(string $path, array $data): void
    {
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('File kerja privat tidak dapat dibuat.');
        try {
            chmod($path, 0600);
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('Penulisan file kerja tidak lengkap.');
        } finally { fclose($handle); }
    }

    private function mapSupplemental(string $table, array $row, array $spec, SourceSnapshot $service): array
    {
        $row = $service->forArchive($table, $row);
        $result = [];
        foreach ($spec['fields'] as $field) {
            $value = $row[$field['name']];
            if ($value !== null && $field['type'] === 'DateTime') $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if ($value !== null && ($field['type'] === 'Json' || $field['list'])) $value = json_encode($value, JSON_THROW_ON_ERROR);
            $result[$field['column']] = $value;
        }
        if ($table === 'student_identifiers' && $row['valueEncrypted']) {
            $plain = Crypt::decryptString($row['valueEncrypted']);
            $result['blind_index'] = hash_hmac('sha256', $row['type'].':'.strtoupper(preg_replace('/\s+/', '', $plain)), config('app.key'));
        }
        return $result;
    }
}
