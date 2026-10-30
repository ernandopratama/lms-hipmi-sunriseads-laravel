<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('code')->nullable()->unique();
            $table->longText('description')->nullable();
            $table->text('logo')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('npsn')->nullable()->unique();
            $table->string('code')->nullable();
            $table->string('level')->nullable();
            foreach (['address', 'district', 'principal', 'pic_name', 'pic_phone', 'pic_email'] as $field) {
                $table->string($field)->nullable();
            }
            $table->text('logo')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedInteger('onboarding_step')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('joined_at', 3)->nullable();
            $table->unique(['organization_id', 'code']);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->unique(['school_id', 'code']);
        });
        Schema::table('course_modules', function (Blueprint $table): void {
            $table->longText('description')->nullable();
            $table->string('created_by_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->unique(['course_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table): void {
            $table->dropUnique(['course_id', 'position']);
            $table->dropColumn(['description', 'created_by_id']);
        });
        Schema::dropIfExists('departments');
        Schema::table('schools', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'code']);
            $table->dropUnique(['npsn']);
            $table->dropColumn(['npsn', 'code', 'level', 'address', 'district', 'principal', 'pic_name', 'pic_phone', 'pic_email', 'logo', 'status', 'onboarding_step', 'is_active', 'joined_at']);
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'description', 'logo', 'is_active']);
        });
    }
};
