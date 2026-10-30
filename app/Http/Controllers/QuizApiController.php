<?php
namespace App\Http\Controllers;

use App\Support\{LessonProgress, ApiContext, StudentDashboardAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Q&A ("tanya jawab") quiz per lesson: teachers author it, students submit attempts. */
class QuizApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function lessonFor(array $c, string $lessonId): object
    {
        $lesson = DB::table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.course_module_id')->join('courses as k', 'k.id', '=', 'm.course_id')
            ->where('l.id', $lessonId)->where('k.organization_id', $c['organization'])->where('k.school_id', $c['school'])->first(['l.id', 'l.course_module_id']);
        abort_unless($lesson, 404, 'Lesson tidak ditemukan');
        return $lesson;
    }

    private function studentLesson(array $c, string $lessonId): object
    {
        $a = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']->id);
        abort_unless($a, 503, 'Paket akses sumber belum dimigrasikan');
        abort_unless($this->access->enabled($a, 'learning.materials.read'), 403, 'Materi tidak termasuk dalam paket aktif');
        $lesson = DB::table('lessons')->where('id', $lessonId)->where('is_published', true)->whereIn('course_module_id', $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $a))->first();
        abort_unless($lesson, 403, 'Lesson tidak tersedia');
        return $lesson;
    }

    private function questions(string $quizId): \Illuminate\Support\Collection
    {
        $options = DB::table('quiz_options')->whereIn('question_id', DB::table('quiz_questions')->where('quiz_id', $quizId)->select('id'))->orderBy('position')->get()->groupBy('question_id');
        return DB::table('quiz_questions')->where('quiz_id', $quizId)->orderBy('position')->get()->map(fn ($q) => (object) ['q' => $q, 'options' => $options[$q->id] ?? collect()]);
    }

    private function quizBody(object $quiz, bool $withAnswers, ?array $reveal = null): array
    {
        return ['id' => $quiz->id, 'lessonId' => $quiz->lesson_id, 'title' => $quiz->title, 'passPercent' => $quiz->pass_percent === null ? null : (int) $quiz->pass_percent, 'maxAttempts' => (int) $quiz->max_attempts,
            'isRequired' => (bool) $quiz->is_required, 'isActive' => (bool) $quiz->is_active,
            'questions' => $this->questions($quiz->id)->map(fn ($x) => ['id' => $x->q->id, 'position' => (int) $x->q->position, 'prompt' => $x->q->prompt]
                + ($withAnswers || isset($reveal[$x->q->id]) ? ['explanation' => $x->q->explanation] : [])
                + ['options' => $x->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label] + ($withAnswers || isset($reveal[$x->q->id]) ? ['isCorrect' => (bool) $o->is_correct] : []))->values()->all()])->values()->all()];
    }

    /** Teacher sees the answer key; a student sees questions only, plus their attempt history. */
    public function show(Request $r, string $lessonId)
    {
        $c = $this->context->resolve($r, 'materials', 'read');
        $quiz = null;
        if ($c['student']) {
            $this->studentLesson($c, $lessonId);
            $quiz = DB::table('lesson_quizzes')->where('lesson_id', $lessonId)->where('is_active', true)->first();
            if (! $quiz) return response()->json(['quiz' => null]);
            $attempts = DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->where('student_id', $c['student']->id)->orderBy('attempt_no')->get();
            $finished = $attempts->contains('passed', true) || $attempts->count() >= $quiz->max_attempts;
            return response()->json(['quiz' => $this->quizBody($quiz, $finished), 'attempts' => $attempts->map(fn ($a) => ['attemptNo' => (int) $a->attempt_no, 'scorePercent' => (int) $a->score_percent, 'passed' => (bool) $a->passed, 'submittedAt' => \Carbon\CarbonImmutable::parse($a->submitted_at, 'UTC')->format('Y-m-d\TH:i:s.v\Z')])->all(),
                'attemptsLeft' => max(0, $quiz->max_attempts - $attempts->count()), 'component' => app(LessonProgress::class)->components($c['student']->id, $lessonId)['quiz']]);
        }
        abort_unless($c['teacher'], 403, 'Hanya guru atau siswa yang dapat membuka kuis');
        $this->lessonFor($c, $lessonId);
        $quiz = DB::table('lesson_quizzes')->where('lesson_id', $lessonId)->first();
        return response()->json(['quiz' => $quiz ? $this->quizBody($quiz, true) : null, 'attemptCount' => $quiz ? DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->count() : 0]);
    }

    /** Creates or replaces the lesson's quiz. Replacing is refused once a student has attempted it. */
    public function save(Request $r, string $lessonId)
    {
        $c = $this->context->resolve($r, 'materials', 'create');
        abort_unless($c['teacher'], 403, 'Akun tidak memiliki profil guru');
        $this->lessonFor($c, $lessonId);
        $d = $r->validate(['title' => 'required|string|min:3|max:160', 'passPercent' => 'nullable|integer|min:1|max:100', 'maxAttempts' => 'sometimes|integer|min:1|max:10', 'isRequired' => 'sometimes|boolean', 'isActive' => 'sometimes|boolean',
            'questions' => 'required|array|min:1|max:50', 'questions.*.prompt' => 'required|string|min:3|max:1000', 'questions.*.explanation' => 'nullable|string|max:1000',
            'questions.*.options' => 'required|array|min:2|max:6', 'questions.*.options.*.label' => 'required|string|min:1|max:500', 'questions.*.options.*.isCorrect' => 'required|boolean']);
        foreach ($d['questions'] as $i => $q) abort_unless(collect($q['options'])->where('isCorrect', true)->count() === 1, 422, 'Pertanyaan ' . ($i + 1) . ' harus punya tepat satu jawaban benar');
        return DB::transaction(function () use ($d, $lessonId, $c) {
            $quiz = DB::table('lesson_quizzes')->where('lesson_id', $lessonId)->lockForUpdate()->first();
            abort_if($quiz && DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->exists(), 409, 'Kuis sudah dikerjakan siswa dan tidak dapat diganti');
            $quiz && DB::table('lesson_quizzes')->where('id', $quiz->id)->delete();
            $row = ['id' => (string) Str::ulid(), 'lesson_id' => $lessonId, 'title' => trim($d['title']), 'pass_percent' => $d['passPercent'] ?? null, 'max_attempts' => $d['maxAttempts'] ?? 3,
                'is_required' => $d['isRequired'] ?? true, 'is_active' => $d['isActive'] ?? true, 'created_by_id' => $c['user'], 'created_at' => now(), 'updated_at' => now()];
            DB::table('lesson_quizzes')->insert($row);
            foreach ($d['questions'] as $i => $q) {
                $qid = (string) Str::ulid();
                DB::table('quiz_questions')->insert(['id' => $qid, 'quiz_id' => $row['id'], 'position' => $i + 1, 'prompt' => trim($q['prompt']), 'explanation' => $q['explanation'] ?? null]);
                foreach ($q['options'] as $j => $o) DB::table('quiz_options')->insert(['id' => (string) Str::ulid(), 'question_id' => $qid, 'position' => $j + 1, 'label' => trim($o['label']), 'is_correct' => $o['isCorrect']]);
            }
            return response()->json(['quiz' => $this->quizBody((object) $row, true)], $quiz ? 200 : 201);
        });
    }

    public function destroy(Request $r, string $lessonId)
    {
        $c = $this->context->resolve($r, 'materials', 'create');
        abort_unless($c['teacher'], 403, 'Akun tidak memiliki profil guru');
        $this->lessonFor($c, $lessonId);
        $quiz = DB::table('lesson_quizzes')->where('lesson_id', $lessonId)->first();
        abort_unless($quiz, 404, 'Kuis tidak ditemukan');
        abort_if(DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->exists(), 409, 'Kuis sudah dikerjakan siswa; nonaktifkan saja');
        DB::table('lesson_quizzes')->where('id', $quiz->id)->delete();
        return response()->json(['deleted' => true]);
    }

    /** One submission: every question answered with an option of that question. Scored on the server. */
    public function attempt(Request $r, string $quizId)
    {
        $c = $this->context->resolve($r, 'materials', 'read');
        abort_unless($c['student'], 403, 'Hanya siswa dapat mengerjakan kuis');
        $quiz = DB::table('lesson_quizzes')->where('id', $quizId)->where('is_active', true)->first();
        abort_unless($quiz, 404, 'Kuis tidak ditemukan');
        $this->studentLesson($c, $quiz->lesson_id);
        $d = $r->validate(['answers' => 'required|array|min:1|max:50', 'answers.*.questionId' => 'required|string|max:191', 'answers.*.optionId' => 'required|string|max:191']);
        $questions = $this->questions($quiz->id);
        $given = collect($d['answers'])->keyBy('questionId');
        abort_unless($given->count() === count($d['answers']) && $given->keys()->sort()->values()->all() === $questions->pluck('q.id')->sort()->values()->all(), 422, 'Jawab semua pertanyaan tepat satu kali');
        foreach ($questions as $x) abort_unless($x->options->contains('id', $given[$x->q->id]['optionId']), 422, 'Pilihan tidak valid');
        $progress = app(LessonProgress::class);
        return DB::transaction(function () use ($c, $quiz, $questions, $given, $progress) {
            DB::table('students')->where('id', $c['student']->id)->lockForUpdate()->first();
            $previous = DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->where('student_id', $c['student']->id)->get();
            abort_if($previous->contains('passed', true), 409, 'Kuis sudah diselesaikan');
            abort_if($previous->count() >= $quiz->max_attempts, 409, 'Kesempatan mengerjakan sudah habis');
            $results = $questions->map(fn ($x) => [$x, $x->options->firstWhere('id', $given[$x->q->id]['optionId'])]);
            $score = (int) round($results->filter(fn ($p) => $p[1]->is_correct)->count() / $questions->count() * 100);
            $passed = $quiz->pass_percent === null || $score >= $quiz->pass_percent;
            $attemptId = (string) Str::ulid();
            DB::table('quiz_attempts')->insert(['id' => $attemptId, 'quiz_id' => $quiz->id, 'student_id' => $c['student']->id, 'attempt_no' => $previous->count() + 1, 'score_percent' => $score, 'passed' => $passed, 'submitted_at' => now()]);
            foreach ($results as [$x, $option]) DB::table('quiz_answers')->insert(['id' => (string) Str::ulid(), 'attempt_id' => $attemptId, 'question_id' => $x->q->id, 'option_id' => $option->id, 'is_correct' => (bool) $option->is_correct]);
            $progress->rollup($c['student']->id, $c['user'], $quiz->lesson_id);
            $finished = $passed || $previous->count() + 1 >= $quiz->max_attempts;
            return response()->json(['attemptNo' => $previous->count() + 1, 'scorePercent' => $score, 'passed' => $passed, 'attemptsLeft' => max(0, $quiz->max_attempts - $previous->count() - 1),
                'results' => $results->map(fn ($p) => ['questionId' => $p[0]->q->id, 'correct' => (bool) $p[1]->is_correct] + ($finished ? ['correctOptionId' => $p[0]->options->firstWhere('is_correct', true)?->id, 'explanation' => $p[0]->q->explanation] : []))->values()->all(),
                'snapshot' => $progress->snapshot($c['student']->id, $quiz->lesson_id)], 201);
        });
    }
}
