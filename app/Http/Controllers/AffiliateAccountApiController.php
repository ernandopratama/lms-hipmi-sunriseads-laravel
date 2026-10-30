<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AffiliateAccountApiController extends Controller
{
    private const HOSTS = [
        'TIKTOK' => ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com', 'vm.tiktok.com'],
        'SHOPEE' => ['shopee.co.id', 'www.shopee.co.id', 'shopee.com', 'www.shopee.com', 's.shopee.co.id'],
        'INSTAGRAM' => ['instagram.com', 'www.instagram.com'],
        'YOUTUBE' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
    ];

    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'affiliate', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'affiliate.content.submit'), 403,
            'Affiliate tidak termasuk dalam paket aktif');
        return $c;
    }

    private function output(object $r): array
    {
        $s = DB::table('students')->where('id', $r->student_id)->first();
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'studentId' => $r->student_id, 'platform' => $r->platform, 'username' => $r->username,
            'displayName' => $r->display_name, 'profileUrl' => $r->profile_url,
            'externalAccountId' => $r->external_account_id, 'status' => $r->status,
            'connectedAt' => $r->connected_at, 'reviewerNotes' => $r->reviewer_notes,
            'reviewedById' => $r->reviewed_by_id, 'reviewedAt' => $r->reviewed_at,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at,
            'student' => $s ? ['id' => $s->id, 'fullName' => $s->full_name,
                'studentNumber' => $s->student_number] : null,
            '_count' => ['videos' => DB::table('affiliate_videos')->where('affiliate_account_id', $r->id)->count()]];
    }

    public function providers(Request $request)
    {
        $this->scope($request, 'read');
        return response()->json(collect(array_keys(self::HOSTS))->map(fn ($platform) => [
            'platform' => $platform, 'supportsOAuth' => false, 'supportsMetricSync' => false,
            'inputMode' => 'MANUAL_OR_IMPORT']));
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        $d = $request->validate(['studentId' => 'sometimes|string']);
        $q = DB::table('affiliate_accounts')->where('organization_id', $c['organization'])->where('school_id', $c['school']);
        if ($c['student']) $q->where('student_id', $c['student']->id);
        elseif (isset($d['studentId'])) $q->where('student_id', $d['studentId']);
        return response()->json($q->orderByDesc('created_at')->limit(200)->get()->map(fn ($r) => $this->output($r)));
    }

    private function profileUrl(string $value, string $platform): string
    {
        $parts = parse_url(trim($value));
        abort_unless($parts && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])
            && empty($parts['user']) && empty($parts['pass'])
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS[$platform], true), 400,
            'Domain URL tidak sesuai dengan platform');
        $host = strtolower($parts['host']);
        $path = preg_replace('#/+#', '/', $parts['path'] ?? '/');
        $path = rtrim($path, '/') ?: '/';
        $query = [];
        parse_str($parts['query'] ?? '', $params);
        foreach ($params as $key => $v) if (! str_starts_with(strtolower($key), 'utm_')
            && ! in_array(strtolower($key), ['fbclid', 'gclid', 'igshid', 'spm'], true)) $query[$key] = $v;
        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($path === '/' && ! $query ? '' : $path).($query ? '?'.http_build_query($query) : '');
    }

    public function store(Request $request)
    {
        $c = $this->scope($request, 'create');
        abort_unless($c['student'], 403, 'Profil siswa dan konteks sekolah diperlukan');
        $d = $request->validate(['platform' => 'required|in:TIKTOK,SHOPEE,INSTAGRAM,YOUTUBE',
            'username' => 'required|string|min:1|max:100', 'displayName' => 'sometimes|nullable|string|max:160',
            'profileUrl' => 'required|url:http,https', 'externalAccountId' => 'sometimes|nullable|string|max:200']);
        $username = ltrim(trim($d['username']), '@');
        abort_if(DB::table('affiliate_accounts')->where('student_id', $c['student']->id)
            ->where('platform', $d['platform'])->where('username', $username)->exists(), 400,
            'Akun affiliate ini sudah terdaftar');
        $id = (string) Str::ulid();
        DB::table('affiliate_accounts')->insert(['id' => $id, 'organization_id' => $c['organization'],
            'school_id' => $c['school'], 'student_id' => $c['student']->id,
            'platform' => $d['platform'], 'username' => $username,
            'display_name' => trim($d['displayName'] ?? '') ?: null,
            'profile_url' => $this->profileUrl($d['profileUrl'], $d['platform']),
            'external_account_id' => trim($d['externalAccountId'] ?? '') ?: null,
            'status' => 'PENDING_VERIFICATION', 'connected_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->output(DB::table('affiliate_accounts')->where('id', $id)->first()), 201);
    }

    public function review(Request $request, string $id)
    {
        $c = $this->scope($request, 'verify');
        $d = $request->validate(['status' => 'required|in:VERIFIED,REJECTED', 'notes' => 'sometimes|nullable|string|max:2000']);
        return DB::transaction(function () use ($c, $d, $id) {
            $r = DB::table('affiliate_accounts')->where('id', $id)->where('organization_id', $c['organization'])
                ->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($r, 404, 'Akun affiliate tidak ditemukan');
            abort_unless($r->status === 'PENDING_VERIFICATION', 400, 'Akun affiliate sudah memiliki keputusan final');
            abort_if($d['status'] === 'REJECTED' && ! trim($d['notes'] ?? ''), 400, 'Catatan wajib untuk penolakan');
            DB::table('affiliate_account_reviews')->insert(['id' => (string) Str::ulid(), 'account_id' => $id,
                'reviewer_id' => $c['user'], 'decision' => $d['status'],
                'notes' => trim($d['notes'] ?? '') ?: null, 'created_at' => now()]);
            DB::table('affiliate_accounts')->where('id', $id)->update(['status' => $d['status'],
                'reviewer_notes' => trim($d['notes'] ?? '') ?: null, 'reviewed_by_id' => $c['user'],
                'reviewed_at' => now(), 'updated_at' => now()]);
            return response()->json($this->output(DB::table('affiliate_accounts')->where('id', $id)->first()));
        });
    }
}
