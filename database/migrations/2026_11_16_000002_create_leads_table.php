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
            $table->string('reference', 20)->unique();

            // Établissement
            $table->string('company_name');
            $table->string('type', 30)->default('cabinet')->index();
            $table->string('siret', 20)->nullable()->index();
            $table->string('website')->nullable();
            $table->unsignedSmallInteger('practitioners_count')->nullable();

            // Contact principal
            $table->string('contact_civility', 10)->nullable();
            $table->string('contact_first_name')->nullable();
            $table->string('contact_last_name')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('mobile', 40)->nullable();
            $table->string('mobile_normalized', 20)->nullable()->index();

            // Adresse
            $table->string('address')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('department', 3)->nullable()->index();
            $table->string('country', 2)->default('FR');

            // Pipeline
            $table->string('status', 30)->default('nouveau')->index();
            $table->string('source')->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Qualification métier collecte
            $table->string('current_buyer')->nullable();          // concurrent / récupérateur actuel
            $table->unsignedInteger('estimated_grams_year')->nullable(); // volume estimé de déchets métalliques
            $table->string('collection_frequency')->nullable();   // ponctuelle, trimestrielle…
            $table->string('lost_reason')->nullable();
            $table->boolean('do_not_call')->default(false);
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();

            // Suivi
            $table->unsignedSmallInteger('call_attempts')->default(0);
            $table->timestamp('last_contacted_at')->nullable()->index();
            $table->timestamp('next_action_at')->nullable()->index();
            $table->string('next_action')->nullable();

            // IA
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->string('temperature', 10)->nullable()->index();
            $table->text('ai_summary')->nullable();
            $table->text('ai_pitch')->nullable();
            $table->json('ai_insights')->nullable();
            $table->timestamp('ai_scored_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
