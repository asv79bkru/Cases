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

# Ежесуточная синхронизация с Google Drive (bin/sync.php): раз в сутки, внутри окна
# SYNC_WINDOW_START_MSK..SYNC_WINDOW_END_MSK (по умолчанию 20:00–07:00 МСК) — ночью сервер
# свободнее. Первый прогон — сразу при старте контейнера, если окно уже открыто (sync
# идемпотентен: без изменений в Drive ничего не делает); иначе — при ближайшем открытии окна.
SYNC_WINDOW_START_MSK="${SYNC_WINDOW_START_MSK:-20}"
SYNC_WINDOW_END_MSK="${SYNC_WINDOW_END_MSK:-7}"
SYNC_DAY_SECONDS=86400
SYNC_WINDOW_START_MIN=$((SYNC_WINDOW_START_MSK * 60))
SYNC_WINDOW_END_MIN=$((SYNC_WINDOW_END_MSK * 60))
mkdir -p /app/storage/logs

# «Сейчас» в МСК, в минутах от полуночи: контейнер работает в UTC, МСК = UTC+3 всегда
# (в России нет перевода часов), поэтому сдвиг 3 часа прибавляем явно.
sync_now_msk_min() {
    echo $(( ( ( $(date -u +%s) + 10800 ) % 86400 ) / 60 ))
}

sync_in_window() {
    if [ "$SYNC_WINDOW_START_MIN" -lt "$SYNC_WINDOW_END_MIN" ]; then
        # Окно внутри одних суток (например, 10:00–15:00).
        [ "$1" -ge "$SYNC_WINDOW_START_MIN" ] && [ "$1" -lt "$SYNC_WINDOW_END_MIN" ]
    else
        # Окно через полночь (20:00–07:00): [start..24:00) U [00:00..end).
        [ "$1" -ge "$SYNC_WINDOW_START_MIN" ] || [ "$1" -lt "$SYNC_WINDOW_END_MIN" ]
    fi
}

# Спит до ближайшего открытия окна; если окно уже открыто — не спит вовсе.
sync_wait_for_window() {
    while :; do
        sync_now_min=$(sync_now_msk_min)
        if sync_in_window "$sync_now_min"; then
            return 0
        fi
        sleep $(( (SYNC_WINDOW_START_MIN - sync_now_min) * 60 ))
    done
}

(
    while true; do
        sync_wait_for_window
        php bin/sync.php >> /app/storage/logs/sync.log 2>&1 || true
        # Сутки от момента запуска: окно повторяется каждые 24 ч, поэтому следующий
        # запуск тоже попадает в окно. wait_for_window в начале цикла дополнительно
        # страхует от дрейфа, если очередь затянулась за границу окна.
        sleep "$SYNC_DAY_SECONDS"
    done
) &

# upload_max_filesize/post_max_size по умолчанию малы для ~70МБ pptx; max_execution_time —
# переиндексация всех презентаций (bin/index.php runAutomatic) может занять больше 30 сек по умолчанию.
php -d upload_max_filesize=200M -d post_max_size=210M -d max_execution_time=300 \
    -S 0.0.0.0:8081 -t /app/public &

exec php bin/poll.php
