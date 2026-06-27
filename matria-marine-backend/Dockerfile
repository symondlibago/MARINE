FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip pdo_mysql

WORKDIR /app

# Running Composer as root in the image — allow it.
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . .

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --prefer-source: git-clone each package from github.com instead of downloading
# zip archives from codeload.github.com, which intermittently returns HTTP 400 on
# Railway's build network (Composer 2's batch downloader won't fall back to source
# on a dist 400, so we skip dist entirely). git is installed above.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-source

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
