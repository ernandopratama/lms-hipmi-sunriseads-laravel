<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportAccountsStaging extends Command
{
    protected $signature = 'sunrise:import-accounts {file : Private JSON exported from source} {--baseline= : Source ID baseline} {--apply : Insert into empty staging tables}';

    protected $description = 'Validate and import source accounts, roles, student and teacher profiles';

    private const FIELDS = [
        'roles' => ['id', 'organizationId', 'name', 'slug', 'description', 'isSystem', 'isActive', 'createdAt', 'updatedAt'],
        'permissions' => ['id', 'organizationId', 'resource', 'action', 'description', 'isSystem', 'createdAt', 'updatedAt'],
        'users' => ['id', 'organizationId', 'email', 'username', 'passwordHash', 'firstName', 'lastName', 'phone', 'avatar', 'status', 'emailVerified', 'mfaEnabled', 'mfaSecretWrapped', 'lastLoginAt', 'failedLoginCount', 'lockedUntil', 'activatedAt', 'createdAt', 'updatedAt'],
        'students' => ['id', 'userId', 'schoolId', 'classId', 'studentNumber', 'nisn', 'fullName', 'birthPlace', 'birthDate', 'gender', 'address', 'phone', 'socialMedia', 'affiliateAccount', 'photo', 'status', 'activatedAt', 'createdAt', 'updatedAt'],
        'teachers' => ['id', 'userId', 'schoolId', 'nip', 'fullName', 'phone', 'isActive', 'createdAt', 'updatedAt'],
        'role_permissions' => ['id', 'roleId', 'permissionId', 'createdAt'],
        'user_roles' => ['id', 'userId', 'roleId', 'schoolId', 'createdAt'],
    ];

    private const DATES = ['createdAt', 'updatedAt', 'lastLoginAt', 'lockedUntil', 'activatedAt', 'birthDate'];

    public function handle(): int
    {
        if ($this->option('apply') && app()->environment('production')) {
            $this->error('Impor production ditolak. Gunakan database staging.');
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
        if (($payload['format'] ?? null) !== 'sunrise-accounts-staging-v1' || ! is_array($payload['tables'] ?? null)
            || ($baseline !== null && ! is_array($baseline['tables'] ?? null))) {
            $this->error('Format akun atau baseline tidak sesuai.');
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

        if ($issues === []) $issues = $this->validateRelations($tables);
        if ($issues === []) {
            foreach (array_keys(self::FIELDS) as $table) {
                if (DB::table($table)->exists()) $issues[] = "Tabel tujuan {$table} harus kosong.";
            }
        }
        $this->line(json_encode(['tables' => $summary, 'issues' => array_values(array_unique($issues)), 'baselineCompared' => $baseline !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($issues !== []) return self::FAILURE;
        if (! $this->option('apply')) {
            $this->warn('Pratinjau saja: tidak ada data yang ditulis.');
            return self::SUCCESS;
        }

        try {
            $wrapKey = $this->wrapKey($tables['users']);
            DB::transaction(function () use ($tables, $summary, $wrapKey): void {
                $context = $this->rolesByUser($tables);
                foreach (self::FIELDS as $table => $fields) {
                    foreach (array_chunk($tables[$table], 200) as $chunk) {
                        DB::table($table)->insert(array_map(
                            fn ($row) => $this->mapRow($table, $row, $fields, $context, $wrapKey), $chunk,
                        ));
                    }
                    $hash = hash_init('sha256');
                    $count = 0;
                    foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                        hash_update($hash, $row->id."\n");
                        $count++;
                    }
                    if ($summary[$table] !== ['count' => $count, 'idSha256' => hash_final($hash)]) {
                        throw new \RuntimeException('Post-import ID mismatch');
                    }
                }
            });
        } catch (Throwable) {
            $this->error('Impor akun gagal dan seluruh transaksi dibatalkan. Periksa kunci, relasi, dan skema staging.');
            return self::FAILURE;
        }
        $this->info('Akun/profil sumber terimpor. Role yang belum didukung tetap terkunci dari login staging.');
        return self::SUCCESS;
    }

    private function validateRelations(array $tables): array
    {
        $issues = [];
        $organizations = DB::table('organizations')->pluck('id')->flip();
        $schools = DB::table('schools')->pluck('organization_id', 'id');
        $classes = DB::table('classes')->pluck('school_id', 'id');
        $users = collect($tables['users'])->keyBy('id');
        $roles = collect($tables['roles'])->keyBy('id');
        $permissions = collect($tables['permissions'])->keyBy('id');
        foreach (['users', 'roles', 'permissions'] as $table) {
            foreach ($tables[$table] as $row) {
                if ($row['organizationId'] && ! isset($organizations[$row['organizationId']])) $issues[] = "Organisasi {$table} tidak ada.";
            }
        }
        foreach ($tables['users'] as $row) {
            if (! preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/D', (string) $row['passwordHash'])) {
                $issues[] = 'Hash kata sandi sumber bukan bcrypt yang didukung.';
            }
            if ($row['mfaEnabled'] && ! $row['mfaSecretWrapped']) $issues[] = 'Akun MFA aktif tanpa secret terbungkus.';
            if ($row['mfaSecretWrapped'] && ! is_string($row['mfaSecretWrapped'])) $issues[] = 'Secret MFA terbungkus tidak valid.';
        }
        foreach ($tables['students'] as $row) {
            $user = $users[$row['userId']] ?? null;
            if (! $user || ($schools[$row['schoolId']] ?? null) !== $user['organizationId']
                || ($row['classId'] && ($classes[$row['classId']] ?? null) !== $row['schoolId'])) {
                $issues[] = 'Profil siswa merujuk akun, sekolah, atau kelas yang tidak cocok.';
            }
        }
        foreach ($tables['teachers'] as $row) {
            $user = $users[$row['userId']] ?? null;
            if (! $user || ($schools[$row['schoolId']] ?? null) !== $user['organizationId']) {
                $issues[] = 'Profil guru merujuk akun atau sekolah yang tidak cocok.';
            }
        }
        foreach ($tables['user_roles'] as $row) {
            $user = $users[$row['userId']] ?? null;
            $role = $roles[$row['roleId']] ?? null;
            if (! $user || ! $role || ($role['organizationId'] && $role['organizationId'] !== $user['organizationId'])
                || ($row['schoolId'] && ($schools[$row['schoolId']] ?? null) !== $user['organizationId'])) {
                $issues[] = 'Role akun merujuk organisasi atau sekolah yang tidak cocok.';
            }
        }
        foreach ($tables['role_permissions'] as $row) {
            $role = $roles[$row['roleId']] ?? null;
            $permission = $permissions[$row['permissionId']] ?? null;
            if (! $role || ! $permission || ($permission['organizationId'] && $permission['organizationId'] !== $role['organizationId'])) {
                $issues[] = 'Izin role merujuk lingkup yang tidak cocok.';
            }
        }
        return $issues;
    }

    private function rolesByUser(array $tables): array
    {
        $roles = collect($tables['roles'])->keyBy('id');
        $students = collect($tables['students'])->keyBy('userId');
        $teachers = collect($tables['teachers'])->keyBy('userId');
        $assignments = collect($tables['user_roles'])->groupBy('userId');
        $result = [];
        foreach ($tables['users'] as $user) {
            $grants = $assignments[$user['id']] ?? collect();
            $slugs = $grants->map(fn ($row) => $roles[$row['roleId']]['slug'])->unique()->values()->all();
            $schools = collect([$students[$user['id']]['schoolId'] ?? null, $teachers[$user['id']]['schoolId'] ?? null])
                ->merge($grants->pluck('schoolId'))->filter()->unique()->values();
            $role = 'unmapped';
            if ($slugs === ['student'] && isset($students[$user['id']]) && $schools->count() === 1) $role = 'student';
            if ($slugs === ['teacher'] && isset($teachers[$user['id']]) && $schools->count() === 1) $role = 'teacher';
            if (count($slugs) === 1 && in_array($slugs[0], ['school-admin', 'principal', 'super-admin'], true) && $schools->count() === 1) $role = 'admin';
            $result[$user['id']] = ['role' => $role, 'schoolId' => $schools->count() === 1 ? $schools->first() : null];
        }
        return $result;
    }

    private function wrapKey(array $users): ?string
    {
        if (! collect($users)->contains(fn ($row) => ! empty($row['mfaSecretWrapped']))) return null;
        $hex = getenv('SUNRISE_MIGRATION_WRAP_KEY');
        if (! is_string($hex) || ! preg_match('/^[a-f\d]{64}$/iD', $hex)) {
            throw new \RuntimeException('Missing migration key');
        }
        return hex2bin($hex);
    }

    private function unwrapSecret(string $wrapped, ?string $key): string
    {
        if (! $key) throw new \RuntimeException('Missing migration key');
        $raw = base64_decode($wrapped, true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Invalid wrapped secret');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false || $plain === '') throw new \RuntimeException('Cannot unwrap secret');
        return Crypt::encryptString($plain);
    }

    private function mapRow(string $table, array $row, array $fields, array $context, ?string $wrapKey): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            if (in_array($field, ['passwordHash', 'mfaSecretWrapped'], true)) continue;
            $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
            $value = $row[$field];
            if (in_array($field, self::DATES, true) && $value !== null) {
                $value = CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s.v');
            }
            $mapped[$column] = $value;
        }
        if ($table === 'users') {
            $mapped['name'] = trim($row['firstName'].' '.$row['lastName']);
            // bcryptjs uses the corrected bcrypt 2b variant. PHP's strict algorithm
            // metadata recognizes 2y; the salt/cost/digest are identical. Preserve them.
            $mapped['password'] = in_array(substr($row['passwordHash'], 0, 4), ['$2a$', '$2b$'], true) ? '$2y$'.substr($row['passwordHash'], 4) : $row['passwordHash'];
            $mapped['school_id'] = $context[$row['id']]['schoolId'];
            $mapped['role'] = $context[$row['id']]['role'];
            $mapped['source_status'] = $row['status'];
            $mapped['status'] = strtolower($row['status']);
            $mapped['mfa_secret'] = $row['mfaSecretWrapped'] ? $this->unwrapSecret($row['mfaSecretWrapped'], $wrapKey) : null;
        }
        return $mapped;
    }
}
