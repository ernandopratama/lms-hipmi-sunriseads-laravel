<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('affiliate_accounts')) Schema::create('affiliate_accounts', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('student_id', 191)->collation('utf8mb4_bin');
            $table->string('platform');
            $table->string('username');
            $table->string('display_name')->nullable();
            $table->string('profile_url', 2048);
            $table->string('external_account_id')->nullable();
            $table->string('status', 50)->default('PENDING_VERIFICATION');
            $table->dateTime('connected_at', 3);
            $table->text('reviewer_notes')->nullable();
            $table->string('reviewed_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('reviewed_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('reviewed_by_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['student_id', 'platform', 'username'], 'affiliate_account_identity');
            $table->index(['school_id', 'status']);
        });
        if (! Schema::hasTable('affiliate_videos')) Schema::create('affiliate_videos', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('submission_id', 191)->collation('utf8mb4_bin');
            $table->string('student_id', 191)->collation('utf8mb4_bin');
            $table->string('affiliate_account_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('platform')->default('TIKTOK');
            $table->string('video_url', 2048);
            $table->string('normalized_url', 2048)->nullable();
            $table->string('external_id')->nullable();
            $table->dateTime('published_at', 3);
            $table->text('caption')->nullable();
            $table->string('product_name')->nullable();
            $table->string('data_source')->default('MANUAL');
            $table->string('verification', 32)->default('UNVERIFIED');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps(3);
            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('affiliate_account_id')->references('id')->on('affiliate_accounts')->nullOnDelete();
            $table->index('student_id');
            $table->index('affiliate_account_id');
            $table->index('platform');
        });
        if (! Schema::hasTable('affiliate_metric_snapshots')) Schema::create('affiliate_metric_snapshots', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('student_id', 191)->collation('utf8mb4_bin');
            $table->string('affiliate_video_id', 191)->collation('utf8mb4_bin');
            $table->string('affiliate_account_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('platform');
            foreach (['views', 'likes', 'comments', 'shares', 'clicks', 'orders', 'units_sold', 'refunds'] as $name) $table->unsignedBigInteger($name)->default(0);
            $table->decimal('ctr', 7, 2)->default(0);
            $table->decimal('gmv', 18, 2)->default(0);
            $table->decimal('conversion_rate', 7, 2)->default(0);
            $table->decimal('average_order_value', 18, 2)->default(0);
            $table->decimal('commission', 18, 2)->default(0);
            $table->decimal('commission_rate', 7, 2)->default(0);
            $table->string('currency')->default('IDR');
            $table->dateTime('snapshot_date', 3);
            $table->string('source')->default('MANUAL');
            $table->json('raw_values')->nullable();
            $table->json('data_quality_flags')->nullable();
            $table->string('verification', 32)->default('UNVERIFIED');
            $table->string('verified_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('verified_at', 3)->nullable();
            $table->string('supersedes_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('affiliate_video_id')->references('id')->on('affiliate_videos')->cascadeOnDelete();
            $table->foreign('affiliate_account_id')->references('id')->on('affiliate_accounts')->nullOnDelete();
            $table->foreign('verified_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('supersedes_id')->references('id')->on('affiliate_metric_snapshots')->nullOnDelete();
            $table->index(['school_id', 'verification', 'snapshot_date'], 'affiliate_metric_scope');
            $table->index(['student_id', 'snapshot_date']);
            $table->index(['affiliate_video_id', 'snapshot_date'], 'affiliate_video_snapshot_date');
        });
        // MySQL commits DDL before a later index statement can fail. Repair the
        // sole trailing index when resuming that interrupted migration.
        if (! Schema::hasIndex('affiliate_metric_snapshots', ['affiliate_video_id', 'snapshot_date'])) {
            Schema::table('affiliate_metric_snapshots', fn (Blueprint $table) => $table->index(['affiliate_video_id', 'snapshot_date'], 'affiliate_video_snapshot_date'));
        }
    }

    public function down(): void
    {
        foreach (['affiliate_metric_snapshots', 'affiliate_videos', 'affiliate_accounts'] as $name) Schema::dropIfExists($name);
    }
};
