<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->longText('description')->nullable();
            $table->unsignedInteger('max_score')->nullable();
            $table->string('rubric_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('checklist_template_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->unsignedInteger('target_views')->nullable();
            $table->unsignedInteger('target_clicks')->nullable();
            $table->unsignedInteger('target_orders')->nullable();
            $table->decimal('target_gmv', 18, 2)->nullable();
            $table->unsignedInteger('version')->nullable();
        });
        Schema::create('assignment_versions', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('assignment_id', 191)->collation('utf8mb4_bin');
            $table->unsignedInteger('version');
            $table->longText('snapshot_data');
            $table->string('changed_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('created_at', 3);
            $table->foreign('assignment_id')->references('id')->on('assignments')->restrictOnDelete();
            $table->unique(['assignment_id', 'version']);
        });
        Schema::table('submissions', function (Blueprint $table): void {
            $table->string('student_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('reviewed_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('locked_at', 3)->nullable();
            $table->dateTime('grade_published_at', 3)->nullable();
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('teachers')->restrictOnDelete();
        });
        Schema::table('submission_versions', function (Blueprint $table): void {
            $table->longText('content')->nullable();
            $table->text('file_url')->nullable();
            $table->text('video_url')->nullable();
            $table->longText('notes')->nullable();
            $table->longText('snapshot_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submission_versions', fn (Blueprint $table) => $table->dropColumn(['content', 'file_url', 'video_url', 'notes', 'snapshot_data']));
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropForeign(['student_id']);
            $table->dropForeign(['reviewed_by_id']);
            $table->dropColumn(['student_id', 'reviewed_by_id', 'locked_at', 'grade_published_at']);
        });
        Schema::dropIfExists('assignment_versions');
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn(['description', 'max_score', 'rubric_id', 'checklist_template_id', 'target_views', 'target_clicks', 'target_orders', 'target_gmv', 'version']));
    }
};
