<?php

namespace Tests\Feature\Learning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RubricDashboardSupportTest extends LearningTestCase
{
    private function grantAdvanced(): void
    {
        DB::table('access_grants')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->demoUser('teacher')->organization_id, 'target_type' => 'ORGANIZATION',
            'access_plan_id' => DB::table('access_plans')->where('code', 'ADVANCED')->value('id'), 'source' => 'MANUAL', 'status' => 'ACTIVE',
            'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function warning(string $studentId, ?string $classId = null): string
    {
        $org = $this->demoUser('teacher')->organization_id;
        $rule = (string) Str::ulid();
        DB::table('early_warning_rules')->insert(['id' => $rule, 'organization_id' => $org, 'code' => 'LOW-'.Str::random(4), 'name' => 'Progres rendah', 'description' => 'Progres di bawah ambang',
            'signal_type' => 'LOW_PROGRESS', 'thresholds' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::ulid();
        DB::table('early_warnings')->insert(['id' => $id, 'organization_id' => $org, 'school_id' => $this->schoolId(), 'class_id' => $classId, 'student_id' => $studentId, 'rule_id' => $rule,
            'severity' => 'HIGH', 'status' => 'OPEN', 'title' => 'Progres rendah', 'message' => 'Belum membuka materi', 'evidence' => '{"progress":0}', 'triggered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_rubric_weights_must_total_100(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik miring', 'criteria' => [['name' => 'Isi', 'weight' => 70, 'maxScore' => 10], ['name' => 'Bukti', 'weight' => 20, 'maxScore' => 10]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Total bobot kriteria harus tepat 100%');
        $this->assertSame(0, DB::table('rubrics')->count());
    }

    public function test_student_cannot_create_rubric(): void
    {
        $this->loginAs('student');

        $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik siswa', 'criteria' => [['name' => 'Isi', 'weight' => 100, 'maxScore' => 10]]])->assertForbidden();
    }

    public function test_rubrics_are_scoped_to_organization(): void
    {
        $this->loginAs('teacher');
        $rubric = $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik penawaran', 'criteria' => [['name' => 'Isi', 'weight' => 100, 'maxScore' => 10]]])->assertCreated()->json();
        $this->assertSame(100, $rubric['criteria'][0]['weight']);
        [$org, $school] = $this->otherTenant();
        $this->makeUser('teacher2@sunrise.example', 'teacher', $org, $school);
        $this->switchTo('teacher2');

        $this->getJson('/api/backend/rubrics')->assertOk()->assertExactJson([]);
        $this->getJson("/api/backend/rubrics/{$rubric['id']}")->assertNotFound();
    }

    public function test_mgmp_templates_import_once(): void
    {
        $this->loginAs('teacher');

        $first = count($this->postJson('/api/backend/rubrics/templates/mgmp')->assertOk()->json());
        $second = count($this->postJson('/api/backend/rubrics/templates/mgmp')->assertOk()->json());

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);
    }

    public function test_student_sees_only_rubrics_of_their_assignments(): void
    {
        $c = $this->createContent();
        $used = $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik dipakai', 'criteria' => [['name' => 'Isi', 'weight' => 100, 'maxScore' => 10]]])->json();
        $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik lain', 'criteria' => [['name' => 'Isi', 'weight' => 100, 'maxScore' => 10]]]);
        $this->createAssignment($c, ['rubricId' => $used['id']]);
        $this->switchTo('student');

        $this->assertSame([$used['id']], array_column($this->getJson('/api/backend/rubrics')->assertOk()->json(), 'id'));
    }

    public function test_submit_notifies_teacher_and_published_review_notifies_student(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);
        $this->switchTo('student');
        $id = $this->submitWork($assignment['id']);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser('teacher')->id, 'event_type' => 'SUBMISSION_RECEIVED',
            'title' => 'Pengumpulan baru: Rancang penawaran', 'action_url' => '/dashboard/review', 'idempotency_key' => "submission-received:$id:v1"]);
        $this->switchTo('teacher');
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 70, 'publishGrade' => false])->assertOk();
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->demoUser('student')->id]);
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'REVISION_REQUIRED', 'feedback' => 'Perbaiki harga', 'publishGrade' => true])->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser('student')->id, 'event_type' => 'REVISION_REQUESTED',
            'title' => 'Revisi diminta: Rancang penawaran', 'action_url' => '/dashboard/tugas']);
        $this->assertSame(2, DB::table('notification_deliveries')->where('channel', 'IN_APP')->count());
    }

    public function test_teacher_dashboard_reports_advanced_access_from_plan(): void
    {
        $this->loginAs('teacher');
        $this->getJson('/api/backend/dashboard/teacher')->assertOk()->assertJsonPath('advancedAccess', false);

        $this->grantAdvanced();

        $this->getJson('/api/backend/dashboard/teacher')->assertOk()->assertJsonPath('advancedAccess', true);
    }

    public function test_guest_cannot_open_dashboards(): void
    {
        $this->getJson('/api/backend/dashboard/teacher')->assertUnauthorized();
        $this->getJson('/api/backend/dashboard/student')->assertUnauthorized();
    }

    public function test_achievements_and_warnings_need_advanced_plan(): void
    {
        $this->loginAs('teacher');

        $this->getJson('/api/backend/support/achievement-definitions')->assertForbidden()->assertJsonPath('message', 'Pencapaian hanya tersedia pada paket Advanced');
        $this->getJson('/api/backend/monitoring/early-warnings')->assertForbidden()->assertJsonPath('message', 'Dashboard lanjutan tidak termasuk paket aktif');
    }

    public function test_teacher_awards_achievement_and_student_sees_only_own(): void
    {
        $this->grantAdvanced();
        $this->loginAs('teacher');
        $achievement = $this->postJson('/api/backend/support/achievements', ['code' => 'first-step', 'title' => 'Langkah pertama', 'description' => 'Menyelesaikan tugas pertama', 'criteria' => []])
            ->assertCreated()->assertJsonPath('code', 'FIRST-STEP')->json();
        $other = $this->makeUser('student2@sunrise.example', 'student');
        $otherId = DB::table('students')->where('user_id', $other->id)->value('id');
        $this->postJson("/api/backend/support/achievements/{$achievement['id']}/award", ['studentId' => $this->studentRow()->id])->assertOk();
        $this->postJson("/api/backend/support/achievements/{$achievement['id']}/award", ['studentId' => $otherId])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser('student')->id, 'event_type' => 'ACHIEVEMENT_AWARDED']);
        $this->switchTo('student');

        $this->assertSame([$this->studentRow()->id], array_column($this->getJson('/api/backend/support/achievements')->assertOk()->json(), 'studentId'));
        $this->postJson('/api/backend/support/achievements', ['code' => 'x', 'title' => 'Buat sendiri', 'description' => 'Tidak boleh', 'criteria' => []])->assertForbidden();
    }

    public function test_award_to_student_of_other_school_is_not_found(): void
    {
        $this->grantAdvanced();
        $this->loginAs('teacher');
        $achievement = $this->postJson('/api/backend/support/achievements', ['code' => 'ok', 'title' => 'Bagus', 'description' => 'Deskripsi', 'criteria' => []])->json();
        [$org, $school] = $this->otherTenant();
        $foreign = $this->makeUser('student3@sunrise.example', 'student', $org, $school);

        $this->postJson("/api/backend/support/achievements/{$achievement['id']}/award", ['studentId' => DB::table('students')->where('user_id', $foreign->id)->value('id')])
            ->assertNotFound()->assertJsonPath('message', 'Pencapaian atau siswa tidak ditemukan');
        $this->assertSame(0, DB::table('student_achievements')->count());
    }

    public function test_warnings_are_scoped_to_teacher_classes_and_private_for_students(): void
    {
        $this->grantAdvanced();
        $c = $this->createContent();
        $id = $this->warning($this->studentRow()->id, $c['classId']);

        $this->getJson('/api/backend/monitoring/early-warnings')->assertOk()->assertExactJson([]);
        $this->patchJson("/api/backend/monitoring/early-warnings/$id", ['status' => 'ACKNOWLEDGED', 'note' => 'Saya tangani'])->assertForbidden();
        DB::table('teacher_assignments')->insert(['id' => (string) Str::ulid(), 'teacher_id' => $this->teacherRow()->id, 'class_id' => $c['classId'], 'school_id' => $this->schoolId(),
            'effective_from' => now()->subDay(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/backend/monitoring/early-warnings')->assertOk()->assertJsonPath('0.id', $id)->assertJsonPath('0.evidence.progress', 0);
        $this->patchJson("/api/backend/monitoring/early-warnings/$id", ['status' => 'ACKNOWLEDGED', 'note' => 'Saya tangani'])->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

        $this->switchTo('student');
        $own = $this->getJson('/api/backend/monitoring/early-warnings')->assertOk()->json('0');
        $this->assertSame($id, $own['id']);
        $this->assertArrayNotHasKey('evidence', $own);
        $this->patchJson("/api/backend/monitoring/early-warnings/$id", ['status' => 'RESOLVED', 'note' => 'Selesai sendiri'])->assertForbidden();
    }
}
