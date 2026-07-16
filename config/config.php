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
];
