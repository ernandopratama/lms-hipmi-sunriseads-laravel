<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportAffiliateReviewsStaging extends Command
{
    protected $signature = 'sunrise:import-affiliate-reviews {file : Private PostgreSQL export JSON} {--baseline= : Source ID baseline} {--apply : Write to empty staging tables}';
    protected $description = 'Validate and import affiliate fraud flags and metric verification history';

    private const FIELDS = [
        'affiliate_fraud_flags' => ['id', 'affiliateVideoId', 'metricSnapshotId', 'type', 'reason', 'evidence', 'confidence', 'status', 'resolvedById', 'resolutionNotes', 'resolvedAt', 'createdAt'],
        'affiliate_metric_verifications' => ['id', 'metricSnapshotId', 'reviewerId', 'decision', 'notes', 'createdAt'],
    ];

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
        if (($payload['format'] ?? null) !== 'sunrise-affiliate-reviews-staging-v1' || ! is_array($payload['tables'] ?? null)
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
                foreach (['createdAt', 'resolvedAt'] as $field) {
                    if (! array_key_exists($field, $row)) continue;
                    $value = $row[$field];
                    if (($field === 'createdAt' && $value === null)
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
        $this->info('Riwayat fraud affiliate dan verifikasi metrik terimpor.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables, array &$issues): void
    {
        $videos = DB::table('affiliate_videos as v')->join('students as st', 'st.id', '=', 'v.student_id')->join('schools as sc', 'sc.id', '=', 'st.school_id')
            ->get(['v.id', 'st.school_id', 'sc.organization_id'])->keyBy('id');
        $snapshots = DB::table('affiliate_metric_snapshots')->get(['id', 'affiliate_video_id', 'school_id', 'organization_id'])->keyBy('id');
        $users = DB::table('users')->get(['id', 'organization_id', 'school_id'])->keyBy('id');
        foreach ($tables['affiliate_fraud_flags'] as $row) {
            $video = $videos[$row['affiliateVideoId'] ?? ''] ?? null;
            $snapshot = $row['metricSnapshotId'] === null ? null : ($snapshots[$row['metricSnapshotId']] ?? null);
            if (! $video || ($row['metricSnapshotId'] !== null && (! $snapshot || $snapshot->affiliate_video_id !== $row['affiliateVideoId']
                    || $snapshot->school_id !== $video->school_id))
                || ($row['resolvedById'] !== null && ! $this->actor($row['resolvedById'], $video->organization_id, $video->school_id, $users))) {
                $issues[] = 'Flag fraud affiliate merujuk video/snapshot/resolver lintas sekolah.';
            }
        }
        foreach ($tables['affiliate_metric_verifications'] as $row) {
            $snapshot = $snapshots[$row['metricSnapshotId'] ?? ''] ?? null;
            if (! $snapshot || ! is_string($row['reviewerId']) || $row['reviewerId'] === ''
                || ! $this->actor($row['reviewerId'], $snapshot->organization_id, $snapshot->school_id, $users)) {
                $issues[] = 'Verifikasi metrik merujuk snapshot atau reviewer lintas sekolah.';
            }
        }
    }

    private function actor(string $id, string $organization, string $school, $users): bool
    {
        $user = $users[$id] ?? null;
        return $user !== null && $user->organization_id === $organization && ($user->school_id === null || $user->school_id === $school);
    }

    private function mapRow(array $row, array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, ['createdAt', 'resolvedAt'], true) && $value !== null) $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            if ($field === 'evidence' && $value !== null) $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mapped[$column] = $value;
        }
        return $mapped;
    }
}
