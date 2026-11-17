#!/bin/sh
# Exécuté au démarrage de chaque conteneur (après les automatisations Laravel de serversideup).
set -e
cd /var/www/html

if [ "${CRM_IS_WEB:-false}" = "true" ]; then
    php artisan filament:optimize --no-interaction || true
    # Compte admin initial (idempotent) : ADMIN_EMAIL / ADMIN_PASSWORD
    php artisan db:seed --class=AdminSeeder --force --no-interaction || true
    php artisan db:seed --class=MetalPriceSeeder --force --no-interaction || true
fi
