<?php

namespace App\Http\Controllers;

use App\Support\StudentDashboardAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentDashboardApiController extends Controller
{
    public function __invoke(Request $request, StudentDashboardAccess $accessService): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'student' && $user->school_id && $user->organization_id, 403);
        $student = DB::table('students as st')
            ->join('schools as sc', 'sc.id', '=', 'st.school_id')
            ->leftJoin('classes as cl', function ($join) {
                $join->on('cl.id', '=', 'st.class_id')->on('cl.school_id', '=', 'st.school_id');
            })
            ->where('st.user_id', $user->id)->where('st.school_id', $user->school_id)
            ->where('sc.organization_id', $user->organization_id)
            ->first(['st.id', 'st.school_id', 'st.full_name', 'cl.name as class_name']);
        abort_unless($student, 403, 'Akun tidak memiliki profil siswa yang valid');

        $access = $accessService->resolve($user->organization_id, $student->school_id, $user->id, $student->id);
        if ($access === null) {
            return response()->json(['message' => 'Paket akses sumber belum dimigrasikan'], 503)
                ->header('Cache-Control', 'no-store');
        }

        $moduleIds = $accessService->moduleIds($user->organization_id, $student->school_id, $student->id, $access);
        $types = $accessService->assignmentTypes($access);
        $assignments = DB::table('assignments as a')
            ->join('course_modules as m', 'm.id', '=', 'a.module_id')
            ->join('courses as c', 'c.id', '=', 'a.course_id')
            ->join('course_enrollments as e', 'e.course_id', '=', 'c.id')
            ->where('e.student_id', $student->id)->where('e.school_id', $student->school_id)
            ->where('e.status', 'ACTIVE')->where('c.school_id', $student->school_id)
            ->where('c.organization_id', $user->organization_id)->where('c.is_active', true)
            ->whereColumn('m.course_id', 'c.id')->where('m.is_published', true)
            ->whereIn('a.module_id', $moduleIds)->whereIn('a.type', $types)
            ->where('a.is_active', true)->whereNotNull('a.published_at')
            ->where('a.published_at', '<=', now())->where('a.end_date', '>=', now())
            ->where('a.organization_id', $user->organization_id);

        $activeCount = ($moduleIds->isEmpty() || $types === []) ? 0 : (clone $assignments)->count();
        $pendingCount = ($moduleIds->isEmpty() || $types === []) ? 0 : (clone $assignments)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('submissions as s')
                ->whereColumn('s.assignment_id', 'a.id')->where('s.user_id', $user->id))
            ->count();

        $affiliateAccess = $accessService->enabled($access, 'affiliate.metrics.manage');
        $videos = $affiliateAccess ? DB::table('affiliate_videos as v')
            ->join('submissions as sub', 'sub.id', '=', 'v.submission_id')
            ->where('v.student_id', $student->id)->where('sub.user_id', $user->id)->get(['v.id']) : collect();
        $totals = ['views' => 0, 'clicks' => 0, 'orders' => 0, 'gmv' => 0.0, 'commission' => 0.0];
        if ($videos->isNotEmpty()) {
            $snapshots = DB::table('affiliate_metric_snapshots as s')
                ->whereIn('s.affiliate_video_id', $videos->pluck('id'))
                ->where('s.student_id', $student->id)->where('s.school_id', $student->school_id)
                ->where('s.organization_id', $user->organization_id)->where('s.verification', 'VERIFIED')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('affiliate_metric_snapshots as corr')
                    ->whereColumn('corr.supersedes_id', 's.id'))
                ->orderBy('s.affiliate_video_id')->orderByDesc('s.snapshot_date')->orderByDesc('s.id')
                ->get(['s.affiliate_video_id', 's.views', 's.clicks', 's.orders', 's.gmv', 's.commission'])
                ->unique('affiliate_video_id');
            foreach ($snapshots as $snapshot) {
                foreach (array_keys($totals) as $key) $totals[$key] += (float) $snapshot->$key;
            }
        }

        return response()->json([
            'studentInfo' => ['fullName' => $student->full_name, 'className' => $student->class_name ?: 'Belum ada kelas'],
            'kpi' => [
                ...$totals,
                'ctr' => $totals['views'] > 0 ? $totals['clicks'] / $totals['views'] * 100 : 0,
                'conversionRate' => $totals['clicks'] > 0 ? $totals['orders'] / $totals['clicks'] * 100 : 0,
            ],
            'activeAssignmentsCount' => $activeCount,
            'pendingTasksCount' => $pendingCount,
            'recentVideosCount' => $videos->count(),
            'affiliateAccess' => $affiliateAccess,
        ])->header('Cache-Control', 'no-store');
    }
}
