<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services de téléphonie connectés (Ringover, Aircall, Twilio, Asterisk, webhook générique)
        Schema::create('telephony_providers', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);      // utilisé pour le click-to-call
            $table->text('credentials')->nullable();            // JSON chiffré (clés API, mots de passe)
            $table->json('settings')->nullable();               // options non sensibles
            $table->string('webhook_token', 64)->unique();      // URL : /webhooks/telephony/{token}
            $table->boolean('sync_enabled')->default(true);     // récupération périodique des appels
            $table->unsignedSmallInteger('sync_interval_minutes')->default(5);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('synced_until')->nullable();      // curseur de récupération
            $table->string('last_sync_status', 20)->nullable(); // ok, error
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_sync_count')->default(0);
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });

        // Correspondance utilisateurs CRM <-> comptes / lignes / postes chez le fournisseur
        Schema::create('telephony_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telephony_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();    // ID utilisateur chez le fournisseur
            $table->string('phone_number', 30)->nullable(); // ligne / numéro direct de l'agent (E.164)
            $table->string('extension', 20)->nullable();    // poste interne (Asterisk / PBX)
            $table->timestamps();
            $table->unique(['telephony_provider_id', 'user_id']);
            $table->index(['telephony_provider_id', 'external_id']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('telephony_provider_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->after('telephony_provider_id');
            $table->string('external_number', 30)->nullable()->after('to_number')->index();
            $table->string('status', 20)->nullable()->after('ringover_status')->index(); // answered, missed, voicemail…
            $table->string('missed_reason')->nullable()->after('status');
            $table->timestamp('answered_at')->nullable()->after('started_at');
            $table->unsignedInteger('ring_duration')->nullable()->after('duration');
            $table->string('voicemail_url', 1000)->nullable()->after('recording_url');
            $table->string('hangup_by', 30)->nullable();
            $table->string('agent_external_id')->nullable();
            $table->unique(['telephony_provider_id', 'external_id']);
        });

        // Reprise des appels Ringover existants
        DB::table('calls')->whereNotNull('ringover_call_id')->update(['external_id' => DB::raw('ringover_call_id')]);
        DB::table('calls')->whereNull('status')->where('is_answered', true)->update(['status' => 'answered']);
        DB::table('calls')->whereNull('status')->where('is_answered', false)->where('direction', 'in')->update(['status' => 'missed']);
        DB::table('calls')->whereNull('status')->where('is_answered', false)->where('direction', 'out')->update(['status' => 'no_answer']);
        DB::table('calls')->whereNull('external_number')->where('direction', 'in')->update(['external_number' => DB::raw('from_number')]);
        DB::table('calls')->whereNull('external_number')->where('direction', 'out')->update(['external_number' => DB::raw('to_number')]);
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropUnique(['telephony_provider_id', 'external_id']);
            $table->dropConstrainedForeignId('telephony_provider_id');
            $table->dropColumn(['external_id', 'external_number', 'status', 'missed_reason', 'answered_at', 'ring_duration', 'voicemail_url', 'hangup_by', 'agent_external_id']);
        });
        Schema::dropIfExists('telephony_agents');
        Schema::dropIfExists('telephony_providers');
    }
};
