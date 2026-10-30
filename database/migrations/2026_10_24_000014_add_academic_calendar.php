<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('name');
            $table->dateTime('start_date', 3);
            $table->dateTime('end_date', 3);
            $table->boolean('is_active');
            $table->timestamps(3);
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('academic_year_id', 191)->collation('utf8mb4_bin');
            $table->string('name');
            $table->dateTime('start_date', 3);
            $table->dateTime('end_date', 3);
            $table->boolean('is_active');
            $table->timestamps(3);
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();
        });
        Schema::table('class_enrollments', function (Blueprint $table): void {
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->nullOnDelete();
            $table->foreign('semester_id')->references('id')->on('semesters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table): void {
            $table->dropForeign(['academic_year_id']);
            $table->dropForeign(['semester_id']);
        });
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_years');
    }
};
