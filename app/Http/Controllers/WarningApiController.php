<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarningApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'monitoring', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'dashboards.advanced.read'), 403,
            'Dashboard lanjutan tidak termasuk paket aktif');
        return $c;
    }

    private function studentIds(array $c, ?string $classId = null)
    {
        $students = DB::table('students')->where('school_id', $c['school']);
        if ($classId) {
            abort_unless(DB::table('classes')->where('id', $classId)->where('school_id', $c['school'])->exists(),
                403, 'Kelas di luar ruang lingkup Anda');
            $students->where('class_id', $classId);
        }
        if ($c['student']) return $students->where('id', $c['student']->id)->pluck('id');
        if (in_array('parent', $c['roles'], true)) {
            $parent = DB::table('parents')->where('user_id', $c['user'])->where('is_active', true)->first();
            $ids = $parent ? DB::table('student_parent_links')->where('parent_id', $parent->id)
                ->where('verification_status', 'VERIFIED')->whereNull('revoked_at')
                ->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->pluck('student_id') : collect();
            return $students->whereIn('id', $ids)->pluck('id');
        }
        if ($c['teacher']) {
            $classes = DB::table('teacher_assignments')->where('teacher_id', $c['teacher']->id)
                ->where('school_id', $c['school'])->where('is_active', true)
                ->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->pluck('class_id');
            $ids = DB::table('class_enrollments')->where('school_id', $c['school'])
                ->whereIn('class_id', $classes)->where('status', 'ACTIVE')->pluck('student_id');
            return $students->whereIn('id', $ids)->pluck('id');
        }
        return $students->pluck('id');
    }

    private function output(object $r, bool $private): array
    {
        $s = DB::table('students')->where('id', $r->student_id)->first();
        $class = $s?->class_id ? DB::table('classes')->where('id', $s->class_id)->first() : null;
        $rule = DB::table('early_warning_rules')->where('id', $r->rule_id)->first();
        $base = ['id' => $r->id, 'severity' => $r->severity, 'status' => $r->status,
            'title' => $r->title, 'message' => $r->message, 'triggeredAt' => $r->triggered_at,
            'student' => $s ? ['id' => $s->id, 'fullName' => $s->full_name,
                'studentNumber' => $s->student_number, 'class' => $class ? ['name' => $class->name] : null] : null,
            'rule' => $rule ? ['code' => $rule->code, 'name' => $rule->name, 'description' => $rule->description] : null];
        if ($private) return [...$base, 'assignedTo' => null, 'actions' => []];
        $assigned = $r->assigned_to_id ? DB::table('users')->where('id', $r->assigned_to_id)->first() : null;
        $actions = DB::table('early_warning_actions')->where('early_warning_id', $r->id)
            ->orderByDesc('created_at')->limit(5)->get()->map(function ($a) {
                $actor = DB::table('users')->where('id', $a->actor_id)->first();
                return ['id' => $a->id, 'earlyWarningId' => $a->early_warning_id, 'actorId' => $a->actor_id,
                    'fromStatus' => $a->from_status, 'toStatus' => $a->to_status, 'note' => $a->note,
                    'createdAt' => $a->created_at, 'actor' => $actor ? ['firstName' => $actor->first_name,
                        'lastName' => $actor->last_name] : null];
            });
        return [...$base, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'classId' => $r->class_id, 'studentId' => $r->student_id, 'ruleId' => $r->rule_id,
            'resourceType' => $r->resource_type, 'resourceId' => $r->resource_id,
            'evidence' => json_decode($r->evidence, true), 'assignedToId' => $r->assigned_to_id,
            'acknowledgedAt' => $r->acknowledged_at, 'actionNote' => $r->action_note,
            'resolution' => $r->resolution, 'resolvedAt' => $r->resolved_at,
            'resolvedById' => $r->resolved_by_id, 'lastEvaluatedAt' => $r->last_evaluated_at,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at,
            'assignedTo' => $assigned ? ['id' => $assigned->id, 'firstName' => $assigned->first_name,
                'lastName' => $assigned->last_name] : null, 'actions' => $actions];
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        $d = $request->validate(['classId' => 'sometimes|string', 'studentId' => 'sometimes|string',
            'status' => 'sometimes|in:OPEN,ACKNOWLEDGED,RESOLVED,DISMISSED',
            'periodDays' => 'sometimes|integer|min:7|max:365']);
        $ids = $this->studentIds($c, $d['classId'] ?? null);
        if (isset($d['studentId'])) abort_unless($ids->contains($d['studentId']), 403,
            'Siswa berada di luar ruang lingkup Anda');
        $q = DB::table('early_warnings')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->whereIn('student_id', $ids);
        if (isset($d['studentId'])) $q->where('student_id', $d['studentId']);
        if (isset($d['status'])) $q->where('status', $d['status']);
        else $q->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
        return response()->json($q->orderByDesc('severity')->orderByDesc('triggered_at')->limit(200)->get()
            ->map(fn ($r) => $this->output($r, (bool) ($c['student'] || in_array('parent', $c['roles'], true)))));
    }

    public function update(Request $request, string $id)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['status' => 'required|in:OPEN,ACKNOWLEDGED,RESOLVED,DISMISSED',
            'note' => 'required|string|min:3|max:2000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = DB::table('early_warnings')->where('id', $id)->where('organization_id', $c['organization'])
                ->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($r, 404, 'Early warning tidak ditemukan');
            abort_if($c['student'] || in_array('parent', $c['roles'], true), 403,
                'Hanya petugas pendamping yang dapat memperbarui tindak lanjut');
            abort_if(in_array($r->status, ['RESOLVED', 'DISMISSED']), 400,
                'Early warning sudah memiliki keputusan final');
            abort_unless($this->studentIds($c)->contains($r->student_id), 403,
                'Early warning berada di luar ruang lingkup Anda');
            abort_if($d['status'] === 'OPEN' || $d['status'] === $r->status, 400, 'Status early warning tidak berubah');
            $resolved = in_array($d['status'], ['RESOLVED', 'DISMISSED']);
            DB::table('early_warnings')->where('id', $id)->update(['status' => $d['status'],
                'action_note' => trim($d['note']),
                'acknowledged_at' => $d['status'] === 'ACKNOWLEDGED' ? now() : $r->acknowledged_at,
                'resolved_at' => $resolved ? now() : null, 'resolved_by_id' => $resolved ? $c['user'] : null,
                'resolution' => $resolved ? trim($d['note']) : null, 'updated_at' => now()]);
            DB::table('early_warning_actions')->insert(['id' => (string) Str::ulid(),
                'early_warning_id' => $id, 'actor_id' => $c['user'], 'from_status' => $r->status,
                'to_status' => $d['status'], 'note' => trim($d['note']), 'created_at' => now()]);
            return response()->json($this->output(DB::table('early_warnings')->where('id', $id)->first(), false));
        });
    }
}
