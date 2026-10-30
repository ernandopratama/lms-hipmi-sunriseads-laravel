<?php
namespace App\Http\Controllers;

use App\Support\{ApiContext, StudentDashboardAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearningApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}
    private function scope(Request $r, string $action = 'read'): array { return $this->context->resolve($r, 'materials', $action); }
    private function coursesQuery(array $c)
    {
        $q = DB::table('courses')->where('organization_id', $c['organization'])->where('school_id', $c['school'])->where('is_active', true);
        if ($c['student']) $q->whereIn('id', DB::table('course_enrollments')->where('student_id', $c['student']->id)->where('school_id', $c['school'])->where('status', 'ACTIVE')->select('course_id'));
        return $q;
    }
    private function encode(object $row, array $names): array
    {
        $result = [];
        foreach ($names as $column) $result[Str::camel($column)] = $row->$column ?? null;
        return $result;
    }
    private function course(object $row): array
    {
        $result = $this->encode($row, ['id', 'code', 'description']); $result['name'] = $row->title;
        $program = $row->program_id ? DB::table('programs')->where('id', $row->program_id)->where('school_id', $row->school_id)->first() : null;
        $result['program'] = $program ? $this->encode($program, ['id', 'code', 'name']) : null;
        return $result;
    }
    private function studentAccess(array $c): ?array
    {
        if (! $c['student']) return null;
        $a = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']->id);
        abort_unless($a, 503, 'Paket akses sumber belum dimigrasikan');
        abort_unless($this->access->enabled($a, 'learning.materials.read'), 403, 'Materi tidak termasuk dalam paket aktif');
        return $a;
    }
    public function courses(Request $r) { $c = $this->scope($r); return response()->json($this->coursesQuery($c)->orderBy('title')->get()->map(fn ($row) => $this->course($row))); }
    public function programs(Request $r)
    {
        $c = $this->scope($r);
        return response()->json(DB::table('programs')->where('organization_id', $c['organization'])->where('school_id', $c['school'])->where('is_active', true)->orderBy('name')->get()->map(function ($p) use ($c) {
            return \App\Support\Camel::row($p) + ['createdById' => null, '_count' => ['courses' => $this->coursesQuery($c)->where('program_id', $p->id)->count()]];
        }));
    }
    public function classes(Request $r)
    {
        $c = $this->scope($r);
        return response()->json(DB::table('classes')->where('school_id', $c['school'])->where('is_active', true)->orderBy('grade')->orderBy('name')->get()->map(fn ($p) => $this->encode($p, ['id', 'code', 'name', 'grade'])));
    }
    private function material(object $m): array
    {
        return $this->encode($m, ['id', 'title', 'type', 'content', 'file_url', 'lesson_id', 'course_id', 'teacher_id', 'original_file_name', 'mime_type', 'preview_slide_count', 'created_at']) + $this->videoInfo($m);
    }
    /** Embed details for YouTube/Vimeo materials; empty for every other kind. */
    private function videoInfo(object $m): array
    {
        $source = ($m->type ?? null) === 'VIDEO' ? \App\Support\VideoSource::parse($m->file_url ?? null) : null;
        return $source ? ['video' => $source + ['durationSeconds' => $m->duration_seconds ?? null, 'completionThreshold' => (int) ($m->completion_threshold ?? 90)]] : [];
    }
    public function tree(Request $r)
    {
        $c = $this->scope($r); $access = $this->studentAccess($c);
        $allowed = $access ? $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $access)->all() : null;
        $result = $this->coursesQuery($c)->orderBy('title')->get()->map(function ($course) use ($c, $access, $allowed) {
            $result = $this->course($course);
            $modules = DB::table('course_modules')->where('course_id', $course->id)->orderBy('position')->orderBy('id');
            if ($c['student']) $modules->where('is_published', true);
            $result['modules'] = $modules->get()->map(function ($m) use ($c, $allowed) {
                $result = $this->encode($m, ['id', 'title', 'description', 'position']); $result['isPublished'] = (bool) $m->is_published;
                $locked = $allowed !== null && ! in_array($m->id, $allowed, true);
                $minimum = DB::table('module_plan_access as mp')->join('access_plans as p', 'p.id', '=', 'mp.minimum_plan_id')->where('mp.module_id', $m->id)->value('p.code') ?? 'FREE';
                if ($allowed !== null) $result += ['locked' => $locked, 'lockReason' => $locked ? 'PLAN_MODULE_LIMIT' : null, 'minimumPlan' => $minimum];
                else {
                    $plan = DB::table('module_plan_access as mp')->join('access_plans as p', 'p.id', '=', 'mp.minimum_plan_id')->where('mp.module_id', $m->id)->first(['p.rank', 'p.code']);
                    $result['planAccess'] = $plan ? ['minimumPlan' => ['rank' => (int) $plan->rank, 'code' => $plan->code]] : null;
                }
                $lessons = DB::table('lessons')->where('course_module_id', $m->id)->orderBy('position')->orderBy('id');
                if ($c['student']) $lessons->where('is_published', true);
                $result['lessons'] = $locked ? [] : $lessons->get()->map(function ($lesson) use ($c) {
                    $result = $this->encode($lesson, ['id', 'title', 'summary', 'position', 'estimated_minutes']); $result['isPublished'] = (bool) $lesson->is_published;
                    $result['materials'] = DB::table('materials')->where('lesson_id', $lesson->id)->where('is_active', true)->get()->map(fn ($m) => $this->encode($m, ['id', 'title', 'type', 'content', 'file_url']) + $this->videoInfo($m));
                    if ($c['student']) $result['progress'] = DB::table('lesson_progress')->where('lesson_id', $lesson->id)->where('student_id', $c['student']->id)->get()->map(fn ($p) => $this->encode($p, ['state', 'progress_percent', 'last_position', 'completed_at']) + ['components' => app(\App\Support\LessonProgress::class)->components($c['student']->id, $lesson->id)]);
                    if ($c['student']) $result['components'] = app(\App\Support\LessonProgress::class)->components($c['student']->id, $lesson->id);
                    return $result;
                });
                return $result;
            });
            if ($access) $result['accessPlan'] = ['code' => $access['plan']['code'], 'moduleLimit' => $access['moduleLimit']];
            if ($c['student']) $result['enrollments'] = DB::table('course_enrollments')->where('course_id', $course->id)->where('student_id', $c['student']->id)->get()->map(fn ($e) => $this->encode($e, ['status', 'enrolled_at', 'completed_at']));
            return $result;
        });
        return response()->json($result);
    }
    public function materials(Request $r)
    {
        $c = $this->scope($r); $access = $this->studentAccess($c);
        $q = DB::table('materials as m')->join('courses as c', 'c.id', '=', 'm.course_id')->whereIn('c.id', $this->coursesQuery($c)->select('id'));
        if ($c['teacher']) $q->where('m.teacher_id', $c['teacher']->id); else $q->where('m.is_active', true);
        if ($r->filled('courseId')) $q->where('m.course_id', $r->string('courseId')->toString());
        if ($access) {
            $moduleIds = $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $access);
            $q->whereIn('m.lesson_id', DB::table('lessons')->whereIn('course_module_id', $moduleIds)->where('is_published', true)->select('id'));
        }
        return response()->json($q->select('m.*')->orderByDesc('m.created_at')->limit(100)->get()->map(function ($m) use ($access) {
            $course = DB::table('courses')->where('id', $m->course_id)->first();
            $teacher = DB::table('teachers')->where('id', $m->teacher_id)->first();
            $lesson = $m->lesson_id ? DB::table('lessons as l')->join('course_modules as cm', 'cm.id', '=', 'l.module_id')->where('l.id', $m->lesson_id)->first(['l.module_id', 'l.position', 'cm.course_id', 'cm.position as module_position']) : null;
            $row = \App\Support\Camel::row($m) + $this->videoInfo($m) + ['course' => ['id' => $course->id, 'name' => $course->title, 'code' => $course->code], 'teacher' => $teacher ? $this->encode($teacher, ['id', 'full_name']) : null];
            // Students never saw the lesson block in the original response; teachers did.
            if ($access) $row['__order'] = [(int) ($lesson->module_position ?? 0), (int) ($lesson->position ?? 0)];
            if (! $access) $row['lesson'] = $lesson ? ['moduleId' => $lesson->module_id, 'position' => $lesson->position, 'module' => ['courseId' => $lesson->course_id, 'position' => $lesson->module_position]] : null;
            return $row;
        })->when((bool) $access, fn ($rows) => $rows->sortBy('__order')->map(function ($row) { unset($row['__order']); return $row; }))->values());
    }
    public function createProgram(Request $r)
    {
        $c = $this->scope($r, 'create'); $data = $r->validate(['code' => 'required|string|min:2|max:40', 'name' => 'required|string|min:3|max:160', 'description' => 'nullable|string|max:2000']);
        $row = ['id' => (string) Str::ulid(), 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'code' => strtoupper(trim($data['code'])), 'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        abort_if(DB::table('programs')->where('school_id', $c['school'])->where('code', $row['code'])->exists(), 409, 'Kode program sudah digunakan');
        DB::table('programs')->insert($row); return response()->json($this->encode((object) $row, array_keys($row)), 201);
    }
    public function createCourse(Request $r)
    {
        $c = $this->scope($r, 'create'); $d = $r->validate(['code' => 'required|string|min:2|max:40', 'name' => 'required|string|min:3|max:160', 'description' => 'nullable|string|max:2000', 'programId' => 'nullable|string|max:191']);
        if (! empty($d['programId'])) abort_unless(DB::table('programs')->where('id', $d['programId'])->where('school_id', $c['school'])->where('organization_id', $c['organization'])->where('is_active', true)->exists(), 422, 'Program tidak ditemukan');
        $row = ['id' => (string) Str::ulid(), 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'program_id' => $d['programId'] ?? null, 'code' => strtoupper(trim($d['code'])), 'name' => trim($d['name']), 'title' => trim($d['name']), 'description' => $d['description'] ?? null, 'is_active' => true, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()];
        abort_if(DB::table('courses')->where('school_id', $c['school'])->where('code', $row['code'])->exists(), 409, 'Kode course sudah digunakan');
        DB::table('courses')->insert($row); return response()->json($this->course((object) $row), 201);
    }
    public function createModule(Request $r, string $id)
    {
        $c = $this->scope($r, 'create'); abort_unless($this->coursesQuery($c)->where('id', $id)->exists(), 404);
        $d = $r->validate(['title' => 'required|string|min:3|max:180', 'description' => 'nullable|string|max:2000', 'position' => 'nullable|integer|min:1', 'isPublished' => 'sometimes|boolean']);
        return DB::transaction(function () use ($id, $d, $c) {
            DB::table('courses')->where('id', $id)->lockForUpdate()->first();
            $row = ['id' => (string) Str::ulid(), 'course_id' => $id, 'title' => trim($d['title']), 'description' => $d['description'] ?? null, 'position' => $d['position'] ?? (DB::table('course_modules')->where('course_id', $id)->max('position') + 1), 'is_published' => $d['isPublished'] ?? false, 'created_by_id' => $c['user'], 'created_at' => now(), 'updated_at' => now()];
            DB::table('course_modules')->insert($row); return response()->json($this->encode((object) $row, array_keys($row)), 201);
        });
    }
    public function createLesson(Request $r, string $id)
    {
        $c = $this->scope($r, 'create');
        $module = DB::table('course_modules')->where('id', $id)->whereIn('course_id', $this->coursesQuery($c)->select('id'))->first(); abort_unless($module, 404);
        $d = $r->validate(['title' => 'required|string|min:3|max:180', 'summary' => 'nullable|string|max:2000', 'position' => 'nullable|integer|min:1', 'estimatedMinutes' => 'nullable|integer|min:1|max:1440', 'isPublished' => 'sometimes|boolean']);
        return DB::transaction(function () use ($id, $d, $c) {
            DB::table('course_modules')->where('id', $id)->lockForUpdate()->first();
            $row = ['id' => (string) Str::ulid(), 'course_module_id' => $id, 'module_id' => $id, 'title' => trim($d['title']), 'summary' => $d['summary'] ?? null, 'content' => $d['summary'] ?? '', 'position' => $d['position'] ?? (DB::table('lessons')->where('course_module_id', $id)->max('position') + 1), 'estimated_minutes' => $d['estimatedMinutes'] ?? null, 'is_published' => $d['isPublished'] ?? false, 'status' => ($d['isPublished'] ?? false) ? 'published' : 'draft', 'created_by_id' => $c['user'], 'created_at' => now(), 'updated_at' => now()];
            DB::table('lessons')->insert($row); return response()->json($this->encode((object) $row, array_keys($row)), 201);
        });
    }
    public function enrollClass(Request $r)
    {
        $c = $this->scope($r, 'create'); $d = $r->validate(['courseId' => 'required|string|max:191', 'classId' => 'required|string|max:191']);
        abort_unless($this->coursesQuery($c)->where('id', $d['courseId'])->exists() && DB::table('classes')->where('id', $d['classId'])->where('school_id', $c['school'])->where('is_active', true)->exists(), 422, 'Course atau kelas tidak ditemukan');
        return DB::transaction(function () use ($d, $c) {
            DB::table('courses')->where('id', $d['courseId'])->lockForUpdate()->first();
            $students = DB::table('students as s')->join('class_enrollments as e', 'e.student_id', '=', 's.id')->where('s.school_id', $c['school'])->where('e.school_id', $c['school'])->where('e.class_id', $d['classId'])->where('e.status', 'ACTIVE')->distinct()->pluck('s.id');
            foreach ($students as $student) {
                $existing = DB::table('course_enrollments')->where('course_id', $d['courseId'])->where('student_id', $student)->first();
                if ($existing) DB::table('course_enrollments')->where('id', $existing->id)->update(['status' => 'ACTIVE', 'completed_at' => null, 'updated_at' => now()]);
                else DB::table('course_enrollments')->insert(['id' => (string) Str::ulid(), 'course_id' => $d['courseId'], 'student_id' => $student, 'school_id' => $c['school'], 'status' => 'ACTIVE', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            return response()->json(['courseId' => $d['courseId'], 'classId' => $d['classId'], 'enrolledStudents' => $students->count()]);
        });
    }
    public function createMaterial(Request $r)
    {
        $c = $this->scope($r, 'create'); abort_unless($c['teacher'], 403, 'Akun tidak memiliki profil guru');
        $d = $r->validate(['courseId' => 'required|string|max:191', 'lessonId' => 'required|string|max:191', 'title' => 'required|string|min:3|max:160', 'content' => 'nullable|string|max:5000', 'fileUrl' => 'nullable|url:http,https|max:2048', 'type' => 'required|in:DOC,VIDEO,LINK,TEXT', 'durationSeconds' => 'nullable|integer|min:10|max:21600', 'completionThreshold' => 'nullable|integer|min:50|max:100', 'isRequired' => 'sometimes|boolean']);
        abort_if($d['type'] === 'VIDEO' && ! \App\Support\VideoSource::parse($d['fileUrl'] ?? null), 422, 'Tautan video harus berupa YouTube atau Vimeo');
        abort_unless($this->coursesQuery($c)->where('id', $d['courseId'])->exists(), 422, 'Course tidak ditemukan');
        abort_unless(DB::table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.course_module_id')->where('l.id', $d['lessonId'])->where('m.course_id', $d['courseId'])->exists(), 422, 'Lesson tidak berada pada course');
        abort_if($d['type'] !== 'TEXT' && empty($d['content']) && empty($d['fileUrl']), 422, 'Tambahkan isi atau tautan materi');
        $row = ['id' => (string) Str::ulid(), 'course_id' => $d['courseId'], 'lesson_id' => $d['lessonId'], 'teacher_id' => $c['teacher']->id, 'title' => trim($d['title']), 'content' => $d['content'] ?? null, 'file_url' => $d['fileUrl'] ?? null, 'type' => $d['type'], 'is_active' => true, 'duration_seconds' => $d['durationSeconds'] ?? null,
            'completion_threshold' => $d['completionThreshold'] ?? 90, 'is_required' => $d['isRequired'] ?? true, 'created_at' => now(), 'updated_at' => now()];
        DB::table('materials')->insert($row); return response()->json($this->material((object) $row), 201);
    }
    private function studentLesson(Request $r, string $id): array
    {
        $c = $this->scope($r); abort_unless($c['student'], 403, 'Hanya siswa dapat memperbarui progres');
        $access = $this->studentAccess($c);
        $allowed = $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $access);
        $lesson = DB::table('lessons')->where('id', $id)->where('is_published', true)->whereIn('course_module_id', $allowed)->first(); abort_unless($lesson, 403, 'Lesson tidak tersedia');
        return [$c, $lesson];
    }
    public function lessonProgress(Request $r, string $id)
    {
        [$c] = $this->studentLesson($r, $id);
        return response()->json(app(\App\Support\LessonProgress::class)->snapshot($c['student']->id, $id));
    }
    /** Player heartbeat for a YouTube/Vimeo material; coverage is computed on the server. */
    public function materialProgress(Request $r, string $id)
    {
        $material = DB::table('materials')->where('id', $id)->where('is_active', true)->where('type', 'VIDEO')->first();
        abort_unless($material && $material->lesson_id, 404, 'Video tidak ditemukan');
        [$c] = $this->studentLesson($r, $material->lesson_id);
        $d = $r->validate(['kind' => 'required|in:VIDEO', 'position' => 'required|numeric|min:0|max:86400', 'durationSeconds' => 'nullable|integer|min:10|max:21600', 'playing' => 'required|boolean']);
        $progress = app(\App\Support\LessonProgress::class);
        $video = $progress->videos($material->lesson_id)->firstWhere('id', $id);
        abort_unless($video, 404, 'Video tidak ditemukan atau bukan tautan YouTube/Vimeo');
        DB::transaction(function () use ($progress, $c, $material, $video, $d) {
            DB::table('students')->where('id', $c['student']->id)->lockForUpdate()->first();
            $progress->recordVideo($c['student']->id, $c['user'], $material->lesson_id, $video, (float) $d['position'], $d['durationSeconds'] ?? null, (bool) $d['playing']);
        });
        $snapshot = $progress->snapshot($c['student']->id, $material->lesson_id);
        return response()->json($snapshot + ['component' => collect($snapshot['components']['video']['materials'] ?? [])->firstWhere('materialId', $id)]);
    }
    public function progress(Request $r, string $id)
    {
        [$c, $lesson] = $this->studentLesson($r, $id);
        $d = $r->validate(['state' => 'required|in:NOT_STARTED,IN_PROGRESS,COMPLETED', 'progressPercent' => 'required|integer|min:0|max:100', 'lastPosition' => 'nullable|integer|min:0', 'materialId' => 'nullable|string|max:191']);
        $progress = app(\App\Support\LessonProgress::class);
        $tracked = $progress->tracked($id);
        if ($progress->hasComponents($id)) {
            // Tracked lessons are driven by what the student actually opened; a client-supplied
            // state or percent is never trusted, only a position inside a known deck.
            if (empty($d['materialId'])) return response()->json($progress->snapshot($c['student']->id, $id));
            $material = $tracked->firstWhere('id', $d['materialId']);
            abort_unless($material && ! empty($d['lastPosition']), 422, 'Urutan slide tidak valid');
            DB::transaction(function () use ($progress, $c, $id, $material, $d) {
                DB::table('students')->where('id', $c['student']->id)->lockForUpdate()->first();
                $progress->recordRead($c['student']->id, $c['user'], $id, $material, (int) $d['lastPosition']);
            });
            return response()->json($progress->snapshot($c['student']->id, $id));
        }
        return DB::transaction(function () use ($id, $c, $d) {
            DB::table('students')->where('id', $c['student']->id)->lockForUpdate()->first();
            $q = DB::table('lesson_progress')->where('lesson_id', $id)->where('student_id', $c['student']->id);
            $existing = (clone $q)->first();
            abort_unless(empty($d['materialId']), 422, 'Urutan slide tidak valid');
            $requested = $d['state'] === 'COMPLETED' ? 100 : $d['progressPercent'];
            $percent = max($existing?->progress_percent ?? 0, $requested);
            $completed = $existing?->state === 'COMPLETED' || $d['state'] === 'COMPLETED' || $percent >= 100;
            $state = $completed ? 'COMPLETED' : ($percent > 0 ? 'IN_PROGRESS' : 'NOT_STARTED');
            $row = ['state' => $state, 'status' => $state, 'progress_percent' => $percent, 'last_position' => max($existing?->last_position ?? 0, $d['lastPosition'] ?? 0) ?: null,
                'started_at' => $existing?->started_at ?? ($state !== 'NOT_STARTED' ? now() : null), 'completed_at' => $state === 'COMPLETED' ? ($existing?->completed_at ?? now()) : null, 'updated_at' => now()];
            if ($existing) $q->update($row);
            else DB::table('lesson_progress')->insert($row + ['id' => (string) Str::ulid(), 'lesson_id' => $id, 'student_id' => $c['student']->id, 'user_id' => $c['user'], 'created_at' => now()]);
            return response()->json(app(\App\Support\LessonProgress::class)->snapshot($c['student']->id, $id));
        });
    }
    public function materialAccess(Request $r, string $id)
    {
        $materials = $this->materials($r)->getData(true);
        $material = collect($materials)->firstWhere('id', $id);
        abort_unless($material && $material['originalFileName'], 404, 'File materi tidak tersedia');
        return response()->json(array_intersect_key($material, array_flip(['id', 'title', 'lessonId', 'originalFileName', 'mimeType', 'previewSlideCount'])));
    }
}
