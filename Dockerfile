FROM php:8.3-cli

# Installer les extensions PHP nécessaires à Laravel + MySQL
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copier le code de l'application
COPY . .

# Installer les dépendances PHP (sans les paquets de dev, plus léger et plus rapide)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Créer le lien symbolique storage -> public/storage
RUN php artisan storage:link || true

# Donner les bons droits d'écriture à Laravel
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 10000

# Au démarrage : migrations + cache config + lancement du serveur
# $PORT est fourni automatiquement par Render
CMD php artisan config:cache && \
    php artisan migrate --force && \
    php artisan serve --host 0.0.0.0 --port ${PORT:-10000}
