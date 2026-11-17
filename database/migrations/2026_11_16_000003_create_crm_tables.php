<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'activité (timeline du lead)
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->index();
            $table->string('subject');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        // Appels téléphoniques (Ringover ou saisie manuelle)
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ringover_call_id')->nullable()->unique();
            $table->string('direction', 10)->default('out'); // in / out
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('ringover_status', 30)->nullable(); // answered, missed, voicemail…
            $table->boolean('is_answered')->default(false);
            $table->unsignedInteger('duration')->default(0); // secondes
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->string('recording_url', 1000)->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('agent_notes')->nullable();
            $table->longText('transcript')->nullable();
            // Résultats IA
            $table->text('ai_summary')->nullable();
            $table->string('ai_sentiment', 20)->nullable();
            $table->string('ai_next_step')->nullable();
            $table->json('ai_extracted')->nullable();
            $table->timestamp('ai_processed_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        // Rappels / tâches
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('type', 20)->default('rappel'); // rappel, email, relance, collecte, autre
            $table->string('priority', 10)->default('normale');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        // Cours des métaux (prix au gramme de métal fin)
        Schema::create('metal_prices', function (Blueprint $table) {
            $table->id();
            $table->string('metal', 20)->unique(); // au, pt, pd, ag
            $table->string('label');
            $table->decimal('price_per_gram', 10, 3);
            $table->timestamp('quoted_at')->nullable();
            $table->timestamps();
        });

        // Collectes de déchets métalliques dentaires
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // commercial
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete(); // logistique
            $table->string('status', 30)->default('planifiee')->index();

            // Logistique
            $table->string('pickup_method', 30)->default('kit_postal'); // kit_postal, coursier, passage
            $table->date('kit_sent_at')->nullable();
            $table->date('pickup_date')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->date('received_at')->nullable();

            // Contenu & analyse
            $table->json('items')->nullable(); // [{type: couronnes, quantity: 12}]
            $table->decimal('gross_weight_g', 10, 2)->nullable();
            $table->decimal('net_weight_g', 10, 2)->nullable(); // après fonte / nettoyage
            $table->decimal('au_pct', 5, 2)->nullable();
            $table->decimal('pt_pct', 5, 2)->nullable();
            $table->decimal('pd_pct', 5, 2)->nullable();
            $table->decimal('ag_pct', 5, 2)->nullable();
            $table->decimal('payout_rate', 5, 2)->default(85); // % de la valeur métal reversé
            $table->json('price_snapshot')->nullable();

            // Financier
            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->decimal('offer_amount', 12, 2)->nullable();
            $table->date('offer_sent_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
        Schema::dropIfExists('metal_prices');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('activities');
    }
};
