<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PublicStatisticsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Match the source: select the latest 12 approved records, then apply
        // the minimum cohort rule before serializing any response.
        $items = DB::table('public_statistics as stats')
            ->leftJoin('schools', 'schools.id', '=', 'stats.school_id')
            ->where('stats.status', 'APPROVED')
            ->whereNotNull('stats.published_at')
            ->orderByDesc('stats.published_at')->limit(12)
            ->get(['stats.id', 'stats.title', 'stats.period_start', 'stats.period_end',
                'stats.cohort_size', 'stats.minimum_cohort', 'stats.metrics', 'stats.published_at',
                'schools.name as school_name', 'schools.city', 'schools.province']);

        return response()->json($items->filter(fn ($item) => $item->cohort_size >= $item->minimum_cohort)
            ->values()->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'periodStart' => Carbon::parse($item->period_start, 'UTC')->toISOString(),
            'periodEnd' => Carbon::parse($item->period_end, 'UTC')->toISOString(),
            'cohortSize' => $item->cohort_size,
            'metrics' => json_decode($item->metrics, true),
            'publishedAt' => Carbon::parse($item->published_at, 'UTC')->toISOString(),
            'school' => $item->school_name ? ['name' => $item->school_name, 'city' => $item->city, 'province' => $item->province] : null,
        ]))->header('Cache-Control', 'no-store');
    }
}
