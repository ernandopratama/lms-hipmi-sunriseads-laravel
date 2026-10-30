<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAffiliateMetricImport;
use App\Support\AffiliateSnapshotWriter;
use App\Support\AffiliateUrl;
use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Affiliate content submission, manual metric snapshots and bulk metric imports. */
class AffiliateWriteApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access, private AffiliateSnapshotWriter $snapshots) {}

    private function iso($v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function entitled(array $c, string $key, string $message): array
    {
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, $key), 403, $message);
        return $plan;
    }

    public function content(Request $request)
    {
        $c = $this->context->resolve($request, 'affiliate', 'create');
        $plan = $this->entitled($c, 'affiliate.content.submit', 'Affiliate tidak termasuk dalam paket aktif');
        abort_unless($c['student'], 403, 'Profil siswa dan konteks sekolah diperlukan');
        $d = $request->validate(['assignmentId' => 'required|string|max:191', 'affiliateAccountId' => 'required|string|max:191', 'platform' => 'required|in:TIKTOK,SHOPEE,INSTAGRAM,YOUTUBE',
            'videoUrl' => 'required|url:http,https|max:2048', 'externalId' => 'nullable|string|max:200', 'publishedAt' => 'required|date', 'caption' => 'nullable|string|max:2200', 'hook' => 'nullable|string|max:500',
            'productName' => 'required|string|min:1|max:300', 'productExternalId' => 'nullable|string|max:200', 'productUrl' => 'required|url:http,https|max:2048', 'campaignName' => 'nullable|string|max:300',
            'evidenceUrl' => 'required|url:http,https|max:2048', 'insightsEvidenceUrl' => 'nullable|url:http,https|max:2048', 'studentNote' => 'nullable|string|max:2000',
            'disclosureAccepted' => 'required|accepted', 'ownershipDeclared' => 'required|accepted']);
        $student = $c['student']->id;
        $assignment = DB::table('assignments as a')->join('courses as co', 'co.id', '=', 'a.course_id')->where('a.id', $d['assignmentId'])->where('a.is_active', true)->where('a.type', 'AFFILIATE')
            ->where('a.published_at', '<=', now())->where(fn ($q) => $q->whereNull('a.start_date')->orWhere('a.start_date', '<=', now()))->where(fn ($q) => $q->whereNull('a.end_date')->orWhere('a.end_date', '>=', now()))
            ->where('co.school_id', $c['school'])->where('co.organization_id', $c['organization'])->where('co.is_active', true)
            ->whereIn('a.module_id', $this->access->moduleIds($c['organization'], $c['school'], $student, $plan))
            ->whereIn('a.course_id', DB::table('course_enrollments')->where('student_id', $student)->where('status', 'ACTIVE')->select('course_id'))->first(['a.*']);
        abort_unless($assignment, 403, 'Tugas belum dibuka, sudah berakhir, atau tidak tersedia');
        $account = DB::table('affiliate_accounts')->where('id', $d['affiliateAccountId'])->where('student_id', $student)->where('school_id', $c['school'])->where('platform', $d['platform'])->first();
        abort_unless($account, 400, 'Akun affiliate tidak sesuai dengan siswa atau platform');

        $published = CarbonImmutable::parse($d['publishedAt'], 'UTC');
        abort_if(($assignment->start_date && $published->lt(CarbonImmutable::parse($assignment->start_date, 'UTC'))) || ($assignment->end_date && $published->gt(CarbonImmutable::parse($assignment->end_date, 'UTC'))), 400, 'Tanggal publikasi harus berada dalam periode tugas');
        $url = AffiliateUrl::normalizeContent($d['videoUrl'], $d['platform']);
        $productUrl = AffiliateUrl::normalizePublic($d['productUrl'], 'URL produk');
        $evidence = AffiliateUrl::normalizePublic($d['evidenceUrl'], 'URL bukti publikasi');
        $insights = ! empty($d['insightsEvidenceUrl']) ? AffiliateUrl::normalizePublic($d['insightsEvidenceUrl'], 'URL bukti insight') : null;
        $external = trim($d['externalId'] ?? '') ?: AffiliateUrl::externalId($url, $d['platform']);
        $sub = DB::table('submissions')->where('assignment_id', $assignment->id)->where('student_id', $student)->first();
        abort_if($sub && ! in_array($sub->status, ['DRAFT', 'REVISION_REQUIRED'], true), 400, 'Konten tidak dapat diubah pada status saat ini');
        $existing = $sub ? DB::table('affiliate_videos')->where('submission_id', $sub->id)->orderBy('created_at')->first() : null;
        $duplicate = DB::table('affiliate_videos as v')->join('students as st', 'st.id', '=', 'v.student_id')->where('st.school_id', $c['school'])->when($existing, fn ($q) => $q->where('v.id', '!=', $existing->id))
            ->where(fn ($q) => $q->where('v.normalized_url', $url)->when($external, fn ($q) => $q->orWhere('v.external_id', $external)))->first(['v.id', 'v.student_id']);
        $handle = AffiliateUrl::username($url, $d['platform']);
        $mismatch = $handle && $handle !== strtolower(ltrim($account->username, '@'));
        $note = trim($d['studentNote'] ?? '') ?: null;
        $snapshot = ['affiliateAccountId' => $account->id, 'platform' => $d['platform'], 'videoUrl' => $url, 'externalId' => $external, 'publishedAt' => $published->format('Y-m-d\TH:i:s.v\Z'),
            'caption' => trim($d['caption'] ?? '') ?: null, 'hook' => trim($d['hook'] ?? '') ?: null, 'productName' => trim($d['productName']), 'productExternalId' => trim($d['productExternalId'] ?? '') ?: null,
            'productUrl' => $productUrl, 'campaignName' => trim($d['campaignName'] ?? '') ?: null, 'evidenceUrl' => $evidence, 'insightsEvidenceUrl' => $insights, 'disclosureAccepted' => true, 'ownershipDeclared' => true];
        $fields = ['affiliate_account_id' => $account->id, 'platform' => $d['platform'], 'video_url' => $url, 'normalized_url' => $url, 'external_id' => $external, 'published_at' => $published,
            'caption' => $snapshot['caption'], 'hook' => $snapshot['hook'], 'product_name' => $snapshot['productName'], 'product_external_id' => $snapshot['productExternalId'], 'product_url' => $productUrl,
            'campaign_name' => $snapshot['campaignName'], 'evidence_url' => $evidence, 'insights_evidence_url' => $insights, 'student_note' => $note, 'disclosure_accepted' => true, 'ownership_declared' => true];

        $videoId = DB::transaction(function () use ($c, $assignment, $student, $sub, $existing, $snapshot, $fields, $note, $url): string {
            $subId = $sub->id ?? null;
            $revision = 1;
            if (! $sub) {
                $subId = (string) Str::ulid();
                DB::table('submissions')->insert(['id' => $subId, 'assignment_id' => $assignment->id, 'user_id' => $c['user'], 'student_id' => $student, 'body' => '', 'status' => 'DRAFT', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $revision = ((int) DB::table('submission_versions')->where('submission_id', $subId)->max('revision')) + 1;
                DB::table('submissions')->where('id', $subId)->update(['revision' => $revision, 'updated_at' => now()]);
            }
            DB::table('submission_versions')->insert(['id' => (string) Str::ulid(), 'submission_id' => $subId, 'revision' => $revision, 'body' => '', 'status' => 'DRAFT', 'recorded_at' => now(),
                'notes' => $note, 'video_url' => $url, 'snapshot_data' => json_encode($snapshot), 'created_at' => now(), 'updated_at' => now()]);
            if (! $existing) {
                $vid = (string) Str::ulid();
                DB::table('affiliate_videos')->insert($fields + ['id' => $vid, 'submission_id' => $subId, 'student_id' => $student, 'data_source' => 'MANUAL', 'verification' => 'UNVERIFIED',
                    'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('affiliate_content_versions')->insert(['id' => (string) Str::ulid(), 'affiliate_video_id' => $vid, 'version_number' => 1, 'snapshot_data' => json_encode($snapshot), 'student_note' => $note, 'created_at' => now()]);
                return $vid;
            }
            $next = (int) $existing->current_version + 1;
            DB::table('affiliate_content_versions')->insert(['id' => (string) Str::ulid(), 'affiliate_video_id' => $existing->id, 'version_number' => $next, 'snapshot_data' => json_encode($snapshot), 'student_note' => $note, 'created_at' => now()]);
            DB::table('affiliate_videos')->where('id', $existing->id)->update($fields + ['verification' => 'UNVERIFIED', 'current_version' => $next, 'updated_at' => now()]);
            return $existing->id;
        });

        $signals = array_filter([
            $duplicate ? ['DUPLICATE_CONTENT', 'URL atau ID konten juga ditemukan pada pengumpulan lain di sekolah ini.', ['matchingVideoId' => $duplicate->id, 'sameStudent' => $duplicate->student_id === $student]] : null,
            $mismatch ? ['ACCOUNT_OWNERSHIP_MISMATCH', 'Nama akun pada URL konten berbeda dari akun affiliate yang dipilih.', ['urlUsername' => $handle, 'accountUsername' => $account->username]] : null,
        ]);
        foreach ($signals as [$type, $reason, $evidenceData]) {
            if (! DB::table('affiliate_fraud_flags')->where('affiliate_video_id', $videoId)->where('status', 'OPEN')->where('type', $type)->exists()) {
                DB::table('affiliate_fraud_flags')->insert(['id' => (string) Str::ulid(), 'affiliate_video_id' => $videoId, 'type' => $type, 'reason' => $reason, 'evidence' => json_encode($evidenceData), 'confidence' => 0.8, 'status' => 'OPEN', 'created_at' => now()]);
            }
        }
        $v = DB::table('affiliate_videos')->where('id', $videoId)->first();
        $s = DB::table('submissions')->where('id', $v->submission_id)->first();
        return response()->json(['id' => $v->id, 'submissionId' => $v->submission_id, 'studentId' => $v->student_id, 'affiliateAccountId' => $v->affiliate_account_id, 'platform' => $v->platform, 'videoUrl' => $v->video_url,
            'normalizedUrl' => $v->normalized_url, 'externalId' => $v->external_id, 'publishedAt' => $this->iso($v->published_at), 'caption' => $v->caption, 'hook' => $v->hook, 'productName' => $v->product_name,
            'productExternalId' => $v->product_external_id, 'productUrl' => $v->product_url, 'campaignName' => $v->campaign_name, 'evidenceUrl' => $v->evidence_url, 'insightsEvidenceUrl' => $v->insights_evidence_url,
            'studentNote' => $v->student_note, 'disclosureAccepted' => (bool) $v->disclosure_accepted, 'ownershipDeclared' => (bool) $v->ownership_declared, 'verification' => $v->verification, 'currentVersion' => (int) $v->current_version,
            'createdAt' => $this->iso($v->created_at), 'updatedAt' => $this->iso($v->updated_at),
            'submission' => ['id' => $s->id, 'assignmentId' => $s->assignment_id, 'studentId' => $s->student_id, 'status' => $s->status, 'currentVersion' => (int) $s->revision],
            'versions' => DB::table('affiliate_content_versions')->where('affiliate_video_id', $videoId)->orderByDesc('version_number')->get()->map(fn ($x) => ['id' => $x->id, 'versionNumber' => (int) $x->version_number,
                'snapshotData' => json_decode($x->snapshot_data, true), 'studentNote' => $x->student_note, 'createdAt' => $this->iso($x->created_at)])->values()], 201);
    }

    public function snapshot(Request $request, string $videoId)
    {
        $c = $this->context->resolve($request, 'affiliate', 'create');
        $this->entitled($c, 'affiliate.metrics.manage', 'Metrik affiliate tidak termasuk dalam paket aktif');
        $d = $request->validate(AffiliateSnapshotWriter::rules());
        $m = $this->snapshots->create($c, $videoId, $d);
        return response()->json($this->metric($m), 201);
    }

    private function metric(object $m): array
    {
        return ['id' => $m->id, 'organizationId' => $m->organization_id, 'schoolId' => $m->school_id, 'studentId' => $m->student_id, 'affiliateVideoId' => $m->affiliate_video_id, 'affiliateAccountId' => $m->affiliate_account_id,
            'importJobId' => $m->import_job_id, 'createdById' => $m->created_by_id, 'platform' => $m->platform, 'views' => (int) $m->views, 'likes' => (int) $m->likes, 'comments' => (int) $m->comments, 'shares' => (int) $m->shares,
            'clicks' => (int) $m->clicks, 'orders' => (int) $m->orders, 'unitsSold' => (int) $m->units_sold, 'refunds' => (int) $m->refunds, 'ctr' => (string) ($m->ctr + 0), 'gmv' => (string) ($m->gmv + 0),
            'conversionRate' => (string) ($m->conversion_rate + 0), 'averageOrderValue' => (string) ($m->average_order_value + 0), 'commission' => (string) ($m->commission + 0), 'commissionRate' => (string) ($m->commission_rate + 0),
            'currency' => $m->currency, 'snapshotDate' => $this->iso($m->snapshot_date), 'periodStart' => $this->iso($m->period_start), 'periodEnd' => $this->iso($m->period_end), 'source' => $m->source,
            'sourceReference' => $m->source_reference, 'evidenceUrl' => $m->evidence_url, 'supersedesId' => $m->supersedes_id, 'rawValues' => json_decode($m->raw_values, true),
            'dataQualityFlags' => json_decode($m->data_quality_flags, true), 'verification' => $m->verification, 'createdAt' => $this->iso($m->created_at)];
    }

    public function import(Request $request)
    {
        $c = $this->context->resolve($request, 'affiliate', 'import');
        $this->entitled($c, 'affiliate.metrics.manage', 'Metrik affiliate tidak termasuk dalam paket aktif');
        $d = $request->validate(['sourceName' => 'required|string|min:1|max:255', 'rows' => 'required|array|min:1|max:10000', 'rows.*' => 'array']);
        abort_if(trim($d['sourceName']) === '', 400, 'Nama sumber impor wajib diisi');
        $rules = collect(AffiliateSnapshotWriter::rules(true))->mapWithKeys(fn ($rule, $key) => ['rows.*.'.$key => $rule])->all();
        $request->validate($rules);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($c, $d, $id): void {
            DB::table('import_jobs')->insert(['id' => $id, 'organization_id' => $c['organization'], 'school_id' => $c['school'], 'created_by_id' => $c['user'], 'type' => 'AFFILIATE_METRICS',
                'source_name' => trim($d['sourceName']), 'status' => 'QUEUED', 'total_rows' => count($d['rows']), 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_chunk($d['rows'], 500, true) as $chunk) {
                DB::table('import_rows')->insert(array_map(fn ($row, $i) => ['id' => (string) Str::ulid(), 'import_job_id' => $id, 'row_number' => $i + 2, 'status' => 'PENDING',
                    'payload' => json_encode($row), 'created_at' => now(), 'updated_at' => now()], $chunk, array_keys($chunk)));
            }
        });
        $j = DB::table('import_jobs')->where('id', $id)->first();
        ProcessAffiliateMetricImport::dispatch($id)->afterCommit();
        return response()->json(['id' => $j->id, 'status' => $j->status, 'totalRows' => (int) $j->total_rows, 'sourceName' => $j->source_name, 'createdAt' => $this->iso($j->created_at)], 201);
    }
}
