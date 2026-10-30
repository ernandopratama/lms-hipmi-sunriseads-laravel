<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('organization_id', 191)->collation('utf8mb4_bin');
            $t->string('school_id', 191)->collation('utf8mb4_bin');
            $t->string('created_by_id', 191)->collation('utf8mb4_bin');
            $t->string('type');
            $t->text('source_name');
            $t->string('status', 32);
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('failed_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->text('failure_reason')->nullable();
            $t->dateTime('started_at', 3)->nullable();
            $t->dateTime('completed_at', 3)->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3);
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $t->foreign('created_by_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['school_id', 'status', 'created_at']);
        });
        Schema::create('import_rows', function (Blueprint $t): void {
            $t->string('id', 191)->collation('utf8mb4_bin')->primary();
            $t->string('import_job_id', 191)->collation('utf8mb4_bin');
            $t->unsignedInteger('row_number');
            $t->string('status', 32);
            $t->longText('payload');
            $t->longText('errors')->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3);
            $t->foreign('import_job_id')->references('id')->on('import_jobs')->restrictOnDelete();
            $t->unique(['import_job_id', 'row_number']);
            $t->index(['import_job_id', 'status']);
        });
        Schema::table('affiliate_metric_snapshots', function (Blueprint $t): void {
            $t->index('import_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_metric_snapshots', fn (Blueprint $t) => $t->dropIndex(['import_job_id']));
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
