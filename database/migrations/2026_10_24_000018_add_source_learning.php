<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $t): void {
            $t->string('module_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->text('summary')->nullable();
            $t->integer('estimated_minutes')->nullable();
            $t->boolean('is_published')->default(false);
            $t->string('created_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->foreign('module_id')->references('id')->on('course_modules')->restrictOnDelete();
            $t->unique(['module_id', 'position'], 'lesson_source_position');
        });
        Schema::table('lesson_progress', function (Blueprint $t): void {
            $t->string('student_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->string('state', 32)->nullable();
            $t->unsignedInteger('progress_percent')->nullable();
            $t->integer('last_position')->nullable();
            $t->dateTime('started_at', 3)->nullable();
            $t->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $t->unique(['student_id', 'lesson_id'], 'lesson_student_source_unique');
        });
        Schema::create('materials', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('course_id', 191)->collation('utf8mb4_bin');
            $t->string('teacher_id', 191)->collation('utf8mb4_bin');
            $t->string('lesson_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->string('title');
            $t->longText('content')->nullable();
            $t->text('file_url')->nullable();
            $t->text('storage_key')->nullable();
            $t->string('original_file_name')->nullable();
            $t->string('mime_type')->nullable();
            $t->integer('preview_slide_count')->nullable();
            $t->string('type', 32);
            $t->boolean('is_active');
            $t->timestamps(3);
            $t->foreign('course_id')->references('id')->on('courses')->restrictOnDelete();
            $t->foreign('teacher_id')->references('id')->on('teachers')->restrictOnDelete();
            $t->foreign('lesson_id')->references('id')->on('lessons')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
        Schema::table('lesson_progress', function (Blueprint $t): void {
            $t->dropForeign(['student_id']);
            $t->dropUnique('lesson_student_source_unique');
            $t->dropColumn(['student_id', 'state', 'progress_percent', 'last_position', 'started_at']);
        });
        Schema::table('lessons', function (Blueprint $t): void {
            $t->dropForeign(['module_id']);
            $t->dropUnique('lesson_source_position');
            $t->dropColumn(['module_id', 'summary', 'estimated_minutes', 'is_published', 'created_by_id']);
        });
    }
};
