<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportAssignmentsStaging extends Command
{
    protected $signature = 'sunrise:import-assignments {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import assignments, submissions and their source versions';

    private const FIELDS = [
        'assignments' => ['id', 'courseId', 'moduleId', 'teacherId', 'title', 'description', 'type', 'startDate', 'endDate', 'maxScore', 'rubricId', 'checklistTemplateId', 'targetViews', 'targetClicks', 'targetOrders', 'targetGmv', 'isActive', 'createdAt', 'updatedAt', 'publishedAt', 'version'],
        'assignment_versions' => ['id', 'assignmentId', 'version', 'snapshotData', 'changedById', 'createdAt'],
        'submissions' => ['id', 'assignmentId', 'studentId', 'status', 'score', 'feedback', 'reviewedById', 'reviewedAt', 'currentVersion', 'submittedAt', 'lockedAt', 'gradePublishedAt', 'createdAt', 'updatedAt'],
        'submission_versions' => ['id', 'submissionId', 'content', 'fileUrl', 'videoUrl', 'version', 'notes', 'createdAt', 'snapshotData'],
    ];
    private const DATES = ['startDate', 'endDate', 'createdAt', 'updatedAt', 'publishedAt', 'reviewedAt', 'submittedAt', 'lockedAt', 'gradePublishedAt'];
    private const REQUIRED_DATES = ['startDate', 'endDate', 'createdAt', 'updatedAt'];

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
        if (($payload['format'] ?? null) !== 'sunrise-assignment-staging-v1' || ! is_array($payload['tables'] ?? null)
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
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa nilai dan kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Tugas dan pengumpulan beserta versi sumber terimpor.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $courses = DB::table('courses')->get(['id', 'school_id', 'organization_id'])->keyBy('id');
        $modules = DB::table('course_modules')->pluck('course_id', 'id')->all();
        $teachers = DB::table('teachers')->get(['id', 'school_id', 'user_id'])->keyBy('id');
        $students = DB::table('students')->get(['id', 'school_id', 'user_id'])->keyBy('id');
        $assignments = collect($tables['assignments'])->keyBy('id');
        $submissions = collect($tables['submissions'])->keyBy('id');
        $seenPairs = [];
        $versions = [];
        foreach ($tables['assignments'] as $row) {
            $course = $courses[$row['courseId'] ?? ''] ?? null;
            if (! $course || ($modules[$row['moduleId'] ?? ''] ?? null) !== $row['courseId']
                || ($teachers[$row['teacherId'] ?? '']->school_id ?? null) !== $course->school_id
                || ! is_int($row['version']) || $row['version'] < 1 || ! is_int($row['maxScore']) || $row['maxScore'] < 0) {
                $issues[] = 'Tugas merujuk course/modul/guru yang tidak cocok atau memiliki versi/skor tidak valid.';
            }
        }
        foreach ($tables['assignment_versions'] as $row) {
            $key = ($row['assignmentId'] ?? '').':'.($row['version'] ?? '');
            if (! isset($assignments[$row['assignmentId'] ?? '']) || ! is_int($row['version']) || $row['version'] < 1
                || ! array_key_exists('snapshotData', $row) || isset($versions[$key])) {
                $issues[] = 'Versi tugas memiliki induk, nomor atau snapshot tidak valid.';
            }
            $versions[$key] = true;
        }
        $versions = [];
        foreach ($tables['submissions'] as $row) {
            $assignment = $assignments[$row['assignmentId'] ?? ''] ?? null;
            $course = $assignment ? ($courses[$assignment['courseId']] ?? null) : null;
            $student = $students[$row['studentId'] ?? ''] ?? null;
            $reviewer = $row['reviewedById'] ? ($teachers[$row['reviewedById']] ?? null) : null;
            $pair = ($row['assignmentId'] ?? '').':'.($row['studentId'] ?? '');
            if (! $assignment || ! $course || ! $student || $student->school_id !== $course->school_id
                || ($row['reviewedById'] !== null && (! $reviewer || $reviewer->school_id !== $course->school_id))
                || isset($seenPairs[$pair]) || ! is_int($row['currentVersion']) || $row['currentVersion'] < 1
                || ($row['score'] !== null && (! is_int($row['score']) || $row['score'] < 0 || $row['score'] > 999))) {
                $issues[] = 'Pengumpulan merujuk tugas/murid/guru lintas sekolah atau versi/skor tidak valid.';
            }
            $seenPairs[$pair] = true;
        }
        foreach ($tables['submission_versions'] as $row) {
            $key = ($row['submissionId'] ?? '').':'.($row['version'] ?? '');
            if (! isset($submissions[$row['submissionId'] ?? '']) || ! is_int($row['version']) || $row['version'] < 1
                || isset($versions[$key])) {
                $issues[] = 'Versi pengumpulan memiliki induk, nomor atau snapshot tidak valid.';
            }
            $versions[$key] = true;
        }
    }

    private function mapRow(string $table, array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if ($field === 'snapshotData' && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[$column] = $value;
        }
        if ($table === 'assignments') {
            $mapped['organization_id'] = DB::table('courses')->where('id', $row['courseId'])->value('organization_id');
            $mapped['instructions'] = $row['description'] ?? '';
            $mapped['due_at'] = $mapped['end_date'];
            $mapped['status'] = $row['isActive'] ? 'published' : 'draft';
        } elseif ($table === 'submissions') {
            $mapped['user_id'] = DB::table('students')->where('id', $row['studentId'])->value('user_id');
            $mapped['reviewer_id'] = $row['reviewedById'] ? DB::table('teachers')->where('id', $row['reviewedById'])->value('user_id') : null;
            $mapped['revision'] = $row['currentVersion'];
            $mapped['body'] = '';
            unset($mapped['current_version']);
        } elseif ($table === 'submission_versions') {
            $mapped['revision'] = $row['version'];
            $mapped['body'] = $row['content'] ?? '';
            $mapped['status'] = 'SOURCE_VERSION';
            $mapped['recorded_at'] = $mapped['created_at'];
            $mapped['updated_at'] = $mapped['created_at'];
            unset($mapped['version']);
        }
        return $mapped;
    }
}
