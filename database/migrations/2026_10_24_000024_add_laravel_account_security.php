<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->unsignedInteger('session_version')->default(0));
        Schema::create('account_reset_tokens', function (Blueprint $t): void {
            $t->string('token_hash', 64)->primary();
            $t->string('user_id', 191)->collation('utf8mb4_bin');
            $t->dateTime('expires_at', 3);
            $t->dateTime('used_at', 3)->nullable();
            $t->dateTime('created_at', 3);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'expires_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('account_reset_tokens');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('session_version'));
    }
};
