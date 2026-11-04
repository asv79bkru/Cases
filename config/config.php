<?php

declare(strict_types=1);

/**
 * Конфигурация приложения (см. класс Config, §6.2 ТЗ):
 * пути к рабочим папкам, токены VK Teams, путь к справочнику тегов.
 *
 * Источник презентаций временно — папка presentations/ рядом с приложением (не Google Drive,
 * см. LocalPresentationsClient): в git не хранится (большие бинарники), заливается на сервер
 * отдельно при деплое; интеграция с Google Drive API отложена.
 */

// getenv() работает и с .env, подгруженным вручную в bin/poll.php (putenv()),
// и с переменными из `docker run --env-file` — в отличие от $_ENV (variables_order по умолчанию без "E").
$env = static fn (string $key, string $default = ''): string => getenv($key) !== false ? getenv($key) : $default;

return [
    'vk_teams' => [
        'bot_token' => $env('VK_TEAMS_BOT_TOKEN'),
        'api_url' => $env('VK_TEAMS_API_URL'),
    ],
    'presentations' => [
        'folder_path' => $env('PRESENTATIONS_FOLDER_PATH', __DIR__ . '/../presentations'),
        // presentations/ дополнительно отдаётся по HTTP (docker/entrypoint.sh, php -S :8080) —
        // прямая ссылка на исходный файл в сообщении команды «кейсы» вместо/вместе с вложением.
        'http_base_url' => $env('PRESENTATIONS_HTTP_BASE_URL', 'http://vm-docker-ba.simbirsoft:8080'),
        // «Парадные» копии без заметок/комментариев (PublicCopyBuilder) — именно этот каталог
        // отдаётся на :8080 для ссылок из чата; сырые исходники с заметками наружу не отдаётся.
        'public_folder_path' => $env('PRESENTATIONS_PUBLIC_FOLDER_PATH', __DIR__ . '/../presentations-public'),
        // Имя публичной копии, отличающееся от имени исходника: файл в presentations/ ->
        // имя, под которым копия отдаётся в чат. Старые копии (в т.ч. под прежними именами)
        // PublicCopyBuilder удаляет сам — в presentations-public остаётся ровно актуальный набор.
        'public_name_overrides' => [
            'Кейсы направления Бизнес автоматизации.pptx' => 'Экспертиза направления Бизнес автоматизации.pptx',
        ],
    ],
    'catalog' => [
        'storage_path' => $env('CATALOG_STORAGE_PATH', __DIR__ . '/../storage/catalog/catalog.sqlite'),
        'images_path' => $env('CATALOG_IMAGES_PATH', __DIR__ . '/../storage/catalog/images'),
    ],
    'tags_taxonomy_path' => __DIR__ . '/tags.php',
    'python' => [
        'bin' => $env('PYTHON_BIN', 'python'),
        'slide_text_extractor' => __DIR__ . '/../python/slide_text_extractor.py',
        'slide_cloner' => __DIR__ . '/../python/slide_cloner.py',
    ],
    // Слайд считается кейсом, только если эта метка есть в заметках докладчика (не на самом слайде).
    'case_marker' => $env('CASE_MARKER', '#кейс#'),
    // Синхронизация из Google Drive (bin/sync.php): сервисный аккаунт (ключ в config/google/,
    // не в git) + id презентаций Drive, за которыми следим (через запятую — если их станет несколько).
    'google' => [
        'service_account_json' => $env('GOOGLE_SERVICE_ACCOUNT_JSON'),
        'presentation_ids' => array_values(array_filter(array_map('trim', explode(',', $env('GOOGLE_PRESENTATION_ID'))))),
        'sync_state_path' => $env('SYNC_STATE_PATH', __DIR__ . '/../storage/catalog/drive-sync.json'),
        'token_cache_path' => $env('GOOGLE_TOKEN_CACHE_PATH', __DIR__ . '/../storage/cache/google-token.json'),
        // Интервал недельного цикла, сек (docker/entrypoint.sh). 604800 = 7 дней.
        'sync_interval_seconds' => (int) $env('SYNC_INTERVAL_SECONDS', '604800'),
    ],
    // LLM-провайдер для дополнения тегов по содержимому кейса (см. CasesBot\Api\Providers\ProviderFactory).
    'llm' => [
        'provider' => $env('AI_PROVIDER', 'opencodezen'),
        'ollama' => [
            'api_url' => $env('OLLAMA_API_URL', 'http://localhost:11434/v1'),
            'api_key' => $env('OLLAMA_API_KEY'),
            'model' => $env('OLLAMA_MODEL'),
        ],
        'opencodezen' => [
            'api_key' => $env('OPENCODEZEN_API_KEY'),
            'model' => $env('OPENCODEZEN_MODEL'),
        ],
    ],
    'storage' => [
        'incoming' => __DIR__ . '/../storage/incoming',
        'output' => __DIR__ . '/../storage/output',
        'logs' => __DIR__ . '/../storage/logs',
    ],
    'max_slides_per_deck' => (int) $env('MAX_SLIDES_PER_DECK', '10'),
    // Веб-загрузчик презентаций (public/upload.php, :8081) — HTTP Basic Auth, без учётных данных
    // в .env страница отказывает в доступе (fail closed), а не открывается всем без пароля.
    'upload' => [
        'username' => $env('UPLOAD_USERNAME'),
        'password' => $env('UPLOAD_PASSWORD'),
    ],
];
