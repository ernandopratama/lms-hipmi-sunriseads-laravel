<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validation permits 50,000 Unicode characters (up to 200,000 bytes).
        Schema::table('assignments', function (Blueprint $table) {
            $table->longText('instructions')->change();
        });
        Schema::table('submissions', function (Blueprint $table) {
            $table->longText('body')->change();
        });
    }

    public function down(): void
    {
        // Preserve submitted work: narrowing back to TEXT could truncate history.
        // Leaving the wider columns is compatible with the previous application.
    }
};
