<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_fraud_flags', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('affiliate_video_id', 191)->collation('utf8mb4_bin');
            $t->string('metric_snapshot_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->string('type'); $t->longText('reason'); $t->longText('evidence')->nullable();
            $t->decimal('confidence', 5, 2)->nullable(); $t->string('status', 32);
            $t->string('resolved_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->longText('resolution_notes')->nullable(); $t->dateTime('resolved_at', 3)->nullable();
            $t->dateTime('created_at', 3);
            $t->foreign('affiliate_video_id')->references('id')->on('affiliate_videos')->restrictOnDelete();
            $t->foreign('metric_snapshot_id')->references('id')->on('affiliate_metric_snapshots')->restrictOnDelete();
            $t->foreign('resolved_by_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('affiliate_metric_verifications', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('metric_snapshot_id', 191)->collation('utf8mb4_bin');
            $t->string('reviewer_id', 191)->collation('utf8mb4_bin');
            $t->string('decision', 32); $t->longText('notes')->nullable(); $t->dateTime('created_at', 3);
            $t->foreign('metric_snapshot_id')->references('id')->on('affiliate_metric_snapshots')->restrictOnDelete();
            $t->foreign('reviewer_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_metric_verifications');
        Schema::dropIfExists('affiliate_fraud_flags');
    }
};
