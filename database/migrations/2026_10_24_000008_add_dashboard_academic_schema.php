<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing prototype rows keep their fields; source identifiers can be
        // imported verbatim. Required source columns stay nullable until ETL.
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('school_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('department_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('name');
            $table->string('code');
            $table->integer('grade')->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('students', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('user_id', 191)->collation('utf8mb4_bin')->unique();
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('class_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('student_number');
            $table->string('nisn')->nullable()->unique();
            $table->string('full_name');
            $table->string('status', 32)->default('DRAFT');
            $table->dateTime('activated_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->nullOnDelete();
            $table->unique(['school_id', 'student_number']);
            $table->index('class_id');
        });
        Schema::create('teachers', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('user_id', 191)->collation('utf8mb4_bin')->unique();
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('nip')->nullable();
            $table->string('full_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index('school_id');
        });
        Schema::create('class_enrollments', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('student_id', 191)->collation('utf8mb4_bin');
            $table->string('class_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('academic_year_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('semester_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('status', 32)->default('ACTIVE');
            $table->dateTime('effective_from', 3);
            $table->dateTime('effective_until', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->index(['school_id', 'status']);
            $table->index(['student_id', 'effective_from']);
            $table->index(['class_id', 'status']);
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('course_id', 191)->collation('utf8mb4_bin');
            $table->string('student_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('status', 32)->default('ACTIVE');
            $table->dateTime('enrolled_at', 3);
            $table->dateTime('completed_at', 3)->nullable();
            $table->timestamps(3);
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->unique(['course_id', 'student_id']);
            $table->index(['school_id', 'status']);
            $table->index(['student_id', 'status']);
        });
        Schema::create('teacher_assignments', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('teacher_id', 191)->collation('utf8mb4_bin');
            $table->string('class_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('course_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('subject')->nullable();
            $table->dateTime('effective_from', 3);
            $table->dateTime('effective_until', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->index(['school_id', 'is_active']);
            $table->index(['teacher_id', 'class_id']);
        });
        Schema::table('assignments', function (Blueprint $table): void {
            $table->string('teacher_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('module_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('start_date', 3)->nullable();
            $table->dateTime('end_date', 3)->nullable();
            $table->dateTime('published_at', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreign('teacher_id')->references('id')->on('teachers')->nullOnDelete();
            $table->foreign('module_id')->references('id')->on('course_modules')->nullOnDelete();
            $table->index(['teacher_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
            $table->dropForeign(['module_id']);
            $table->dropIndex(['teacher_id', 'end_date']);
            $table->dropColumn(['teacher_id', 'module_id', 'start_date', 'end_date', 'published_at', 'is_active']);
        });
        foreach (['teacher_assignments', 'course_enrollments', 'class_enrollments', 'teachers', 'students', 'classes'] as $name) Schema::dropIfExists($name);
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropForeign(['school_id']);
            $table->dropUnique(['school_id', 'code']);
            $table->dropColumn(['school_id', 'name', 'code', 'is_active']);
        });
    }
};
