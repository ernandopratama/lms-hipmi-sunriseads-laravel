<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-material progress and the lesson roll-up the dashboards already read.
 * Components: reading (slide decks), video (YouTube/Vimeo) and quiz (Q&A). The overall
 * percent averages the components a lesson has, never goes down, and only reaches COMPLETED
 * when every required component is done.
 */
class LessonProgress
{
    public const SEGMENT_SECONDS = 5;
    private const MAX_GAP_SECONDS = 30;

    /** Required, active slide decks of a lesson: the reading components. */
    public function tracked(string $lessonId): Collection
    {
        return DB::table('materials')->where('lesson_id', $lessonId)->where('is_active', true)->where('is_required', true)
            ->where('preview_slide_count', '>', 0)->orderBy('created_at')->orderBy('id')->get(['id', 'title', 'preview_slide_count']);
    }

    /** Required, active videos with a recognised YouTube/Vimeo link: the video components. */
    public function videos(string $lessonId): Collection
    {
        return DB::table('materials')->where('lesson_id', $lessonId)->where('is_active', true)->where('is_required', true)->where('type', 'VIDEO')
            ->orderBy('created_at')->orderBy('id')->get(['id', 'title', 'file_url', 'duration_seconds', 'completion_threshold'])
            ->map(function ($m) { $m->source = VideoSource::parse($m->file_url); return $m; })->filter(fn ($m) => $m->source)->values();
    }

    /** The lesson's required, active quiz with at least one question: the Q&A component. */
    public function quiz(string $lessonId): ?object
    {
        $quiz = DB::table('lesson_quizzes')->where('lesson_id', $lessonId)->where('is_active', true)->where('is_required', true)->first();
        return $quiz && DB::table('quiz_questions')->where('quiz_id', $quiz->id)->exists() ? $quiz : null;
    }

    public function hasComponents(string $lessonId): bool
    {
        return $this->tracked($lessonId)->isNotEmpty() || $this->videos($lessonId)->isNotEmpty() || $this->quiz($lessonId) !== null;
    }

    /**
     * Stored rows, or a virtual one built from the legacy lesson-level row when the lesson has
     * exactly one deck and nothing was recorded per material yet.
     */
    private function rows(string $studentId, string $lessonId, Collection $tracked): Collection
    {
        $rows = DB::table('material_progress')->where('student_id', $studentId)->where('lesson_id', $lessonId)->get()->keyBy('material_id');
        $legacy = DB::table('lesson_progress')->where('student_id', $studentId)->where('lesson_id', $lessonId)->first();
        // Only rows never rolled up (reading_percent still null) can hold legacy reading progress.
        if ($rows->where('kind', 'READ')->isEmpty() && $legacy && $legacy->reading_percent === null && $tracked->count() === 1 && (int) $legacy->progress_percent > 0) {
            $m = $tracked->first();
            $rows[$m->id] = (object) ['material_id' => $m->id, 'kind' => 'READ', 'state' => $legacy->state, 'percent' => (int) $legacy->progress_percent, 'started_at' => $legacy->started_at, 'completed_at' => $legacy->completed_at,
                'position' => $legacy->last_position ?: (int) floor((int) $legacy->progress_percent / (100 / $m->preview_slide_count))];
        }
        return $rows;
    }

    private function iso(?string $v): ?string
    {
        return $v === null ? null : CarbonImmutable::parse($v, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    private function aggregate(Collection $items): array
    {
        return ['percent' => (int) round($items->avg('percent')),
            'state' => $items->every(fn ($i) => $i['state'] === 'COMPLETED') ? 'COMPLETED' : ($items->contains(fn ($i) => $i['percent'] > 0) ? 'IN_PROGRESS' : 'NOT_STARTED'),
            'materials' => $items->all()];
    }

    /** Component breakdown for the progress bar; a component is null when the lesson lacks it. */
    public function components(string $studentId, string $lessonId): array
    {
        $decks = $this->tracked($lessonId);
        $videos = $this->videos($lessonId);
        $rows = ($decks->isNotEmpty() || $videos->isNotEmpty()) ? $this->rows($studentId, $lessonId, $decks) : collect();
        $reading = $decks->isEmpty() ? null : $this->aggregate($decks->map(function ($m) use ($rows) {
            $r = $rows[$m->id] ?? null;
            return ['materialId' => $m->id, 'title' => $m->title, 'slideCount' => (int) $m->preview_slide_count, 'position' => (int) ($r->position ?? 0),
                'percent' => (int) ($r->percent ?? 0), 'state' => $r->state ?? 'NOT_STARTED'];
        })->values());
        $video = $videos->isEmpty() ? null : $this->aggregate($videos->map(function ($m) use ($rows) {
            $r = $rows[$m->id] ?? null;
            $duration = (int) ($m->duration_seconds ?: ($r->duration_seconds ?? 0));
            return ['materialId' => $m->id, 'title' => $m->title, 'provider' => $m->source['provider'], 'durationSeconds' => $duration ?: null, 'position' => (int) ($r->position ?? 0),
                'coveredPercent' => $r ? $this->coveredPercent($r->covered_segments ?? null) : 0, 'threshold' => (int) $m->completion_threshold,
                'percent' => (int) ($r->percent ?? 0), 'state' => $r->state ?? 'NOT_STARTED'];
        })->values());
        $quiz = $this->quizComponent($studentId, $this->quiz($lessonId));
        return ['reading' => $reading, 'video' => $video, 'quiz' => $quiz];
    }

    private function quizComponent(string $studentId, ?object $quiz): ?array
    {
        if (! $quiz) return null;
        $attempts = DB::table('quiz_attempts')->where('quiz_id', $quiz->id)->where('student_id', $studentId)->get(['score_percent', 'passed']);
        $best = (int) $attempts->max('score_percent');
        $done = $attempts->contains('passed', true);
        return ['quizId' => $quiz->id, 'title' => $quiz->title, 'attempts' => $attempts->count(), 'maxAttempts' => (int) $quiz->max_attempts, 'bestScore' => $attempts->isEmpty() ? null : $best,
            'passPercent' => $quiz->pass_percent === null ? null : (int) $quiz->pass_percent, 'percent' => $done ? 100 : min(99, $best), 'state' => $done ? 'COMPLETED' : ($attempts->isNotEmpty() ? 'IN_PROGRESS' : 'NOT_STARTED')];
    }

    private function coveredPercent(?string $bits): int
    {
        return $bits ? (int) round(substr_count($bits, '1') / strlen($bits) * 100) : 0;
    }

    /** Legacy shape the UI already consumes, plus the component breakdown. */
    public function snapshot(string $studentId, string $lessonId): array
    {
        $p = DB::table('lesson_progress')->where('student_id', $studentId)->where('lesson_id', $lessonId)->first();
        return ['state' => $p->state ?? 'NOT_STARTED', 'progressPercent' => (int) ($p->progress_percent ?? 0), 'lastPosition' => $p->last_position ?? null,
            'startedAt' => $this->iso($p->started_at ?? null), 'completedAt' => $this->iso($p->completed_at ?? null), 'components' => $this->components($studentId, $lessonId)];
    }

    private function upsertRow(string $studentId, string $lessonId, object $material, ?object $row, array $values): void
    {
        $q = DB::table('material_progress')->where('material_id', $material->id)->where('student_id', $studentId);
        $row && $q->exists() ? $q->update($values) : DB::table('material_progress')->insert($values + ['id' => (string) Str::ulid(), 'material_id' => $material->id,
            'lesson_id' => $lessonId, 'student_id' => $studentId, 'created_at' => $values['updated_at']]);
    }

    /** Records that the student reached $position in a deck; enforces sequential viewing. */
    public function recordRead(string $studentId, string $userId, string $lessonId, object $material, int $position): void
    {
        $slides = (int) $material->preview_slide_count;
        $row = $this->rows($studentId, $lessonId, $this->tracked($lessonId))[$material->id] ?? null;
        $previous = (int) ($row->position ?? 0);
        abort_if($position < 1 || $position > $slides || (($row->state ?? null) !== 'COMPLETED' && $position > $previous + 1), 422, 'Buka materi secara berurutan');
        $percent = max((int) ($row->percent ?? 0), (int) round($position / $slides * 100));
        $furthest = max($previous, $position);
        $state = $percent >= 100 ? 'COMPLETED' : 'IN_PROGRESS';
        $now = now();
        $this->upsertRow($studentId, $lessonId, $material, $row, ['kind' => 'READ', 'state' => $state, 'percent' => $percent, 'position' => $furthest, 'started_at' => $row->started_at ?? $now,
            'completed_at' => $state === 'COMPLETED' ? ($row->completed_at ?? $now) : null, 'updated_at' => $now]);
        $this->rollup($studentId, $userId, $lessonId, $furthest);
    }

    /**
     * One heartbeat from the player. Only wall-clock-plausible forward playback earns coverage:
     * seeking ahead, pausing, long gaps and rapid-fire requests credit nothing. Coverage is a
     * bitmap of 5-second segments, so replaying the same part never counts twice.
     */
    public function recordVideo(string $studentId, string $userId, string $lessonId, object $material, float $position, ?int $reportedDuration, bool $playing): void
    {
        $stored = DB::table('material_progress')->where('material_id', $material->id)->where('student_id', $studentId)->first();
        $duration = (int) ($material->duration_seconds ?: ($stored->duration_seconds ?? 0));
        if (! $duration) {
            abort_unless($reportedDuration, 422, 'Durasi video wajib dikirim pada detak pertama');
            $duration = $reportedDuration;
        }
        $now = CarbonImmutable::now('UTC');
        $position = max(0.0, min($position, (float) $duration));
        $segments = (int) ceil($duration / self::SEGMENT_SECONDS);
        $bits = ($stored && strlen((string) $stored->covered_segments) === $segments) ? $stored->covered_segments : str_repeat('0', $segments);
        $lastBeat = $stored?->last_heartbeat_at ? CarbonImmutable::parse($stored->last_heartbeat_at, 'UTC') : null;
        $elapsed = $lastBeat ? $lastBeat->diffInMilliseconds($now, true) / 1000 : null;

        if ($elapsed !== null && $elapsed < 1) return; // Too soon: neither credit nor move the reference point.
        if ($playing && $elapsed !== null && $elapsed <= self::MAX_GAP_SECONDS) {
            $from = (float) ($stored->position ?? 0);
            $delta = $position - $from;
            if ($delta > 0 && $delta <= $elapsed * 2.1 + 0.5) {
                $first = (int) floor($from / self::SEGMENT_SECONDS);
                $last = min($segments - 1, (int) floor($position / self::SEGMENT_SECONDS));
                for ($i = $first; $i <= $last; $i++) $bits[$i] = '1';
            }
        }
        $covered = substr_count($bits, '1') / $segments * 100;
        $threshold = max(1, (int) $material->completion_threshold);
        $done = ($stored->state ?? null) === 'COMPLETED' || $covered >= $threshold;
        $percent = $done ? 100 : max((int) ($stored->percent ?? 0), (int) min(99, round($covered / $threshold * 100)));
        $this->upsertRow($studentId, $lessonId, $material, $stored, ['kind' => 'VIDEO', 'state' => $done ? 'COMPLETED' : ($percent > 0 ? 'IN_PROGRESS' : 'NOT_STARTED'), 'percent' => $percent,
            'position' => (int) floor($position), 'duration_seconds' => $duration, 'covered_segments' => $bits, 'last_heartbeat_at' => $now,
            'started_at' => $stored->started_at ?? ($percent > 0 ? $now : null), 'completed_at' => $done ? ($stored->completed_at ?? $now) : null, 'updated_at' => $now]);
        $this->rollup($studentId, $userId, $lessonId);
    }

    /** Recomputes lesson_progress from its components. */
    public function rollup(string $studentId, string $userId, string $lessonId, ?int $lastPosition = null): void
    {
        $components = $this->components($studentId, $lessonId);
        $existing = DB::table('lesson_progress')->where('student_id', $studentId)->where('lesson_id', $lessonId)->first();
        $present = collect($components)->filter()->values();
        if ($present->isEmpty()) return;
        $percent = max((int) ($existing->progress_percent ?? 0), (int) round($present->avg('percent')));
        $completed = $present->every(fn ($c) => $c['state'] === 'COMPLETED');
        $state = ($existing?->state === 'COMPLETED' || $completed) ? 'COMPLETED' : ($percent > 0 ? 'IN_PROGRESS' : 'NOT_STARTED');
        $now = now();
        $row = ['state' => $state, 'status' => $state, 'progress_percent' => $state === 'COMPLETED' ? 100 : $percent,
            'last_position' => max((int) ($existing->last_position ?? 0), (int) $lastPosition) ?: null,
            'reading_percent' => $components['reading']['percent'] ?? null, 'video_percent' => $components['video']['percent'] ?? null, 'quiz_percent' => $components['quiz']['percent'] ?? null,
            'started_at' => $existing?->started_at ?? ($state !== 'NOT_STARTED' ? $now : null), 'completed_at' => $state === 'COMPLETED' ? ($existing?->completed_at ?? $now) : null, 'updated_at' => $now];
        $q = DB::table('lesson_progress')->where('student_id', $studentId)->where('lesson_id', $lessonId);
        $existing ? $q->update($row) : DB::table('lesson_progress')->insert($row + ['id' => (string) Str::ulid(), 'lesson_id' => $lessonId, 'student_id' => $studentId, 'user_id' => $userId, 'created_at' => $now]);
    }
}
