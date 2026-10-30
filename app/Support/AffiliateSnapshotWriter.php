<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateSnapshotWriter
{
    public static function rules(bool $import = false): array
    {
        return [...($import ? ['videoId' => 'required|string'] : []),
            'views' => 'required|integer|min:0', 'likes' => 'sometimes|integer|min:0',
            'comments' => 'sometimes|integer|min:0', 'shares' => 'sometimes|integer|min:0',
            'clicks' => 'required|integer|min:0', 'orders' => 'required|integer|min:0',
            'unitsSold' => 'required|integer|min:0', 'refunds' => 'sometimes|integer|min:0',
            'gmv' => 'required|numeric|min:0', 'commission' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|max:8', 'snapshotDate' => 'required|date',
            'periodStart' => 'sometimes|nullable|date', 'periodEnd' => 'sometimes|nullable|date',
            'evidenceUrl' => 'sometimes|nullable|url:http,https', 'sourceReference' => 'sometimes|nullable|string|max:300',
            'supersedesId' => 'sometimes|nullable|string|max:64'];
    }

    public function create(array $c, string $videoId, array $d, string $source = 'MANUAL', ?string $importId = null): object
    {
        $video = DB::table('affiliate_videos as v')->join('students as s', 's.id', '=', 'v.student_id')
            ->where('v.id', $videoId)->where('s.school_id', $c['school'])
            ->when($c['student'] ?? null, fn ($q) => $q->where('v.student_id', $c['student']->id))
            ->select('v.*')->first();
        abort_unless($video, 404, 'Konten affiliate tidak ditemukan');
        $snapshotDate = Carbon::parse($d['snapshotDate']);
        abort_if($snapshotDate->lt(Carbon::parse($video->published_at)) || $snapshotDate->isFuture(), 400,
            'Tanggal snapshot tidak valid');
        $start = isset($d['periodStart']) ? Carbon::parse($d['periodStart']) : null;
        $end = isset($d['periodEnd']) ? Carbon::parse($d['periodEnd']) : null;
        abort_if(($start && $end && $start->gt($end)) || ($end && $end->gt($snapshotDate)), 400,
            'Periode metrik tidak valid');
        $evidence = $d['evidenceUrl'] ?? null;
        if ($evidence) {
            $url = parse_url($evidence);
            $host = strtolower($url['host'] ?? '');
            abort_if(! $url || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
                || isset($url['user']) || isset($url['pass']) || $host === 'localhost'
                || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
                || filter_var($host, FILTER_VALIDATE_IP), 400, 'URL bukti metrik harus publik');
            $evidence = 'https://'.$host.($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
        }
        if (isset($d['supersedesId'])) abort_unless(DB::table('affiliate_metric_snapshots')
            ->where('id', $d['supersedesId'])->where('affiliate_video_id', $videoId)
            ->where('school_id', $c['school'])->whereNotIn('id', DB::table('affiliate_metric_snapshots')
                ->whereNotNull('supersedes_id')->select('supersedes_id'))->exists(), 400,
            'Snapshot yang dikoreksi tidak valid atau sudah memiliki koreksi');
        $previous = DB::table('affiliate_metric_snapshots')->where('affiliate_video_id', $videoId)
            ->where('snapshot_date', '<', $snapshotDate)->whereNotIn('id', DB::table('affiliate_metric_snapshots')
                ->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->orderByDesc('snapshot_date')->first();
        $duplicate = DB::table('affiliate_metric_snapshots')->where('affiliate_video_id', $videoId)
            ->where('snapshot_date', $snapshotDate)->exists() && ! isset($d['supersedesId']);
        $views = (int) $d['views']; $clicks = (int) $d['clicks']; $orders = (int) $d['orders'];
        $units = (int) $d['unitsSold']; $refunds = (int) ($d['refunds'] ?? 0);
        $gmv = (float) $d['gmv']; $commission = (float) $d['commission'];
        $flags = [];
        if ($clicks > $views) $flags[] = 'CLICK_EXCEEDS_VIEWS';
        if ($orders > $clicks) $flags[] = 'ORDERS_EXCEED_CLICKS';
        if ($refunds > $orders) $flags[] = 'REFUNDS_EXCEED_ORDERS';
        if ($units < $orders) $flags[] = 'UNITS_BELOW_ORDERS';
        if ($orders === 0 && $gmv > 0) $flags[] = 'GMV_WITHOUT_ORDERS';
        if ($gmv === 0 && $commission > 0) $flags[] = 'COMMISSION_WITHOUT_GMV';
        if ($gmv > 0 && $commission / $gmv > .5) $flags[] = 'SUSPICIOUS_COMMISSION_RATE';
        if (! $evidence) $flags[] = 'EVIDENCE_MISSING';
        if ($duplicate) $flags[] = 'DUPLICATE_TIMESTAMP';
        if ($previous && ((int) $previous->views > $views || (int) $previous->clicks > $clicks
            || (int) $previous->orders > $orders || (float) $previous->gmv > $gmv
            || (float) $previous->commission > $commission)) $flags[] = 'LIFETIME_METRIC_DECREASE';
        if ($previous && $previous->views > 0 && $views > $previous->views * 10
            && $views - $previous->views > 1000) $flags[] = 'EXTREME_CHANGE';
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $c, $video, $d, $source, $importId, $snapshotDate,
            $start, $end, $evidence, $flags, $views, $clicks, $orders, $units, $refunds, $gmv, $commission) {
            DB::table('affiliate_metric_snapshots')->insert(['id' => $id,
                'organization_id' => $c['organization'], 'school_id' => $c['school'],
                'student_id' => $video->student_id, 'affiliate_video_id' => $video->id,
                'affiliate_account_id' => $video->affiliate_account_id, 'import_job_id' => $importId,
                'created_by_id' => $c['user'], 'platform' => $video->platform,
                'views' => $views, 'likes' => $d['likes'] ?? 0, 'comments' => $d['comments'] ?? 0,
                'shares' => $d['shares'] ?? 0, 'clicks' => $clicks, 'orders' => $orders,
                'units_sold' => $units, 'refunds' => $refunds, 'gmv' => $gmv,
                'commission' => $commission, 'ctr' => $views ? round($clicks / $views * 100, 2) : 0,
                'conversion_rate' => $clicks ? round($orders / $clicks * 100, 2) : 0,
                'average_order_value' => $orders ? round($gmv / $orders, 2) : 0,
                'commission_rate' => $gmv ? round($commission / $gmv * 100, 2) : 0,
                'currency' => strtoupper($d['currency'] ?? 'IDR'), 'snapshot_date' => $snapshotDate,
                'period_start' => $start, 'period_end' => $end, 'source' => $source,
                'source_reference' => $d['sourceReference'] ?? null, 'evidence_url' => $evidence,
                'supersedes_id' => $d['supersedesId'] ?? null, 'raw_values' => json_encode([
                    'views' => $views, 'likes' => $d['likes'] ?? 0, 'comments' => $d['comments'] ?? 0,
                    'shares' => $d['shares'] ?? 0, 'clicks' => $clicks, 'orders' => $orders,
                    'unitsSold' => $units, 'refunds' => $refunds, 'gmv' => $gmv, 'commission' => $commission]),
                'data_quality_flags' => json_encode($flags),
                'verification' => $flags ? 'NEEDS_REVIEW' : 'UNVERIFIED', 'created_at' => now()]);
            foreach ($flags as $type) DB::table('affiliate_fraud_flags')->insert([
                'id' => (string) Str::ulid(), 'affiliate_video_id' => $video->id,
                'metric_snapshot_id' => $id, 'type' => $type, 'reason' => $type,
                'evidence' => json_encode(['snapshotDate' => $snapshotDate->toIso8601String(), 'source' => $source]),
                'confidence' => $type === 'EVIDENCE_MISSING' ? 1 : .8,
                'status' => 'OPEN', 'created_at' => now()]);
        });
        return DB::table('affiliate_metric_snapshots')->where('id', $id)->first();
    }
}
