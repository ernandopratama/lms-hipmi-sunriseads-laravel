<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Manual-transfer checkout and approval; mirrors apps/api/src/commerce. */
class CommerceApiController extends Controller
{
    private const OPEN_ATTEMPT = ['CREATED', 'PENDING', 'AUTHORIZED'];

    public function __construct(private ApiContext $context) {}

    private function account(): array
    {
        return [
            'bankName' => env('PAYMENT_BANK_NAME', 'BANK DEMO'),
            'accountNumber' => env('PAYMENT_BANK_ACCOUNT_NUMBER', '0000000000'),
            'accountHolder' => env('PAYMENT_BANK_ACCOUNT_HOLDER', 'SUNRISE ACADEMY DEMO'),
        ];
    }

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Prisma Decimal#toString drops trailing zeros. */
    private function dec(string|int|float|null $v): string
    {
        $s = (string) $v;
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    private function expired(string $status, ?string $expiresAt, ?CarbonImmutable $now = null): bool
    {
        return $status === 'PENDING_PAYMENT' && $expiresAt !== null
            && CarbonImmutable::parse($expiresAt, 'UTC')->lessThanOrEqualTo($now ?? CarbonImmutable::now('UTC'));
    }

    private function audit(?string $org, ?string $actor, string $action, string $id, array $details): void
    {
        DB::table('billing_audit_events')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'actor_id' => $actor,
            'action' => $action, 'resource' => 'order', 'resource_id' => $id, 'safe_details' => json_encode($details), 'created_at' => now()]);
    }

    private function lockOrder(string $id)
    {
        return DB::table('orders')->where('id', $id)->lockForUpdate()->first();
    }

    /** Serialize unique-code allocation across concurrent checkouts. */
    private function lockCodes(): void
    {
        if (DB::getDriverName() === 'mysql') DB::select('SELECT GET_LOCK(?, 10)', ['sunrise-order-unique-code']);
    }

    private function unlockCodes(): void
    {
        if (DB::getDriverName() === 'mysql') DB::select('SELECT RELEASE_LOCK(?)', ['sunrise-order-unique-code']);
    }

    private function allocateCode(string $currency, string $amount): int
    {
        $used = DB::table('orders')->where('status', 'PENDING_PAYMENT')->where('payment_expires_at', '>', now())
            ->where('currency', $currency)->where('subtotal_amount', $amount)->where('fee_amount', '>', 0)
            ->pluck('fee_amount')->map(fn ($v) => (int) $v)->flip();
        $start = random_int(1, 999);
        for ($i = 0; $i < 999; $i++) {
            $candidate = (($start + $i - 1) % 999) + 1;
            if (! $used->has($candidate)) return $candidate;
        }
        abort(409, 'Kode unik pembayaran sedang penuh. Coba lagi setelah pesanan lain kedaluwarsa.');
    }

    private function expirePending(array $scope): void
    {
        $ids = DB::table('orders')->where($scope)->where('status', 'PENDING_PAYMENT')->where('payment_expires_at', '<=', now())->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $o = $this->lockOrder($id);
                if ($o && $this->expired($o->status, $o->payment_expires_at)) $this->markExpired($o);
            });
        }
    }

    private function markExpired(object $o): void
    {
        DB::table('orders')->where('id', $o->id)->update(['status' => 'EXPIRED', 'updated_at' => now()]);
        DB::table('payment_attempts')->where('order_id', $o->id)->whereIn('status', self::OPEN_ATTEMPT)
            ->update(['status' => 'EXPIRED', 'failed_at' => now(), 'failure_code' => 'PAYMENT_DEADLINE_EXPIRED', 'updated_at' => now()]);
        $this->audit($o->organization_id, null, 'ORDER_EXPIRED', $o->id, ['orderNumber' => $o->order_number, 'expiredAt' => now()->utc()->toISOString()]);
    }

    private function present(string $orderId): array
    {
        $o = DB::table('orders')->where('id', $orderId)->first();
        $items = DB::table('order_items as i')->join('access_plans as p', 'p.id', '=', 'i.access_plan_id')
            ->join('plan_offers as f', 'f.id', '=', 'i.plan_offer_id')->where('i.order_id', $orderId)->orderBy('i.created_at')
            ->get(['i.*', 'p.code as plan_code', 'p.name as plan_name', 'p.module_limit', 'f.slug as offer_slug', 'f.name as offer_name', 'f.access_duration_days']);
        $items = $items->map(fn ($i) => [
            'id' => $i->id, 'quantityOrSeats' => (int) $i->quantity_or_seats,
            'unitAmount' => $this->dec($i->unit_amount), 'lineTotal' => $this->dec($i->line_total),
            'accessStartsAt' => $this->iso($i->access_starts_at), 'accessEndsAt' => $this->iso($i->access_ends_at),
            'plan' => ['id' => $i->access_plan_id, 'code' => $i->plan_code, 'name' => $i->plan_name, 'moduleLimit' => (int) $i->module_limit],
            'offer' => ['slug' => $i->offer_slug, 'name' => $i->offer_name, 'accessDurationDays' => $i->access_duration_days === null ? null : (int) $i->access_duration_days],
            'beneficiaries' => DB::table('purchase_beneficiaries')->where('order_item_id', $i->id)->get()->map(fn ($b) => [
                'id' => $b->id, 'beneficiaryType' => $b->beneficiary_type, 'userId' => $b->user_id,
                'schoolId' => $b->school_id, 'verificationStatus' => $b->verification_status])->all(),
            'accessGrants' => DB::table('access_grants')->where('order_item_id', $i->id)->get()->map(fn ($g) => [
                'id' => $g->id, 'userId' => $g->user_id, 'targetType' => $g->target_type, 'source' => $g->source,
                'status' => $g->status, 'startsAt' => $this->iso($g->starts_at), 'endsAt' => $this->iso($g->ends_at)])->all(),
        ])->all();
        $a = DB::table('payment_attempts')->where('order_id', $orderId)->orderByDesc('created_at')->first();
        $pending = $o->status === 'PENDING_PAYMENT' && ! $this->expired($o->status, $o->payment_expires_at);
        $acct = $this->account();
        return [
            'id' => $o->id, 'orderNumber' => $o->order_number, 'status' => $o->status, 'currency' => $o->currency,
            'subtotalAmount' => $this->dec($o->subtotal_amount), 'discountAmount' => $this->dec($o->discount_amount),
            'feeAmount' => $this->dec($o->fee_amount), 'totalAmount' => $this->dec($o->total_amount),
            'paymentExpiresAt' => $this->iso($o->payment_expires_at), 'paidAt' => $this->iso($o->paid_at),
            'createdAt' => $this->iso($o->created_at), 'cancelledAt' => $this->iso($o->cancelled_at),
            'items' => $items, 'pricingUnit' => 'PER_STUDENT',
            'payment' => $a ? ['id' => $a->id, 'provider' => $a->provider, 'methodType' => $a->method_type, 'status' => $a->status,
                'requestedAmount' => $this->dec($a->requested_amount), 'currency' => $a->currency, 'providerReference' => $a->provider_reference,
                'paymentUrlExpiresAt' => $this->iso($a->payment_url_expires_at), 'createdAt' => $this->iso($a->created_at)] : null,
            'paymentInstructions' => $pending ? ['method' => 'BANK_TRANSFER'] + $acct + ['baseAmount' => $this->dec($o->subtotal_amount),
                'uniqueCode' => (int) $o->fee_amount, 'transferAmount' => $this->dec($o->total_amount), 'expiresAt' => $this->iso($o->payment_expires_at)] : null,
            'nextAction' => $pending ? ['type' => 'BANK_TRANSFER', 'message' => "Transfer tepat sesuai total hingga kode unik ke {$acct['bankName']} {$acct['accountNumber']} a.n. {$acct['accountHolder']}. Akses aktif setelah pembayaran terverifikasi."] : null,
        ];
    }

    private function ensureCode(string $orderId): void
    {
        $this->lockCodes();
        try {
            DB::transaction(function () use ($orderId): void {
                $o = $this->lockOrder($orderId);
                if ($o->status !== 'PENDING_PAYMENT' || (float) $o->fee_amount !== 0.0 || ! $o->payment_expires_at
                    || CarbonImmutable::parse($o->payment_expires_at, 'UTC')->lessThanOrEqualTo(now())) return;
                $code = $this->allocateCode($o->currency, $o->subtotal_amount);
                $total = bcadd((string) $o->total_amount, (string) $code, 2);
                DB::table('orders')->where('id', $o->id)->update(['fee_amount' => $code, 'total_amount' => $total, 'updated_at' => now()]);
                DB::table('payment_attempts')->where('order_id', $o->id)->whereIn('status', self::OPEN_ATTEMPT)->update(['requested_amount' => $total, 'updated_at' => now()]);
                $this->audit($o->organization_id, $o->purchaser_user_id, 'PAYMENT_UNIQUE_CODE_ASSIGNED', $o->id, ['uniqueCode' => $code, 'totalAmount' => $this->dec($total), 'currency' => $o->currency]);
            });
        } finally {
            $this->unlockCodes();
        }
    }

    private function needsCode(object $o): bool
    {
        return $o->status === 'PENDING_PAYMENT' && (float) $o->fee_amount === 0.0 && $o->payment_expires_at
            && CarbonImmutable::parse($o->payment_expires_at, 'UTC')->greaterThan(now());
    }

    private function mine(Request $request, string $id): array
    {
        $o = DB::table('orders')->where('id', $id)->first();
        if ($o && $this->needsCode($o)) $this->ensureCode($id);
        return $this->present($id);
    }

    public function create(Request $request)
    {
        $d = $request->validate(['offerSlug' => 'required|string|min:2|max:120', 'idempotencyKey' => 'required|uuid', 'acceptTerms' => 'required|boolean']);
        abort_unless($d['acceptTerms'], 400, 'Persetujuan syarat pembelian wajib diberikan');
        $user = $request->user();
        $this->expirePending([['purchaser_user_id', $user->id]]);
        $existing = DB::table('orders')->where('purchaser_user_id', $user->id)->where('idempotency_key', $d['idempotencyKey'])->first();
        if ($existing) return response()->json($this->present($existing->id), 201);

        $now = now();
        $offer = DB::table('plan_offers')->where('slug', $d['offerSlug'])->where('status', 'ACTIVE')->where('audience', 'INDIVIDUAL')
            ->where(fn ($q) => $q->whereNull('sales_start_at')->orWhere('sales_start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('sales_end_at')->orWhere('sales_end_at', '>', $now))->first();
        abort_unless($offer, 404, 'Paket tidak tersedia');
        abort_if($offer->billing_model === 'FREE', 400, 'Paket Gratis tidak memerlukan pembelian');
        $price = DB::table('offer_prices')->where('plan_offer_id', $offer->id)->where('status', 'ACTIVE')->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))->orderByDesc('effective_from')->first();
        abort_if(! $price || (float) $price->amount <= 0, 400, 'Harga paket belum tersedia');
        abort_if(! $offer->terms_version || ! $offer->refund_policy_version, 400, 'Syarat pembelian paket belum lengkap');
        $plan = DB::table('access_plans')->where('id', $offer->access_plan_id)->first();

        $isStudent = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('s.user_id', $user->id)->where('s.status', 'ACTIVE')
            ->when($user->organization_id, fn ($q) => $q->where('sc.organization_id', $user->organization_id))->exists();

        $active = DB::table('orders as o')->join('order_items as i', 'i.order_id', '=', 'o.id')->where('o.purchaser_user_id', $user->id)
            ->where('o.status', 'PENDING_PAYMENT')->where('o.payment_expires_at', '>', $now)->where('i.plan_offer_id', $offer->id)
            ->orderByDesc('o.created_at')->value('o.id');
        if ($active) return response()->json($this->mine($request, $active), 201);

        $expires = $now->copy()->addDay();
        $number = 'SA-'.$now->format('Ymd').'-'.strtoupper(substr((string) Str::uuid(), 0, 8));
        $orderId = (string) Str::ulid();
        $this->lockCodes();
        try {
            DB::transaction(function () use ($d, $user, $offer, $price, $plan, $now, $expires, $number, $orderId, $isStudent): void {
                $code = $this->allocateCode($price->currency, (string) $price->amount);
                $total = bcadd((string) $price->amount, (string) $code, 2);
                DB::table('orders')->insert(['id' => $orderId, 'order_number' => $number, 'organization_id' => $user->organization_id,
                    'purchaser_user_id' => $user->id, 'status' => 'PENDING_PAYMENT', 'currency' => $price->currency,
                    'subtotal_amount' => $price->amount, 'fee_amount' => $code, 'total_amount' => $total, 'payment_expires_at' => $expires,
                    'terms_version' => $offer->terms_version, 'refund_policy_version' => $offer->refund_policy_version,
                    'consent_snapshot' => json_encode(['accepted' => true, 'acceptedAt' => $now->utc()->toISOString(), 'termsVersion' => $offer->terms_version, 'refundPolicyVersion' => $offer->refund_policy_version]),
                    'idempotency_key' => $d['idempotencyKey'], 'created_at' => $now, 'updated_at' => $now]);
                $itemId = (string) Str::ulid();
                DB::table('order_items')->insert(['id' => $itemId, 'order_id' => $orderId, 'plan_offer_id' => $offer->id, 'access_plan_id' => $plan->id,
                    'offer_snapshot' => json_encode(['code' => $offer->code, 'slug' => $offer->slug, 'name' => $offer->name, 'billingModel' => $offer->billing_model, 'accessDurationDays' => $offer->access_duration_days]),
                    'price_snapshot' => json_encode(['currency' => $price->currency, 'amount' => $this->dec($price->amount), 'compareAtAmount' => $price->compare_at_amount === null ? null : $this->dec($price->compare_at_amount),
                        'taxMode' => $price->tax_mode, 'taxRate' => $price->tax_rate === null ? null : $this->dec($price->tax_rate)]),
                    'unit_amount' => $price->amount, 'line_total' => $price->amount, 'created_at' => $now]);
                DB::table('purchase_beneficiaries')->insert(['id' => (string) Str::ulid(), 'order_item_id' => $itemId, 'beneficiary_type' => 'USER',
                    'user_id' => $user->id, 'verification_status' => $isStudent ? 'VERIFIED' : 'PENDING', 'created_at' => $now]);
                DB::table('payment_attempts')->insert(['id' => (string) Str::ulid(), 'order_id' => $orderId, 'provider' => 'MANUAL_REVIEW',
                    'method_type' => 'PAYMENT_INSTRUCTION', 'status' => 'PENDING', 'requested_amount' => $total, 'currency' => $price->currency,
                    'provider_reference' => $number, 'payment_url_expires_at' => $expires, 'created_at' => $now, 'updated_at' => $now]);
                $this->audit($user->organization_id, $user->id, 'ORDER_CREATED', $orderId, ['orderNumber' => $number, 'offerCode' => $offer->code,
                    'amount' => $this->dec($price->amount), 'uniqueCode' => $code, 'totalAmount' => $this->dec($total), 'currency' => $price->currency]);
            });
        } finally {
            $this->unlockCodes();
        }
        return response()->json($this->present($orderId), 201);
    }

    public function index(Request $request)
    {
        $this->expirePending([['purchaser_user_id', $request->user()->id]]);
        $ids = DB::table('orders')->where('purchaser_user_id', $request->user()->id)->orderByDesc('created_at')->pluck('id');
        return response()->json($ids->map(fn ($id) => $this->mine($request, $id))->all());
    }

    public function show(Request $request, string $id)
    {
        $this->expirePending([['id', $id], ['purchaser_user_id', $request->user()->id]]);
        abort_unless(DB::table('orders')->where('id', $id)->where('purchaser_user_id', $request->user()->id)->exists(), 404, 'Pesanan tidak ditemukan');
        return response()->json($this->mine($request, $id));
    }

    public function cancel(Request $request, string $id)
    {
        $user = $request->user();
        $this->expirePending([['id', $id], ['purchaser_user_id', $user->id]]);
        $order = DB::table('orders')->where('id', $id)->where('purchaser_user_id', $user->id)->first();
        abort_unless($order, 404, 'Pesanan tidak ditemukan');
        abort_unless(in_array($order->status, ['DRAFT', 'PENDING_PAYMENT'], true), 403, 'Pesanan ini tidak dapat dibatalkan');
        DB::transaction(function () use ($id, $user, $order): void {
            DB::table('payment_attempts')->where('order_id', $id)->whereIn('status', self::OPEN_ATTEMPT)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            DB::table('orders')->where('id', $id)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
            $this->audit($user->organization_id, $user->id, 'ORDER_CANCELLED', $id, ['orderNumber' => $order->order_number]);
        });
        return response()->json($this->present($id));
    }

    private function admin(Request $request): array
    {
        $c = $this->context->resolve($request, 'access-grants', 'manage');
        return $c;
    }

    private function identity(?object $u, ?object $s): ?array
    {
        if (! $u) return null;
        return ['userId' => $u->id, 'email' => $u->email, 'fullName' => $s->full_name ?? $u->name, 'studentNumber' => $s->student_number ?? null,
            'schoolId' => $s->school_id ?? null, 'schoolName' => $s->school_name ?? null, 'isStudent' => (bool) $s];
    }

    public function adminOrders(Request $request)
    {
        $c = $this->admin($request);
        $org = $c['organization'];
        $this->expirePending([['organization_id', $org]]);
        $scoped = DB::table('users as u')->where('u.organization_id', $org)->where(fn ($q) => $q
            ->whereIn('u.id', DB::table('students')->where('school_id', $c['school'])->select('user_id'))
            ->orWhereIn('u.id', DB::table('user_roles')->where('school_id', $c['school'])->select('user_id')))->pluck('u.id');
        $ids = DB::table('orders as o')->where('o.organization_id', $org)->whereIn('o.status', ['PENDING_PAYMENT', 'PAID', 'FAILED', 'EXPIRED'])
            ->where(fn ($q) => $q->whereIn('o.purchaser_user_id', $scoped)->orWhereIn('o.id', DB::table('order_items as i')
                ->join('purchase_beneficiaries as b', 'b.order_item_id', '=', 'i.id')->where('b.school_id', $c['school'])->select('i.order_id')))
            ->orderByDesc('o.created_at')->limit(100)->pluck('o.id');
        $rows = $ids->map(fn ($id) => $this->present($id));
        $userIds = $rows->flatMap(fn ($r) => array_merge([DB::table('orders')->where('id', $r['id'])->value('purchaser_user_id')],
            collect($r['items'])->flatMap(fn ($i) => array_merge(array_column($i['beneficiaries'], 'userId'), array_column($i['accessGrants'], 'userId')))->all()))->filter()->unique()->values();
        $users = DB::table('users')->whereIn('id', $userIds)->get()->keyBy('id');
        $students = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->whereIn('s.user_id', $userIds)
            ->get(['s.user_id', 's.full_name', 's.student_number', 's.school_id', 'sc.name as school_name'])->keyBy('user_id');
        $out = $rows->map(function ($r) use ($users, $students) {
            $purchaserId = DB::table('orders')->where('id', $r['id'])->value('purchaser_user_id');
            $benId = collect($r['items'])->flatMap(fn ($i) => array_merge(array_column($i['accessGrants'], 'userId'), array_column($i['beneficiaries'], 'userId')))->first(fn ($v) => $v);
            return $r + ['purchaser' => $this->identity($users[$purchaserId] ?? null, $students[$purchaserId] ?? null),
                'beneficiary' => $benId ? $this->identity($users[$benId] ?? null, $students[$benId] ?? null) : null];
        });
        return response()->json($out->values()->all());
    }

    public function adminStudents(Request $request)
    {
        $c = $this->admin($request);
        return response()->json(DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.status', 'ACTIVE')->where('sc.organization_id', $c['organization'])->where('s.school_id', $c['school'])->orderBy('s.full_name')
            ->get(['s.id', 's.user_id', 's.full_name', 's.student_number', 'u.email', 's.school_id', 'sc.name as school_name'])
            ->map(fn ($s) => ['id' => $s->id, 'userId' => $s->user_id, 'fullName' => $s->full_name, 'studentNumber' => $s->student_number,
                'email' => $s->email, 'schoolId' => $s->school_id, 'schoolName' => $s->school_name])->all());
    }

    public function approve(Request $request, string $id)
    {
        $c = $this->admin($request);
        $d = $request->validate(['beneficiaryUserId' => 'required|string|max:120', 'paymentReference' => 'required|string|max:120', 'note' => 'required|string|max:500']);
        $ref = trim($d['paymentReference']);
        $note = trim($d['note']);
        abort_if(strlen($ref) < 3, 400, 'Referensi transfer wajib diisi');
        abort_if(strlen($note) < 5, 400, 'Catatan pemeriksaan wajib diisi');
        $org = $c['organization'];
        $this->expirePending([['id', $id], ['organization_id', $org]]);
        $target = DB::table('students as s')->join('schools as sc', 'sc.id', '=', 's.school_id')->where('s.user_id', $d['beneficiaryUserId'])
            ->where('s.status', 'ACTIVE')->where('sc.organization_id', $org)->where('s.school_id', $c['school'])->first(['s.user_id', 's.school_id']);
        abort_unless($target, 400, 'Penerima akses harus siswa aktif dalam sekolah yang dikelola');

        $expired = false;
        DB::transaction(function () use ($id, $org, $c, $ref, $note, $target, &$expired): void {
            $o = DB::table('orders')->where('id', $id)->where('organization_id', $org)->lockForUpdate()->first();
            abort_unless($o, 404, 'Pesanan tidak ditemukan');
            if ($o->status === 'PAID') return;
            abort_unless($o->status === 'PENDING_PAYMENT', 409, 'Pesanan ini tidak lagi menunggu approval');
            abort_if($o->purchaser_user_id === $c['user'], 403, 'Pembayaran harus diverifikasi oleh admin lain, bukan pembuat pesanan');
            if ($this->expired($o->status, $o->payment_expires_at)) { $this->markExpired($o); $expired = true; return; }
            abort_if(DB::table('payment_attempts')->where('provider', 'MANUAL_REVIEW')->where('provider_reference', $ref)->where('status', 'SETTLED')->where('order_id', '!=', $o->id)->exists(),
                409, 'Referensi transfer sudah digunakan pada pesanan lain');
            $items = DB::table('order_items')->where('order_id', $o->id)->get();
            abort_if($items->count() !== 1 || (int) $items[0]->quantity_or_seats !== 1, 400, 'Pesanan berbayar harus berisi tepat satu paket untuk satu siswa');
            $item = $items[0];
            $days = DB::table('plan_offers')->where('id', $item->plan_offer_id)->value('access_duration_days');
            $now = now();
            $ends = $days ? $now->copy()->addDays((int) $days) : null;
            $plan = DB::table('access_plans')->where('id', $item->access_plan_id)->first();
            DB::table('purchase_beneficiaries')->where('order_item_id', $item->id)->update(['beneficiary_type' => 'USER', 'user_id' => $target->user_id, 'school_id' => null, 'verification_status' => 'VERIFIED']);
            $grantId = (string) Str::ulid();
            DB::table('access_grants')->insert(['id' => $grantId, 'organization_id' => $org, 'user_id' => $target->user_id, 'target_type' => 'USER',
                'access_plan_id' => $item->access_plan_id, 'order_item_id' => $item->id, 'source' => 'PURCHASE', 'status' => 'ACTIVE', 'starts_at' => $now, 'ends_at' => $ends,
                'reason' => "Pembayaran {$o->order_number} disetujui untuk satu siswa", 'granted_by' => $c['user'], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('access_grant_events')->insert(['id' => (string) Str::ulid(), 'access_grant_id' => $grantId, 'event_type' => 'CREATED', 'actor_id' => $c['user'], 'reason' => $note,
                'metadata' => json_encode(['orderNumber' => $o->order_number, 'planCode' => $plan->code, 'beneficiaryUserId' => $target->user_id, 'pricingUnit' => 'PER_STUDENT']), 'created_at' => $now]);
            DB::table('order_items')->where('id', $item->id)->update(['access_starts_at' => $now, 'access_ends_at' => $ends]);
            DB::table('payment_attempts')->where('order_id', $o->id)->whereIn('status', self::OPEN_ATTEMPT)
                ->update(['status' => 'SETTLED', 'settled_amount' => $o->total_amount, 'settled_at' => $now, 'provider_reference' => $ref, 'updated_at' => $now]);
            DB::table('orders')->where('id', $o->id)->update(['status' => 'PAID', 'paid_at' => $now, 'updated_at' => $now]);
            $this->audit($org, $c['user'], 'PAYMENT_APPROVED_AND_ACCESS_GRANTED', $o->id, ['orderNumber' => $o->order_number, 'beneficiaryUserId' => $target->user_id,
                'schoolId' => $target->school_id, 'planCode' => $plan->code, 'paymentReference' => $ref, 'pricingUnit' => 'PER_STUDENT']);
        });
        abort_if($expired, 409, 'Batas pembayaran telah lewat. Buat pesanan baru untuk memperoleh kode unik baru.');
        return response()->json($this->present($id), 201);
    }

    public function reject(Request $request, string $id)
    {
        $c = $this->admin($request);
        $d = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $org = $c['organization'];
        $this->expirePending([['id', $id], ['organization_id', $org]]);
        DB::transaction(function () use ($id, $org, $c, $d): void {
            $o = DB::table('orders')->where('id', $id)->where('organization_id', $org)->lockForUpdate()->first();
            abort_unless($o, 404, 'Pesanan tidak ditemukan');
            abort_unless($o->status === 'PENDING_PAYMENT', 409, 'Pesanan ini tidak lagi menunggu approval');
            DB::table('payment_attempts')->where('order_id', $id)->whereIn('status', self::OPEN_ATTEMPT)
                ->update(['status' => 'FAILED', 'failed_at' => now(), 'failure_code' => 'MANUAL_REJECTED', 'updated_at' => now()]);
            DB::table('purchase_beneficiaries')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $id)->select('id'))->update(['verification_status' => 'REJECTED']);
            DB::table('orders')->where('id', $id)->update(['status' => 'FAILED', 'updated_at' => now()]);
            $this->audit($org, $c['user'], 'PAYMENT_REJECTED', $id, ['orderNumber' => $o->order_number, 'reason' => trim($d['reason'])]);
        });
        return response()->json($this->present($id), 201);
    }
}
