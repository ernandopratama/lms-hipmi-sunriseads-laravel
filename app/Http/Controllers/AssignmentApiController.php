<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AssignmentApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scoped(Request $request)
    {
        $context = $this->context->resolve($request, 'assignments', 'read');
        $query = DB::table('assignments as a')->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('c.organization_id', $context['organization'])->where('c.school_id', $context['school']);
        if ($context['teacher']) $query->where('a.teacher_id', $context['teacher']->id);
        if ($context['student']) {
            $student = $context['student'];
            $plan = $this->access->resolve($context['organization'], $context['school'], $context['user'], $student->id);
            abort_unless($plan, 503, 'Paket akses belum tersedia');
            $types = $this->access->assignmentTypes($plan);
            $query->whereIn('a.type', $types)
                ->whereIn('a.module_id', $this->access->moduleIds($context['organization'], $context['school'], $student->id, $plan))
                ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $student->id)
                    ->where('school_id', $context['school'])->where('status', 'ACTIVE')->select('course_id'))
                ->where('a.is_active', true)->whereNotNull('a.published_at')->where('a.published_at', '<=', now());
        }
        if ($request->filled('courseId')) $query->where('a.course_id', $request->string('courseId')->toString());
        return [$context, $query];
    }

    private function present(object $a, array $context, bool $detail = false): array
    {
        $fields = ['id', 'course_id', 'module_id', 'teacher_id', 'title', 'description', 'type', 'start_date', 'end_date', 'max_score', 'rubric_id', 'checklist_template_id', 'target_views', 'target_clicks', 'target_orders', 'target_gmv', 'is_active', 'published_at', 'version', 'created_at', 'updated_at'];
        $result = [];
        foreach ($fields as $field) $result[Str::camel($field)] = $a->$field;
        $result['isActive'] = (bool) $a->is_active;
        $course = DB::table('courses')->where('id', $a->course_id)->first();
        $module = $a->module_id ? DB::table('course_modules')->where('id', $a->module_id)->first() : null;
        $teacher = $a->teacher_id ? DB::table('teachers')->where('id', $a->teacher_id)->first() : null;
        $result['course'] = $detail ? ['name' => $course->title] + array_diff_key(\App\Support\Camel::row($course), ['title' => 1, 'status' => 1]) : ['id' => $course->id, 'name' => $course->name ?? $course->title, 'code' => $course->code];
        $result['module'] = $module ? ($detail ? array_diff_key(\App\Support\Camel::row($module), ['minimumRank' => 1]) : ['id' => $module->id, 'title' => $module->title, 'position' => $module->position]) : null;
        $result['teacher'] = $teacher ? ($detail ? \App\Support\Camel::row($teacher) : ['id' => $teacher->id, 'fullName' => $teacher->full_name]) : null;
        $rubric = $a->rubric_id ? DB::table('rubrics')->where('id', $a->rubric_id)->where('organization_id', $context['organization'])->first() : null;
        $result['rubric'] = ! $rubric ? null : ($detail
            ? \App\Support\Camel::row($rubric) + ['criteria' => DB::table('rubric_criteria')->where('rubric_id', $rubric->id)->orderBy('position')->get()->map(fn ($k) => \App\Support\Camel::row($k, ['levels']))->all()]
            : ['id' => $rubric->id, 'name' => $rubric->name]);
        $template = $a->checklist_template_id ? DB::table('store_checklist_templates')->where('id', $a->checklist_template_id)->where('school_id', $context['school'])->first(['id', 'name', 'minimum_products']) : null;
        $result['checklistTemplate'] = $template ? \App\Support\Camel::row($template) : null;
        $submissions = DB::table('submissions')->where('assignment_id', $a->id);
        if ($context['student']) $submissions->where('student_id', $context['student']->id);
        else $submissions->whereIn('student_id', DB::table('students')->where('school_id', $context['school'])->select('id'));
        $result['_count'] = ['submissions' => (clone $submissions)->count()];
        if ($detail) unset($result['checklistTemplate']);
        if ($detail) $result['submissions'] = $submissions->orderByDesc('created_at')->limit(10)->get();
        return $result;
    }

    public function index(Request $request)
    {
        [$context, $query] = $this->scoped($request);
        $total = (clone $query)->count();
        $rows = $query->select('a.*')->orderByDesc('a.created_at')->limit(50)->get();
        return response()->json(['data' => $rows->map(fn ($a) => $this->present($a, $context)), 'total' => $total]);
    }

    public function show(Request $request, string $id)
    {
        [$context, $query] = $this->scoped($request);
        $assignment = $query->where('a.id', $id)->select('a.*')->first();
        abort_unless($assignment, 404, 'Tugas tidak ditemukan');
        return response()->json($this->present($assignment, $context, true));
    }

    private function validateAssignment(Request $request, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        return $request->validate([
            'courseId' => $create ? 'required|string|max:191' : 'prohibited',
            'moduleId' => $required.'|string|max:191',
            'title' => $required.'|string|min:3|max:160',
            'description' => 'nullable|string|max:5000',
            'type' => 'sometimes|in:AFFILIATE,WRITTEN,QUIZ,PROJECT,STORE',
            'startDate' => $required.'|date', 'endDate' => $required.'|date',
            'maxScore' => 'sometimes|integer|min:1|max:1000',
            'rubricId' => 'nullable|string|max:191', 'checklistTemplateId' => 'nullable|string|max:191',
            'targetViews' => 'nullable|integer|min:0', 'targetClicks' => 'nullable|integer|min:0',
            'targetOrders' => 'nullable|integer|min:0', 'targetGmv' => 'nullable|numeric|min:0',
            'isActive' => 'sometimes|boolean',
        ]);
    }

    private function assertModule(string $moduleId, string $courseId, string $school, bool $publish): void
    {
        $module = DB::table('course_modules as m')->join('courses as c', 'c.id', '=', 'm.course_id')
            ->where('m.id', $moduleId)->where('m.course_id', $courseId)->where('c.school_id', $school)->select('m.*')->first();
        abort_unless($module, 422, 'Modul tugas tidak berada pada mata pelajaran ini');
        abort_if($publish && ! $module->is_published, 422, 'Modul harus diterbitkan sebelum tugas dipublikasikan');
    }

    private function assertDates(string $start, string $end): void
    {
        abort_unless(Carbon::parse($end)->gt(Carbon::parse($start)), 422, 'Deadline harus setelah waktu mulai');
    }

    private function snapshot(object $a, string $userId): void
    {
        DB::table('assignment_versions')->insert(['id' => (string) Str::ulid(), 'assignment_id' => $a->id,
            'version' => $a->version, 'changed_by_id' => $userId, 'snapshot_data' => json_encode($a, JSON_THROW_ON_ERROR),
            'created_at' => now()]);
    }

    private function writable(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'assignments', $action);
        abort_unless($c['teacher'], 403, 'Akun tidak memiliki profil guru yang valid');
        return $c;
    }

    public function store(Request $request)
    {
        $c = $this->writable($request, 'create');
        $d = $this->validateAssignment($request, true);
        $course = DB::table('courses')->where('id', $d['courseId'])->where('school_id', $c['school'])
            ->where('organization_id', $c['organization'])->where('is_active', true)->first();
        abort_unless($course, 422, 'Mata pelajaran tidak ditemukan pada sekolah ini');
        $active = $d['isActive'] ?? true;
        $this->assertModule($d['moduleId'], $course->id, $c['school'], $active);
        $this->assertDates($d['startDate'], $d['endDate']);
        if (! empty($d['rubricId'])) abort_unless(DB::table('rubrics')->where('id', $d['rubricId'])->where('organization_id', $c['organization'])->exists(), 422, 'Rubrik tidak tersedia');
        if (! empty($d['checklistTemplateId'])) abort_unless(DB::table('store_checklist_templates')->where('id', $d['checklistTemplateId'])->where('school_id', $c['school'])->where('is_active', true)->exists(), 422, 'Checklist tidak tersedia');
        abort_if(($d['type'] ?? 'AFFILIATE') === 'STORE' && empty($d['checklistTemplateId']), 422, 'Tugas toko membutuhkan checklist');
        return DB::transaction(function () use ($d, $c, $active, $course) {
            $id = (string) Str::ulid();
            DB::table('assignments')->insert(['id' => $id, 'organization_id' => $c['organization'], 'course_id' => $course->id,
                'module_id' => $d['moduleId'], 'teacher_id' => $c['teacher']->id, 'title' => trim($d['title']),
                'description' => $d['description'] ?? null, 'instructions' => $d['description'] ?? '',
                'type' => $d['type'] ?? 'AFFILIATE', 'start_date' => Carbon::parse($d['startDate']),
                'end_date' => Carbon::parse($d['endDate']), 'due_at' => Carbon::parse($d['endDate']),
                'max_score' => $d['maxScore'] ?? 100, 'rubric_id' => $d['rubricId'] ?? null,
                'checklist_template_id' => $d['checklistTemplateId'] ?? null,
                'target_views' => $d['targetViews'] ?? null, 'target_clicks' => $d['targetClicks'] ?? null,
                'target_orders' => $d['targetOrders'] ?? null, 'target_gmv' => $d['targetGmv'] ?? null,
                'is_active' => $active, 'status' => $active ? 'published' : 'draft',
                'published_at' => $active ? now() : null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('assignments')->where('id', $id)->first();
            $this->snapshot($row, $c['user']);
            return response()->json($this->present($row, $c), 201);
        });
    }

    private function owned(string $id, array $c): object
    {
        $row = DB::table('assignments as a')->join('courses as course', 'course.id', '=', 'a.course_id')
            ->where('a.id', $id)->where('a.teacher_id', $c['teacher']->id)
            ->where('course.organization_id', $c['organization'])->where('course.school_id', $c['school'])
            ->select('a.*')->first();
        abort_unless($row, 404, 'Tugas tidak ditemukan');
        return $row;
    }

    public function update(Request $request, string $id)
    {
        $c = $this->writable($request, 'update');
        $d = $this->validateAssignment($request, false);
        return DB::transaction(function () use ($c, $d, $id) {
            $row = $this->owned($id, $c);
            DB::table('assignments')->where('id', $id)->lockForUpdate()->first();
            $active = $d['isActive'] ?? (bool) $row->is_active;
            $this->assertModule($d['moduleId'] ?? $row->module_id, $row->course_id, $c['school'], $active);
            $this->assertDates($d['startDate'] ?? $row->start_date, $d['endDate'] ?? $row->end_date);
            $fields = ['title' => 'title', 'description' => 'description', 'moduleId' => 'module_id',
                'maxScore' => 'max_score', 'targetViews' => 'target_views', 'targetClicks' => 'target_clicks',
                'targetOrders' => 'target_orders', 'targetGmv' => 'target_gmv'];
            $changes = [];
            foreach ($fields as $input => $column) if (array_key_exists($input, $d)) $changes[$column] = $d[$input];
            if (array_key_exists('description', $d)) $changes['instructions'] = $d['description'] ?? '';
            if (isset($d['startDate'])) $changes['start_date'] = Carbon::parse($d['startDate']);
            if (isset($d['endDate'])) { $changes['end_date'] = Carbon::parse($d['endDate']); $changes['due_at'] = $changes['end_date']; }
            if (array_key_exists('isActive', $d)) { $changes['is_active'] = $active; $changes['status'] = $active ? 'published' : 'draft'; }
            if ($active && ! $row->published_at) $changes['published_at'] = now();
            if ($changes) DB::table('assignments')->where('id', $id)->update($changes + ['version' => ($row->version ?? 0) + 1, 'updated_at' => now()]);
            $updated = DB::table('assignments')->where('id', $id)->first();
            if ($changes) $this->snapshot($updated, $c['user']);
            return response()->json($this->present($updated, $c));
        });
    }

    public function archive(Request $request, string $id)
    {
        $c = $this->writable($request, 'delete');
        return DB::transaction(function () use ($c, $id) {
            $row = $this->owned($id, $c);
            DB::table('assignments')->where('id', $id)->lockForUpdate()->first();
            if ($row->is_active) {
                DB::table('assignments')->where('id', $id)->update(['is_active' => false,
                    'version' => ($row->version ?? 0) + 1, 'updated_at' => now()]);
                $row = DB::table('assignments')->where('id', $id)->first();
                $this->snapshot($row, $c['user']);
            }
            return response()->json($this->present($row, $c));
        });
    }
}
