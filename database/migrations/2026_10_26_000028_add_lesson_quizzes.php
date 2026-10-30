<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function key(Blueprint $t, string $column, string $parent, bool $cascade = true): void
    {
        $t->string($column, 191)->collation('utf8mb4_bin');
        $foreign = $t->foreign($column)->references('id')->on($parent);
        $cascade ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('lesson_quizzes', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $this->key($t, 'lesson_id', 'lessons');
            $t->string('title');
            $t->unsignedTinyInteger('pass_percent')->nullable(); // null: submitting is enough
            $t->unsignedTinyInteger('max_attempts')->default(3);
            $t->boolean('is_required')->default(true);
            $t->boolean('is_active')->default(true);
            $t->string('created_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->timestamps(3);
            $t->unique('lesson_id');
        });
        Schema::create('quiz_questions', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $this->key($t, 'quiz_id', 'lesson_quizzes');
            $t->unsignedSmallInteger('position');
            $t->text('prompt');
            $t->text('explanation')->nullable();
            $t->unique(['quiz_id', 'position']);
        });
        Schema::create('quiz_options', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $this->key($t, 'question_id', 'quiz_questions');
            $t->unsignedSmallInteger('position');
            $t->string('label', 500);
            $t->boolean('is_correct')->default(false);
            $t->unique(['question_id', 'position']);
        });
        Schema::create('quiz_attempts', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $this->key($t, 'quiz_id', 'lesson_quizzes');
            $this->key($t, 'student_id', 'students');
            $t->unsignedTinyInteger('attempt_no');
            $t->unsignedTinyInteger('score_percent');
            $t->boolean('passed'); // counts toward lesson completion
            $t->dateTime('submitted_at', 3);
            $t->unique(['quiz_id', 'student_id', 'attempt_no']);
        });
        Schema::create('quiz_answers', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $this->key($t, 'attempt_id', 'quiz_attempts');
            $this->key($t, 'question_id', 'quiz_questions');
            $this->key($t, 'option_id', 'quiz_options');
            $t->boolean('is_correct');
            $t->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        foreach (['quiz_answers', 'quiz_attempts', 'quiz_options', 'quiz_questions', 'lesson_quizzes'] as $table) Schema::dropIfExists($table);
    }
};
