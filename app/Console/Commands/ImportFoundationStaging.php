<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportFoundationStaging extends Command
{
    protected $signature = 'sunrise:import-foundation {file : Protected JSON exported from PostgreSQL} {--baseline= : Source ID baseline} {--apply : Insert into empty staging tables}';

    protected $description = 'Validate and import source organization, school, course, class, and module IDs';

    private const FIELDS = [
        'organizations' => ['id', 'name', 'code', 'description', 'logo', 'isActive', 'createdAt', 'updatedAt'],
        'schools' => ['id', 'organizationId', 'npsn', 'code', 'name', 'level', 'address', 'province', 'city', 'district', 'principal', 'picName', 'picPhone', 'picEmail', 'logo', 'status', 'onboardingStep', 'isActive', 'joinedAt', 'createdAt', 'updatedAt'],
        'departments' => ['id', 'schoolId', 'name', 'code', 'isActive', 'createdAt', 'updatedAt'],
        'courses' => ['id', 'organizationId', 'schoolId', 'programId', 'name', 'code', 'description', 'isActive', 'createdAt', 'updatedAt'],
        'classes' => ['id', 'schoolId', 'departmentId', 'name', 'code', 'grade', 'capacity', 'isActive', 'createdAt', 'updatedAt'],
        'course_modules' => ['id', 'courseId', 'title', 'description', 'position', 'isPublished', 'createdById', 'createdAt', 'updatedAt'],
    ];

    private const DATES = ['createdAt', 'updatedAt', 'joinedAt'];

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
        if (($payload['format'] ?? null) !== 'sunrise-foundation-staging-v1' || ! is_array($payload['tables'] ?? null)
            || ($baseline !== null && ! is_array($baseline['tables'] ?? null))) {
            $this->error('Format data fondasi atau baseline tidak sesuai.');
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
            $seen = [];
            foreach ($rows as $row) {
                if (! is_array($row) || array_diff($fields, array_keys($row)) !== []) {
                    $issues[] = "Kolom {$table} tidak lengkap.";
                    break;
                }
                $id = $row['id'];
                if (! is_string($id) || $id === '' || strlen($id) > 191 || isset($seen[$id])) {
                    $issues[] = "ID {$table} kosong, duplikat, atau terlalu panjang.";
                    break;
                }
                $seen[$id] = true;
                foreach (self::DATES as $field) {
                    if (! isset($row[$field])) continue;
                    if (! is_string($row[$field]) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $row[$field])) {
                        $issues[] = "Tanggal {$table} tidak sesuai format UTC sumber.";
                        break 2;
                    }
                }
            }
            $ids[$table] = $seen;
            $ordered = array_keys($seen);
            sort($ordered, SORT_STRING);
            $summary[$table] = ['count' => count($rows), 'idSha256' => hash('sha256', implode('', array_map(fn ($id) => $id."\n", $ordered)))];
            if ($baseline !== null && ($baseline['tables'][$table] ?? null) !== $summary[$table]) {
                $issues[] = "Jumlah atau ID {$table} berbeda dari baseline.";
            }
        }

        $organizations = collect($tables['organizations'] ?? [])->keyBy('id');
        $schools = collect($tables['schools'] ?? [])->keyBy('id');
        $departments = collect($tables['departments'] ?? [])->keyBy('id');
        $courses = collect($tables['courses'] ?? [])->keyBy('id');
        foreach ($tables['schools'] ?? [] as $row) {
            if (! isset($organizations[$row['organizationId'] ?? ''])) $issues[] = 'Sekolah merujuk organisasi yang tidak ada.';
        }
        foreach ($tables['departments'] ?? [] as $row) {
            if (! isset($schools[$row['schoolId'] ?? ''])) $issues[] = 'Jurusan merujuk sekolah yang tidak ada.';
        }
        foreach ($tables['courses'] ?? [] as $row) {
            if (! isset($schools[$row['schoolId'] ?? '']) || ($schools[$row['schoolId']]['organizationId'] ?? null) !== ($row['organizationId'] ?? null)) {
                $issues[] = 'Course berada di luar organisasi/sekolah sumber.';
            }
        }
        foreach ($tables['classes'] ?? [] as $row) {
            if (! isset($schools[$row['schoolId'] ?? ''])
                || (! empty($row['departmentId']) && ($departments[$row['departmentId']]['schoolId'] ?? null) !== $row['schoolId'])) {
                $issues[] = 'Kelas merujuk sekolah/jurusan yang tidak cocok.';
            }
        }
        foreach ($tables['course_modules'] ?? [] as $row) {
            if (! isset($courses[$row['courseId'] ?? '']) || ! is_int($row['position'] ?? null) || $row['position'] < 1) {
                $issues[] = 'Modul merujuk course yang tidak ada atau posisi tidak valid.';
            }
        }
        if ($issues === []) {
            foreach (array_keys(self::FIELDS) as $table) {
                if (DB::table($table)->exists()) $issues[] = "Tabel tujuan {$table} harus kosong.";
            }
        }
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
                        DB::table($table)->insert(array_map(fn ($row) => $this->mapRow($table, $row, $fields), $chunk));
                    }
                    $digest = hash_init('sha256');
                    $count = 0;
                    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                        hash_update($digest, $row->id."\n");
                        $count++;
                    }
                    if ($summary[$table] !== ['count' => $count, 'idSha256' => hash_final($digest)]) {
                        throw new \RuntimeException('Post-import ID mismatch');
                    }
                }
            });
        } catch (Throwable) {
            $this->error('Impor gagal dan seluruh transaksi dibatalkan. Periksa relasi atau kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Fondasi sekolah dan akademik terimpor. Lanjutkan akun/profil sebelum impor akses.');
        return self::SUCCESS;
    }

    private function mapRow(string $table, array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) {
                $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            }
            $mapped[$column] = $value;
        }
        if ($table === 'courses') {
            $mapped['title'] = $row['name'];
            $mapped['status'] = $row['isActive'] ? 'published' : 'draft';
        }
        return $mapped;
    }
}
