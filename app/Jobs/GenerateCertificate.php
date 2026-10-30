<?php

namespace App\Jobs;

use App\Support\StudentDashboardAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $certificateId, public string $requestedBy) {}

    public function handle(StudentDashboardAccess $access): void
    {
        $r = DB::table('certificates')->where('id', $this->certificateId)->first();
        if (! $r || $r->status !== 'QUEUED') return;
        $allowed = DB::table('user_roles as ur')->join('roles as role', 'role.id', '=', 'ur.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'role.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('ur.user_id', $this->requestedBy)
            ->where('u.organization_id', $r->organization_id)->where('u.school_id', $r->school_id)
            ->where(fn ($q) => $q->where('ur.school_id', $r->school_id)->orWhereNull('ur.school_id'))
            ->where(fn ($q) => $q->whereNull('role.organization_id')->orWhere('role.organization_id', $r->organization_id))
            ->where(fn ($q) => $q->whereNull('p.organization_id')->orWhere('p.organization_id', $r->organization_id))
            ->where('role.is_active', true)->where('p.resource', 'certificates')->where('p.action', 'manage')->exists();
        $plan = $access->resolve($r->organization_id, $r->school_id, $this->requestedBy);
        if (! $allowed || ! $plan || ! $access->enabled($plan, 'support.advanced.use')) {
            DB::table('certificates')->where('id', $r->id)->update(['status' => 'FAILED',
                'failure_reason' => 'ACCESS_REVOKED_BEFORE_PROCESSING', 'updated_at' => now()]);
            return;
        }
        try {
            $student = DB::table('students')->where('id', $r->student_id)->firstOrFail();
            $school = DB::table('schools')->where('id', $r->school_id)->firstOrFail();
            $issuedAt = now();
            $key = $r->organization_id.'/certificates/'.$r->id.'/'.$r->verification_code.'.pdf';
            Storage::disk('certificates')->put($key, self::pdf($r->title, $student->full_name,
                $school->name, $r->verification_code, $issuedAt->format('d-m-Y')));
            DB::transaction(function () use ($r, $student, $issuedAt, $key) {
                $changed = DB::table('certificates')->where('id', $r->id)->where('status', 'QUEUED')
                    ->update(['status' => 'ISSUED', 'pdf_storage_key' => $key, 'issued_at' => $issuedAt,
                        'failure_reason' => null, 'updated_at' => now()]);
                if (! $changed) return;
                $notificationId = (string) Str::ulid();
                DB::table('notifications')->insert(['id' => $notificationId, 'organization_id' => $r->organization_id,
                    'school_id' => $r->school_id, 'user_id' => $student->user_id, 'event_type' => 'CERTIFICATE_ISSUED',
                    'title' => 'Sertifikat baru telah terbit', 'body' => 'Sertifikat '.$r->title.' sudah tersedia untuk diunduh.',
                    'action_url' => '/dashboard/dukungan', 'idempotency_key' => 'certificate-issued:'.$r->id,
                    'created_at' => now()]);
                DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(),
                    'notification_id' => $notificationId, 'channel' => 'IN_APP', 'status' => 'SENT',
                    'provider' => 'INTERNAL', 'sent_at' => now(), 'scheduled_at' => now(),
                    'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            DB::table('certificates')->where('id', $r->id)->where('status', 'QUEUED')->update([
                'status' => 'FAILED', 'failure_reason' => Str::limit($e->getMessage(), 180, ''), 'updated_at' => now()]);
            throw $e;
        }
    }

    private static function pdf(string $title, string $student, string $school, string $code, string $date): string
    {
        $lines = [['SUNRISE ACADEMY', 16, 500], ['SERTIFIKAT', 30, 430], [$title, 18, 375],
            ['Diberikan kepada', 12, 320], [$student, 24, 275], [$school, 14, 235],
            ['Diterbitkan '.$date, 11, 155], ['Kode verifikasi: '.$code, 10, 125],
            ['Verifikasi keaslian sertifikat melalui portal Sunrise Academy.', 9, 95]];
        $stream = "0.98 0.97 0.92 rg 0 0 842 595 re f\n0.94 0.45 0.12 RG 5 w 24 24 794 547 re S\n";
        foreach ($lines as [$value, $size, $y]) {
            $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
            $value = preg_replace('/[^\x20-\x7E]/', '', $value);
            $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
            $stream .= "BT /F1 $size Tf 70 $y Td ($value) Tj ET\n";
        }
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $out = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $out .= sprintf('%010d 00000 n ', $offset)."\n";
        return $out.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }
}
