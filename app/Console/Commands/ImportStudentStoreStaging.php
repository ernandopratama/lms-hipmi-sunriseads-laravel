<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportStudentStoreStaging extends Command
{
    protected $signature = 'sunrise:import-student-store {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import source student stores, evidence, checklists, reviews and fraud flags';

    private const FIELDS = [
        'store_checklist_templates' => ['id', 'organizationId', 'schoolId', 'name', 'platform', 'minimumProducts', 'isActive', 'createdById', 'createdAt', 'updatedAt'],
        'store_checklist_items' => ['id', 'templateId', 'code', 'label', 'description', 'evidenceType', 'weight', 'isRequired', 'position'],
        'student_stores' => ['id', 'organizationId', 'schoolId', 'studentId', 'platform', 'storeName', 'storeUsername', 'storeExternalId', 'storeUrl', 'normalizedStoreUrl', 'category', 'description', 'logoStorageKey', 'bannerStorageKey', 'createdDate', 'activationStatus', 'productCount', 'createdAt', 'updatedAt'],
        'student_store_submissions' => ['id', 'organizationId', 'schoolId', 'assignmentId', 'studentId', 'studentStoreId', 'status', 'currentVersion', 'rowVersion', 'draftData', 'submittedAt', 'reviewedAt', 'lockedAt', 'score', 'feedback', 'createdAt', 'updatedAt'],
        'student_store_submission_versions' => ['id', 'storeSubmissionId', 'versionNumber', 'storeSnapshotData', 'studentNotes', 'obstacles', 'declarationAccepted', 'createdAt'],
        'student_store_files' => ['id', 'storeSubmissionId', 'kind', 'storageKey', 'quarantineKey', 'originalFileName', 'mimeType', 'sizeBytes', 'sha256', 'scanStatus', 'caption', 'position', 'createdAt'],
        'store_checklist_responses' => ['id', 'storeSubmissionId', 'itemId', 'completed', 'value', 'comment', 'updatedAt'],
        'store_reviews' => ['id', 'storeSubmissionId', 'reviewerId', 'rubricId', 'decision', 'totalScore', 'feedback', 'isPublished', 'createdAt'],
        'store_review_scores' => ['id', 'reviewId', 'criterionId', 'score', 'comment'],
        'store_fraud_flags' => ['id', 'storeSubmissionId', 'type', 'reason', 'evidence', 'confidence', 'status', 'resolvedById', 'resolutionNotes', 'resolvedAt', 'createdAt'],
    ];
    private const DATES = ['createdAt', 'updatedAt', 'createdDate', 'submittedAt', 'reviewedAt', 'lockedAt', 'resolvedAt'];

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
        if (($payload['format'] ?? null) !== 'sunrise-student-store-staging-v1' || ! is_array($payload['tables'] ?? null)
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
                    if (($value === null && in_array($field, ['createdAt', 'updatedAt'], true))
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
            });
        } catch (Throwable) {
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi dan kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Toko siswa dan riwayatnya terimpor; objek berkas belum disalin.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $schools = DB::table('schools')->pluck('organization_id', 'id')->all();
        $students = DB::table('students')->pluck('school_id', 'id')->all();
        $courses = DB::table('courses')->pluck('school_id', 'id')->all();
        $assignments = DB::table('assignments')->get(['id', 'course_id', 'organization_id', 'checklist_template_id'])->keyBy('id');
        $teachers = DB::table('teachers')->pluck('school_id', 'id')->all();
        $rubrics = DB::table('rubrics')->pluck('organization_id', 'id')->all();
        $criteria = DB::table('rubric_criteria')->pluck('rubric_id', 'id')->all();
        $users = DB::table('users')->get(['id', 'organization_id', 'school_id'])->keyBy('id');
        $templates = collect($tables['store_checklist_templates'])->keyBy('id');
        $items = collect($tables['store_checklist_items'])->keyBy('id');
        $stores = collect($tables['student_stores'])->keyBy('id');
        $submissions = collect($tables['student_store_submissions'])->keyBy('id');
        $reviews = collect($tables['store_reviews'])->keyBy('id');
        $pairs = [];
        foreach ($tables['store_checklist_templates'] as $row) {
            if (($schools[$row['schoolId'] ?? ''] ?? null) !== $row['organizationId']
                || ! $this->actor($row['createdById'], $row['organizationId'], $row['schoolId'], $users)) $issues[] = 'Checklist template lintas organisasi/sekolah.';
        }
        foreach ($assignments as $assignment) {
            if ($assignment->checklist_template_id !== null
                && (($templates[$assignment->checklist_template_id]['organizationId'] ?? null) !== $assignment->organization_id
                    || ($templates[$assignment->checklist_template_id]['schoolId'] ?? null) !== ($courses[$assignment->course_id] ?? null))) {
                $issues[] = 'Checklist tugas tidak sesuai organisasi/sekolah.';
            }
        }
        foreach ($tables['store_checklist_items'] as $row) if (! isset($templates[$row['templateId'] ?? ''])) $issues[] = 'Butir checklist tanpa template.';
        foreach ($tables['student_stores'] as $row) {
            if (($schools[$row['schoolId'] ?? ''] ?? null) !== $row['organizationId']
                || ($students[$row['studentId'] ?? ''] ?? null) !== $row['schoolId']) $issues[] = 'Toko siswa lintas sekolah.';
        }
        foreach ($tables['student_store_submissions'] as $row) {
            $assignment = $assignments[$row['assignmentId'] ?? ''] ?? null;
            $store = $row['studentStoreId'] === null ? null : ($stores[$row['studentStoreId']] ?? null);
            $key = ($row['assignmentId'] ?? '').':'.($row['studentId'] ?? '');
            if (($schools[$row['schoolId'] ?? ''] ?? null) !== $row['organizationId']
                || ($students[$row['studentId'] ?? ''] ?? null) !== $row['schoolId']
                || ! $assignment || $assignment->organization_id !== $row['organizationId']
                || ($courses[$assignment->course_id] ?? null) !== $row['schoolId']
                || ($row['studentStoreId'] !== null && (! $store || $store['studentId'] !== $row['studentId'] || $store['schoolId'] !== $row['schoolId']))
                || isset($pairs[$key])) $issues[] = 'Pengumpulan toko lintas sekolah, tidak punya tugas/toko, atau ganda.';
            $pairs[$key] = true;
        }
        $this->uniqueChild($tables['student_store_submission_versions'], $submissions, 'storeSubmissionId', 'versionNumber', $issues);
        foreach ($tables['student_store_files'] as $row) {
            if (! isset($submissions[$row['storeSubmissionId'] ?? '']) || ! is_int($row['sizeBytes']) || $row['sizeBytes'] < 0
                || ! is_string($row['storageKey']) || $row['storageKey'] === '') $issues[] = 'Berkas toko tanpa pengumpulan atau metadata ukuran/key tidak valid.';
        }
        $this->uniqueChild($tables['store_checklist_responses'], $submissions, 'storeSubmissionId', 'itemId', $issues);
        foreach ($tables['store_checklist_responses'] as $row) {
            $submission = $submissions[$row['storeSubmissionId'] ?? ''] ?? null;
            $assignment = $submission ? ($assignments[$submission['assignmentId']] ?? null) : null;
            if (! isset($items[$row['itemId'] ?? '']) || ! $assignment
                || $items[$row['itemId']]['templateId'] !== $assignment->checklist_template_id) $issues[] = 'Respons checklist tidak cocok dengan template tugas.';
        }
        foreach ($tables['store_reviews'] as $row) {
            $submission = $submissions[$row['storeSubmissionId'] ?? ''] ?? null;
            if (! $submission || ($teachers[$row['reviewerId'] ?? ''] ?? null) !== $submission['schoolId']
                || ($row['rubricId'] !== null && ($rubrics[$row['rubricId']] ?? null) !== $submission['organizationId'])) $issues[] = 'Review toko lintas sekolah atau rubrik.';
        }
        $this->uniqueChild($tables['store_review_scores'], $reviews, 'reviewId', 'criterionId', $issues);
        foreach ($tables['store_review_scores'] as $row) {
            if (! isset($criteria[$row['criterionId'] ?? ''])
                || ($criteria[$row['criterionId']] ?? null) !== ($reviews[$row['reviewId'] ?? '']['rubricId'] ?? null)) $issues[] = 'Skor review memakai kriteria rubrik lain.';
        }
        foreach ($tables['store_fraud_flags'] as $row) {
            $submission = $submissions[$row['storeSubmissionId'] ?? ''] ?? null;
            if (! $submission || ! $this->actor($row['resolvedById'], $submission['organizationId'], $submission['schoolId'], $users)) $issues[] = 'Flag fraud tanpa pengumpulan atau resolver lintas sekolah.';
        }
    }

    private function actor(?string $id, string $organization, string $school, $users): bool
    {
        if ($id === null) return true;
        $user = $users[$id] ?? null;
        return $user !== null && $user->organization_id === $organization && ($user->school_id === null || $user->school_id === $school);
    }

    private function uniqueChild(array $rows, $parents, string $parentField, string $uniqueField, array &$issues): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = ($row[$parentField] ?? '').':'.($row[$uniqueField] ?? '');
            if (! isset($parents[$row[$parentField] ?? '']) || isset($seen[$key])) $issues[] = 'Relasi anak toko tidak valid atau ganda.';
            $seen[$key] = true;
        }
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if (in_array($field, ['draftData', 'storeSnapshotData', 'evidence'], true) && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
