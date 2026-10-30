<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('school_name');
            $table->string('education_level');
            $table->string('city');
            $table->string('province');
            $table->string('pic_name');
            $table->string('pic_title');
            $table->string('email');
            $table->string('phone');
            $table->unsignedInteger('estimated_students');
            $table->string('program_of_interest');
            $table->text('message')->nullable();
            $table->boolean('consent')->default(false);
            $table->string('status', 32)->default('PENDING')->index();
            $table->timestamps(3);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
