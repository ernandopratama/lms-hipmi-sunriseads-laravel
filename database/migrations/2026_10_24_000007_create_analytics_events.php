<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('school_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('user_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('anonymous_id', 100)->nullable();
            $table->string('event_name', 50);
            $table->json('properties')->nullable();
            $table->boolean('consent_verified')->default(false);
            $table->dateTime('occurred_at', 3)->useCurrent();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['event_name', 'occurred_at']);
            $table->index(['organization_id', 'school_id', 'occurred_at'], 'analytics_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
