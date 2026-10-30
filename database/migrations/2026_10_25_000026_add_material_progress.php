<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('material_progress', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            foreach (['material_id' => 'materials', 'lesson_id' => 'lessons', 'student_id' => 'students'] as $column => $parent) {
                $t->string($column, 191)->collation('utf8mb4_bin');
                $t->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            }
            $t->string('kind', 16)->default('READ');
            $t->string('state', 16)->default('NOT_STARTED');
            $t->unsignedTinyInteger('percent')->default(0);
            $t->unsignedInteger('position')->nullable();
            $t->dateTime('started_at', 3)->nullable();
            $t->dateTime('completed_at', 3)->nullable();
            $t->timestamps(3);
            $t->unique(['material_id', 'student_id']);
            $t->index(['student_id', 'lesson_id']);
        });
        Schema::table('lesson_progress', function (Blueprint $t): void {
            // Null means the lesson has no such component; the overall percent averages the others.
            foreach (['reading_percent', 'video_percent', 'quiz_percent'] as $column) $t->unsignedTinyInteger($column)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', fn (Blueprint $t) => $t->dropColumn(['reading_percent', 'video_percent', 'quiz_percent']));
        Schema::dropIfExists('material_progress');
    }
};
