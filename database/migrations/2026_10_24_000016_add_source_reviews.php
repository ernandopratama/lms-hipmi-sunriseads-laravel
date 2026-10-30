<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rubrics', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('organization_id', 191)->collation('utf8mb4_bin');
            $t->string('name');
            $t->text('description')->nullable();
            $t->timestamps(3);
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });
        Schema::create('rubric_criteria', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('rubric_id', 191)->collation('utf8mb4_bin');
            $t->string('name');
            $t->text('description')->nullable();
            $t->integer('weight');
            $t->integer('max_score');
            $t->integer('position');
            $t->longText('levels')->nullable();
            $t->foreign('rubric_id')->references('id')->on('rubrics')->restrictOnDelete();
        });
        Schema::create('submission_reviews', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('submission_id', 191)->collation('utf8mb4_bin');
            $t->string('reviewer_id', 191)->collation('utf8mb4_bin');
            $t->string('rubric_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->string('status', 32);
            $t->integer('total_score')->nullable();
            $t->longText('feedback')->nullable();
            $t->boolean('is_published');
            $t->dateTime('published_at', 3)->nullable();
            $t->dateTime('created_at', 3);
            $t->foreign('submission_id')->references('id')->on('submissions')->restrictOnDelete();
            $t->foreign('reviewer_id')->references('id')->on('teachers')->restrictOnDelete();
            $t->foreign('rubric_id')->references('id')->on('rubrics')->restrictOnDelete();
        });
        Schema::create('review_scores', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('review_id', 191)->collation('utf8mb4_bin');
            $t->string('criterion_id', 191)->collation('utf8mb4_bin');
            $t->integer('score');
            $t->text('comment')->nullable();
            $t->foreign('review_id')->references('id')->on('submission_reviews')->restrictOnDelete();
            $t->foreign('criterion_id')->references('id')->on('rubric_criteria')->restrictOnDelete();
            $t->unique(['review_id', 'criterion_id']);
        });
        Schema::create('ai_assessments', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('submission_id', 191)->collation('utf8mb4_bin');
            $t->string('rubric_id', 191)->collation('utf8mb4_bin');
            $t->integer('content_version');
            $t->string('model');
            $t->integer('total_score');
            $t->longText('feedback');
            $t->boolean('insufficient_evidence');
            $t->boolean('requires_teacher_review');
            $t->dateTime('created_at', 3);
            $t->foreign('submission_id')->references('id')->on('submissions')->restrictOnDelete();
            $t->foreign('rubric_id')->references('id')->on('rubrics')->restrictOnDelete();
        });
        Schema::create('ai_assessment_scores', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('ai_assessment_id', 191)->collation('utf8mb4_bin');
            $t->string('criterion_id', 191)->collation('utf8mb4_bin');
            $t->integer('score');
            $t->longText('reason');
            $t->foreign('ai_assessment_id')->references('id')->on('ai_assessments')->restrictOnDelete();
            $t->foreign('criterion_id')->references('id')->on('rubric_criteria')->restrictOnDelete();
            $t->unique(['ai_assessment_id', 'criterion_id']);
        });
    }

    public function down(): void
    {
        foreach (['ai_assessment_scores', 'ai_assessments', 'review_scores', 'submission_reviews', 'rubric_criteria', 'rubrics'] as $table) Schema::dropIfExists($table);
    }
};
