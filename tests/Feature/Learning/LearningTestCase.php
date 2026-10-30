<?php

namespace Tests\Feature\Learning;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Shared builders for the Learning area: content made through the API as the demo teacher. */
abstract class LearningTestCase extends TestCase
{
    protected const VIDEO_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    /** Ends the current session (if any) and logs in as $role@sunrise.example. */
    protected function switchTo(string $role): User
    {
        $this->deleteJson('/api/session');
        $this->app['auth']->forgetGuards();

        return $this->loginAs($role);
    }

    protected function schoolId(): string
    {
        return $this->demoUser('teacher')->school_id;
    }

    protected function studentRow(string $role = 'student'): object
    {
        return DB::table('students')->where('user_id', $this->demoUser($role)->id)->first();
    }

    protected function teacherRow(string $role = 'teacher'): object
    {
        return DB::table('teachers')->where('user_id', $this->demoUser($role)->id)->first();
    }

    /** A user with one role in the given organization/school, plus a student/teacher profile when relevant. */
    protected function makeUser(string $email, string $role, ?string $organizationId = null, ?string $schoolId = null): User
    {
        $organizationId ??= $this->demoUser('teacher')->organization_id;
        $schoolId ??= $this->schoolId();
        $roleId = DB::table('roles')->where('organization_id', $organizationId)->where('slug', $role)->value('id');
        $user = new User();
        $user->forceFill(['name' => "$role Lain", 'email' => $email, 'password' => Hash::make('DemoLogin#2026'), 'organization_id' => $organizationId,
            'school_id' => $schoolId, 'role' => in_array($role, ['teacher', 'student'], true) ? $role : 'unmapped', 'status' => 'active', 'plan_rank' => 0])->save();
        DB::table('user_roles')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'role_id' => $roleId, 'school_id' => $schoolId, 'created_at' => now()]);
        if ($role === 'student') DB::table('students')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'school_id' => $schoolId,
            'student_number' => 'NIS-'.Str::random(6), 'full_name' => 'Siswa Lain', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        if ($role === 'teacher') DB::table('teachers')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'school_id' => $schoolId,
            'full_name' => 'Guru Lain', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** A second organization + school with its own baseline roles. Returns [organizationId, schoolId]. */
    protected function otherTenant(): array
    {
        $org = (string) Str::ulid();
        $school = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Organisasi Lain', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('schools')->insert(['id' => $school, 'organization_id' => $org, 'name' => 'Sekolah Lain', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('sunrise:seed-baseline', ['--organization' => $org]);

        return [$org, $school];
    }

    protected function enrollInClass(string $studentId, string $classId): void
    {
        DB::table('class_enrollments')->insert(['id' => (string) Str::ulid(), 'student_id' => $studentId, 'class_id' => $classId, 'school_id' => $this->schoolId(),
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The teacher builds a course -> published module -> published lesson and enrolls the demo student's class.
     * Leaves the teacher logged in.
     */
    protected function createContent(): array
    {
        $this->switchTo('teacher');
        $course = $this->postJson('/api/backend/learning/courses', ['code' => 'bd101', 'name' => 'Bisnis Digital Dasar'])->assertCreated()->json();
        $module = $this->postJson("/api/backend/learning/courses/{$course['id']}/modules", ['title' => 'Modul Pelanggan', 'isPublished' => true])->assertCreated()->json();
        $lesson = $this->postJson("/api/backend/learning/modules/{$module['id']}/lessons", ['title' => 'Wawancara pelanggan', 'summary' => 'Ringkasan', 'isPublished' => true])->assertCreated()->json();
        $classId = (string) Str::ulid();
        DB::table('classes')->insert(['id' => $classId, 'school_id' => $this->schoolId(), 'name' => 'XII RPL 1', 'code' => 'XII-RPL-1', 'grade' => 12, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->enrollInClass($this->studentRow()->id, $classId);
        $this->postJson('/api/backend/learning/enrollments/class', ['courseId' => $course['id'], 'classId' => $classId])->assertOk()->assertJsonPath('enrolledStudents', 1);

        return ['course' => $course, 'module' => $module, 'lesson' => $lesson, 'classId' => $classId];
    }

    protected function createVideo(string $courseId, string $lessonId, int $duration = 60): array
    {
        return $this->postJson('/api/backend/learning/materials', ['courseId' => $courseId, 'lessonId' => $lessonId, 'title' => 'Video wawancara',
            'type' => 'VIDEO', 'fileUrl' => self::VIDEO_URL, 'durationSeconds' => $duration, 'completionThreshold' => 90])->assertCreated()->json();
    }

    /** Two questions; the first option of each is correct. */
    protected function quizPayload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Kuis pelanggan', 'passPercent' => 100, 'maxAttempts' => 2, 'questions' => [
            ['prompt' => 'Apa tujuan wawancara?', 'explanation' => 'Mengenali masalah.', 'options' => [['label' => 'Mengenali masalah', 'isCorrect' => true], ['label' => 'Menjual langsung', 'isCorrect' => false]]],
            ['prompt' => 'Berapa calon pelanggan?', 'options' => [['label' => 'Tiga', 'isCorrect' => true], ['label' => 'Satu', 'isCorrect' => false], ['label' => 'Nol', 'isCorrect' => false]]],
        ]];
    }

    /** Answers for the quiz as returned to the student: index 0 = correct option (options keep their position). */
    protected function answers(array $quiz, array $optionIndexes): array
    {
        return ['answers' => collect($quiz['questions'])->map(fn ($q, $i) => ['questionId' => $q['id'], 'optionId' => $q['options'][$optionIndexes[$i]]['id']])->all()];
    }

    /** The teacher (logged in) creates a published WRITTEN assignment on the module. */
    protected function createAssignment(array $content, array $overrides = []): array
    {
        return $this->postJson('/api/backend/assignments', $overrides + ['courseId' => $content['course']['id'], 'moduleId' => $content['module']['id'],
            'title' => 'Rancang penawaran', 'description' => 'Tulis penawaran.', 'type' => 'WRITTEN', 'startDate' => now()->subHour()->toIso8601String(),
            'endDate' => now()->addWeek()->toIso8601String(), 'maxScore' => 100, 'isActive' => true])->assertCreated()->json();
    }

    /** The demo student (logged in) saves a draft and submits it. Returns the submission id. */
    protected function submitWork(string $assignmentId, string $content = 'Target pelanggan: UMKM.'): string
    {
        $id = $this->postJson('/api/backend/submissions', ['assignmentId' => $assignmentId, 'content' => $content, 'notes' => $content])->assertCreated()->json('id');
        $this->postJson("/api/backend/submissions/$id/submit")->assertOk()->assertJsonPath('status', 'SUBMITTED');

        return $id;
    }
}
