FROM php:8.2-cli-alpine

RUN apk add --no-cache libzip libxml2 \
    && apk add --no-cache --virtual .build-deps curl-dev libzip-dev libxml2-dev \
    && docker-php-ext-install curl zip dom \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY . .

# Секреты (VK_TEAMS_BOT_TOKEN и т.п.) передаются через `docker run --env-file .env`,
# а не копируются в образ — .env исключён через .dockerignore.
ENTRYPOINT ["php", "bin/poll.php"]
