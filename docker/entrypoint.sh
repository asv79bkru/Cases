#!/bin/sh
# Отдаёт presentations/ по обычному HTTP на :8080 (php -S, в фоне), поднимает веб-загрузчик
# презентаций (public/upload.php, :8081, см. README) и запускает long-poll бота (bin/poll.php)
# как PID 1 — единственная причина этого entrypoint вместо ENTRYPOINT ["php","bin/poll.php"]
# напрямую.
set -e

php -S 0.0.0.0:8080 -t /app/presentations &

# upload_max_filesize/post_max_size по умолчанию малы для ~70МБ pptx; max_execution_time —
# переиндексация всех презентаций (bin/index.php runAutomatic) может занять больше 30 сек по умолчанию.
php -d upload_max_filesize=200M -d post_max_size=210M -d max_execution_time=300 \
    -S 0.0.0.0:8081 -t /app/public &

exec php bin/poll.php
