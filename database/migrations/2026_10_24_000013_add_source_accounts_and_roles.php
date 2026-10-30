<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('avatar')->nullable();
            $table->boolean('email_verified')->default(false);
            $table->string('source_status', 40)->nullable();
            $table->dateTime('activated_at', 3)->nullable();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'slug']);
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('organization_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->string('resource');
            $table->string('action');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps(3);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->unique(['organization_id', 'resource', 'action'], 'permission_scope');
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('user_id', 191)->collation('utf8mb4_bin');
            $table->string('role_id', 191)->collation('utf8mb4_bin');
            $table->string('school_id', 191)->nullable()->collation('utf8mb4_bin');
            $table->dateTime('created_at', 3);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('school_id')->references('id')->on('schools')->restrictOnDelete();
            $table->unique(['user_id', 'role_id', 'school_id'], 'user_role_scope');
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->string('id', 191)->collation('utf8mb4_bin')->primary();
            $table->string('role_id', 191)->collation('utf8mb4_bin');
            $table->string('permission_id', 191)->collation('utf8mb4_bin');
            $table->dateTime('created_at', 3);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });
        Schema::table('students', function (Blueprint $table): void {
            $table->string('birth_place')->nullable();
            $table->dateTime('birth_date', 3)->nullable();
            $table->string('gender')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('social_media')->nullable();
            $table->string('affiliate_account')->nullable();
            $table->text('photo')->nullable();
        });
        Schema::table('teachers', fn (Blueprint $table) => $table->string('phone')->nullable());
    }

    public function down(): void
    {
        Schema::table('teachers', fn (Blueprint $table) => $table->dropColumn('phone'));
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn(['birth_place', 'birth_date', 'gender', 'address', 'phone', 'social_media', 'affiliate_account', 'photo']));
        foreach (['role_permissions', 'user_roles', 'permissions', 'roles'] as $table) Schema::dropIfExists($table);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'first_name', 'last_name', 'phone', 'avatar', 'email_verified', 'source_status', 'activated_at']);
        });
    }
};
