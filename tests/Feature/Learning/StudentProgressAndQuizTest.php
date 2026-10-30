<?php

namespace Tests\Feature\Learning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentProgressAndQuizTest extends LearningTestCase
{
    private function beat(string $materialId, float $position, bool $playing = true)
    {
        return $this->postJson("/api/backend/learning/materials/$materialId/progress", ['kind' => 'VIDEO', 'position' => $position, 'playing' => $playing]);
    }

    /** A 3-slide deck attached directly (uploads are not part of this API). */
    private function deck(array $c): string
    {
        $id = (string) Str::ulid();
        DB::table('materials')->insert(['id' => $id, 'course_id' => $c['course']['id'], 'lesson_id' => $c['lesson']['id'], 'teacher_id' => $this->teacherRow()->id,
            'title' => 'Slide pelanggan', 'type' => 'DOC', 'is_active' => true, 'is_required' => true, 'original_file_name' => 'pelanggan.pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'preview_slide_count' => 3, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_student_sees_video_material_with_embed_in_tree(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);
        $this->switchTo('student');

        $lesson = $this->getJson('/api/backend/learning/tree')->assertOk()->json('0.modules.0.lessons.0');

        $this->assertSame($video['id'], $lesson['materials'][0]['id']);
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $lesson['materials'][0]['video']['embedUrl']);
        $this->assertSame('NOT_STARTED', $lesson['components']['video']['state']);
    }

    public function test_watching_the_whole_video_completes_the_lesson(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);
        $this->switchTo('student');

        $this->beat($video['id'], 0)->assertOk();
        foreach (range(5, 60, 5) as $position) {
            $this->travel(5)->seconds();
            $response = $this->beat($video['id'], $position)->assertOk();
        }

        $response->assertJsonPath('component.state', 'COMPLETED')->assertJsonPath('component.coveredPercent', 100)
            ->assertJsonPath('state', 'COMPLETED')->assertJsonPath('progressPercent', 100);
        $this->assertDatabaseHas('lesson_progress', ['lesson_id' => $c['lesson']['id'], 'student_id' => $this->studentRow()->id, 'state' => 'COMPLETED', 'video_percent' => 100]);
    }

    public function test_seeking_ahead_earns_no_video_credit(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);
        $this->switchTo('student');

        $this->beat($video['id'], 0)->assertOk();
        $this->travel(5)->seconds();

        $this->beat($video['id'], 55)->assertOk()->assertJsonPath('component.coveredPercent', 0)->assertJsonPath('component.state', 'NOT_STARTED');
    }

    public function test_paused_heartbeats_earn_no_video_credit(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);
        $this->switchTo('student');

        $this->beat($video['id'], 0, false)->assertOk();
        $this->travel(5)->seconds();

        $this->beat($video['id'], 5, false)->assertOk()->assertJsonPath('component.coveredPercent', 0);
    }

    public function test_teacher_cannot_post_video_progress(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);

        $this->beat($video['id'], 0)->assertForbidden()->assertJsonPath('message', 'Hanya siswa dapat memperbarui progres');
    }

    public function test_unenrolled_student_cannot_watch_or_take_quiz(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id']);
        $quiz = $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload())->json('quiz');
        $this->makeUser('student2@sunrise.example', 'student');
        $this->switchTo('student2');

        $this->beat($video['id'], 0)->assertForbidden()->assertJsonPath('message', 'Lesson tidak tersedia');
        $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->assertForbidden();
        $this->postJson("/api/backend/learning/quizzes/{$quiz['id']}/attempts", $this->answers($quiz, [0, 0]))->assertForbidden();
        $this->assertSame(0, DB::table('quiz_attempts')->count());
    }

    public function test_student_quiz_hides_answer_key_until_finished(): void
    {
        $c = $this->createContent();
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');

        $response = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->assertOk();

        $this->assertArrayNotHasKey('isCorrect', $response->json('quiz.questions.0.options.0'));
        $this->assertArrayNotHasKey('explanation', $response->json('quiz.questions.0'));
        $response->assertJsonPath('attemptsLeft', 2)->assertJsonPath('component.state', 'NOT_STARTED');
    }

    public function test_failed_attempt_is_scored_on_server_and_keeps_key_hidden(): void
    {
        $c = $this->createContent();
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->json('quiz');

        $result = $this->postJson("/api/backend/learning/quizzes/{$quiz['id']}/attempts", $this->answers($quiz, [0, 1]))->assertCreated();

        $result->assertJsonPath('scorePercent', 50)->assertJsonPath('passed', false)->assertJsonPath('attemptsLeft', 1)
            ->assertJsonPath('snapshot.components.quiz.state', 'IN_PROGRESS');
        $this->assertArrayNotHasKey('correctOptionId', $result->json('results.0'));
    }

    public function test_attempts_are_limited_and_reveal_key_when_exhausted(): void
    {
        $c = $this->createContent();
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->json('quiz');
        $url = "/api/backend/learning/quizzes/{$quiz['id']}/attempts";
        $this->postJson($url, $this->answers($quiz, [1, 1]))->assertCreated();

        $last = $this->postJson($url, $this->answers($quiz, [1, 1]))->assertCreated()->assertJsonPath('attemptsLeft', 0);

        $this->assertSame($quiz['questions'][0]['options'][0]['id'], $last->json('results.0.correctOptionId'));
        $this->postJson($url, $this->answers($quiz, [0, 0]))->assertStatus(409)->assertJsonPath('message', 'Kesempatan mengerjakan sudah habis');
        $this->assertSame(2, DB::table('quiz_attempts')->count());
    }

    public function test_passing_quiz_completes_quiz_component_and_blocks_retake(): void
    {
        $c = $this->createContent();
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->json('quiz');
        $url = "/api/backend/learning/quizzes/{$quiz['id']}/attempts";

        $this->postJson($url, $this->answers($quiz, [0, 0]))->assertCreated()->assertJsonPath('passed', true)->assertJsonPath('scorePercent', 100)
            ->assertJsonPath('snapshot.state', 'COMPLETED');

        $this->postJson($url, $this->answers($quiz, [0, 0]))->assertStatus(409)->assertJsonPath('message', 'Kuis sudah diselesaikan');
    }

    public function test_attempt_must_answer_every_question_with_its_own_options(): void
    {
        $c = $this->createContent();
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->json('quiz');
        $url = "/api/backend/learning/quizzes/{$quiz['id']}/attempts";

        $this->postJson($url, ['answers' => [['questionId' => $quiz['questions'][0]['id'], 'optionId' => $quiz['questions'][0]['options'][0]['id']]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Jawab semua pertanyaan tepat satu kali');
        $swapped = $this->answers($quiz, [0, 0]);
        $swapped['answers'][1]['optionId'] = $quiz['questions'][0]['options'][0]['id'];
        $this->postJson($url, $swapped)->assertUnprocessable()->assertJsonPath('message', 'Pilihan tidak valid');
        $this->assertSame(0, DB::table('quiz_attempts')->count());
    }

    public function test_teacher_cannot_replace_or_delete_quiz_after_an_attempt(): void
    {
        $c = $this->createContent();
        $url = "/api/backend/learning/lessons/{$c['lesson']['id']}/quiz";
        $this->putJson($url, $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson($url)->json('quiz');
        $this->postJson("/api/backend/learning/quizzes/{$quiz['id']}/attempts", $this->answers($quiz, [0, 1]))->assertCreated();
        $this->switchTo('teacher');

        $this->putJson($url, $this->quizPayload())->assertStatus(409)->assertJsonPath('message', 'Kuis sudah dikerjakan siswa dan tidak dapat diganti');
        $this->deleteJson($url)->assertStatus(409);
        $this->getJson($url)->assertJsonPath('attemptCount', 1);
    }

    public function test_lesson_with_video_and_quiz_completes_only_when_both_done(): void
    {
        $c = $this->createContent();
        $video = $this->createVideo($c['course']['id'], $c['lesson']['id'], 10);
        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz", $this->quizPayload());
        $this->switchTo('student');
        $quiz = $this->getJson("/api/backend/learning/lessons/{$c['lesson']['id']}/quiz")->json('quiz');

        $this->postJson("/api/backend/learning/quizzes/{$quiz['id']}/attempts", $this->answers($quiz, [0, 0]))->assertCreated()
            ->assertJsonPath('snapshot.state', 'IN_PROGRESS')->assertJsonPath('snapshot.progressPercent', 50);
        $this->beat($video['id'], 0);
        $this->travel(5)->seconds();
        $this->beat($video['id'], 5);
        $this->travel(5)->seconds();

        $this->beat($video['id'], 10)->assertOk()->assertJsonPath('state', 'COMPLETED')->assertJsonPath('progressPercent', 100);
    }

    public function test_slide_deck_must_be_read_in_order(): void
    {
        $c = $this->createContent();
        $deck = $this->deck($c);
        $this->switchTo('student');
        $url = "/api/backend/learning/lessons/{$c['lesson']['id']}/progress";

        $this->putJson($url, ['state' => 'COMPLETED', 'progressPercent' => 100, 'lastPosition' => 3, 'materialId' => $deck])
            ->assertUnprocessable()->assertJsonPath('message', 'Buka materi secara berurutan');
        $this->putJson($url, ['state' => 'IN_PROGRESS', 'progressPercent' => 33, 'lastPosition' => 1, 'materialId' => $deck])->assertOk()
            ->assertJsonPath('components.reading.materials.0.position', 1);
        $this->putJson($url, ['state' => 'IN_PROGRESS', 'progressPercent' => 66, 'lastPosition' => 2, 'materialId' => $deck])->assertOk();

        $this->putJson($url, ['state' => 'COMPLETED', 'progressPercent' => 100, 'lastPosition' => 3, 'materialId' => $deck])->assertOk()
            ->assertJsonPath('state', 'COMPLETED')->assertJsonPath('progressPercent', 100);
    }

    public function test_tracked_lesson_ignores_client_supplied_completion(): void
    {
        $c = $this->createContent();
        $this->deck($c);
        $this->switchTo('student');

        $this->putJson("/api/backend/learning/lessons/{$c['lesson']['id']}/progress", ['state' => 'COMPLETED', 'progressPercent' => 100])
            ->assertOk()->assertJsonPath('state', 'NOT_STARTED')->assertJsonPath('progressPercent', 0);
    }

    public function test_untracked_lesson_keeps_completed_state_on_revisit(): void
    {
        $c = $this->createContent();
        $this->switchTo('student');
        $url = "/api/backend/learning/lessons/{$c['lesson']['id']}/progress";
        $this->putJson($url, ['state' => 'COMPLETED', 'progressPercent' => 100, 'lastPosition' => 5])->assertOk()->assertJsonPath('state', 'COMPLETED');

        $this->putJson($url, ['state' => 'IN_PROGRESS', 'progressPercent' => 20, 'lastPosition' => 1])->assertOk()
            ->assertJsonPath('state', 'COMPLETED')->assertJsonPath('progressPercent', 100)->assertJsonPath('lastPosition', 5);
    }

    public function test_material_file_endpoint_requires_access(): void
    {
        $c = $this->createContent();
        $deck = $this->deck($c);
        $this->switchTo('student');

        $this->getJson("/api/materials/$deck")->assertOk()->assertJsonPath('slideCount', 3)->assertJsonPath('downloadUrl', "/api/materials/$deck?download=1");
        $this->makeUser('student2@sunrise.example', 'student');
        $this->switchTo('student2');
        $this->getJson("/api/materials/$deck")->assertNotFound();
    }
}
