FROM php:8.2-fpm

# Installer les dépendances système pour Laravel, Nginx et Supervisor
RUN apt-get update && apt-get install -y \
    nginx \
    supervisor \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo_mysql zip mbstring xml \
    && rm -rf /var/lib/apt/lists/*

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Répertoire de travail
WORKDIR /var/www/html

# Copier le code Laravel
COPY . .

# Installer les dépendances Laravel
RUN composer install --optimize-autoloader --no-dev --no-interaction

# Configuration Nginx, PHP-FPM et Supervisor
COPY nginx-internal.conf /etc/nginx/nginx.conf
COPY php-fpm-prod.conf /usr/local/etc/php-fpm.d/www.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Création des répertoires nécessaires pour les logs et PID
RUN mkdir -p /var/log/supervisor /var/run /var/log/nginx /var/lib/nginx/body \
    && chown -R www-data:www-data /var/log/nginx /var/lib/nginx

# Nettoyage Nginx
RUN rm -rf /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default

# Permissions Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Créer le lien symbolique pour le stockage (supprimer l'existant s'il est cassé)
RUN rm -rf public/storage && php artisan storage:link

# Exposer le port HTTP
EXPOSE 80

# Démarrer via Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
