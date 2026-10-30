<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportAccessStaging extends Command
{
    protected $signature = 'sunrise:import-access {file : Protected JSON exported from source} {--baseline= : Read-only source ID baseline} {--apply : Insert into an empty staging schema}';

    protected $description = 'Validate source access data, then optionally import original IDs into empty staging tables';

    private const FIELDS = [
        'programs' => ['id', 'organizationId', 'schoolId', 'code', 'name', 'description', 'isActive', 'createdAt', 'updatedAt'],
        'access_plans' => ['id', 'code', 'name', 'rank', 'moduleLimit', 'status', 'createdAt', 'updatedAt'],
        'entitlement_definitions' => ['id', 'key', 'valueType', 'isActive', 'createdAt', 'updatedAt'],
        'plan_entitlements' => ['id', 'accessPlanId', 'entitlementDefinitionId', 'enabled', 'valueJson', 'effectiveFrom', 'effectiveTo', 'createdAt', 'updatedAt'],
        'access_grants' => ['id', 'organizationId', 'schoolId', 'programId', 'classId', 'userId', 'targetType', 'accessPlanId', 'source', 'status', 'startsAt', 'endsAt', 'createdAt', 'updatedAt'],
        'module_plan_access' => ['moduleId', 'minimumPlanId'],
    ];

    /** Optional in older exports; a missing key imports as null. */
    private const OPTIONAL = ['access_grants' => ['orderItemId', 'reason', 'grantedBy']];

    private const DATES = ['createdAt', 'updatedAt', 'effectiveFrom', 'effectiveTo', 'startsAt', 'endsAt'];

    public function handle(): int
    {
        if ($this->option('apply') && app()->environment('production')) {
            $this->error('Impor langsung ke production ditolak. Gunakan database staging.');
            return self::FAILURE;
        }
        $path = $this->argument('file');
        $baselinePath = $this->option('baseline');
        if (! is_file($path) || ($baselinePath && ! is_file($baselinePath))) {
            $this->error('Berkas sumber atau baseline tidak ditemukan.');
            return self::FAILURE;
        }
        try {
            $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $baseline = $baselinePath ? json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $this->error('JSON sumber atau baseline tidak valid.');
            return self::FAILURE;
        }
        if (($payload['format'] ?? null) !== 'sunrise-access-staging-v1' || ! is_array($payload['tables'] ?? null)
            || ($baseline !== null && ! is_array($baseline['tables'] ?? null))) {
            $this->error('Format data akses atau baseline tidak sesuai.');
            return self::FAILURE;
        }
        if ($this->option('apply') && ! $baseline) {
            $this->error('Impor memerlukan --baseline dari sumber.');
            return self::FAILURE;
        }

        $tables = $payload['tables'];
        $issues = [];
        $ids = [];
        $summary = [];
        foreach (self::FIELDS as $table => $fields) {
            if (! Schema::hasTable($table)) $issues[] = "Tabel {$table} belum dimigrasikan.";
            $rows = $tables[$table] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                $issues[] = "Format {$table} tidak valid.";
                continue;
            }
            $key = $table === 'module_plan_access' ? 'moduleId' : 'id';
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row) || array_diff($fields, array_keys($row)) !== []) {
                    $issues[] = "Kolom {$table} tidak lengkap.";
                    break;
                }
                $id = $row[$key];
                if (! is_string($id) || $id === '' || strlen($id) > 191 || isset($seen[$id])) {
                    $issues[] = "ID {$table} kosong, duplikat, atau terlalu panjang.";
                    break;
                }
                $seen[$id] = true;
                foreach (self::DATES as $field) {
                    if (! array_key_exists($field, $row) || $row[$field] === null) continue;
                    if (! is_string($row[$field]) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $row[$field])) {
                        $issues[] = "Tanggal {$table} tidak sesuai format UTC sumber.";
                        break 2;
                    }
                }
            }
            $ids[$table] = $seen;
            $ordered = array_keys($seen);
            sort($ordered, SORT_STRING);
            $hash = hash('sha256', implode('', array_map(fn ($id) => $id."\n", $ordered)));
            $summary[$table] = ['count' => count($rows), 'idSha256' => $hash];
            if ($baseline !== null && ($baseline['tables'][$table] ?? null) !== $summary[$table]) {
                $issues[] = "Jumlah atau ID {$table} berbeda dari baseline.";
            }
        }

        if (! isset($ids['access_plans']) || ! collect($tables['access_plans'] ?? [])->contains(fn ($row) => ($row['code'] ?? null) === 'FREE')) {
            $issues[] = 'Paket FREE sumber wajib tersedia.';
        }
        foreach ($tables['plan_entitlements'] ?? [] as $row) {
            if (! isset($ids['access_plans'][$row['accessPlanId'] ?? '']) || ! isset($ids['entitlement_definitions'][$row['entitlementDefinitionId'] ?? ''])) {
                $issues[] = 'Entitlement merujuk paket atau definisi yang tidak ada.';
                break;
            }
        }
        foreach ($tables['access_grants'] ?? [] as $row) {
            if (! isset($ids['access_plans'][$row['accessPlanId'] ?? ''])) {
                $issues[] = 'Grant merujuk paket yang tidak ada.';
                break;
            }
            $targets = ['ORGANIZATION' => null, 'SCHOOL' => 'schoolId', 'CLASS' => 'classId', 'PROGRAM' => 'programId', 'USER' => 'userId'];
            $target = array_key_exists($row['targetType'] ?? '', $targets) ? $targets[$row['targetType']] : false;
            $populated = array_keys(array_filter(array_intersect_key($row, array_fill_keys(['schoolId', 'programId', 'classId', 'userId'], true)), fn ($value) => $value !== null && $value !== ''));
            if ($target === false || ($target === null && $populated !== [])
                || ($target !== null && $populated !== [$target])) {
                $issues[] = 'Tipe atau target grant tidak valid.';
                break;
            }
            if ($target === 'programId' && ! isset($ids['programs'][$row['programId']])) {
                $issues[] = 'Grant merujuk program yang tidak ada dalam ekspor.';
                break;
            }
        }
        foreach ($tables['module_plan_access'] ?? [] as $row) {
            if (! isset($ids['access_plans'][$row['minimumPlanId'] ?? ''])) {
                $issues[] = 'Batas modul merujuk paket yang tidak ada.';
                break;
            }
        }

        if ($issues === []) $issues = $this->checkDestination($tables);
        $this->line(json_encode(['tables' => $summary, 'issues' => array_values(array_unique($issues)), 'baselineCompared' => $baseline !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($issues !== []) return self::FAILURE;
        if (! $this->option('apply')) {
            $this->warn('Pratinjau saja: tidak ada data yang ditulis. --apply membutuhkan --baseline pada staging.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($tables, $summary): void {
                foreach (self::FIELDS as $table => $fields) {
                    foreach (array_chunk($tables[$table], 250) as $chunk) {
                        $optional = self::OPTIONAL[$table] ?? [];
                        // The order link only survives when its order item is already imported (FK); otherwise it is dropped and reported.
                        $orderItems = $table === 'access_grants' ? DB::table('order_items')->whereIn('id', array_filter(array_column($chunk, 'orderItemId')))->pluck('id')->flip() : collect();
                        $mapped = array_map(function ($row) use ($fields, $optional, $orderItems) {
                            $row += array_fill_keys($optional, null);
                            if (($row['orderItemId'] ?? null) !== null && ! $orderItems->has($row['orderItemId'])) {
                                $this->warn("Grant {$row['id']} merujuk order item yang belum diimpor; tautan pesanan dikosongkan.");
                                $row['orderItemId'] = null;
                            }
                            return $this->mapRow($row, array_merge($fields, $optional));
                        }, $chunk);
                        DB::table($table)->insert($mapped);
                    }
                    $key = $table === 'module_plan_access' ? 'module_id' : 'id';
                    $hash = hash_init('sha256');
                    $count = 0;
                    foreach (DB::table($table)->orderBy($key)->cursor() as $row) {
                        hash_update($hash, $row->$key."\n");
                        $count++;
                    }
                    if ($summary[$table] !== ['count' => $count, 'idSha256' => hash_final($hash)]) {
                        throw new \RuntimeException('Post-import ID mismatch');
                    }
                }
            });
        } catch (Throwable) {
            $this->error('Impor gagal dan seluruh transaksi dibatalkan. Periksa relasi dan skema staging.');
            return self::FAILURE;
        }
        $this->info('Subset data akses berhasil diimpor. Jalankan sunrise:audit-dashboard --baseline untuk pemeriksaan ID penuh.');
        return self::SUCCESS;
    }

    private function checkDestination(array $tables): array
    {
        $issues = [];
        foreach (array_keys(self::FIELDS) as $table) {
            if (DB::table($table)->exists()) $issues[] = "Tabel tujuan {$table} harus kosong.";
        }
        $references = [
            'organizations' => array_merge(array_filter(array_column($tables['access_grants'], 'organizationId')), array_column($tables['programs'], 'organizationId')),
            'schools' => array_merge(array_filter(array_column($tables['access_grants'], 'schoolId')), array_column($tables['programs'], 'schoolId')),
            'classes' => array_filter(array_column($tables['access_grants'], 'classId')),
            'users' => array_filter(array_column($tables['access_grants'], 'userId')),
            'course_modules' => array_filter(array_column($tables['module_plan_access'], 'moduleId')),
        ];
        foreach ($references as $table => $values) {
            $unique = array_values(array_unique($values));
            foreach (array_chunk($unique, 500) as $chunk) {
                if (DB::table($table)->whereIn('id', $chunk)->count() !== count($chunk)) {
                    $issues[] = "Data induk {$table} belum lengkap.";
                    break;
                }
            }
        }
        $classes = DB::table('classes')->whereIn('id', array_values(array_unique(array_filter(array_column($tables['access_grants'], 'classId')))))->pluck('school_id', 'id');
        $schoolIds = array_values(array_unique(array_merge(array_filter(array_column($tables['access_grants'], 'schoolId')), $classes->values()->all(), array_column($tables['programs'], 'schoolId'))));
        $schools = DB::table('schools')->whereIn('id', $schoolIds)->pluck('organization_id', 'id');
        $users = DB::table('users')->whereIn('id', array_values(array_unique(array_filter(array_column($tables['access_grants'], 'userId')))))->pluck('organization_id', 'id');
        $programs = collect($tables['programs'])->keyBy('id');
        foreach ($programs as $program) {
            if (($schools[$program['schoolId']] ?? null) !== $program['organizationId']) {
                $issues[] = 'Program melintasi organisasi tujuan.';
                break;
            }
        }
        foreach ($tables['access_grants'] as $row) {
            $valid = match ($row['targetType']) {
                'SCHOOL' => ($schools[$row['schoolId']] ?? null) === $row['organizationId'],
                'CLASS' => ($schools[$classes[$row['classId']] ?? ''] ?? null) === $row['organizationId'],
                'PROGRAM' => ($programs[$row['programId']]['organizationId'] ?? null) === $row['organizationId'],
                'USER' => ($users[$row['userId']] ?? null) === $row['organizationId'],
                default => true,
            };
            if (! $valid) {
                $issues[] = 'Grant melintasi organisasi tujuan.';
                break;
            }
        }
        return $issues;
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) {
                $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            } elseif ($field === 'valueJson' && $value !== null) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
