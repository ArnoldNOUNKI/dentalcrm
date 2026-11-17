# ---------------------------------------------------------------------------
# DentalCRM — image de production (Nginx + PHP-FPM, port 8080)
# Basée sur serversideup/php : migrations et mises en cache automatiques au démarrage.
# ---------------------------------------------------------------------------
FROM serversideup/php:8.3-fpm-nginx

USER root
# intl : formats FR (dates, montants) de Filament ; bcmath : calculs ; exif/gd : imports de fichiers
RUN install-php-extensions intl bcmath gd exif

# Script exécuté au démarrage (cache Filament, lien storage)
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/

USER www-data
WORKDIR /var/www/html

# Dépendances PHP (couche mise en cache tant que composer.lock ne change pas)
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

# Code de l'application
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && rm -rf tests docker .env
