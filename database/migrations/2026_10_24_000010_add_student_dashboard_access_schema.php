<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('course_modules', function (Blueprint $table): void {
            $table->boolean('is_published')->default(false);
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('program_id', 191)->nullable()->collation('utf8mb4_bin');
        });
        Schema::table('assignments', function (Blueprint $table): void {
            $table->string('type', 32)->nullable();
        });
        Schema::create('access_plans', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('rank');
            $table->unsignedInteger('module_limit');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestamps(3);
        });
        Schema::create('entitlement_definitions', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('key')->unique();
            $table->string('value_type', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
        });
        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('access_plan_id', 191)->collation('utf8mb4_bin');
            $table->string('entitlement_definition_id', 191)->collation('utf8mb4_bin');
            $table->boolean('enabled')->default(false);
            $table->json('value_json')->nullable();
            $table->dateTime('effective_from', 3);
            $table->dateTime('effective_to', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('access_plan_id')->references('id')->on('access_plans')->cascadeOnDelete();
            $table->foreign('entitlement_definition_id')->references('id')->on('entitlement_definitions')->cascadeOnDelete();
            $table->unique(['access_plan_id', 'entitlement_definition_id', 'effective_from'], 'plan_entitlement_period');
        });
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->collation('utf8mb4_bin');
            foreach (['school_id', 'program_id', 'class_id', 'user_id'] as $column) {
                $table->string($column, 191)->nullable()->collation('utf8mb4_bin');
            }
            $table->string('target_type', 32);
            $table->string('access_plan_id', 191)->collation('utf8mb4_bin');
            $table->string('source', 40);
            $table->string('status', 32)->default('ACTIVE');
            $table->dateTime('starts_at', 3);
            $table->dateTime('ends_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('access_plan_id')->references('id')->on('access_plans')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'starts_at', 'ends_at'], 'grant_effective_scope');
        });
        Schema::create('module_plan_access', function (Blueprint $table): void {
            $table->string('module_id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('minimum_plan_id', 191)->collation('utf8mb4_bin');
            $table->foreign('module_id')->references('id')->on('course_modules')->cascadeOnDelete();
            $table->foreign('minimum_plan_id')->references('id')->on('access_plans')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['module_plan_access', 'access_grants', 'plan_entitlements', 'entitlement_definitions', 'access_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn('type'));
        Schema::table('course_modules', fn (Blueprint $table) => $table->dropColumn('is_published'));
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('program_id'));
    }
};
