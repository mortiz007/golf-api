<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('title', 255);
            $table->decimal('price', 10, 2);
            $table->enum('condition', ['New', 'Used', 'Refurbished', 'Like New']);
            $table->text('description');
            $table->date('end_date')->nullable();

            $table->enum('moderation_status', ['pending', 'approved', 'rejected'])
                ->default('pending');
            $table->json('moderation_result')->nullable();

            $table->json('ai_enrichment')->nullable();
            $table->enum('ai_enrichment_status', ['pending', 'succeeded', 'failed'])
                ->default('pending');

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Índices (SPECS §3 / DESIGN II-bis)
            $table->index('price');
            $table->index('category_id');
            $table->index('condition');
            $table->index('created_at');
            $table->index('end_date');
            $table->index(['moderation_status', 'end_date']); // listado público
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};