<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AchievementApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'achievements', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'support.advanced.use'), 403,
            'Pencapaian hanya tersedia pada paket Advanced');
        return $c;
    }

    private function students(array $c)
    {
        $query = DB::table('students')->where('school_id', $c['school']);
        if ($c['student']) return $query->where('id', $c['student']->id);
        if (in_array('parent', $c['roles'], true)) {
            $parent = DB::table('parents')->where('user_id', $c['user'])->where('is_active', true)->first();
            $ids = $parent ? DB::table('student_parent_links')->where('parent_id', $parent->id)
                ->where('verification_status', 'VERIFIED')->whereNull('revoked_at')
                ->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->pluck('student_id') : collect();
            return $query->whereIn('id', $ids);
        }
        if ($c['teacher']) {
            $classes = DB::table('teacher_assignments')->where('teacher_id', $c['teacher']->id)
                ->where('school_id', $c['school'])->where('is_active', true)->pluck('class_id');
            $ids = DB::table('class_enrollments')->whereIn('class_id', $classes)->where('school_id', $c['school'])
                ->where('status', 'ACTIVE')->pluck('student_id');
            return $query->whereIn('id', $ids);
        }
        return $query;
    }

    private function achievement(object $r): array
    {
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'code' => $r->code, 'title' => $r->title, 'description' => $r->description,
            'icon' => $r->icon, 'criteria' => json_decode($r->criteria, true),
            'isActive' => (bool) $r->is_active, 'createdById' => $r->created_by_id,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at];
    }

    public function definitions(Request $request)
    {
        $c = $this->scope($request, 'manage');
        return response()->json(DB::table('achievements')->where('organization_id', $c['organization'])
            ->where('is_active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))
            ->orderBy('title')->get()->map(fn ($r) => $this->achievement($r)));
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        $ids = $this->students($c)->pluck('id');
        return response()->json(DB::table('student_achievements')->whereIn('student_id', $ids)
            ->orderByDesc('awarded_at')->get()->map(function ($r) {
                $a = DB::table('achievements')->where('id', $r->achievement_id)->first();
                $s = DB::table('students')->where('id', $r->student_id)->first();
                return ['id' => $r->id, 'studentId' => $r->student_id, 'achievementId' => $r->achievement_id,
                    'awardedById' => $r->awarded_by_id, 'evidence' => $r->evidence ? json_decode($r->evidence, true) : null,
                    'awardedAt' => $r->awarded_at, 'achievement' => $a ? $this->achievement($a) : null,
                    'student' => $s ? ['fullName' => $s->full_name, 'studentNumber' => $s->student_number] : null];
            }));
    }

    public function store(Request $request)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['code' => 'required|string|min:2|max:40', 'title' => 'required|string|min:3|max:120',
            'description' => 'required|string|min:3|max:1000', 'icon' => 'sometimes|string|max:191',
            'criteria' => 'present|array']);
        $code = preg_replace('/[^A-Z0-9_-]/', '', strtoupper($d['code']));
        abort_if($code === '' || DB::table('achievements')->where('organization_id', $c['organization'])
            ->where('code', $code)->exists(), 409, 'Kode pencapaian sudah digunakan');
        $id = (string) Str::ulid();
        DB::table('achievements')->insert(['id' => $id, 'organization_id' => $c['organization'],
            'school_id' => $c['school'], 'created_by_id' => $c['user'], 'code' => $code,
            'title' => trim($d['title']), 'description' => trim($d['description']),
            'icon' => $d['icon'] ?? 'AWARD', 'criteria' => json_encode($d['criteria']),
            'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->achievement(DB::table('achievements')->where('id', $id)->first()), 201);
    }

    public function award(Request $request, string $id)
    {
        $c = $this->scope($request, 'manage');
        $d = $request->validate(['studentId' => 'required|string', 'evidence' => 'sometimes|array']);
        $a = DB::table('achievements')->where('id', $id)->where('organization_id', $c['organization'])
            ->where('is_active', true)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))->first();
        $s = DB::table('students')->where('id', $d['studentId'])->where('school_id', $c['school'])->first();
        abort_unless($a && $s, 404, 'Pencapaian atau siswa tidak ditemukan');
        $award = DB::transaction(function () use ($c, $d, $a, $s) {
            $existing = DB::table('student_achievements')->where('student_id', $s->id)
                ->where('achievement_id', $a->id)->first();
            if ($existing) return $existing;
            $awardId = (string) Str::ulid();
            DB::table('student_achievements')->insert(['id' => $awardId, 'student_id' => $s->id,
                'achievement_id' => $a->id, 'awarded_by_id' => $c['user'],
                'evidence' => isset($d['evidence']) ? json_encode($d['evidence']) : null, 'awarded_at' => now()]);
            $notificationId = (string) Str::ulid();
            DB::table('notifications')->insertOrIgnore(['id' => $notificationId,
                'organization_id' => $c['organization'], 'school_id' => $c['school'], 'user_id' => $s->user_id,
                'event_type' => 'ACHIEVEMENT_AWARDED', 'title' => 'Pencapaian baru: '.$a->title,
                'body' => $a->description, 'action_url' => '/dashboard/dukungan',
                'idempotency_key' => 'achievement:'.$awardId, 'created_at' => now()]);
            DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(),
                'notification_id' => $notificationId, 'channel' => 'IN_APP', 'status' => 'SENT',
                'provider' => 'INTERNAL', 'sent_at' => now(), 'scheduled_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            return DB::table('student_achievements')->where('id', $awardId)->first();
        });
        return response()->json(['id' => $award->id, 'studentId' => $award->student_id,
            'achievementId' => $award->achievement_id, 'awardedById' => $award->awarded_by_id,
            'evidence' => $award->evidence ? json_decode($award->evidence, true) : null,
            'awardedAt' => $award->awarded_at]);
    }
}
