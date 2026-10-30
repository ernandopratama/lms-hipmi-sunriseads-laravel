<?php

namespace App\Http\Controllers;

use App\Support\{ApiContext, StudentDashboardAccess};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolDashboardApiController extends Controller
{
    public function __invoke(Request $request, ApiContext $context, StudentDashboardAccess $access): JsonResponse
    {
        // Like the source: the dashboard:read permission within one selected school; no fixed role.
        $c = $context->resolve($request, 'dashboard', 'read');
        $schoolId = $c['school'];

        $resolved = $access->resolve($c['organization'], $schoolId, $c['user'], $c['student']?->id);
        abort_unless($resolved, 503, 'Paket akses sumber belum dimigrasikan');
        $advanced = $access->enabled($resolved, 'dashboards.advanced.read');

        $performance = ['totalViews' => 0, 'totalClicks' => 0, 'totalOrders' => 0, 'totalGmv' => 0, 'totalCommission' => 0];
        if ($advanced) {
            // Latest verified snapshot per video that no later correction supersedes.
            $latest = DB::table('affiliate_metric_snapshots as s')->where('s.school_id', $schoolId)->where('s.verification', 'VERIFIED')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('affiliate_metric_snapshots as c')->whereColumn('c.supersedes_id', 's.id'))
                ->orderBy('s.affiliate_video_id')->orderByDesc('s.snapshot_date')->get(['s.affiliate_video_id', 's.views', 's.clicks', 's.orders', 's.gmv', 's.commission'])
                ->unique('affiliate_video_id');
            $performance = ['totalViews' => (int) $latest->sum('views'), 'totalClicks' => (int) $latest->sum('clicks'), 'totalOrders' => (int) $latest->sum('orders'),
                'totalGmv' => (float) $latest->sum('gmv'), 'totalCommission' => (float) $latest->sum('commission')];
        }

        return response()->json([
            'stats' => [
                'totalStudents' => DB::table('students')->where('school_id', $schoolId)->count(),
                'totalTeachers' => DB::table('teachers')->where('school_id', $schoolId)->count(),
                'totalClasses' => DB::table('classes')->where('school_id', $schoolId)->count(),
            ],
            'performance' => $performance,
            'advancedAccess' => $advanced,
        ])->header('Cache-Control', 'no-store');
    }
}
