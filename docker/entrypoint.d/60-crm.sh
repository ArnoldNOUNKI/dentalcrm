#!/bin/sh
# Exécuté au démarrage de chaque conteneur (après les automatisations Laravel de serversideup).
set -e
cd /var/www/html

# Contrôle des variables indispensables (message visible dans les logs Coolify du conteneur)
missing=""
[ -z "${APP_KEY:-}" ] && missing="$missing APP_KEY"
[ -z "${APP_URL:-}" ] && missing="$missing APP_URL"
[ -z "${DB_PASSWORD:-}" ] && missing="$missing DB_PASSWORD"
if [ -n "$missing" ]; then
    echo "==================================================================" >&2
    echo " DentalCRM : variable(s) manquante(s) :$missing" >&2
    echo " Renseignez-les dans Coolify > Environment Variables puis redéployez." >&2
    echo " APP_KEY : echo \"base64:\$(openssl rand -base64 32)\"" >&2
    echo "==================================================================" >&2
fi

if [ "${CRM_IS_WEB:-false}" = "true" ]; then
    php artisan filament:optimize --no-interaction || true
    # Compte admin initial (idempotent) : ADMIN_EMAIL / ADMIN_PASSWORD
    php artisan db:seed --class=AdminSeeder --force --no-interaction || true
    php artisan db:seed --class=MetalPriceSeeder --force --no-interaction || true
fi
