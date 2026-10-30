<?php

namespace App\Jobs;

use App\Support\IdentifierCrypto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Creates pending students from import rows; port of the student-import worker. */
class ProcessStudentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $importJobId) {}

    public function handle(): void
    {
        $job = DB::table('import_jobs')->where('id', $this->importJobId)->first();
        if (! $job || $job->status !== 'QUEUED') return;
        DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'PROCESSING', 'started_at' => now(), 'updated_at' => now()]);
        $imported = 0;
        $failed = 0;
        try {
            $role = DB::table('roles')->where('organization_id', $job->organization_id)->where('slug', 'student')->where('is_active', true)->first();
            if (! $role) throw new \RuntimeException('Student role is not configured');
            while (($rows = DB::table('import_rows')->where('import_job_id', $job->id)->where('status', 'PENDING')->orderBy('row_number')->limit(200)->get())->isNotEmpty()) {
                foreach ($rows as $row) {
                    try {
                        $this->importRow($job, $role, $row);
                        $imported++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $code = preg_match('/^[A-Z_]+$/', $e->getMessage()) ? $e->getMessage() : 'ROW_IMPORT_FAILED';
                        DB::table('import_rows')->where('id', $row->id)->update(['status' => 'FAILED', 'errors' => json_encode(['codes' => [$code]]), 'updated_at' => now()]);
                    }
                }
                DB::table('import_jobs')->where('id', $job->id)->update(['processed_rows' => $imported + $failed, 'valid_rows' => $imported, 'failed_rows' => $failed, 'updated_at' => now()]);
            }
            DB::table('import_jobs')->where('id', $job->id)->update(['status' => $failed > 0 ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED', 'processed_rows' => $imported + $failed,
                'valid_rows' => $imported, 'failed_rows' => $failed, 'completed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('import_jobs')->where('id', $job->id)->update(['status' => 'FAILED', 'failure_reason' => 'IMPORT_PROCESSING_FAILED', 'completed_at' => now(), 'updated_at' => now()]);
            throw $e;
        }
    }

    private function importRow(object $job, object $role, object $row): void
    {
        $p = json_decode($row->payload, true) ?: [];
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        $number = trim((string) ($p['studentNumber'] ?? ''));
        $name = trim((string) ($p['fullName'] ?? ''));
        $nisn = ! empty($p['nisnEncrypted']) ? trim(IdentifierCrypto::decrypt((string) $p['nisnEncrypted'])) : null;
        if ($email === '' || $number === '' || $name === '') throw new \RuntimeException('REQUIRED_FIELDS_MISSING');
        $dupe = DB::table('students as s')->join('users as u', 'u.id', '=', 's.user_id')->where(fn ($q) => $q->where(fn ($q) => $q->where('s.school_id', $job->school_id)->where('s.student_number', $number))->orWhere('u.email', $email))->exists()
            || DB::table('users')->where('email', $email)->exists()
            || ($nisn && DB::table('student_identifiers')->where('type', 'NISN')->where('blind_index', IdentifierCrypto::blindIndex($nisn, 'NISN'))->exists());
        if ($dupe) throw new \RuntimeException('DUPLICATE_STUDENT');
        $class = ! empty($p['classCode']) ? DB::table('classes')->where('school_id', $job->school_id)->where('code', (string) $p['classCode'])->where('is_active', true)->first() : null;
        if (! empty($p['classCode']) && ! $class) throw new \RuntimeException('CLASS_NOT_FOUND');

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0];
        $last = implode(' ', array_slice($parts, 1)) ?: '-';
        DB::transaction(function () use ($job, $role, $row, $p, $email, $number, $name, $nisn, $class, $token, $first, $last): void {
            $userId = (string) Str::ulid();
            DB::table('users')->insert(['id' => $userId, 'organization_id' => $job->organization_id, 'school_id' => $job->school_id, 'name' => $name, 'first_name' => $first, 'last_name' => $last,
                'email' => $email, 'password' => Hash::make(bin2hex(random_bytes(32))), 'role' => 'student', 'status' => 'pending_activation', 'source_status' => 'PENDING_ACTIVATION',
                'created_at' => now(), 'updated_at' => now()]);
            $studentId = (string) Str::ulid();
            DB::table('students')->insert(['id' => $studentId, 'user_id' => $userId, 'school_id' => $job->school_id, 'class_id' => $class->id ?? null, 'student_number' => $number,
                'full_name' => $name, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
            if ($nisn) DB::table('student_identifiers')->insert(['id' => (string) Str::ulid(), 'student_id' => $studentId, 'type' => 'NISN', 'value_encrypted' => (string) $p['nisnEncrypted'],
                'blind_index' => IdentifierCrypto::blindIndex($nisn, 'NISN'), 'last4' => (string) ($p['nisnLast4'] ?? substr($nisn, -4)), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $userId, 'role_id' => $role->id, 'school_id' => $job->school_id, 'created_at' => now()]);
            DB::table('invitations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $job->organization_id, 'school_id' => $job->school_id, 'role_id' => $role->id, 'user_id' => $userId,
                'invited_by_id' => $job->created_by_id, 'email' => $email, 'first_name' => $first, 'last_name' => $last, 'token_hash' => hash('sha256', $token),
                'profile_data' => json_encode(['studentNumber' => $number, 'fullName' => $name, 'classId' => $class->id ?? null]), 'expires_at' => now()->addHours(48), 'created_at' => now()]);
            if ($class) DB::table('class_enrollments')->insert(['id' => (string) Str::ulid(), 'student_id' => $studentId, 'class_id' => $class->id, 'school_id' => $job->school_id,
                'status' => 'ACTIVE', 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $safe = ['email' => $email, 'studentNumber' => $number, 'fullName' => $name] + (! empty($p['classCode']) ? ['classCode' => (string) $p['classCode']] : [])
                + ($nisn ? ['nisnLast4' => (string) ($p['nisnLast4'] ?? substr($nisn, -4))] : []);
            DB::table('import_rows')->where('id', $row->id)->update(['status' => 'IMPORTED', 'errors' => null, 'payload' => json_encode($safe), 'updated_at' => now()]);
        });
    }
}
