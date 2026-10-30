<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherDashboardApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'teacher' && $user->school_id, 403);
        $schoolId = $user->school_id;
        abort_unless(DB::table('schools')->where('id', $schoolId)
            ->where('organization_id', $user->organization_id)->exists(), 403);
        $teacher = DB::table('teachers')->where('user_id', $user->id)
            ->where('school_id', $schoolId)->where('is_active', true)->first(['id']);
        abort_unless($teacher, 403, 'Akun tidak memiliki konteks guru dan sekolah yang valid');

        $ownedCourses = DB::table('assignments as a')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('a.teacher_id', $teacher->id)
            ->where('c.school_id', $schoolId)
            ->where('c.organization_id', $user->organization_id)
            ->distinct()->pluck('a.course_id');
        $assignedClasses = DB::table('teacher_assignments as ta')
            ->join('classes as c', 'c.id', '=', 'ta.class_id')
            ->where('ta.teacher_id', $teacher->id)
            ->where('ta.school_id', $schoolId)
            ->where('c.school_id', $schoolId)
            ->where('ta.is_active', true)->distinct()->pluck('ta.class_id');

        $totalStudents = DB::table('students as st')->where('st.school_id', $schoolId)
            ->where(function ($query) use ($ownedCourses, $assignedClasses, $schoolId) {
                $query->whereExists(function ($sub) use ($ownedCourses, $schoolId) {
                    $sub->selectRaw('1')->from('course_enrollments as ce')
                        ->whereColumn('ce.student_id', 'st.id')->where('ce.school_id', $schoolId)
                        ->where('ce.status', 'ACTIVE')->whereIn('ce.course_id', $ownedCourses);
                })->orWhereExists(function ($sub) use ($assignedClasses, $schoolId) {
                    $sub->selectRaw('1')->from('class_enrollments as ce')
                        ->whereColumn('ce.student_id', 'st.id')->where('ce.school_id', $schoolId)
                        ->where('ce.status', 'ACTIVE')->whereIn('ce.class_id', $assignedClasses);
                });
            })->count();

        $pending = DB::table('submissions as s')
            ->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->join('students as st', 'st.user_id', '=', 's.user_id')
            ->where('a.teacher_id', $teacher->id)
            ->where('c.school_id', $schoolId)
            ->where('c.organization_id', $user->organization_id)
            ->where('st.school_id', $schoolId)
            ->whereIn('s.status', ['SUBMITTED', 'RESUBMITTED'])
            ->select(['s.id', 's.status', 's.submitted_at', 'st.full_name', 'a.title'])
            ->orderByDesc('s.submitted_at');
        $pendingReviewsCount = (clone $pending)->count();
        $pendingReviewsQueue = (clone $pending)->limit(10)->get()->map(fn ($row) => [
            'id' => $row->id,
            'status' => $row->status,
            'submittedAt' => $row->submitted_at,
            'student' => ['fullName' => $row->full_name],
            'assignment' => ['title' => $row->title],
        ]);

        $active = DB::table('assignments as a')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('a.teacher_id', $teacher->id)
            ->where('c.school_id', $schoolId)
            ->where('c.organization_id', $user->organization_id)
            ->where('a.is_active', true)->where('a.end_date', '>=', now())
            ->select(['a.id', 'a.title', 'a.end_date'])
            ->orderBy('a.end_date')->get();
        $submissionCounts = DB::table('submissions as s')
            ->join('students as st', 'st.user_id', '=', 's.user_id')
            ->where('st.school_id', $schoolId)
            ->whereIn('s.assignment_id', $active->pluck('id'))
            ->select('s.assignment_id')->selectRaw('count(*) as total')
            ->groupBy('s.assignment_id')->pluck('total', 's.assignment_id');
        $activeAssignments = $active->map(fn ($row) => [
                'id' => $row->id, 'title' => $row->title, 'endDate' => $row->end_date,
                '_count' => ['submissions' => (int) ($submissionCounts[$row->id] ?? 0)],
            ]);

        $access = app(\App\Support\StudentDashboardAccess::class);
        $plan = $access->resolve($user->organization_id, $schoolId, $user->id);

        return response()->json([
            'kpi' => [
                'totalStudents' => $totalStudents,
                'pendingReviewsCount' => $pendingReviewsCount,
                'activeAssignmentsCount' => $activeAssignments->count(),
            ],
            'pendingReviewsQueue' => $pendingReviewsQueue,
            'activeAssignments' => $activeAssignments,
            'advancedAccess' => $plan !== null && $access->enabled($plan, 'dashboards.advanced.read'),
        ])->header('Cache-Control', 'no-store');
    }
}
