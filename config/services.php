<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        // Modèle principal (résumés d'appels, emails, assistant)
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        // Modèle rapide et économique (scoring en masse des leads importés)
        'model_fast' => env('ANTHROPIC_MODEL_FAST', 'claude-haiku-5-5'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        // Scoring IA automatique des leads importés
        'auto_score_imports' => (bool) env('AI_AUTO_SCORE_IMPORTS', true),
        // Résumé IA automatique des appels terminés
        'auto_summarize_calls' => (bool) env('AI_AUTO_SUMMARIZE_CALLS', true),
        // Contexte métier injecté dans tous les prompts
        'company_context' => env('AI_COMPANY_CONTEXT') ?: ("Nous sommes une société française spécialisée dans la collecte et le rachat de déchets métalliques dentaires (couronnes, bridges, inlays, alliages, limailles, chutes de laboratoire) contenant de l'or, du platine, du palladium et de l'argent. Nous fournissons un kit de collecte gratuit, un enlèvement sécurisé, une analyse/titrage en laboratoire et un paiement rapide au cours du jour. Nos prospects sont des cabinets dentaires, centres dentaires et laboratoires de prothèse."),
    ],

    'ringover' => [
        'api_key' => env('RINGOVER_API_KEY'),
        // EU : https://public-api.ringover.com/v2 — US : https://public-api-us.ringover.com/v2
        'base_url' => env('RINGOVER_BASE_URL', 'https://public-api.ringover.com/v2'),
        // Jeton secret inclus dans l'URL du webhook : /webhooks/ringover/{token}
        'webhook_token' => env('RINGOVER_WEBHOOK_TOKEN'),
        // Synchronisation périodique des appels (filet de sécurité si un webhook est perdu)
        'sync_enabled' => (bool) env('RINGOVER_SYNC_ENABLED', true),
        'sync_lookback_minutes' => (int) env('RINGOVER_SYNC_LOOKBACK', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
