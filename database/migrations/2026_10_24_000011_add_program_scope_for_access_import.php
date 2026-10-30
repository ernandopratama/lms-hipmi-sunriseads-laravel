<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->collation('utf8mb4_bin');
            $table->string('code');
            $table->string('name');
            $table->longText('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->unique(['school_id', 'code']);
        });
        Schema::table('access_grants', function (Blueprint $table): void {
            $table->foreign('program_id')->references('id')->on('programs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', fn (Blueprint $table) => $table->dropForeign(['program_id']));
        Schema::dropIfExists('programs');
    }
};
