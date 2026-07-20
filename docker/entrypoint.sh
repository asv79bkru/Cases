#!/bin/sh
# Отдаёт presentations/ по обычному HTTP на :8080 (php -S, в фоне) и запускает long-poll
# бота (bin/poll.php) как PID 1 — единственная причина этого entrypoint вместо
# ENTRYPOINT ["php","bin/poll.php"] напрямую.
set -e

php -S 0.0.0.0:8080 -t /app/presentations &

exec php bin/poll.php
