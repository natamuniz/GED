<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index(['document_id', 'created_at', 'id'], 'audit_document_chronology_index');
            $table->index(['actor_id', 'created_at', 'id'], 'audit_actor_chronology_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex('audit_document_chronology_index');
            $table->dropIndex('audit_actor_chronology_index');
        });
    }
};
