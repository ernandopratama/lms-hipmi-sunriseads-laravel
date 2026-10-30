<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until', 3)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_enabled', 'mfa_secret', 'failed_login_count', 'locked_until', 'last_login_at']);
        });
    }
};
