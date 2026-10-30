<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportAffiliateStaging extends Command
{
    protected $signature = 'sunrise:import-affiliate {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import affiliate accounts, videos, versions and correction history';

    private const FIELDS = [
        'affiliate_accounts' => ['id', 'organizationId', 'schoolId', 'studentId', 'platform', 'username', 'displayName', 'profileUrl', 'externalAccountId', 'status', 'connectedAt', 'reviewerNotes', 'reviewedById', 'reviewedAt', 'createdAt', 'updatedAt'],
        'affiliate_account_reviews' => ['id', 'accountId', 'reviewerId', 'decision', 'notes', 'createdAt'],
        'affiliate_videos' => ['id', 'submissionId', 'studentId', 'affiliateAccountId', 'platform', 'videoUrl', 'normalizedUrl', 'externalId', 'publishedAt', 'caption', 'hook', 'productName', 'productExternalId', 'productUrl', 'campaignName', 'screenshot', 'evidenceUrl', 'insightsEvidenceUrl', 'dataSource', 'verification', 'studentNote', 'disclosureAccepted', 'ownershipDeclared', 'currentVersion', 'createdAt', 'updatedAt'],
        'affiliate_content_versions' => ['id', 'affiliateVideoId', 'versionNumber', 'snapshotData', 'studentNote', 'createdAt'],
        'affiliate_metric_snapshots' => ['id', 'organizationId', 'schoolId', 'studentId', 'affiliateVideoId', 'affiliateAccountId', 'importJobId', 'createdById', 'platform', 'views', 'likes', 'comments', 'shares', 'clicks', 'ctr', 'orders', 'unitsSold', 'refunds', 'gmv', 'conversionRate', 'averageOrderValue', 'commission', 'commissionRate', 'currency', 'snapshotDate', 'periodStart', 'periodEnd', 'source', 'sourceReference', 'evidenceUrl', 'rawValues', 'dataQualityFlags', 'verification', 'verifiedById', 'verifiedAt', 'verificationNotes', 'supersedesId', 'createdAt'],
    ];
    private const DATES = ['connectedAt', 'reviewedAt', 'createdAt', 'updatedAt', 'publishedAt', 'snapshotDate', 'periodStart', 'periodEnd', 'verifiedAt'];
    private const REQUIRED_DATES = ['connectedAt', 'createdAt', 'updatedAt', 'publishedAt', 'snapshotDate'];

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
        if (($payload['format'] ?? null) !== 'sunrise-affiliate-staging-v1' || ! is_array($payload['tables'] ?? null)
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
                    $rows = $table === 'affiliate_metric_snapshots' ? $this->orderedSnapshots($tables[$table]) : $tables[$table];
                    foreach (array_chunk($rows, 250) as $chunk) {
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
            $this->error('Impor gagal; seluruh transaksi dibatalkan. Periksa relasi atau kapasitas field staging.');
            return self::FAILURE;
        }
        $this->info('Data affiliate dan riwayat koreksi terimpor; status verifikasi dipertahankan.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $schools = DB::table('schools')->pluck('organization_id', 'id')->all();
        $studentProfiles = DB::table('students')->get(['id', 'school_id', 'user_id'])->keyBy('id');
        $students = $studentProfiles->pluck('school_id', 'id')->all();
        $users = DB::table('users')->get(['id', 'organization_id', 'school_id'])->keyBy('id');
        $submissions = DB::table('submissions')->get(['id', 'student_id', 'user_id', 'assignment_id'])->keyBy('id');
        $assignments = DB::table('assignments')->get(['id', 'course_id'])->keyBy('id');
        $courses = DB::table('courses')->pluck('school_id', 'id')->all();
        $accounts = collect($tables['affiliate_accounts'])->keyBy('id');
        $videos = collect($tables['affiliate_videos'])->keyBy('id');
        $snapshots = collect($tables['affiliate_metric_snapshots'])->keyBy('id');
        $identity = [];
        foreach ($tables['affiliate_accounts'] as $row) {
            $scope = $this->scope($row['organizationId'], $row['schoolId'], $row['studentId'], $schools, $students);
            $key = $row['studentId'].'|'.$row['platform'].'|'.$row['username'];
            if (! $scope || isset($identity[$key]) || ! $this->validActor($row['reviewedById'], $row['organizationId'], $row['schoolId'], $users)) $issues[] = 'Akun affiliate memiliki cakupan atau reviewer tidak valid.';
            $identity[$key] = true;
        }
        foreach ($tables['affiliate_account_reviews'] as $row) {
            $account = $accounts[$row['accountId'] ?? ''] ?? null;
            if (! $account || ! is_string($row['reviewerId']) || $row['reviewerId'] === ''
                || ! $this->validActor($row['reviewerId'], $account['organizationId'], $account['schoolId'], $users)) $issues[] = 'Riwayat review akun memiliki akun atau reviewer tidak valid.';
        }
        $versions = [];
        foreach ($tables['affiliate_videos'] as $row) {
            $submission = $submissions[$row['submissionId'] ?? ''] ?? null;
            $assignment = $submission ? ($assignments[$submission->assignment_id] ?? null) : null;
            $school = $assignment ? ($courses[$assignment->course_id] ?? null) : null;
            $account = $row['affiliateAccountId'] === null ? null : ($accounts[$row['affiliateAccountId']] ?? null);
            if (! $submission || ! $school || ($students[$row['studentId'] ?? ''] ?? null) !== $school
                || ($submission->student_id !== null ? $submission->student_id !== $row['studentId'] : ($studentProfiles[$row['studentId']]->user_id ?? null) !== $submission->user_id)
                || ($account && ($account['studentId'] !== $row['studentId'] || $account['platform'] !== $row['platform']))
                || ($row['affiliateAccountId'] !== null && ! $account) || ! is_int($row['currentVersion']) || $row['currentVersion'] < 1
                || ! is_bool($row['disclosureAccepted']) || ! is_bool($row['ownershipDeclared'])) {
                $issues[] = 'Video affiliate memiliki pengumpulan, akun atau sekolah yang tidak cocok.';
            }
        }
        foreach ($tables['affiliate_content_versions'] as $row) {
            $key = ($row['affiliateVideoId'] ?? '').':'.($row['versionNumber'] ?? '');
            if (! isset($videos[$row['affiliateVideoId'] ?? '']) || ! is_int($row['versionNumber']) || $row['versionNumber'] < 1
                || $row['snapshotData'] === null || isset($versions[$key])) $issues[] = 'Versi konten tidak valid atau ganda.';
            $versions[$key] = true;
        }
        foreach ($tables['affiliate_metric_snapshots'] as $row) {
            $video = $videos[$row['affiliateVideoId'] ?? ''] ?? null;
            $account = $row['affiliateAccountId'] === null ? null : ($accounts[$row['affiliateAccountId']] ?? null);
            $parent = $row['supersedesId'] === null ? null : ($snapshots[$row['supersedesId']] ?? null);
            if (! $this->scope($row['organizationId'], $row['schoolId'], $row['studentId'], $schools, $students)
                || ! $video || $video['studentId'] !== $row['studentId'] || $video['platform'] !== $row['platform']
                || ($row['affiliateAccountId'] !== null && (! $account || $account['studentId'] !== $row['studentId'] || $account['platform'] !== $row['platform']))
                || ($row['supersedesId'] !== null && (! $parent || $parent['affiliateVideoId'] !== $row['affiliateVideoId'] || $parent['schoolId'] !== $row['schoolId']))
                || ! $this->validActor($row['verifiedById'], $row['organizationId'], $row['schoolId'], $users)
                || ! $this->validActor($row['createdById'], $row['organizationId'], $row['schoolId'], $users)) {
                $issues[] = 'Snapshot affiliate memiliki cakupan, akun, koreksi atau pemeriksa tidak valid.';
            }
        }
        try {
            $this->orderedSnapshots($tables['affiliate_metric_snapshots']);
        } catch (Throwable) {
            $issues[] = 'Riwayat koreksi snapshot membentuk siklus atau merujuk data yang hilang.';
        }
    }

    private function scope(string $organization, string $school, string $student, array $schools, array $students): bool
    {
        return ($schools[$school] ?? null) === $organization && ($students[$student] ?? null) === $school;
    }

    private function validActor(?string $id, string $organization, string $school, $users): bool
    {
        if ($id === null) return true;
        $actor = $users[$id] ?? null;
        return $actor !== null && $actor->organization_id === $organization && ($actor->school_id === null || $actor->school_id === $school);
    }

    private function orderedSnapshots(array $rows): array
    {
        $pending = collect($rows)->keyBy('id')->all();
        $ordered = [];
        $done = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $row) {
                $parent = $row['supersedesId'];
                if ($parent !== null && ! isset($done[$parent])) continue;
                $ordered[] = $row;
                $done[$id] = true;
                unset($pending[$id]);
                $progress = true;
            }
            if (! $progress) throw new \RuntimeException('Cyclic or missing correction');
        }
        return $ordered;
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if (in_array($field, ['snapshotData', 'rawValues', 'dataQualityFlags'], true) && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
