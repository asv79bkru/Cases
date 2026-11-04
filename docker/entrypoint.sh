#!/bin/sh
# Отдаёт presentations-public/ («парадные» копии без заметок/комментариев, PublicCopyBuilder)
# по обычному HTTP на :8080 (php -S, в фоне), поднимает веб-страницы для эксперта на :8081
# (public/upload.php — загрузка презентаций, public/tags.php — интерфейс разметки кейсов,
# см. README) и запускает long-poll бота (bin/poll.php) как PID 1 — единственная причина
# этого entrypoint вместо ENTRYPOINT ["php","bin/poll.php"] напрямую.
#
# Важно: сырые исходники presentations/ (заметки с #кейс# и комментарии рецензентов) наружу
# не отдаём — они нужны только индексации, их трогает деплой/веб-загрузчик/sync.
set -e

mkdir -p /app/presentations-public
php -S 0.0.0.0:8080 -t /app/presentations-public &

# Недельная синхронизация с Google Drive (bin/sync.php): раз в SYNC_INTERVAL_SECONDS
# (по умолчанию 604800 = 7 дней) проверяет, менялась ли презентация в Drive; если да —
# скачивает её, переиндексирует каталог и пересобирает публичные копии. Первый прогон —
# сразу при старте контейнера (sync идемпотентен: без изменений в Drive ничего не делает).
SYNC_INTERVAL_SECONDS="${SYNC_INTERVAL_SECONDS:-604800}"
mkdir -p /app/storage/logs
(
    while true; do
        php bin/sync.php >> /app/storage/logs/sync.log 2>&1 || true
        sleep "$SYNC_INTERVAL_SECONDS"
    done
) &

# upload_max_filesize/post_max_size по умолчанию малы для ~70МБ pptx; max_execution_time —
# переиндексация всех презентаций (bin/index.php runAutomatic) может занять больше 30 сек по умолчанию.
php -d upload_max_filesize=200M -d post_max_size=210M -d max_execution_time=300 \
    -S 0.0.0.0:8081 -t /app/public &

exec php bin/poll.php
