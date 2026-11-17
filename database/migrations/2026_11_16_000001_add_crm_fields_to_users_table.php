<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('commercial')->after('email')->index();
            $table->string('phone', 30)->nullable()->after('role');
            // Numéro Ringover de l'agent (format E.164) utilisé pour le click-to-call
            $table->string('ringover_number', 30)->nullable()->after('phone');
            // Identifiant utilisateur Ringover (pour rattacher les appels reçus en webhook)
            $table->string('ringover_user_id', 50)->nullable()->after('ringover_number')->index();
            $table->boolean('is_active')->default(true)->after('ringover_user_id');
            // Participe à la distribution automatique des leads importés
            $table->boolean('receives_leads')->default(true)->after('is_active');
            $table->unsignedInteger('daily_call_target')->default(80)->after('receives_leads');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'ringover_number', 'ringover_user_id', 'is_active', 'receives_leads', 'daily_call_target']);
        });
    }
};
