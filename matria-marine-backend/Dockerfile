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

# --prefer-install=auto: try the dist (GitHub zip) first, but fall back to a git
# clone of the source if the zip download fails (e.g. GitHub returns HTTP 400 for
# masterminds/html5). --prefer-dist would disable that fallback and fail the build.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-install=auto

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
