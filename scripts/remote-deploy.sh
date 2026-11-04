#!/bin/sh
# remote-deploy.sh — серверная часть деплоя CasesBot (выполняется на vm-docker-ba).
# Ожидает уже загруженный tarball с исходниками и переменные окружения:
#   REMOTE_DIR, IMAGE, CONTAINER, TARBALL, SKIP_BUILD
# Данные и секреты (.env, presentations/, storage/) не перезаписываются.
set -e

REMOTE_DIR="${REMOTE_DIR:-/home/user/cases-bot}"
IMAGE="${IMAGE:-cases-bot:latest}"
CONTAINER="${CONTAINER:-cases-bot}"
TARBALL="${TARBALL:-/tmp/casesbot-sync.tar.gz}"
STAGING="${STAGING:-/tmp/casesbot-staging}"
SKIP_BUILD="${SKIP_BUILD:-0}"
IMAGE_PREV="cases-bot:previous"

echo "==> Проверка окружения"
[ -f "$TARBALL" ] || { echo "ОШИБКА: не найден tarball $TARBALL" >&2; exit 1; }
[ -f "$REMOTE_DIR/.env" ] || echo "ВНИМАНИЕ: $REMOTE_DIR/.env не найден — контейнер запустится без переменных"

echo "==> Распаковка $TARBALL во временную папку"
rm -rf "$STAGING"
mkdir -p "$STAGING"
tar -xzf "$TARBALL" -C "$STAGING"

echo "==> Синхронизация кода в $REMOTE_DIR (кроме .env, presentations/, storage/)"
rsync -a --delete \
    --exclude='/.env' \
    --exclude='/.env.*' \
    --exclude='/presentations' \
    --exclude='/presentations-public' \
    --exclude='/storage' \
    "$STAGING"/ "$REMOTE_DIR"/

if [ "$SKIP_BUILD" = "1" ]; then
    echo "==> Сборка образа пропущена (SkipBuild)"
else
    echo "==> Сохраняю текущий образ как $IMAGE_PREV (для отката)"
    docker tag "$IMAGE" "$IMAGE_PREV" 2>/dev/null || true
    echo "==> Сборка образа $IMAGE"
    cd "$REMOTE_DIR"
    docker build -t "$IMAGE" .
fi

echo "==> Пересоздание контейнера $CONTAINER"
# presentations-public/ — публичные копии без заметок/комментариев (PublicCopyBuilder):
# bind-mount, чтобы копии переживали рестарт контейнера; после запуска пересобираем,
# т.к. сырые исходники меняются независимо от контейнера.
mkdir -p "$REMOTE_DIR/presentations-public"
docker rm -f "$CONTAINER" 2>/dev/null || true
docker run -d --name "$CONTAINER" --restart unless-stopped \
    -p 8080:8080 -p 8081:8081 \
    -v "$REMOTE_DIR/presentations:/app/presentations" \
    -v "$REMOTE_DIR/presentations-public:/app/presentations-public" \
    -v "$REMOTE_DIR/storage/catalog:/app/storage/catalog" \
    --env-file "$REMOTE_DIR/.env" \
    "$IMAGE"

echo "==> Пересборка публичных копий (presentations-public)"
docker exec "$CONTAINER" php bin/build-public.php || \
    echo "ВНИМАНИЕ: bin/build-public.php не отработал — ссылки на оригиналы в чате могут отдавать 404, запустите вручную: docker exec $CONTAINER php bin/build-public.php" >&2

echo "==> Проверка"
sleep 5
docker ps --filter "name=$CONTAINER" --format '{{.Names}} | {{.Status}} | {{.Ports}}'
echo "OPENCODEZEN_MODEL=$(docker exec "$CONTAINER" printenv OPENCODEZEN_MODEL 2>/dev/null || echo '?')"
echo "--- логи (последние 25 строк) ---"
docker logs --tail 25 "$CONTAINER" 2>&1 || true

rm -rf "$STAGING"
echo "==> Деплой завершён."
