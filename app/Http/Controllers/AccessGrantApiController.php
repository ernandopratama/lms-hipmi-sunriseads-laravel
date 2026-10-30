<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessGrantApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'access-grants', 'manage');
        $d = $request->validate([
            'targetType' => 'required|in:ORGANIZATION,SCHOOL,PROGRAM,CLASS,USER',
            'planCode' => 'required|in:FREE,MEDIUM,ADVANCED',
            'schoolId' => 'nullable|string|max:191', 'programId' => 'nullable|string|max:191',
            'classId' => 'nullable|string|max:191', 'userId' => 'nullable|string|max:191',
            'source' => 'required|in:MANUAL,PARTNERSHIP,SCHOLARSHIP,PILOT,INSTITUTIONAL_INVOICE,MIGRATION',
            'startsAt' => 'nullable|date', 'endsAt' => 'nullable|date', 'reason' => 'required|string|min:5|max:500',
        ]);
        $starts = isset($d['startsAt']) ? CarbonImmutable::parse($d['startsAt'], 'UTC') : CarbonImmutable::now('UTC');
        $ends = isset($d['endsAt']) ? CarbonImmutable::parse($d['endsAt'], 'UTC') : null;
        abort_if($ends && $ends->lessThanOrEqualTo($starts), 400, 'Tanggal berakhir harus setelah tanggal mulai');
        $reason = trim($d['reason']);
        abort_if(strlen($reason) < 5, 400, 'Alasan perubahan akses wajib diisi');

        $plan = DB::table('access_plans')->where('code', $d['planCode'])->where('status', 'ACTIVE')->first();
        abort_unless($plan, 400, 'Paket akses tidak tersedia');

        $targets = array_filter([$d['schoolId'] ?? null, $d['programId'] ?? null, $d['classId'] ?? null, $d['userId'] ?? null]);
        abort_if($d['targetType'] === 'ORGANIZATION' && $targets, 400, 'Grant organisasi tidak boleh memiliki target tambahan');
        abort_if($d['targetType'] !== 'ORGANIZATION' && count($targets) !== 1, 400, 'Access grant harus memiliki tepat satu target');
        $org = $c['organization'];
        $inOrg = match ($d['targetType']) {
            'SCHOOL' => DB::table('schools')->where('id', $d['schoolId'] ?? null)->where('organization_id', $org)->exists(),
            'PROGRAM' => DB::table('programs')->where('id', $d['programId'] ?? null)->where('organization_id', $org)->exists(),
            'CLASS' => DB::table('classes as k')->join('schools as s', 's.id', '=', 'k.school_id')->where('k.id', $d['classId'] ?? null)->where('s.organization_id', $org)->exists(),
            'USER' => DB::table('users')->where('id', $d['userId'] ?? null)->where('organization_id', $org)->exists(),
            default => true,
        };
        abort_unless($inOrg, 403, 'Target access grant berada di luar organisasi aktif');
        // The source also let a school admin grant organization-wide or other-school access;
        // keep that, since the permission itself is the authority.

        $scheduled = $starts->greaterThan(CarbonImmutable::now('UTC'));
        $status = $scheduled ? 'SCHEDULED' : 'ACTIVE';
        $id = (string) Str::ulid();
        $now = now();
        DB::transaction(function () use ($id, $d, $c, $org, $plan, $status, $starts, $ends, $reason, $scheduled, $now): void {
            DB::table('access_grants')->insert([
                'id' => $id, 'organization_id' => $org,
                'school_id' => $d['targetType'] === 'SCHOOL' ? $d['schoolId'] : null,
                'program_id' => $d['targetType'] === 'PROGRAM' ? $d['programId'] : null,
                'class_id' => $d['targetType'] === 'CLASS' ? $d['classId'] : null,
                'user_id' => $d['targetType'] === 'USER' ? $d['userId'] : null,
                'target_type' => $d['targetType'], 'access_plan_id' => $plan->id, 'source' => $d['source'], 'status' => $status,
                'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason, 'granted_by' => $c['user'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('access_grant_events')->insert(['id' => (string) Str::ulid(), 'access_grant_id' => $id,
                'event_type' => $scheduled ? 'SCHEDULED' : 'CREATED', 'actor_id' => $c['user'], 'reason' => $reason,
                'metadata' => json_encode(['planCode' => $plan->code, 'targetType' => $d['targetType']]), 'created_at' => $now]);
        });
        $g = DB::table('access_grants')->where('id', $id)->first();
        return response()->json([
            'id' => $g->id, 'organizationId' => $g->organization_id, 'schoolId' => $g->school_id, 'programId' => $g->program_id,
            'classId' => $g->class_id, 'userId' => $g->user_id, 'orderItemId' => null, 'targetType' => $g->target_type,
            'accessPlanId' => $g->access_plan_id, 'source' => $g->source, 'status' => $g->status,
            'startsAt' => $this->iso($g->starts_at), 'endsAt' => $this->iso($g->ends_at), 'reason' => $g->reason, 'grantedBy' => $g->granted_by,
            'createdAt' => $this->iso($g->created_at), 'updatedAt' => $this->iso($g->updated_at), 'planCode' => $plan->code,
        ], 201);
    }
}
