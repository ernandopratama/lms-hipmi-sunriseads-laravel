<?php

namespace App\Console\Commands;

use App\Support\LessonProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns imported lesson-level reading progress into per-material rows. Only lessons with a
 * single slide deck are unambiguous; lessons with several decks are listed and left alone.
 */
class BackfillMaterialProgress extends Command
{
    protected $signature = 'sunrise:backfill-material-progress {--apply : Write the rows; without it nothing changes}';
    protected $description = 'Create material_progress rows from existing lesson_progress and refresh the roll-up';

    public function handle(LessonProgress $progress): int
    {
        $created = $skipped = $ambiguous = 0;
        $rows = DB::table('lesson_progress')->where('progress_percent', '>', 0)->get();
        foreach ($rows as $p) {
            $decks = $progress->tracked($p->lesson_id);
            if ($decks->isEmpty()) continue;
            if ($decks->count() > 1) { $ambiguous++; $this->warn("Lesson {$p->lesson_id} has {$decks->count()} decks; skipped."); continue; }
            if (DB::table('material_progress')->where('student_id', $p->student_id)->where('lesson_id', $p->lesson_id)->exists()) { $skipped++; continue; }
            $deck = $decks->first();
            $position = $p->last_position ?: (int) ceil((int) $p->progress_percent / (100 / $deck->preview_slide_count));
            $created++;
            if (! $this->option('apply')) continue;
            DB::table('material_progress')->insert(['id' => (string) Str::ulid(), 'material_id' => $deck->id, 'lesson_id' => $p->lesson_id, 'student_id' => $p->student_id,
                'kind' => 'READ', 'state' => $p->state, 'percent' => (int) $p->progress_percent, 'position' => min($position, (int) $deck->preview_slide_count),
                'started_at' => $p->started_at, 'completed_at' => $p->completed_at, 'created_at' => now(), 'updated_at' => now()]);
            $progress->rollup($p->student_id, (string) $p->user_id, $p->lesson_id);
        }
        $this->info(($this->option('apply') ? 'Created' : 'Would create')." {$created} rows; already present {$skipped}; ambiguous {$ambiguous}.");
        if (! $this->option('apply')) $this->line('Re-run with --apply to write.');
        return self::SUCCESS;
    }
}
