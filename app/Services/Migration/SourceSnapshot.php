<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Authenticated snapshot decoding and validation, without database mutations. */
class SourceSnapshot
{
    public function manifest(): array
    {
        return json_decode(file_get_contents(config_path('source-schema.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function key(): string
    {
        $hex = getenv('SUNRISE_MIGRATION_WRAP_KEY');
        if (! is_string($hex) || ! preg_match('/^[a-f0-9]{64}$/iD', $hex)) throw new RuntimeException('Kunci migrasi harus 64 karakter hex.');
        return hex2bin($hex);
    }

    public function unwrap(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Ciphertext migrasi tidak valid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Kunci migrasi salah atau snapshot telah berubah.');
        return $plain;
    }

    public function read(string $file): array
    {
        if (! is_file($file) || ! is_readable($file)) throw new RuntimeException('Snapshot tidak dapat dibaca.');
        $envelope = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($envelope['format'] ?? null) !== 'sunrise-encrypted-snapshot-v1'
            || ($envelope['algorithm'] ?? null) !== 'aes-256-gcm' || ! is_string($envelope['payload'] ?? null)) {
            throw new RuntimeException('Format snapshot terenkripsi tidak valid.');
        }
        $snapshot = json_decode($this->unwrap($envelope['payload'], $this->key()), true, 512, JSON_THROW_ON_ERROR);
        $this->validate($snapshot);
        return $snapshot;
    }

    public function validate(array $snapshot): void
    {
        $manifest = $this->manifest();
        if (($snapshot['format'] ?? null) !== 'sunrise-full-snapshot-v1'
            || ($snapshot['schemaSha256'] ?? null) !== $manifest['schemaSha256']
            || ! is_array($snapshot['tables'] ?? null) || ! is_array($snapshot['baseline']['tables'] ?? null)
            || ! $this->date($snapshot['exportedAt'] ?? null)) throw new RuntimeException('Versi atau struktur snapshot tidak sesuai.');
        $tables = $snapshot['tables'];
        if (array_diff_key($manifest['tables'], $tables) || array_diff_key($tables, $manifest['tables'])) {
            throw new RuntimeException('Snapshot harus memuat seluruh tabel sumber.');
        }
        $indexes = [];
        foreach ($manifest['tables'] as $table => $spec) {
            $rows = $tables[$table];
            if (! is_array($rows) || ! array_is_list($rows)) throw new RuntimeException("Daftar baris {$table} tidak valid.");
            $ids = []; $unique = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || strlen($row['id']) > 191 || isset($ids[$row['id']])) {
                    throw new RuntimeException("ID {$table} kosong, ganda, atau tidak valid.");
                }
                $ids[$row['id']] = true;
                foreach ($spec['fields'] as $field) {
                    $name = $field['name'];
                    if ($table === 'users' && $name === 'mfaSecret') $name = 'mfaSecretWrapped';
                    if (! array_key_exists($name, $row)) throw new RuntimeException("Kolom {$table}.{$name} tidak tersedia.");
                    $value = $row[$name];
                    if ($value === null) {
                        if ($field['required']) throw new RuntimeException("Kolom wajib {$table}.{$name} kosong.");
                        continue;
                    }
                    $wrapped = in_array($name, ['nikEncrypted', 'valueEncrypted'], true);
                    $valid = $wrapped ? (is_array($value) && is_string($value['wrapped'] ?? null))
                        : ($field['list'] ? is_array($value) : match ($field['type']) {
                            'String' => is_string($value),
                            'Int' => is_int($value),
                            'BigInt' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value)),
                            'Boolean' => is_bool($value),
                            'Decimal' => (is_string($value) || is_int($value) || is_float($value)) && is_numeric($value),
                            'Float' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                            'DateTime' => $this->date($value),
                            'Json' => true,
                            default => in_array($value, $manifest['enums'][$field['type']] ?? [], true),
                        });
                    if (! $valid) throw new RuntimeException("Tipe {$table}.{$name} tidak valid.");
                    if ($wrapped) $this->unwrap($value['wrapped'], $this->key());
                    if ($name === 'mfaSecretWrapped') $this->unwrap($value, $this->key());
                    if ($field['unique']) $this->unique($unique, [$name], $row, $table);
                }
                if ($spec['primaryKey'] !== ['id']) {
                    $keyValues = array_map(fn ($name) => $row[$name], $spec['primaryKey']);
                    $expectedId = count($keyValues) === 1 ? $keyValues[0] : hash('sha256', json_encode($keyValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    if ($row['id'] !== $expectedId) throw new RuntimeException("Identitas komposit {$table} tidak sesuai.");
                }
                foreach ($spec['unique'] as $columns) {
                    $names = array_map(fn ($column) => collect($spec['fields'])->firstWhere('column', $column)['name'], $columns);
                    $this->unique($unique, $names, $row, $table);
                }
                $indexes[$table][$row['id']] = $row;
            }
            $sorted = array_keys($ids); sort($sorted, SORT_STRING);
            $actual = ['count' => count($sorted), 'idSha256' => hash('sha256', implode('', array_map(fn ($id) => $id."\n", $sorted)))];
            if (($snapshot['baseline']['tables'][$table] ?? null) !== $actual) throw new RuntimeException("Baseline {$table} berbeda.");
        }
        foreach ($manifest['tables'] as $table => $spec) {
            foreach ($tables[$table] as $row) {
                foreach ($spec['relations'] as $relation) {
                    $values = array_map(fn ($name) => $row[$name], $relation['sourceFields']);
                    if (in_array(null, $values, true)) continue;
                    $target = $relation['target'];
                    $found = false;
                    if ($relation['references'] === ['id']) {
                        $found = isset($indexes[$target][$values[0]]);
                    } else {
                        foreach ($tables[$target] as $candidate) {
                            $found = true;
                            foreach ($relation['references'] as $i => $column) {
                                $name = collect($manifest['tables'][$target]['fields'])->firstWhere('column', $column)['name'];
                                if ($candidate[$name] !== $values[$i]) { $found = false; break; }
                            }
                            if ($found) break;
                        }
                    }
                    if (! $found) throw new RuntimeException("Relasi {$table} ke {$target} terputus.");
                }
                $school = $indexes['schools'][$row['schoolId'] ?? ''] ?? null;
                if ($school && isset($row['organizationId']) && $row['organizationId'] !== $school['organizationId']) throw new RuntimeException("Lingkup organisasi/sekolah {$table} berbeda.");
                foreach (['studentId' => 'students', 'classId' => 'classes', 'teacherId' => 'teachers'] as $field => $target) {
                    $parent = $indexes[$target][$row[$field] ?? ''] ?? null;
                    if ($parent && $school && isset($parent['schoolId']) && $parent['schoolId'] !== $school['id']) throw new RuntimeException("Relasi sekolah {$table} berbeda.");
                    if ($parent && isset($parent['schoolId'], $row['organizationId'])) {
                        $parentSchool = $indexes['schools'][$parent['schoolId']] ?? null;
                        if ($parentSchool && $parentSchool['organizationId'] !== $row['organizationId']) throw new RuntimeException("Relasi organisasi {$table} berbeda.");
                    }
                }
            }
        }
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?Z$/D', $value)) return false;
        try { $date = new \DateTimeImmutable($value); return $date->format('Y-m-d') === substr($value, 0, 10); } catch (\Throwable) { return false; }
    }

    private function unique(array &$seen, array $names, array $row, string $table): void
    {
        $values = array_map(fn ($name) => $row[$name] ?? null, $names);
        if (in_array(null, $values, true)) return; // PostgreSQL/MySQL nullable unique semantics.
        $key = implode(',', $names).':'.json_encode($values, JSON_THROW_ON_ERROR);
        if (isset($seen[$key])) throw new RuntimeException("Kunci unik {$table} ganda.");
        $seen[$key] = true;
    }

    public function forArchive(string $table, array $row): array
    {
        foreach (['nikEncrypted', 'valueEncrypted'] as $name) {
            if (is_array($row[$name] ?? null)) $row[$name] = Crypt::encryptString($this->unwrap($row[$name]['wrapped'], $this->key()));
        }
        if ($table === 'users') {
            $row['mfaSecret'] = $row['mfaSecretWrapped'] ? Crypt::encryptString($this->unwrap($row['mfaSecretWrapped'], $this->key())) : null;
            unset($row['mfaSecretWrapped']);
        }
        return $row;
    }
}
