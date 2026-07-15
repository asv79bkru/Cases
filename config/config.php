<?php

declare(strict_types=1);

/**
 * Конфигурация приложения (см. класс Config, §6.2 ТЗ):
 * пути к рабочим папкам, токены VK Teams и Google Drive, путь к справочнику тегов.
 */

// getenv() работает и с .env, подгруженным вручную в bin/poll.php (putenv()),
// и с переменными из `docker run --env-file` — в отличие от $_ENV (variables_order по умолчанию без "E").
$env = static fn (string $key, string $default = ''): string => getenv($key) !== false ? getenv($key) : $default;

return [
    'vk_teams' => [
        'bot_token' => $env('VK_TEAMS_BOT_TOKEN'),
        'api_url' => $env('VK_TEAMS_API_URL'),
    ],
    'google_drive' => [
        'credentials_path' => $env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
        'folder_id' => $env('GOOGLE_DRIVE_FOLDER_ID'),
    ],
    'catalog' => [
        'storage_path' => $env('CATALOG_STORAGE_PATH', __DIR__ . '/../storage/catalog/catalog.sqlite'),
    ],
    'tags_taxonomy_path' => __DIR__ . '/tags.php',
    'python' => [
        'bin' => $env('PYTHON_BIN', 'python'),
        'slide_text_extractor' => __DIR__ . '/../python/slide_text_extractor.py',
        'slide_cloner' => __DIR__ . '/../python/slide_cloner.py',
    ],
    'storage' => [
        'incoming' => __DIR__ . '/../storage/incoming',
        'output' => __DIR__ . '/../storage/output',
        'logs' => __DIR__ . '/../storage/logs',
    ],
    'max_slides_per_deck' => (int) $env('MAX_SLIDES_PER_DECK', '10'),
];
