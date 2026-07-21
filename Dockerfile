FROM php:8.2-cli-alpine

RUN apk add --no-cache libzip libxml2 \
    && apk add --no-cache --virtual .build-deps curl-dev libzip-dev libxml2-dev \
    && docker-php-ext-install curl zip dom \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# python-pptx/lxml для SlideTextExtractor и SlideCloner (§6 ТЗ — OOXML-хирургия на стороне Python).
# Ставим в venv: Alpine отдельно даёт только python3, а PYTHON_BIN по умолчанию ждёт "python";
# venv кладёт оба имени в PATH и не конфликтует с системным pip (PEP 668).
RUN apk add --no-cache python3 py3-pip \
    && apk add --no-cache --virtual .py-build-deps gcc musl-dev python3-dev libxml2-dev libxslt-dev jpeg-dev zlib-dev \
    && python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
COPY python/requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt \
    && apk del .py-build-deps

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY . .
RUN chmod +x docker/entrypoint.sh

# Секреты (VK_TEAMS_BOT_TOKEN и т.п.) передаются через `docker run --env-file .env`,
# а не копируются в образ — .env исключён через .dockerignore.
# presentations/ дополнительно отдаётся по HTTP на :8080 (см. docker/entrypoint.sh) — прямая
# ссылка на исходный файл презентации, без прогона через VK Teams sendFile.
# :8081 — веб-загрузчик презентаций (public/upload.php), защищён HTTP Basic Auth
# (UPLOAD_USERNAME/UPLOAD_PASSWORD в .env).
EXPOSE 8080 8081
ENTRYPOINT ["docker/entrypoint.sh"]
