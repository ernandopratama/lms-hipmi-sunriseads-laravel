<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MasterDataApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function scope(Request $request): array
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        abort_unless(in_array('super-admin', $c['roles'], true) || in_array('school-admin', $c['roles'], true)
            || ($request->user()->role === 'admin'), 403, 'Master Data hanya untuk administrator');
        return $c;
    }

    private function parents(string $school)
    {
        return DB::table('parents as p')->whereIn('p.id', DB::table('student_parent_links as link')
            ->join('students as student', 'student.id', '=', 'link.student_id')
            ->where('student.school_id', $school)->whereNull('link.revoked_at')->select('link.parent_id'));
    }

    private function rows(string $resource, string $school, ?string $query = null, ?string $status = null, ?string $classId = null)
    {
        abort_unless(in_array($resource, ['classes', 'students', 'teachers', 'parents'], true), 403, 'Jenis Master Data tidak didukung');
        $q = $resource === 'parents' ? $this->parents($school) : DB::table($resource)->where('school_id', $school);
        if ($query) {
            $search = '%'.trim($query).'%';
            $q->where(function ($q) use ($resource, $search) {
                $q->where($resource === 'parents' ? 'p.full_name' : ($resource === 'classes' ? 'name' : 'full_name'), 'like', $search);
                if ($resource === 'classes') $q->orWhere('code', 'like', $search);
                if ($resource === 'students') $q->orWhere('student_number', 'like', $search);
                if ($resource === 'teachers') $q->orWhere('nip', 'like', $search);
                if ($resource === 'parents') $q->orWhere('p.phone', 'like', $search);
            });
        }
        if ($status === 'active' || $status === 'inactive') {
            if (in_array($resource, ['classes', 'teachers', 'parents'], true)) $q->where($resource === 'parents' ? 'p.is_active' : 'is_active', $status === 'active');
        } elseif ($status && $resource === 'students') $q->where('status', $status);
        if ($resource === 'students' && $classId) $q->where('class_id', $classId);
        return $q;
    }

    private function present(string $resource, object $row, string $school): array
    {
        if ($resource === 'classes') return ['id' => $row->id, 'code' => $row->code, 'name' => $row->name,
            'grade' => $row->grade, 'capacity' => $row->capacity, 'isActive' => (bool) $row->is_active,
            '_count' => ['students' => DB::table('students')->where('school_id', $school)->where('class_id', $row->id)->count()]];
        $user = DB::table('users')->where('id', $row->user_id)->first();
        $result = ['id' => $row->id, 'fullName' => $row->full_name,
            'user' => $user ? ['email' => $user->email, 'status' => $user->source_status ?? strtoupper($user->status)] : null];
        if ($resource === 'students') {
            $class = $row->class_id ? DB::table('classes')->where('id', $row->class_id)->where('school_id', $school)->first() : null;
            return $result + ['studentNumber' => $row->student_number, 'phone' => $row->phone, 'status' => $row->status,
                'class' => $class ? ['id' => $class->id, 'name' => $class->name, 'code' => $class->code] : null,
                '_count' => ['studentParents' => DB::table('student_parent_links')->where('student_id', $row->id)->whereNull('revoked_at')->count()]];
        }
        if ($resource === 'teachers') return $result + ['nip' => $row->nip, 'phone' => $row->phone, 'isActive' => (bool) $row->is_active,
            '_count' => ['materials' => DB::table('materials')->where('teacher_id', $row->id)->count(),
                'assignments' => DB::table('assignments')->where('teacher_id', $row->id)->count()]];
        $links = DB::table('student_parent_links as link')->join('students as student', 'student.id', '=', 'link.student_id')
            ->where('link.parent_id', $row->id)->where('student.school_id', $school)->whereNull('link.revoked_at')
            ->get(['student.id', 'student.full_name', 'student.student_number'])->map(fn ($s) =>
                ['student' => ['id' => $s->id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number]]);
        return $result + ['phone' => $row->phone, 'isActive' => (bool) $row->is_active, 'studentParents' => $links];
    }

    public function overview(Request $request)
    {
        $c = $this->scope($request);
        $school = DB::table('schools')->where('id', $c['school'])->where('organization_id', $c['organization'])->first();
        abort_unless($school, 404, 'Sekolah tidak ditemukan');
        abort_unless($school->is_active, 403, 'Sekolah tidak aktif');
        $result = ['school' => ['id' => $school->id, 'code' => $school->code, 'npsn' => $school->npsn,
            'name' => $school->name, 'level' => $school->level, 'status' => $school->status,
            'city' => $school->city, 'province' => $school->province, 'principal' => $school->principal,
            'picName' => $school->pic_name, 'picEmail' => $school->pic_email, 'picPhone' => $school->pic_phone,
            'isActive' => (bool) $school->is_active]];
        foreach (['classes', 'students', 'teachers', 'parents'] as $resource) {
            $rows = $this->rows($resource, $c['school']);
            $result['counts'][$resource] = (clone $rows)->count();
            $result[$resource] = $rows->limit(100)->get($resource === 'parents' ? ['p.*'] : ['*'])
                ->map(fn ($row) => $this->present($resource, $row, $c['school']));
        }
        return response()->json($result);
    }

    public function listing(Request $request, string $resource)
    {
        $c = $this->scope($request);
        $d = $request->validate(['query' => 'nullable|string|max:120', 'status' => 'nullable|string|max:30',
            'classId' => 'nullable|string|max:191', 'page' => 'nullable|integer|min:1', 'pageSize' => 'nullable|integer|min:1|max:100']);
        $rows = $this->rows($resource, $c['school'], $d['query'] ?? null, $d['status'] ?? null, $d['classId'] ?? null);
        $total = (clone $rows)->count(); $page = $d['page'] ?? 1; $size = $d['pageSize'] ?? 20;
        $items = $rows->offset(($page - 1) * $size)->limit($size)->get($resource === 'parents' ? ['p.*'] : ['*'])
            ->map(fn ($row) => $this->present($resource, $row, $c['school']));
        return response()->json(compact('items', 'total', 'page') + ['pageSize' => $size, 'totalPages' => max(1, (int) ceil($total / $size))]);
    }

    private function writeScope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'master-data', $action);
        abort_unless(in_array('super-admin', $c['roles'], true) || in_array('school-admin', $c['roles'], true)
            || $request->user()->role === 'admin', 403);
        return $c;
    }

    public function updateTeacher(Request $request, string $id)
    {
        $c = $this->writeScope($request, 'update');
        $d = $request->validate(['fullName' => 'sometimes|string|max:200', 'nip' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30', 'isActive' => 'sometimes|boolean']);
        abort_unless(DB::table('teachers')->where('id', $id)->where('school_id', $c['school'])->exists(), 404, 'Guru tidak ditemukan');
        $changes = [];
        foreach ($d as $key => $value) $changes[Str::snake($key)] = $value;
        if ($changes) DB::table('teachers')->where('id', $id)->where('school_id', $c['school'])->update($changes + ['updated_at' => now()]);
        $teacher = DB::table('teachers')->where('id', $id)->first();
        return response()->json(['id' => $teacher->id, 'fullName' => $teacher->full_name,
            'nip' => $teacher->nip, 'phone' => $teacher->phone, 'isActive' => (bool) $teacher->is_active]);
    }

    public function archiveTeacher(Request $request, string $id)
    {
        $c = $this->writeScope($request, 'delete');
        return DB::transaction(function () use ($c, $id) {
            $teacher = DB::table('teachers')->where('id', $id)->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($teacher, 404, 'Guru tidak ditemukan');
            DB::table('teachers')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('teacher_assignments')->where('teacher_id', $id)->where('school_id', $c['school'])
                ->where('is_active', true)->update(['is_active' => false, 'effective_until' => now(), 'updated_at' => now()]);
            DB::table('users')->where('id', $teacher->user_id)->where('organization_id', $c['organization'])
                ->update(['status' => 'inactive', 'source_status' => 'ARCHIVED', 'session_version' => DB::raw('session_version + 1'), 'updated_at' => now()]);
            return response()->json(['id' => $id, 'status' => 'ARCHIVED']);
        });
    }

    public function enroll(Request $request)
    {
        $c = $this->writeScope($request, 'update');
        $d = $request->validate(['studentId' => 'required|string|max:191', 'classId' => 'required|string|max:191',
            'academicYearId' => 'nullable|string|max:191', 'semesterId' => 'nullable|string|max:191', 'effectiveFrom' => 'required|date']);
        abort_unless(DB::table('students')->where('id', $d['studentId'])->where('school_id', $c['school'])->exists()
            && DB::table('classes')->where('id', $d['classId'])->where('school_id', $c['school'])->where('is_active', true)->exists(), 404, 'Siswa atau kelas tidak ditemukan');
        if (! empty($d['academicYearId'])) abort_unless(DB::table('academic_years')->where('id', $d['academicYearId'])->where('school_id', $c['school'])->exists(), 404);
        if (! empty($d['semesterId'])) {
            $semester = DB::table('semesters as sem')->join('academic_years as year', 'year.id', '=', 'sem.academic_year_id')
                ->where('sem.id', $d['semesterId'])->where('year.school_id', $c['school'])->first();
            abort_unless($semester && (empty($d['academicYearId']) || $semester->academic_year_id === $d['academicYearId']), 404);
        }
        return DB::transaction(function () use ($c, $d) {
            DB::table('students')->where('id', $d['studentId'])->lockForUpdate()->first();
            DB::table('class_enrollments')->where('student_id', $d['studentId'])->where('school_id', $c['school'])
                ->where('status', 'ACTIVE')->update(['status' => 'TRANSFERRED', 'effective_until' => $d['effectiveFrom'], 'updated_at' => now()]);
            DB::table('students')->where('id', $d['studentId'])->update(['class_id' => $d['classId'], 'updated_at' => now()]);
            $id = (string) Str::ulid();
            DB::table('class_enrollments')->insert(['id' => $id, 'student_id' => $d['studentId'], 'class_id' => $d['classId'],
                'school_id' => $c['school'], 'academic_year_id' => $d['academicYearId'] ?? null,
                'semester_id' => $d['semesterId'] ?? null, 'status' => 'ACTIVE',
                'effective_from' => $d['effectiveFrom'], 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['id' => $id, 'studentId' => $d['studentId'], 'classId' => $d['classId'],
                'schoolId' => $c['school'], 'status' => 'ACTIVE'], 201);
        });
    }

    public function assignTeacher(Request $request)
    {
        $c = $this->writeScope($request, 'update');
        $d = $request->validate(['teacherId' => 'required|string|max:191', 'classId' => 'required|string|max:191',
            'courseId' => 'nullable|string|max:191', 'subject' => 'nullable|string|max:120', 'effectiveFrom' => 'required|date']);
        abort_unless(DB::table('teachers')->where('id', $d['teacherId'])->where('school_id', $c['school'])->where('is_active', true)->exists()
            && DB::table('classes')->where('id', $d['classId'])->where('school_id', $c['school'])->where('is_active', true)->exists(), 404, 'Guru atau kelas tidak ditemukan');
        if (! empty($d['courseId'])) abort_unless(DB::table('courses')->where('id', $d['courseId'])
            ->where('school_id', $c['school'])->where('organization_id', $c['organization'])->where('is_active', true)->exists(), 404, 'Course tidak ditemukan');
        $id = (string) Str::ulid();
        DB::table('teacher_assignments')->insert(['id' => $id, 'teacher_id' => $d['teacherId'], 'class_id' => $d['classId'],
            'school_id' => $c['school'], 'course_id' => $d['courseId'] ?? null, 'subject' => $d['subject'] ?? null,
            'effective_from' => $d['effectiveFrom'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id, 'teacherId' => $d['teacherId'], 'classId' => $d['classId'], 'schoolId' => $c['school'],
            'courseId' => $d['courseId'] ?? null, 'subject' => $d['subject'] ?? null, 'isActive' => true], 201);
    }

    private function parent(string $id, string $school): object
    {
        $row = DB::table('parents as p')->where('p.id', $id)
            ->whereIn('p.user_id', DB::table('user_roles')->where('school_id', $school)->select('user_id'))->select('p.*')->first();
        abort_unless($row, 404, 'Orang tua tidak ditemukan di sekolah ini');
        return $row;
    }

    public function updateParent(Request $request, string $id)
    {
        $c = $this->writeScope($request, 'update');
        $d = $request->validate(['fullName' => 'sometimes|string|max:200', 'phone' => 'nullable|string|max:30', 'isActive' => 'sometimes|boolean']);
        $this->parent($id, $c['school']);
        $changes = [];
        foreach ($d as $key => $value) $changes[Str::snake($key)] = $value;
        if ($changes) DB::table('parents')->where('id', $id)->update($changes + ['updated_at' => now()]);
        $p = DB::table('parents')->where('id', $id)->first();
        return response()->json(['id' => $p->id, 'fullName' => $p->full_name, 'phone' => $p->phone, 'isActive' => (bool) $p->is_active]);
    }

    public function archiveParent(Request $request, string $id)
    {
        $c = $this->writeScope($request, 'delete');
        return DB::transaction(function () use ($c, $id) {
            $p = $this->parent($id, $c['school']);
            DB::table('parents')->where('id', $id)->lockForUpdate()->first();
            DB::table('student_parent_links')->where('parent_id', $id)->whereNull('revoked_at')
                ->whereIn('student_id', DB::table('students')->where('school_id', $c['school'])->select('id'))
                ->update(['verification_status' => 'REVOKED', 'revoked_at' => now()]);
            $remaining = DB::table('student_parent_links')->where('parent_id', $id)->whereNull('revoked_at')->count();
            if ($remaining === 0) {
                DB::table('parents')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
                DB::table('users')->where('id', $p->user_id)->where('organization_id', $c['organization'])
                    ->update(['status' => 'inactive', 'source_status' => 'ARCHIVED', 'session_version' => DB::raw('session_version + 1'), 'updated_at' => now()]);
            }
            return response()->json(['id' => $id, 'status' => $remaining === 0 ? 'ARCHIVED' : 'REVOKED_IN_SCHOOL']);
        });
    }

    public function linkParent(Request $request)
    {
        $c = $this->writeScope($request, 'update');
        $d = $request->validate(['studentId' => 'required|string|max:191', 'parentId' => 'required|string|max:191',
            'relationship' => 'required|string|max:191']);
        abort_unless(DB::table('students')->where('id', $d['studentId'])->where('school_id', $c['school'])->exists(), 404, 'Siswa tidak ditemukan');
        $this->parent($d['parentId'], $c['school']);
        $row = ['relationship' => $d['relationship'], 'verification_status' => 'VERIFIED', 'verified_at' => now(),
            'verified_by' => $c['user'], 'revoked_at' => null];
        DB::table('student_parent_links')->updateOrInsert(['student_id' => $d['studentId'], 'parent_id' => $d['parentId']],
            $row + ['effective_from' => now(), 'created_at' => now()]);
        return response()->json(['studentId' => $d['studentId'], 'parentId' => $d['parentId'],
            'relationship' => $d['relationship'], 'verificationStatus' => 'VERIFIED']);
    }
}
