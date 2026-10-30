<?php

namespace Tests\Feature\Learning;

use Illuminate\Support\Facades\DB;

class LearningContentTest extends LearningTestCase
{
    public function test_guest_cannot_read_learning_tree(): void
    {
        $this->getJson('/api/backend/learning/tree')->assertUnauthorized();
    }

    public function test_student_cannot_create_course(): void
    {
        $this->loginAs('student');

        $this->postJson('/api/backend/learning/courses', ['code' => 'X1', 'name' => 'Course siswa'])
            ->assertForbidden()->assertJsonPath('message', 'Anda tidak memiliki izin untuk tindakan ini');
        $this->assertDatabaseMissing('courses', ['code' => 'X1']);
    }

    public function test_role_without_materials_permission_cannot_read_materials(): void
    {
        $this->loginAs('parent');

        $this->getJson('/api/backend/learning/materials')->assertForbidden();
    }

    public function test_teacher_builds_course_module_lesson_and_enrolls_class(): void
    {
        $content = $this->createContent();

        $this->assertSame('BD101', $content['course']['code']);
        $this->assertSame('Bisnis Digital Dasar', $content['course']['name']);
        $this->assertSame(1, $content['module']['position']);
        $this->assertDatabaseHas('course_enrollments', ['course_id' => $content['course']['id'], 'student_id' => $this->studentRow()->id, 'status' => 'ACTIVE']);

        $tree = collect($this->getJson('/api/backend/learning/tree')->assertOk()->json())->firstWhere('id', $content['course']['id']);
        $this->assertSame('Wawancara pelanggan', $tree['modules'][0]['lessons'][0]['title']);
    }

    public function test_duplicate_course_code_is_rejected(): void
    {
        $this->createContent();

        $this->postJson('/api/backend/learning/courses', ['code' => 'BD101', 'name' => 'Duplikat'])
            ->assertStatus(409)->assertJsonPath('message', 'Kode course sudah digunakan');
    }

    public function test_course_validation_rejects_short_name(): void
    {
        $this->loginAs('teacher');

        $this->postJson('/api/backend/learning/courses', ['code' => 'A', 'name' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
        $this->assertSame(0, DB::table('courses')->where('school_id', $this->schoolId())->count());
    }

    public function test_teacher_adds_video_material_with_embed_info(): void
    {
        $c = $this->createContent();

        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);

        $this->assertSame('YOUTUBE', $video['video']['provider']);
        $this->assertSame('dQw4w9WgXcQ', $video['video']['videoId']);
        $this->assertSame(90, $video['video']['completionThreshold']);
        $listed = collect($this->getJson('/api/backend/learning/materials')->assertOk()->json())->firstWhere('id', $video['id']);
        $this->assertSame($c['course']['id'], $listed['course']['id']);
        $this->assertSame($c['module']['id'], $listed['lesson']['moduleId']);
    }

    public function test_video_material_needs_youtube_or_vimeo_link(): void
    {
        $c = $this->createContent();

        $this->postJson('/api/backend/learning/materials', ['courseId' => $c['course']['id'], 'lessonId' => $c['lesson']['id'], 'title' => 'Video lain',
            'type' => 'VIDEO', 'fileUrl' => 'https://example.com/video.mp4'])->assertUnprocessable()->assertJsonPath('message', 'Tautan video harus berupa YouTube atau Vimeo');
        $this->assertDatabaseMissing('materials', ['title' => 'Video lain']);
    }

    public function test_material_lesson_must_belong_to_course(): void
    {
        $c = $this->createContent();
        $other = $this->postJson('/api/backend/learning/courses', ['code' => 'BD102', 'name' => 'Course kedua'])->json();

        $this->postJson('/api/backend/learning/materials', ['courseId' => $other['id'], 'lessonId' => $c['lesson']['id'], 'title' => 'Salah tempat', 'type' => 'TEXT'])
            ->assertUnprocessable()->assertJsonPath('message', 'Lesson tidak berada pada course');
    }

    public function test_teacher_saves_quiz_with_answer_key(): void
    {
        $c = $this->createContent();

        $quiz = $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload())->assertCreated()->json('quiz');

        $this->assertCount(2, $quiz['questions']);
        $this->assertTrue($quiz['questions'][0]['options'][0]['isCorrect']);
        $this->assertSame(2, $quiz['maxAttempts']);
        $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->assertOk()->assertJsonPath('quiz.id', $quiz['id'])->assertJsonPath('attemptCount', 0);
    }

    public function test_quiz_question_needs_exactly_one_correct_answer(): void
    {
        $c = $this->createContent();
        $payload = $this->quizPayload();
        $payload['questions'][0]['options'][1]['isCorrect'] = true;

        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $payload)
            ->assertUnprocessable()->assertJsonPath('message', 'Pertanyaan 1 harus punya tepat satu jawaban benar');
        $this->assertSame(0, DB::table('lesson_quizzes')->count());
    }

    public function test_teacher_can_replace_and_delete_quiz_before_attempts(): void
    {
        $c = $this->createContent();
        $url = "/api/backend/learning/lessons/{$c['lesson']['id']}/quiz";
        $this->putJson($url, $this->quizPayload())->assertCreated();

        $this->putJson($url, $this->quizPayload(['title' => 'Kuis baru']))->assertOk()->assertJsonPath('quiz.title', 'Kuis baru');
        $this->assertSame(1, DB::table('lesson_quizzes')->count());
        $this->deleteJson($url)->assertOk()->assertJsonPath('deleted', true);
        $this->getJson($url)->assertOk()->assertJsonPath('quiz', null);
    }

    public function test_other_organization_teacher_cannot_touch_lesson_or_course(): void
    {
        $c = $this->createContent();
        [$org, $school] = $this->otherTenant();
        $this->makeUser('teacher2@sunrise.example', 'teacher', $org, $school);
        $this->switchTo('teacher2');

        $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->assertNotFound()->assertJsonPath('message', 'Lesson tidak ditemukan');
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload())->assertNotFound();
        $this->postJson("/api/backend/learning/courses/{$c['course']['id']}/modules", ['title' => 'Modul asing'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/backend/learning/courses')->assertOk()->json());
        $this->assertSame(0, DB::table('lesson_quizzes')->count());
    }

    public function test_student_does_not_see_unpublished_lessons_or_unenrolled_courses(): void
    {
        $c = $this->createContent();
        $this->postJson("/api/backend/learning/modules/{$c['module']['id']}/lessons", ['title' => 'Draf rahasia'])->assertCreated();
        $this->postJson('/api/backend/learning/courses', ['code' => 'BD900', 'name' => 'Belum diikuti'])->assertCreated();
        $this->switchTo('student');

        $tree = $this->getJson('/api/backend/learning/tree')->assertOk()->json();

        $this->assertSame([$c['course']['id']], array_column($tree, 'id'));
        $this->assertSame(['Wawancara pelanggan'], array_column($tree[0]['modules'][0]['lessons'], 'title'));
        $this->assertSame('FREE', $tree[0]['accessPlan']['code']);
    }
}
