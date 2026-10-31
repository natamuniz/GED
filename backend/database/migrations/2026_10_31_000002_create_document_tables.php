<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('producing_unit_id')->nullable()->constrained('organizational_units')->restrictOnDelete();
            $table->date('production_date')->index();
            $table->string('author')->nullable();
            $table->string('interested_party')->nullable();
            $table->string('reference_number')->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable()->index();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['producing_unit_id', 'archived_at']);
        });
        DB::statement('ALTER TABLE documents ADD CONSTRAINT consistent_archival CHECK ((archived_at IS NULL) = (archived_by IS NULL))');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->integer('version_number');
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('extension', 20);
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('change_note')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number']);
            $table->unique(['storage_disk', 'storage_path']);
        });
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT positive_version CHECK (version_number > 0)');
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT nonnegative_file_size CHECK (size_bytes >= 0)');
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT valid_sha256 CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        Schema::create('integrity_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->char('expected_sha256', 64);
            $table->char('calculated_sha256', 64)->nullable();
            $table->string('result', 10);
            $table->string('error_code')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_at');
            $table->index(['document_version_id', 'checked_at']);
        });
        DB::statement("ALTER TABLE integrity_checks ADD CONSTRAINT valid_integrity_result CHECK (result IN ('match', 'mismatch', 'error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_checks');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
