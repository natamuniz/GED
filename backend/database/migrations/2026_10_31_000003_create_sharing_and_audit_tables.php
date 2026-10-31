<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE document_shares ADD CONSTRAINT valid_share_period CHECK (expires_at > starts_at)');
        DB::statement('ALTER TABLE document_shares ADD CONSTRAINT consistent_revocation CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');

        Schema::create('document_share_recipients', function (Blueprint $table) {
            $table->foreignId('document_share_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['document_share_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action')->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('document_share_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('document_share_recipients');
        Schema::dropIfExists('document_shares');
    }
};
