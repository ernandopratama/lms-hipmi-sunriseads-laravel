<?php

namespace Tests\Feature\Commerce;

use App\Support\StudentDashboardAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceOrderTest extends TestCase
{
    use CommerceFixtures;

    private function checkout(string $slug = 'advanced-12-bulan', ?string $key = null)
    {
        return $this->postJson('/api/backend/commerce/orders', ['offerSlug' => $slug, 'idempotencyKey' => $key ?? (string) Str::uuid(), 'acceptTerms' => true]);
    }

    /** Student places an order, then the school admin is logged in. Returns the order id. */
    private function pendingStudentOrder(): string
    {
        $this->loginAs('student');
        $id = $this->checkout()->assertCreated()->json('id');
        $this->loginAs('school-admin');
        return $id;
    }

    private function approve(string $id, array $body = [])
    {
        return $this->postJson("/api/backend/commerce/admin/orders/$id/approve", $body + ['beneficiaryUserId' => $this->demoUser('student')->id,
            'paymentReference' => 'MUTASI-001', 'note' => 'Nominal dan pengirim sesuai']);
    }

    // 1. Public plan list

    public function test_public_plan_list_is_open_ordered_by_rank_with_offers_and_prices(): void
    {
        $response = $this->getJson('/api/backend/access/plans')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['FREE', 'MEDIUM', 'ADVANCED'], array_column($response->json(), 'code'));
        $advanced = $response->json('2');
        $this->assertSame(3, $advanced['rank']);
        $this->assertSame(12, $advanced['moduleLimit']);
        $this->assertTrue($advanced['entitlements']['student_store.submit']);
        $this->assertSame('advanced-12-bulan', $advanced['offers'][0]['slug']);
        $this->assertSame('ONE_TIME', $advanced['offers'][0]['billingModel']);
        $this->assertSame(['currency' => 'IDR', 'amount' => '50000', 'compareAtAmount' => '899000', 'taxMode' => 'INCLUSIVE', 'taxRate' => null],
            $advanced['offers'][0]['prices'][0]);
        $this->assertFalse($response->json('0.entitlements')['reports.export']);
    }

    public function test_public_plan_list_hides_inactive_plans_and_offers_outside_sales_window(): void
    {
        DB::table('access_plans')->where('code', 'MEDIUM')->update(['status' => 'INACTIVE']);
        DB::table('plan_offers')->where('slug', 'advanced-12-bulan')->update(['sales_end_at' => now()->subMinute()]);

        $response = $this->getJson('/api/backend/access/plans')->assertOk();

        $this->assertSame(['FREE', 'ADVANCED'], array_column($response->json(), 'code'));
        $this->assertSame([], $response->json('1.offers'));
    }

    // 2. Checkout

    public function test_guest_cannot_create_an_order(): void
    {
        $this->checkout()->assertUnauthorized();
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_student_checkout_creates_pending_order_with_unique_transfer_code(): void
    {
        $student = $this->loginAs('student');

        $order = $this->checkout()->assertCreated()
            ->assertJsonPath('status', 'PENDING_PAYMENT')->assertJsonPath('currency', 'IDR')
            ->assertJsonPath('subtotalAmount', '50000')->assertJsonPath('discountAmount', '0')
            ->assertJsonPath('items.0.plan.code', 'ADVANCED')->assertJsonPath('items.0.quantityOrSeats', 1)
            ->assertJsonPath('items.0.beneficiaries.0.userId', $student->id)
            ->assertJsonPath('items.0.beneficiaries.0.verificationStatus', 'VERIFIED')
            ->assertJsonPath('payment.provider', 'MANUAL_REVIEW')->assertJsonPath('payment.status', 'PENDING')
            ->assertJsonPath('paymentInstructions.method', 'BANK_TRANSFER')->assertJsonPath('pricingUnit', 'PER_STUDENT')
            ->json();

        $code = $order['paymentInstructions']['uniqueCode'];
        $this->assertGreaterThanOrEqual(1, $code);
        $this->assertLessThanOrEqual(999, $code);
        $this->assertSame((string) (50000 + $code), $order['totalAmount']);
        $this->assertSame($order['totalAmount'], $order['paymentInstructions']['transferAmount']);
        $this->assertSame($order['totalAmount'], $order['payment']['requestedAmount']);
        $this->assertSame([], $order['items'][0]['accessGrants']);
        $this->assertTrue(DB::table('billing_audit_events')->where('resource_id', $order['id'])->where('action', 'ORDER_CREATED')->exists());
    }

    public function test_same_idempotency_key_returns_the_same_order(): void
    {
        $this->loginAs('student');
        $key = (string) Str::uuid();

        $first = $this->checkout('advanced-12-bulan', $key)->assertCreated()->json('id');
        $this->checkout('medium-12-bulan', $key)->assertCreated()->assertJsonPath('id', $first)->assertJsonPath('items.0.plan.code', 'ADVANCED');

        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_open_order_for_the_same_offer_is_reused_instead_of_duplicated(): void
    {
        $this->loginAs('student');

        $first = $this->checkout()->assertCreated()->json('id');
        $this->checkout()->assertCreated()->assertJsonPath('id', $first);
        $this->checkout('medium-12-bulan')->assertCreated()->assertJsonPath('items.0.plan.code', 'MEDIUM');

        $this->assertSame(2, DB::table('orders')->count());
    }

    public function test_concurrent_pending_orders_of_the_same_price_get_distinct_unique_codes(): void
    {
        $codes = [];
        foreach (['student', 'teacher', 'parent'] as $role) {
            $this->loginAs($role);
            $codes[] = $this->checkout()->assertCreated()->json('paymentInstructions.uniqueCode');
        }

        $this->assertCount(3, array_unique($codes));
    }

    public function test_legacy_pending_order_without_code_gets_one_when_read(): void
    {
        $this->loginAs('student');
        $id = $this->checkout()->assertCreated()->json('id');
        DB::table('orders')->where('id', $id)->update(['fee_amount' => 0, 'total_amount' => 50000]);

        $order = $this->getJson("/api/backend/commerce/orders/$id")->assertOk()->json();

        $this->assertGreaterThan(0, $order['paymentInstructions']['uniqueCode']);
        $this->assertSame((string) (50000 + $order['paymentInstructions']['uniqueCode']), $order['totalAmount']);
        $this->assertSame($order['totalAmount'], $order['payment']['requestedAmount']);
    }

    public function test_checkout_validation_and_unavailable_offers(): void
    {
        $this->loginAs('student');

        $this->postJson('/api/backend/commerce/orders', ['offerSlug' => 'advanced-12-bulan', 'idempotencyKey' => 'bukan-uuid', 'acceptTerms' => true])->assertUnprocessable();
        $this->postJson('/api/backend/commerce/orders', ['offerSlug' => 'advanced-12-bulan', 'idempotencyKey' => (string) Str::uuid(), 'acceptTerms' => false])
            ->assertBadRequest()->assertJsonPath('message', 'Persetujuan syarat pembelian wajib diberikan');
        $this->checkout('gratis')->assertBadRequest()->assertJsonPath('message', 'Paket Gratis tidak memerlukan pembelian');
        $this->checkout('tidak-ada')->assertNotFound()->assertJsonPath('message', 'Paket tidak tersedia');

        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_checkout_rejects_offer_without_positive_price(): void
    {
        DB::table('offer_prices')->where('plan_offer_id', DB::table('plan_offers')->where('slug', 'medium-12-bulan')->value('id'))->update(['amount' => 0]);
        $this->loginAs('student');

        $this->checkout('medium-12-bulan')->assertBadRequest()->assertJsonPath('message', 'Harga paket belum tersedia');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_purchaser_lists_and_reads_only_own_orders(): void
    {
        $this->loginAs('student');
        $mine = $this->checkout()->assertCreated()->json('id');
        $this->loginAs('teacher');
        $theirs = $this->checkout()->assertCreated()->json('id');

        $this->getJson('/api/backend/commerce/orders')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $theirs);
        $this->getJson("/api/backend/commerce/orders/$mine")->assertNotFound()->assertJsonPath('message', 'Pesanan tidak ditemukan');
        $this->postJson("/api/backend/commerce/orders/$mine/cancel")->assertNotFound();
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $mine)->value('status'));
    }

    public function test_purchaser_can_cancel_a_pending_order_but_not_a_paid_one(): void
    {
        $this->loginAs('student');
        $id = $this->checkout()->assertCreated()->json('id');

        $this->postJson("/api/backend/commerce/orders/$id/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('payment.status', 'CANCELLED')->assertJsonPath('paymentInstructions', null);

        DB::table('orders')->where('id', $id)->update(['status' => 'PAID']);
        $this->postJson("/api/backend/commerce/orders/$id/cancel")->assertForbidden()->assertJsonPath('message', 'Pesanan ini tidak dapat dibatalkan');
    }

    public function test_overdue_pending_order_is_expired_when_read(): void
    {
        $this->loginAs('student');
        $id = $this->checkout()->assertCreated()->json('id');
        DB::table('orders')->where('id', $id)->update(['payment_expires_at' => now()->subMinute()]);

        $this->getJson("/api/backend/commerce/orders/$id")->assertOk()->assertJsonPath('status', 'EXPIRED')
            ->assertJsonPath('payment.status', 'EXPIRED')->assertJsonPath('paymentInstructions', null);
    }

    // 3. Admin approval and rejection

    public function test_admin_endpoints_require_access_grant_permission(): void
    {
        $id = $this->pendingStudentOrder();
        $this->loginAs('student');

        $this->getJson('/api/backend/commerce/admin/orders')->assertForbidden();
        $this->getJson('/api/backend/commerce/admin/students')->assertForbidden();
        $this->approve($id)->assertForbidden();
        $this->postJson("/api/backend/commerce/admin/orders/$id/reject", ['reason' => 'Transfer tidak ditemukan'])->assertForbidden();
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_admin_lists_school_orders_with_purchaser_identity_and_eligible_students(): void
    {
        $id = $this->pendingStudentOrder();

        $this->getJson('/api/backend/commerce/admin/orders')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.purchaser.email', 'student@sunrise.example')
            ->assertJsonPath('0.purchaser.isStudent', true)->assertJsonPath('0.beneficiary.studentNumber', 'DEMO-001');
        $this->getJson('/api/backend/commerce/admin/students')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.userId', $this->demoUser('student')->id)->assertJsonPath('0.schoolName', 'SMK Contoh Nusantara');
    }

    public function test_approval_marks_order_paid_and_grants_the_plan_to_the_student(): void
    {
        $id = $this->pendingStudentOrder();
        $student = $this->demoUser('student');
        $total = DB::table('orders')->where('id', $id)->value('total_amount');

        $this->approve($id)->assertCreated()->assertJsonPath('status', 'PAID')
            ->assertJsonPath('payment.status', 'SETTLED')->assertJsonPath('payment.providerReference', 'MUTASI-001')
            ->assertJsonPath('items.0.accessGrants.0.userId', $student->id)->assertJsonPath('items.0.accessGrants.0.source', 'PURCHASE')
            ->assertJsonPath('items.0.accessGrants.0.status', 'ACTIVE')->assertJsonPath('paymentInstructions', null);

        $this->assertEquals($total, DB::table('payment_attempts')->where('order_id', $id)->value('settled_amount'));
        $grant = DB::table('access_grants')->where('user_id', $student->id)->first();
        $this->assertSame(DB::table('access_plans')->where('code', 'ADVANCED')->value('id'), $grant->access_plan_id);
        $this->assertSame(365, (int) round(now()->diffInDays($grant->ends_at)));
        // Approval grants access through access_grants (plan rank comes from the granted plan), not users.plan_rank.
        $access = app(StudentDashboardAccess::class)->resolve($student->organization_id, $student->school_id, $student->id, $this->studentRow()->id);
        $this->assertSame('ADVANCED', $access['plan']['code']);
        $this->assertSame(3, $access['rank']);
        $this->assertSame(0, (int) $student->fresh()->plan_rank);
        $this->assertTrue(DB::table('billing_audit_events')->where('resource_id', $id)->where('action', 'PAYMENT_APPROVED_AND_ACCESS_GRANTED')->exists());
    }

    public function test_approving_an_already_paid_order_does_not_grant_twice(): void
    {
        $id = $this->pendingStudentOrder();
        $this->approve($id)->assertCreated();

        $this->approve($id)->assertCreated()->assertJsonPath('status', 'PAID');
        $this->assertSame(1, DB::table('access_grants')->count());
    }

    public function test_approval_validation_errors_write_nothing(): void
    {
        $id = $this->pendingStudentOrder();

        $this->approve($id, ['paymentReference' => '  ab  '])->assertBadRequest()->assertJsonPath('message', 'Referensi transfer wajib diisi');
        $this->approve($id, ['note' => 'oke '])->assertBadRequest()->assertJsonPath('message', 'Catatan pemeriksaan wajib diisi');
        $this->approve($id, ['beneficiaryUserId' => $this->demoUser('teacher')->id])->assertBadRequest()
            ->assertJsonPath('message', 'Penerima akses harus siswa aktif dalam sekolah yang dikelola');
        $this->approve($id, ['paymentReference' => str_repeat('x', 121)])->assertUnprocessable();

        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_approval_requires_exactly_one_seat(): void
    {
        $id = $this->pendingStudentOrder();
        DB::table('order_items')->where('order_id', $id)->update(['quantity_or_seats' => 2]);

        $this->approve($id)->assertBadRequest()->assertJsonPath('message', 'Pesanan berbayar harus berisi tepat satu paket untuk satu siswa');
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_purchaser_cannot_approve_their_own_order(): void
    {
        $this->loginAs('school-admin');
        $id = $this->checkout()->assertCreated()->json('id');

        $this->approve($id)->assertForbidden()->assertJsonPath('message', 'Pembayaran harus diverifikasi oleh admin lain, bukan pembuat pesanan');
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_settled_bank_reference_cannot_be_reused_for_another_order(): void
    {
        $first = $this->pendingStudentOrder();
        $this->approve($first)->assertCreated();
        $this->loginAs('student');
        $second = $this->checkout('medium-12-bulan')->assertCreated()->json('id');
        $this->loginAs('school-admin');

        $this->approve($second)->assertConflict()->assertJsonPath('message', 'Referensi transfer sudah digunakan pada pesanan lain');
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $second)->value('status'));
    }

    public function test_expired_order_cannot_be_approved_and_is_persisted_as_expired(): void
    {
        $id = $this->pendingStudentOrder();
        DB::table('orders')->where('id', $id)->update(['payment_expires_at' => now()->subSecond()]);

        $this->approve($id)->assertConflict();
        $this->assertSame('EXPIRED', DB::table('orders')->where('id', $id)->value('status'));
        $this->assertSame('EXPIRED', DB::table('payment_attempts')->where('order_id', $id)->value('status'));
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_rejection_marks_order_failed_and_beneficiary_rejected(): void
    {
        $id = $this->pendingStudentOrder();

        $this->postJson("/api/backend/commerce/admin/orders/$id/reject", ['reason' => 'abc'])->assertUnprocessable();
        $this->postJson("/api/backend/commerce/admin/orders/$id/reject", ['reason' => 'Transfer tidak ditemukan'])->assertCreated()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('payment.status', 'FAILED')
            ->assertJsonPath('items.0.beneficiaries.0.verificationStatus', 'REJECTED');

        $this->postJson("/api/backend/commerce/admin/orders/$id/reject", ['reason' => 'Transfer tidak ditemukan'])->assertConflict()
            ->assertJsonPath('message', 'Pesanan ini tidak lagi menunggu approval');
        $this->approve($id)->assertConflict();
        $this->assertSame(0, DB::table('access_grants')->count());
    }

    public function test_admin_of_another_organization_cannot_see_or_decide_the_order(): void
    {
        $id = $this->pendingStudentOrder();
        [, $otherSchool] = $this->newOrganization();
        $this->loginUser($this->makeUser('school-admin', $otherSchool));

        $this->getJson('/api/backend/commerce/admin/orders')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/backend/commerce/admin/orders/$id/reject", ['reason' => 'Transfer tidak ditemukan'])->assertNotFound();
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $id)->value('status'));
    }
}
