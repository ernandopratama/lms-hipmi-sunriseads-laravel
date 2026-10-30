<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('access_grants', function (Blueprint $t): void {
            $t->string('order_item_id', 191)->nullable()->collation('utf8mb4_bin');
            $t->longText('reason')->nullable();
            $t->string('granted_by', 191)->nullable()->collation('utf8mb4_bin');
            $t->foreign('order_item_id')->references('id')->on('order_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $t): void {
            $t->dropForeign(['order_item_id']);
            $t->dropColumn(['order_item_id', 'reason', 'granted_by']);
        });
    }
};
