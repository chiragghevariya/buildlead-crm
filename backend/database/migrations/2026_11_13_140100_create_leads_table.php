<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // BL-2026-0001

            // Contact
            $table->string('contact_name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('source', 20)->index();

            // Project
            $table->string('project_type', 30)->nullable()->index();
            $table->text('description')->nullable();
            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();
            $table->string('timeline', 20)->nullable();
            $table->string('street')->nullable();
            $table->string('postcode', 10)->nullable()->index();
            $table->string('city')->nullable();
            $table->unsignedInteger('area_sqm')->nullable();

            // Scoring
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->unsignedTinyInteger('rule_score')->nullable();
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->string('temperature', 10)->nullable()->index();
            $table->json('score_breakdown')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('ai_next_action')->nullable();
            $table->timestamp('scored_at')->nullable();

            // Pipeline
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->string('lost_reason')->nullable();
            $table->unsignedInteger('position')->default(0);

            // GDPR + ingest
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'position']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
