<?php

declare(strict_types=1);

/**
 * Конфигурация приложения (см. класс Config, §6.2 ТЗ):
 * пути к рабочим папкам, токены VK Teams, путь к справочнику тегов.
 *
 * Источник презентаций временно — локальная папка на сервере (не Google Drive,
 * см. LocalPresentationsClient); интеграция с Google Drive API отложена.
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
        'folder_path' => $env('PRESENTATIONS_FOLDER_PATH'),
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
