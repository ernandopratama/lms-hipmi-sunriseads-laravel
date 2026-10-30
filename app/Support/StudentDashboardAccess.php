<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class StudentDashboardAccess
{
    public function resolve(string $organizationId, ?string $schoolId, string $userId, ?string $studentId = null): ?array
    {
        $free = DB::table('access_plans')->where('code', 'FREE')->first();
        if (! $free) return null; // An unimported plan must never imply access.

        $classes = $studentId && $schoolId ? DB::table('class_enrollments')->where('student_id', $studentId)
            ->where('school_id', $schoolId)->where('status', 'ACTIVE')->pluck('class_id') : collect();
        $programs = $studentId && $schoolId ? DB::table('course_enrollments as e')->join('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.student_id', $studentId)->where('e.school_id', $schoolId)
            ->where('e.status', 'ACTIVE')->where('c.school_id', $schoolId)
            ->where('c.organization_id', $organizationId)->whereNotNull('c.program_id')->pluck('c.program_id') : collect();

        $grants = DB::table('access_grants as g')->join('access_plans as p', 'p.id', '=', 'g.access_plan_id')
            ->where('g.organization_id', $organizationId)
            ->whereIn('g.status', ['ACTIVE', 'SCHEDULED'])
            ->where('g.starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('g.ends_at')->orWhere('g.ends_at', '>', now()))
            ->where(function ($q) use ($schoolId, $userId, $classes, $programs) {
                $q->where('g.target_type', 'ORGANIZATION')
                    ->orWhere(fn ($u) => $u->where('g.target_type', 'USER')->where('g.user_id', $userId));
                if ($schoolId) $q->orWhere(fn ($s) => $s->where('g.target_type', 'SCHOOL')->where('g.school_id', $schoolId));
                if ($classes->isNotEmpty()) $q->orWhere(fn ($c) => $c->where('g.target_type', 'CLASS')->whereIn('g.class_id', $classes));
                if ($programs->isNotEmpty()) $q->orWhere(fn ($p) => $p->where('g.target_type', 'PROGRAM')->whereIn('g.program_id', $programs));
            })
            ->select('g.*', 'p.rank', 'p.module_limit', 'p.code', 'p.name', 'p.status as plan_status')
            ->get()->sort(function ($a, $b) {
                $priority = ['ORGANIZATION' => 1, 'SCHOOL' => 2, 'CLASS' => 3, 'PROGRAM' => 3, 'USER' => 4];
                return ($priority[$b->target_type] ?? 0) <=> ($priority[$a->target_type] ?? 0)
                    ?: $b->rank <=> $a->rank
                    ?: strcmp($b->starts_at, $a->starts_at);
            })->first();

        $plan = $grants ?: $free;
        $entitlements = DB::table('plan_entitlements as e')
            ->join('entitlement_definitions as d', 'd.id', '=', 'e.entitlement_definition_id')
            ->where('e.access_plan_id', $grants?->access_plan_id ?? $free->id)
            ->where('e.effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('e.effective_to')->orWhere('e.effective_to', '>', now()))
            ->orderByDesc('e.effective_from')->get(['d.key', 'e.enabled', 'e.value_json'])
            ->unique('key')->mapWithKeys(fn ($row) => [$row->key => $row->value_json === null
                ? (bool) $row->enabled : json_decode($row->value_json, true)])->all();

        $limit = min((int) $plan->module_limit, 12);
        return [
            'plan' => ['code' => $plan->code, 'name' => $plan->name, 'rank' => (int) $plan->rank, 'moduleLimit' => $limit],
            'grant' => $grants ? [
                'id' => $grants->id, 'scope' => $grants->target_type, 'source' => $grants->source,
                'startsAt' => $grants->starts_at, 'endsAt' => $grants->ends_at,
            ] : null,
            'source' => $grants ? 'ACCESS_GRANT' : 'DEFAULT_FREE',
            'rank' => (int) $plan->rank, 'moduleLimit' => $limit,
            'entitlements' => $entitlements, 'resolvedAt' => now()->toIso8601String(),
        ];
    }

    public function enabled(array $access, string $key): bool
    {
        $value = $access['entitlements'][$key] ?? false;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return $value > 0;
        return is_array($value) && ($value['enabled'] ?? false) === true;
    }

    public function assignmentTypes(array $access): array
    {
        return [
            ...($this->enabled($access, 'assignments.standard.read') ? ['WRITTEN', 'QUIZ', 'PROJECT', 'MIXED'] : []),
            ...($this->enabled($access, 'student_store.submit') ? ['STORE'] : []),
            ...($this->enabled($access, 'affiliate.content.submit') ? ['AFFILIATE'] : []),
        ];
    }

    public function moduleIds(string $organizationId, string $schoolId, string $studentId, array $access): Collection
    {
        $modules = DB::table('course_modules as m')
            ->join('courses as c', 'c.id', '=', 'm.course_id')
            ->join('course_enrollments as e', 'e.course_id', '=', 'c.id')
            ->leftJoin('module_plan_access as mp', 'mp.module_id', '=', 'm.id')
            ->leftJoin('access_plans as minimum', 'minimum.id', '=', 'mp.minimum_plan_id')
            ->where('e.student_id', $studentId)->where('e.school_id', $schoolId)
            ->where('e.status', 'ACTIVE')->where('c.organization_id', $organizationId)
            ->where('c.school_id', $schoolId)->where('c.is_active', true)
            ->where('m.is_published', true)
            ->orderBy('c.id')->orderBy('m.position')
            ->get(['m.id', 'm.course_id', 'minimum.rank as minimum_rank']);

        $sequences = [];
        return $modules->filter(function ($module) use (&$sequences, $access) {
            $sequences[$module->course_id] = ($sequences[$module->course_id] ?? 0) + 1;
            return $sequences[$module->course_id] <= $access['moduleLimit']
                && $access['rank'] >= ($module->minimum_rank ?? 0);
        })->pluck('id');
    }
}
