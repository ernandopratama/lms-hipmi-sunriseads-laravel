<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $t): void {
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedTinyInteger('completion_threshold')->default(90);
            $t->boolean('is_required')->default(true);
        });
        Schema::table('material_progress', function (Blueprint $t): void {
            // covered_segments is a string of 0/1, one character per 5-second segment of the video.
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->text('covered_segments')->nullable();
            $t->dateTime('last_heartbeat_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('material_progress', fn (Blueprint $t) => $t->dropColumn(['duration_seconds', 'covered_segments', 'last_heartbeat_at']));
        Schema::table('materials', fn (Blueprint $t) => $t->dropColumn(['duration_seconds', 'completion_threshold', 'is_required']));
    }
};
