<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_batches', function (Blueprint $t): void {
            $t->string('id', 191)->primary();
            $t->string('schema_sha256', 64);
            $t->json('baseline');
            $t->dateTime('source_exported_at', 3);
            $t->dateTime('imported_at', 3);
        });
        Schema::create('migration_source_records', function (Blueprint $t): void {
            $t->string('table_name', 64)->collation('utf8mb4_bin');
            $t->string('source_id', 191)->collation('utf8mb4_bin');
            $t->string('batch_id', 191);
            $t->longText('encrypted_payload');
            $t->primary(['table_name', 'source_id']);
            $t->foreign('batch_id')->references('id')->on('migration_batches')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('migration_source_records');
        Schema::dropIfExists('migration_batches');
    }
};
