<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('store_checklist_templates', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'organization_id', 'organizations');
            $this->ref($t, 'school_id', 'schools');
            $t->string('name'); $t->string('platform', 32)->nullable(); $t->integer('minimum_products'); $t->boolean('is_active');
            $t->string('created_by_id', 191)->nullable()->collation('utf8mb4_bin'); $t->timestamps(3);
            $t->unique(['school_id', 'name']);
        });
        Schema::create('store_checklist_items', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'template_id', 'store_checklist_templates');
            $t->string('code'); $t->string('label'); $t->text('description')->nullable(); $t->string('evidence_type')->nullable();
            $t->integer('weight'); $t->boolean('is_required'); $t->integer('position');
            $t->unique(['template_id', 'code']); $t->unique(['template_id', 'position']);
        });
        Schema::create('student_stores', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'organization_id', 'organizations'); $this->ref($t, 'school_id', 'schools'); $this->ref($t, 'student_id', 'students');
            $t->string('platform', 32); $t->string('store_name'); $t->string('store_username')->nullable(); $t->string('store_external_id')->nullable();
            $t->text('store_url'); $t->text('normalized_store_url'); $t->string('category')->nullable(); $t->text('description')->nullable();
            $t->text('logo_storage_key')->nullable(); $t->text('banner_storage_key')->nullable(); $t->dateTime('created_date', 3)->nullable();
            $t->string('activation_status', 40); $t->integer('product_count'); $t->timestamps(3);
        });
        Schema::create('student_store_submissions', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'organization_id', 'organizations'); $this->ref($t, 'school_id', 'schools');
            $this->ref($t, 'assignment_id', 'assignments'); $this->ref($t, 'student_id', 'students');
            $this->ref($t, 'student_store_id', 'student_stores', true);
            $t->string('status', 40); $t->integer('current_version'); $t->integer('row_version'); $t->longText('draft_data')->nullable();
            $t->dateTime('submitted_at', 3)->nullable(); $t->dateTime('reviewed_at', 3)->nullable(); $t->dateTime('locked_at', 3)->nullable();
            $t->integer('score')->nullable(); $t->longText('feedback')->nullable(); $t->timestamps(3);
            $t->unique(['assignment_id', 'student_id']);
        });
        Schema::create('student_store_submission_versions', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'store_submission_id', 'student_store_submissions');
            $t->integer('version_number'); $t->longText('store_snapshot_data'); $t->longText('student_notes')->nullable();
            $t->longText('obstacles')->nullable(); $t->boolean('declaration_accepted'); $t->dateTime('created_at', 3);
            $t->unique(['store_submission_id', 'version_number'], 'store_submission_version_unique');
        });
        Schema::create('student_store_files', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'store_submission_id', 'student_store_submissions');
            $t->string('kind', 32); $t->text('storage_key'); $t->text('quarantine_key')->nullable();
            $t->string('original_file_name'); $t->string('mime_type'); $t->integer('size_bytes');
            $t->string('sha256', 64)->nullable(); $t->string('scan_status', 32); $t->text('caption')->nullable();
            $t->integer('position'); $t->dateTime('created_at', 3);
        });
        Schema::create('store_checklist_responses', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'store_submission_id', 'student_store_submissions'); $this->ref($t, 'item_id', 'store_checklist_items');
            $t->boolean('completed'); $t->text('value')->nullable(); $t->text('comment')->nullable(); $t->dateTime('updated_at', 3);
            $t->unique(['store_submission_id', 'item_id'], 'store_checklist_response_unique');
        });
        Schema::create('store_reviews', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'store_submission_id', 'student_store_submissions'); $this->ref($t, 'reviewer_id', 'teachers');
            $this->ref($t, 'rubric_id', 'rubrics', true); $t->string('decision', 40); $t->integer('total_score')->nullable();
            $t->longText('feedback')->nullable(); $t->boolean('is_published'); $t->dateTime('created_at', 3);
        });
        Schema::create('store_review_scores', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'review_id', 'store_reviews'); $this->ref($t, 'criterion_id', 'rubric_criteria');
            $t->integer('score'); $t->text('comment')->nullable(); $t->unique(['review_id', 'criterion_id']);
        });
        Schema::create('store_fraud_flags', function (Blueprint $t): void {
            $this->id($t); $this->ref($t, 'store_submission_id', 'student_store_submissions');
            $t->string('type', 50); $t->longText('reason'); $t->longText('evidence')->nullable(); $t->decimal('confidence', 5, 2)->nullable();
            $t->string('status', 32); $t->string('resolved_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->longText('resolution_notes')->nullable(); $t->dateTime('resolved_at', 3)->nullable(); $t->dateTime('created_at', 3);
        });
    }

    private function id(Blueprint $t): void
    {
        $t->string('id', 191)->collation('utf8mb4_bin')->primary();
    }

    private function ref(Blueprint $t, string $field, string $parent, bool $nullable = false): void
    {
        $t->string($field, 191)->nullable($nullable)->collation('utf8mb4_bin');
        $t->foreign($field)->references('id')->on($parent)->restrictOnDelete();
    }

    public function down(): void
    {
        foreach (['store_fraud_flags', 'store_review_scores', 'store_reviews', 'store_checklist_responses', 'student_store_files', 'student_store_submission_versions', 'student_store_submissions', 'student_stores', 'store_checklist_items', 'store_checklist_templates'] as $table) Schema::dropIfExists($table);
    }
};
