<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportReviewsStaging extends Command
{
    protected $signature = 'sunrise:import-reviews {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import source rubrics, teacher reviews and unpublished AI recommendations';

    private const FIELDS = [
        'rubrics' => ['id', 'organizationId', 'name', 'description', 'createdAt', 'updatedAt'],
        'rubric_criteria' => ['id', 'rubricId', 'name', 'description', 'weight', 'maxScore', 'position', 'levels'],
        'submission_reviews' => ['id', 'submissionId', 'reviewerId', 'rubricId', 'status', 'totalScore', 'feedback', 'isPublished', 'publishedAt', 'createdAt'],
        'review_scores' => ['id', 'reviewId', 'criterionId', 'score', 'comment'],
        'ai_assessments' => ['id', 'submissionId', 'rubricId', 'contentVersion', 'model', 'totalScore', 'feedback', 'insufficientEvidence', 'requiresTeacherReview', 'createdAt'],
        'ai_assessment_scores' => ['id', 'aiAssessmentId', 'criterionId', 'score', 'reason'],
    ];
    private const DATES = ['createdAt', 'updatedAt', 'publishedAt'];
    private const REQUIRED_DATES = ['createdAt', 'updatedAt'];

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
        if (($payload['format'] ?? null) !== 'sunrise-reviews-staging-v1' || ! is_array($payload['tables'] ?? null)
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
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi dan kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Rubrik, review guru dan rekomendasi AI terimpor; status publikasi asli dipertahankan.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $organizations = DB::table('organizations')->pluck('id')->flip();
        $assignments = DB::table('assignments')->get(['id', 'organization_id', 'course_id', 'rubric_id'])->keyBy('id');
        $courses = DB::table('courses')->pluck('school_id', 'id');
        $submissions = DB::table('submissions')->pluck('assignment_id', 'id');
        $teachers = DB::table('teachers')->pluck('school_id', 'id');
        $rubrics = collect($tables['rubrics'])->keyBy('id');
        $criteria = collect($tables['rubric_criteria'])->keyBy('id');
        $reviews = collect($tables['submission_reviews'])->keyBy('id');
        $assessments = collect($tables['ai_assessments'])->keyBy('id');
        foreach ($tables['rubrics'] as $row) {
            if (! isset($organizations[$row['organizationId'] ?? ''])) $issues[] = 'Rubrik merujuk organisasi yang tidak ada.';
        }
        foreach ($assignments as $assignment) {
            if ($assignment->rubric_id !== null && ($rubrics[$assignment->rubric_id]['organizationId'] ?? null) !== $assignment->organization_id) {
                $issues[] = 'Rubrik tugas tidak cocok dengan organisasi sumber.';
            }
        }
        foreach ($tables['rubric_criteria'] as $row) {
            if (! isset($rubrics[$row['rubricId'] ?? '']) || ! is_int($row['weight']) || ! is_int($row['maxScore']) || ! is_int($row['position'])) {
                $issues[] = 'Kriteria rubrik memiliki induk atau nilai tidak valid.';
            }
        }
        foreach ($tables['submission_reviews'] as $row) {
            $assignment = $assignments[$submissions[$row['submissionId'] ?? ''] ?? ''] ?? null;
            $school = $assignment ? ($courses[$assignment->course_id] ?? null) : null;
            $rubric = $row['rubricId'] === null ? null : ($rubrics[$row['rubricId']] ?? null);
            if (! $assignment || ! $school || ($teachers[$row['reviewerId'] ?? ''] ?? null) !== $school
                || ($row['rubricId'] !== null && ($rubric['organizationId'] ?? null) !== $assignment->organization_id)
                || ! is_bool($row['isPublished']) || ($row['totalScore'] !== null && ! is_int($row['totalScore']))) {
                $issues[] = 'Review memiliki reviewer, rubrik, sekolah atau skor tidak valid.';
            }
        }
        $this->checkScores($tables['review_scores'], $reviews, $criteria, 'reviewId', $issues);
        foreach ($tables['ai_assessments'] as $row) {
            $assignment = $assignments[$submissions[$row['submissionId'] ?? ''] ?? ''] ?? null;
            if (! $assignment || ($rubrics[$row['rubricId'] ?? '']['organizationId'] ?? null) !== $assignment->organization_id
                || ! is_int($row['contentVersion']) || $row['contentVersion'] < 1 || ! is_int($row['totalScore'])
                || ! is_bool($row['requiresTeacherReview']) || ! is_bool($row['insufficientEvidence'])) {
                $issues[] = 'Penilaian AI memiliki submission/rubrik/versi atau nilai tidak valid.';
            }
        }
        $this->checkScores($tables['ai_assessment_scores'], $assessments, $criteria, 'aiAssessmentId', $issues);
    }

    private function checkScores(array $rows, $parents, $criteria, string $parentField, array &$issues): void
    {
        $pairs = [];
        foreach ($rows as $row) {
            $parent = $parents[$row[$parentField] ?? ''] ?? null;
            $criterion = $criteria[$row['criterionId'] ?? ''] ?? null;
            $key = ($row[$parentField] ?? '').':'.($row['criterionId'] ?? '');
            if (! $parent || ! $criterion || ($parent['rubricId'] ?? null) !== $criterion['rubricId']
                || ! is_int($row['score']) || isset($pairs[$key])) {
                $issues[] = 'Skor kriteria merujuk rubrik lain, ganda, atau nilainya tidak valid.';
            }
            $pairs[$key] = true;
        }
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if ($field === 'levels' && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
