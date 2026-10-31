<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('group');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organizational_units')->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE organizational_units ADD CONSTRAINT unit_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('organizational_unit_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('organizational_unit_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'organizational_unit_id']);
            $table->index('organizational_unit_id');
        });

        Schema::create('permission_profile', function (Blueprint $table) {
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['profile_id', 'permission_id']);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->string('effect', 5);
            $table->timestampsTz();
            $table->primary(['user_id', 'permission_id']);
        });
        DB::statement("ALTER TABLE user_permission_overrides ADD CONSTRAINT valid_permission_effect CHECK (effect IN ('allow', 'deny'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
        Schema::dropIfExists('permission_profile');
        Schema::dropIfExists('organizational_unit_user');
        Schema::dropIfExists('organizational_units');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('profiles');
    }
};
