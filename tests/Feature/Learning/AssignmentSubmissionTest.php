<?php

namespace Tests\Feature\Learning;

use Illuminate\Support\Facades\DB;

class AssignmentSubmissionTest extends LearningTestCase
{
    /** Content + assignment by the teacher, then the student submits. Leaves the teacher logged in. */
    private function submitted(array $assignmentOverrides = []): array
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c, $assignmentOverrides);
        $this->switchTo('student');
        $id = $this->submitWork($assignment['id']);
        $this->switchTo('teacher');

        return [$c, $assignment, $id];
    }

    public function test_guest_cannot_list_submissions(): void
    {
        $this->getJson('/api/backend/submissions')->assertUnauthorized();
    }

    public function test_student_cannot_create_assignment(): void
    {
        $c = $this->createContent();
        $this->switchTo('student');

        $this->postJson('/api/backend/assignments', ['courseId' => $c['course']['id'], 'moduleId' => $c['module']['id'], 'title' => 'Tugas siswa',
            'startDate' => now()->toIso8601String(), 'endDate' => now()->addDay()->toIso8601String()])->assertForbidden();
        $this->assertDatabaseMissing('assignments', ['title' => 'Tugas siswa']);
    }

    public function test_teacher_creates_assignment_and_student_sees_it(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);
        $this->assertSame('WRITTEN', $assignment['type']);
        $this->assertTrue($assignment['isActive']);
        $this->assertSame('BD101', $assignment['course']['code']);
        $this->switchTo('student');

        $this->getJson('/api/backend/assignments')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $assignment['id'])
            ->assertJsonPath('data.0._count.submissions', 0);
    }

    public function test_assignment_deadline_must_follow_start(): void
    {
        $c = $this->createContent();

        $this->postJson('/api/backend/assignments', ['courseId' => $c['course']['id'], 'moduleId' => $c['module']['id'], 'title' => 'Tugas mundur',
            'startDate' => now()->toIso8601String(), 'endDate' => now()->subDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('message', 'Deadline harus setelah waktu mulai');
        $this->assertDatabaseMissing('assignments', ['title' => 'Tugas mundur']);
    }

    public function test_published_assignment_needs_published_module(): void
    {
        $c = $this->createContent();
        $draft = $this->postJson("/api/backend/learning/courses/{$c['course']['id']}/modules", ['title' => 'Modul draf'])->json();

        $this->createAssignmentExpecting422($c, $draft['id'], 'Modul harus diterbitkan sebelum tugas dipublikasikan');
    }

    private function createAssignmentExpecting422(array $c, string $moduleId, string $message): void
    {
        $this->postJson('/api/backend/assignments', ['courseId' => $c['course']['id'], 'moduleId' => $moduleId, 'title' => 'Tugas gagal', 'type' => 'WRITTEN',
            'startDate' => now()->toIso8601String(), 'endDate' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonPath('message', $message);
    }

    public function test_other_teacher_cannot_update_or_archive_assignment(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);
        $this->makeUser('teacher2@sunrise.example', 'teacher');
        $this->switchTo('teacher2');

        $this->putJson("/api/backend/assignments/{$assignment['id']}", ['title' => 'Diambil alih'])->assertNotFound();
        $this->deleteJson("/api/backend/assignments/{$assignment['id']}")->assertNotFound();
        $this->assertDatabaseHas('assignments', ['id' => $assignment['id'], 'title' => 'Rancang penawaran', 'is_active' => true]);
    }

    public function test_teacher_updates_and_archives_own_assignment_with_versions(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);

        $this->putJson("/api/backend/assignments/{$assignment['id']}", ['title' => 'Rancang penawaran v2'])->assertOk()->assertJsonPath('version', 2);
        $this->deleteJson("/api/backend/assignments/{$assignment['id']}")->assertOk()->assertJsonPath('isActive', false);

        $this->assertSame(3, DB::table('assignment_versions')->where('assignment_id', $assignment['id'])->count());
        $this->switchTo('student');
        $this->getJson('/api/backend/assignments')->assertJsonPath('total', 0);
    }

    public function test_student_drafts_and_submits_assignment(): void
    {
        [, $assignment, $id] = $this->submitted();

        $this->assertDatabaseHas('submissions', ['id' => $id, 'assignment_id' => $assignment['id'], 'student_id' => $this->studentRow()->id, 'status' => 'SUBMITTED']);
        $this->assertDatabaseHas('submission_versions', ['submission_id' => $id, 'revision' => 1, 'content' => 'Target pelanggan: UMKM.']);
    }

    public function test_empty_submission_cannot_be_submitted(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);
        $this->switchTo('student');
        $id = $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id']])->assertCreated()->json('id');

        $this->postJson("/api/backend/submissions/$id/submit")->assertUnprocessable()->assertJsonPath('message', 'Isi atau bukti tugas wajib dilengkapi');
        $this->assertDatabaseHas('submissions', ['id' => $id, 'status' => 'DRAFT']);
    }

    public function test_submission_after_deadline_is_rejected(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c, ['startDate' => now()->subWeek()->toIso8601String(), 'endDate' => now()->subMinute()->toIso8601String()]);
        $this->switchTo('student');

        $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id'], 'content' => 'Terlambat'])
            ->assertUnprocessable()->assertJsonPath('message', 'Tenggat pengumpulan telah lewat');
        $this->assertSame(0, DB::table('submissions')->count());
    }

    public function test_teacher_cannot_create_submission(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);

        $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id'], 'content' => 'Guru'])->assertForbidden();
    }

    public function test_teacher_sees_submitted_work_in_list_and_dashboard(): void
    {
        [, , $id] = $this->submitted();

        $this->getJson('/api/backend/submissions')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.student.fullName', 'Siswa Demo');
        $this->getJson('/api/backend/dashboard/teacher')->assertOk()->assertJsonPath('kpi.pendingReviewsCount', 1)
            ->assertJsonPath('pendingReviewsQueue.0.id', $id)->assertJsonPath('activeAssignments.0._count.submissions', 1)
            ->assertJsonPath('kpi.totalStudents', 1);
    }

    public function test_review_requesting_revision_needs_feedback(): void
    {
        [, , $id] = $this->submitted();

        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'REVISION_REQUIRED', 'publishGrade' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Feedback diperlukan untuk penolakan atau revisi');
        $this->assertSame(0, DB::table('submission_reviews')->count());
    }

    public function test_score_above_assignment_maximum_is_rejected(): void
    {
        [, , $id] = $this->submitted();

        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 101, 'publishGrade' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Nilai melebihi maksimal tugas');
        $this->assertDatabaseHas('submissions', ['id' => $id, 'status' => 'SUBMITTED']);
    }

    public function test_unpublished_grade_stays_under_review_and_hidden_from_student(): void
    {
        [, , $id] = $this->submitted();

        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 90, 'feedback' => 'Bagus', 'publishGrade' => false])
            ->assertOk()->assertJsonPath('isPublished', false);

        $this->assertDatabaseHas('submissions', ['id' => $id, 'status' => 'UNDER_REVIEW', 'score' => null, 'grade_published_at' => null]);
        $this->switchTo('student');
        $this->getJson("/api/backend/submissions/$id")->assertOk()->assertJsonPath('status', 'UNDER_REVIEW')->assertJsonPath('score', null)->assertJsonPath('reviews', []);
    }

    public function test_revision_cycle_then_approval_locks_submission(): void
    {
        [, $assignment, $id] = $this->submitted();
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'REVISION_REQUIRED', 'feedback' => 'Tambahkan harga.', 'publishGrade' => true])->assertOk();
        $this->switchTo('student');
        $this->getJson("/api/backend/submissions/$id")->assertOk()->assertJsonPath('status', 'REVISION_REQUIRED')->assertJsonPath('feedback', 'Tambahkan harga.')
            ->assertJsonPath('reviews.0.status', 'REVISION_REQUIRED');

        $this->postJson("/api/backend/submissions/$id/submit")->assertUnprocessable()->assertJsonPath('message', 'Simpan versi revisi sebelum mengirim ulang');
        $this->travel(2)->seconds();
        $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id'], 'content' => 'Revisi dengan harga Rp50.000'])->assertOk()->assertJsonPath('currentVersion', 2);
        $this->postJson("/api/backend/submissions/$id/submit")->assertOk()->assertJsonPath('status', 'RESUBMITTED');
        $this->switchTo('teacher');
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 88, 'feedback' => 'Lengkap', 'publishGrade' => true])->assertOk();

        $this->assertDatabaseHas('submissions', ['id' => $id, 'status' => 'APPROVED', 'score' => 88]);
        $this->assertNotNull(DB::table('submissions')->where('id', $id)->value('locked_at'));
        $this->switchTo('student');
        $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id'], 'content' => 'Ubah lagi'])
            ->assertStatus(409)->assertJsonPath('message', 'Kiriman tidak dapat diubah pada status ini');
    }

    public function test_approved_submission_cannot_be_reviewed_again(): void
    {
        [, , $id] = $this->submitted();
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 80, 'publishGrade' => true])->assertOk();

        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'REJECTED', 'feedback' => 'Batal'])->assertStatus(409)->assertJsonPath('message', 'Kiriman belum dapat dinilai');
    }

    public function test_rubric_scores_compute_the_grade(): void
    {
        $c = $this->createContent();
        $rubric = $this->postJson('/api/backend/rubrics', ['name' => 'Rubrik penawaran', 'criteria' => [
            ['name' => 'Kejelasan', 'weight' => 60, 'maxScore' => 10], ['name' => 'Bukti', 'weight' => 40, 'maxScore' => 10]]])->assertCreated()->json();
        $assignment = $this->createAssignment($c, ['rubricId' => $rubric['id']]);
        $this->switchTo('student');
        $id = $this->submitWork($assignment['id']);
        $this->switchTo('teacher');
        [$clarity, $evidence] = array_column($rubric['criteria'], 'id');

        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'publishGrade' => true, 'scores' => [['criterionId' => $clarity, 'score' => 8]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Semua kriteria rubrik wajib dinilai');
        $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'publishGrade' => true, 'score' => 5,
            'scores' => [['criterionId' => $clarity, 'score' => 8], ['criterionId' => $evidence, 'score' => 5]]])->assertOk()->assertJsonPath('totalScore', 68);

        $this->assertSame(2, DB::table('review_scores')->count());
    }

    public function test_student_cannot_see_or_submit_another_students_submission(): void
    {
        [$c, $assignment, $id] = $this->submitted();
        $other = $this->makeUser('student2@sunrise.example', 'student');
        $this->enrollInClass(DB::table('students')->where('user_id', $other->id)->value('id'), $c['classId']);
        $this->postJson('/api/backend/learning/enrollments/class', ['courseId' => $c['course']['id'], 'classId' => $c['classId']])->assertJsonPath('enrolledStudents', 2);
        $this->switchTo('student2');

        $this->getJson("/api/backend/submissions/$id")->assertNotFound();
        $this->postJson("/api/backend/submissions/$id/submit")->assertNotFound();
        $this->getJson('/api/backend/submissions')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/api/backend/assignments/{$assignment['id']}")->assertOk()->assertJsonPath('_count.submissions', 0)->assertJsonPath('submissions', []);
    }

    public function test_other_teachers_cannot_read_or_review_submission(): void
    {
        [, , $id] = $this->submitted();
        $this->makeUser('teacher2@sunrise.example', 'teacher');
        [$org, $school] = $this->otherTenant();
        $this->makeUser('teacher3@sunrise.example', 'teacher', $org, $school);

        foreach (['teacher2', 'teacher3'] as $role) {
            $this->switchTo($role);
            $this->getJson("/api/backend/submissions/$id")->assertNotFound();
            $this->postJson("/api/backend/submissions/$id/review", ['status' => 'APPROVED', 'score' => 100, 'publishGrade' => true])->assertNotFound();
        }
        $this->assertDatabaseHas('submissions', ['id' => $id, 'status' => 'SUBMITTED', 'score' => null]);
    }

    public function test_ai_assessment_is_teacher_only_and_needs_rubric(): void
    {
        [, , $id] = $this->submitted();

        $this->postJson("/api/backend/submissions/$id/ai-assessment")->assertStatus(400)->assertJsonPath('message', 'Tugas ini belum memiliki rubrik untuk dinilai AI');
        $this->switchTo('student');
        $this->postJson("/api/backend/submissions/$id/ai-assessment")->assertForbidden();
        $this->assertSame(0, DB::table('ai_assessments')->count());
    }

    public function test_affiliate_video_only_on_affiliate_assignment_draft(): void
    {
        $c = $this->createContent();
        $assignment = $this->createAssignment($c);
        $this->switchTo('student');
        $id = $this->postJson('/api/backend/submissions', ['assignmentId' => $assignment['id'], 'content' => 'Draf'])->json('id');

        $this->postJson("/api/backend/submissions/$id/videos", ['platform' => 'TIKTOK', 'videoUrl' => 'https://www.tiktok.com/@a/video/123', 'publishedAt' => now()->toIso8601String()])
            ->assertStatus(400)->assertJsonPath('message', 'Video affiliate hanya dapat ditambahkan pada tugas affiliate');
        $this->assertSame(0, DB::table('affiliate_videos')->count());
    }

    public function test_student_dashboard_counts_active_and_pending_assignments(): void
    {
        $c = $this->createContent();
        $first = $this->createAssignment($c);
        $this->createAssignment($c, ['title' => 'Tugas kedua']);
        $this->switchTo('student');
        $this->submitWork($first['id']);

        $this->getJson('/api/backend/dashboard/student')->assertOk()->assertJsonPath('activeAssignmentsCount', 2)
            ->assertJsonPath('pendingTasksCount', 1)->assertJsonPath('studentInfo.fullName', 'Siswa Demo');
        $this->getJson('/api/backend/dashboard/teacher')->assertForbidden();
    }
}
