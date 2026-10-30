<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_account_reviews', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('account_id', 191)->collation('utf8mb4_bin');
            $t->string('reviewer_id', 191)->collation('utf8mb4_bin');
            $t->string('decision', 50);
            $t->text('notes')->nullable();
            $t->dateTime('created_at', 3);
            $t->foreign('account_id')->references('id')->on('affiliate_accounts')->restrictOnDelete();
            $t->foreign('reviewer_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('affiliate_videos', function (Blueprint $t): void {
            foreach (['hook', 'product_external_id', 'product_url', 'campaign_name', 'screenshot', 'evidence_url', 'insights_evidence_url', 'student_note'] as $name) $t->text($name)->nullable();
            $t->boolean('disclosure_accepted')->default(false);
            $t->boolean('ownership_declared')->default(false);
        });
        Schema::create('affiliate_content_versions', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('affiliate_video_id', 191)->collation('utf8mb4_bin');
            $t->unsignedInteger('version_number');
            $t->longText('snapshot_data');
            $t->text('student_note')->nullable();
            $t->dateTime('created_at', 3);
            $t->foreign('affiliate_video_id')->references('id')->on('affiliate_videos')->restrictOnDelete();
            $t->unique(['affiliate_video_id', 'version_number'], 'affiliate_content_version_unique');
        });
        Schema::table('affiliate_metric_snapshots', function (Blueprint $t): void {
            $t->string('import_job_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->string('created_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->dateTime('period_start', 3)->nullable();
            $t->dateTime('period_end', 3)->nullable();
            $t->text('source_reference')->nullable();
            $t->text('evidence_url')->nullable();
            $t->text('verification_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_metric_snapshots', fn (Blueprint $t) => $t->dropColumn(['import_job_id', 'created_by_id', 'period_start', 'period_end', 'source_reference', 'evidence_url', 'verification_notes']));
        Schema::dropIfExists('affiliate_content_versions');
        Schema::table('affiliate_videos', fn (Blueprint $t) => $t->dropColumn(['hook', 'product_external_id', 'product_url', 'campaign_name', 'screenshot', 'evidence_url', 'insights_evidence_url', 'student_note', 'disclosure_accepted', 'ownership_declared']));
        Schema::dropIfExists('affiliate_account_reviews');
    }
};
