<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportEnrollmentsStaging extends Command
{
    protected $signature = 'sunrise:import-enrollments {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import academic calendar, enrollments, and teacher assignments';

    private const FIELDS = [
        'academic_years' => ['id', 'schoolId', 'name', 'startDate', 'endDate', 'isActive', 'createdAt', 'updatedAt'],
        'semesters' => ['id', 'academicYearId', 'name', 'startDate', 'endDate', 'isActive', 'createdAt', 'updatedAt'],
        'class_enrollments' => ['id', 'studentId', 'classId', 'schoolId', 'academicYearId', 'semesterId', 'status', 'effectiveFrom', 'effectiveUntil', 'createdAt', 'updatedAt'],
        'course_enrollments' => ['id', 'courseId', 'studentId', 'schoolId', 'status', 'enrolledAt', 'completedAt', 'createdAt', 'updatedAt'],
        'teacher_assignments' => ['id', 'teacherId', 'classId', 'schoolId', 'courseId', 'subject', 'effectiveFrom', 'effectiveUntil', 'isActive', 'createdAt', 'updatedAt'],
    ];
    private const DATES = ['startDate', 'endDate', 'effectiveFrom', 'effectiveUntil', 'enrolledAt', 'completedAt', 'createdAt', 'updatedAt'];
    private const REQUIRED_DATES = ['startDate', 'endDate', 'effectiveFrom', 'enrolledAt', 'createdAt', 'updatedAt'];

    public function handle(): int
    {
        if ($this->option('apply') && app()->environment('production')) {
            $this->error('Impor langsung ke production ditolak. Gunakan database staging.');
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
        if (($payload['format'] ?? null) !== 'sunrise-enrollments-staging-v1' || ! is_array($payload['tables'] ?? null)
            || ($baseline !== null && ! is_array($baseline['tables'] ?? null)) || ($this->option('apply') && $baseline === null)) {
            $this->error('Format data tidak sesuai atau --apply tanpa --baseline.');
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
                    $issues[] = "ID {$table} kosong, ganda, atau terlalu panjang.";
                    break;
                }
                $seen[$id] = true;
                foreach (self::DATES as $field) {
                    if (! array_key_exists($field, $row)) continue;
                    $value = $row[$field];
                    if (($value === null && in_array($field, self::REQUIRED_DATES, true))
                        || ($value !== null && (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $value)))) {
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
            foreach (array_keys(self::FIELDS) as $table) {
                if (DB::table($table)->exists()) $issues[] = "Tabel tujuan {$table} harus kosong.";
            }
        }
        $this->line(json_encode(['tables' => $summary, 'issues' => array_values(array_unique($issues)), 'baselineCompared' => $baseline !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($issues !== []) return self::FAILURE;
        if (! $this->option('apply')) {
            $this->warn('Pratinjau saja: tidak ada data ditulis. --apply wajib memakai baseline pada staging.');
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
            });
        } catch (Throwable) {
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi dan ukuran field staging.');
            return self::FAILURE;
        }
        $this->info('Kalender akademik, enrollment, dan penugasan guru terimpor.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $schools = DB::table('schools')->pluck('organization_id', 'id')->all();
        $students = DB::table('students')->pluck('school_id', 'id')->all();
        $teachers = DB::table('teachers')->pluck('school_id', 'id')->all();
        $classes = DB::table('classes')->pluck('school_id', 'id')->all();
        $courses = DB::table('courses')->get(['id', 'school_id'])->pluck('school_id', 'id')->all();
        $years = collect($tables['academic_years'])->keyBy('id');
        $semesters = collect($tables['semesters'])->keyBy('id');
        foreach ($tables['academic_years'] as $row) {
            if (! isset($schools[$row['schoolId'] ?? ''])) $issues[] = 'Tahun ajaran merujuk sekolah yang tidak ada.';
        }
        foreach ($tables['semesters'] as $row) {
            if (! isset($years[$row['academicYearId'] ?? ''])) $issues[] = 'Semester merujuk tahun ajaran yang tidak ada.';
        }
        foreach ($tables['class_enrollments'] as $row) {
            $school = $row['schoolId'];
            $year = $row['academicYearId'];
            $semester = $row['semesterId'];
            if (! isset($schools[$school]) || ($students[$row['studentId']] ?? null) !== $school || ($classes[$row['classId']] ?? null) !== $school
                || ($year !== null && ($years[$year]['schoolId'] ?? null) !== $school)
                || ($semester !== null && (($semesters[$semester]['academicYearId'] ?? null) !== $year || ($years[$year]['schoolId'] ?? null) !== $school))) {
                $issues[] = 'Enrollment kelas memiliki relasi sekolah/tahun ajaran/semester tidak cocok.';
            }
        }
        foreach ($tables['course_enrollments'] as $row) {
            $school = $row['schoolId'];
            if (! isset($schools[$school]) || ($students[$row['studentId']] ?? null) !== $school || ($courses[$row['courseId']] ?? null) !== $school) {
                $issues[] = 'Enrollment course melintasi sekolah atau induk tidak ada.';
            }
        }
        foreach ($tables['teacher_assignments'] as $row) {
            $school = $row['schoolId'];
            if (! isset($schools[$school]) || ($teachers[$row['teacherId']] ?? null) !== $school || ($classes[$row['classId']] ?? null) !== $school
                || ($row['courseId'] !== null && ($courses[$row['courseId']] ?? null) !== $school)) {
                $issues[] = 'Penugasan guru melintasi sekolah atau induk tidak ada.';
            }
        }
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
