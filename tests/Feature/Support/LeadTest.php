<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadTest extends TestCase
{
    private function form(array $overrides = []): array
    {
        return [...['schoolName' => 'SMA Harapan', 'educationLevel' => 'SMA', 'city' => 'Bandung', 'province' => 'Jawa Barat',
            'picName' => 'Bu Sari', 'picTitle' => 'Kepala Sekolah', 'email' => 'sari@harapan.sch.id', 'phone' => '081234567890',
            'estimatedStudents' => 300, 'programOfInterest' => 'Kewirausahaan', 'message' => 'Mohon info', 'consent' => true], ...$overrides];
    }

    public function test_valid_submission_creates_pending_lead(): void
    {
        $this->postJson('/api/backend/leads', $this->form())->assertCreated()
            ->assertJsonPath('schoolName', 'SMA Harapan')->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('estimatedStudents', 300)->assertJsonPath('consent', true);
        $this->assertDatabaseHas('leads', ['email' => 'sari@harapan.sch.id', 'status' => 'PENDING']);
    }

    public function test_invalid_submission_is_rejected(): void
    {
        $this->postJson('/api/backend/leads', $this->form(['email' => 'bukan-email', 'estimatedStudents' => 0, 'schoolName' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'estimatedStudents', 'schoolName']);
        $this->assertSame(0, DB::table('leads')->count());
    }

    public function test_consent_is_required(): void
    {
        $this->postJson('/api/backend/leads', $this->form(['consent' => false]))->assertStatus(400)
            ->assertJsonPath('message', 'Consent to privacy policy is required');
        $this->assertSame(0, DB::table('leads')->count());
    }

    public function test_honeypot_pretends_success_without_saving(): void
    {
        $this->postJson('/api/backend/leads', $this->form(['website' => 'http://spam.example']))->assertCreated()
            ->assertExactJson(['message' => 'Registration submitted successfully']);
        $this->assertSame(0, DB::table('leads')->count());
    }

    public function test_sixth_submission_in_ten_minutes_is_throttled(): void
    {
        foreach (range(1, 5) as $i) $this->postJson('/api/backend/leads', $this->form(['email' => "pic$i@harapan.sch.id"]))->assertCreated();
        $this->postJson('/api/backend/leads', $this->form())->assertStatus(429);
        $this->assertSame(5, DB::table('leads')->count());
    }

    public function test_lead_admin_requires_login_and_permission(): void
    {
        $this->getJson('/api/backend/leads')->assertUnauthorized();
        $this->loginAs('teacher');
        $this->getJson('/api/backend/leads')->assertForbidden();
        $this->putJson('/api/backend/leads/x/status', ['status' => 'CONTACTED'])->assertForbidden();
    }

    public function test_admin_lists_and_updates_lead_status(): void
    {
        $id = $this->postJson('/api/backend/leads', $this->form())->json('id');
        $this->loginAs('program-manager');

        $this->getJson('/api/backend/leads')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.picName', 'Bu Sari')->assertJsonPath('0.status', 'PENDING');
        $this->putJson("/api/backend/leads/$id/status", ['status' => 'CONTACTED'])->assertOk()->assertJsonPath('status', 'CONTACTED');
        $this->assertDatabaseHas('leads', ['id' => $id, 'status' => 'CONTACTED']);
    }

    public function test_status_update_validates_status_and_lead(): void
    {
        $id = $this->postJson('/api/backend/leads', $this->form())->json('id');
        $this->loginAs('school-admin');
        $this->putJson("/api/backend/leads/$id/status", ['status' => 'DIHAPUS'])->assertUnprocessable();
        $this->putJson('/api/backend/leads/tidak-ada/status', ['status' => 'APPROVED'])->assertNotFound()->assertJsonPath('message', 'Lead not found');
        $this->assertDatabaseHas('leads', ['id' => $id, 'status' => 'PENDING']);
    }
}
