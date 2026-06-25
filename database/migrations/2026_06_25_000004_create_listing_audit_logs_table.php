<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bounded Context AuditLog: sin FK hacia listings/users (aislamiento).
        Schema::create('listing_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');    // desde payload, sin FK
            $table->unsignedBigInteger('listing_id'); // desde payload, sin FK
            $table->string('action', 50);             // created/updated/deleted
            $table->string('message', 500);
            $table->json('metadata');
            $table->char('event_id', 36)->unique();   // UUID, idempotencia
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_audit_logs');
    }
};