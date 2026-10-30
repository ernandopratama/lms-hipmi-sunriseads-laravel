<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportLearningStaging extends Command
{
    protected $signature = 'sunrise:import-learning {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import source lessons, materials and student progress';

    private const FIELDS = [
        'lessons' => ['id', 'moduleId', 'title', 'summary', 'position', 'estimatedMinutes', 'isPublished', 'createdById', 'createdAt', 'updatedAt'],
        'materials' => ['id', 'courseId', 'teacherId', 'lessonId', 'title', 'content', 'fileUrl', 'storageKey', 'originalFileName', 'mimeType', 'previewSlideCount', 'type', 'isActive', 'createdAt', 'updatedAt'],
        'lesson_progress' => ['id', 'lessonId', 'studentId', 'state', 'progressPercent', 'lastPosition', 'startedAt', 'completedAt', 'updatedAt'],
    ];
    private const DATES = ['createdAt', 'updatedAt', 'startedAt', 'completedAt'];

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
        if (($payload['format'] ?? null) !== 'sunrise-learning-staging-v1' || ! is_array($payload['tables'] ?? null)
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
                    $value = $row[$field];
                    if (($value === null && $field === 'updatedAt')
                        || ($value === null && $field === 'createdAt' && $table !== 'lesson_progress')
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
            $this->warn('Pratinjau saja. --apply membutuhkan baseline sumber pada staging.');
            return self::SUCCESS;
        }
        try {
            DB::transaction(function () use ($tables, $summary): void {
                foreach (self::FIELDS as $table => $fields) {
                    foreach (array_chunk($tables[$table], 250) as $chunk) {
                        DB::table($table)->insert(array_map(fn ($row) => $this->mapRow($table, $row, $fields), $chunk));
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
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi dan kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Materi dan progres siswa terimpor. Berkas objek harus diverifikasi terpisah.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $courses = DB::table('courses')->pluck('school_id', 'id')->all();
        $modules = DB::table('course_modules')->pluck('course_id', 'id')->all();
        $teachers = DB::table('teachers')->pluck('school_id', 'id')->all();
        $students = DB::table('students')->get(['id', 'user_id', 'school_id'])->keyBy('id');
        $users = DB::table('users')->pluck('organization_id', 'id')->all();
        $schools = DB::table('schools')->pluck('organization_id', 'id')->all();
        $lessons = collect($tables['lessons'])->keyBy('id');
        $lessonPositions = [];
        foreach ($tables['lessons'] as $row) {
            $course = $modules[$row['moduleId'] ?? ''] ?? null;
            $school = $course ? ($courses[$course] ?? null) : null;
            $key = ($row['moduleId'] ?? '').':'.($row['position'] ?? '');
            if (! $school || ! is_int($row['position']) || $row['position'] < 1 || isset($lessonPositions[$key])
                || ($row['createdById'] !== null && ($users[$row['createdById']] ?? null) !== $schools[$school])) {
                $issues[] = 'Pelajaran memiliki modul, posisi atau penulis lintas organisasi.';
            }
            $lessonPositions[$key] = true;
        }
        foreach ($tables['materials'] as $row) {
            $school = $courses[$row['courseId'] ?? ''] ?? null;
            $lesson = $row['lessonId'] === null ? null : ($lessons[$row['lessonId']] ?? null);
            if (! $school || ($teachers[$row['teacherId'] ?? ''] ?? null) !== $school
                || ($row['lessonId'] !== null && (! $lesson || ($modules[$lesson['moduleId']] ?? null) !== $row['courseId']))) {
                $issues[] = 'Materi merujuk guru, course atau pelajaran lintas sekolah.';
            }
        }
        $pairs = [];
        foreach ($tables['lesson_progress'] as $row) {
            $lesson = $lessons[$row['lessonId'] ?? ''] ?? null;
            $student = $students[$row['studentId'] ?? ''] ?? null;
            $course = $lesson ? ($modules[$lesson['moduleId']] ?? null) : null;
            $key = ($row['lessonId'] ?? '').':'.($row['studentId'] ?? '');
            if (! $student || ! $course || ($courses[$course] ?? null) !== $student->school_id
                || ! is_int($row['progressPercent']) || $row['progressPercent'] < 0 || $row['progressPercent'] > 100 || isset($pairs[$key])) {
                $issues[] = 'Progres pelajaran memiliki murid lintas sekolah, nilai tidak valid atau pasangan ganda.';
            }
            $pairs[$key] = true;
        }
    }

    private function mapRow(string $table, array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            $mapped[$column] = $value;
        }
        if ($table === 'lessons') {
            $mapped['course_module_id'] = $row['moduleId'];
            $mapped['content'] = $row['summary'] ?? '';
            $mapped['status'] = $row['isPublished'] ? 'published' : 'draft';
        } elseif ($table === 'lesson_progress') {
            $mapped['user_id'] = DB::table('students')->where('id', $row['studentId'])->value('user_id');
            $mapped['status'] = $row['state'] === 'COMPLETED' ? 'COMPLETED' : $row['state'];
            $mapped['created_at'] = $mapped['updated_at'];
        }
        return $mapped;
    }
}
