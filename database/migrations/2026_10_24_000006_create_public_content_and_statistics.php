<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('city')->nullable();
            $table->string('province')->nullable();
        });
        Schema::create('cms_contents', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('slug')->unique();
            $table->string('type', 50);
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->json('body');
            $table->string('image_url', 2048)->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->integer('position')->default(0);
            $table->string('author_id', 191)->collation('utf8mb4_bin');
            $table->string('reviewed_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('published_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['type', 'status', 'position']);
            $table->index(['organization_id', 'status']);
        });
        Schema::create('public_statistics', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('title');
            $table->dateTime('period_start', 3);
            $table->dateTime('period_end', 3);
            $table->unsignedInteger('cohort_size');
            $table->unsignedInteger('minimum_cohort')->default(10);
            $table->json('metrics');
            $table->string('status', 32)->default('DRAFT');
            $table->string('created_by_id', 191)->collation('utf8mb4_bin');
            $table->string('reviewed_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->text('review_notes')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('published_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('created_by_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'school_id', 'status', 'period_end'], 'public_stats_scope_idx');
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_statistics');
        Schema::dropIfExists('cms_contents');
        Schema::table('schools', fn (Blueprint $table) => $table->dropColumn(['city', 'province']));
    }
};
