<?php

declare(strict_types=1);

/**
 * Рабочий цикл приёма сообщений VK Teams (long polling events/get).
 * Команды бота — отдельные классы в src/Bot/Commands/ (CommandInterface), ChatBotController
 * лишь передаёт сообщение первой подошедшей по триггеру (§6.1 ТЗ).
 *
 * Запуск: php bin/poll.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Api\Providers\ProviderFactory;
use CasesBot\Bot\ChatBotController;
use CasesBot\Bot\Commands\CasesCommand;
use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\LlmCaseMatcher;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Storage\LocalPresentationsClient;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

if ($config['vk_teams']['bot_token'] === '' || $config['vk_teams']['api_url'] === '') {
    fwrite(STDERR, "VK_TEAMS_BOT_TOKEN / VK_TEAMS_API_URL не заданы в .env\n");
    exit(1);
}

$vkTeamsClient = new VkTeamsClient($config['vk_teams']['bot_token'], $config['vk_teams']['api_url']);
$catalog = new CatalogRepository($config['catalog']['storage_path'], __DIR__ . '/../storage/catalog/schema.sql');
$tagTaxonomy = new TagTaxonomy($config['tags_taxonomy_path']);
$presentations = new LocalPresentationsClient(
    $config['presentations']['folder_path'],
    $config['presentations']['http_base_url'],
);

// Резерв на случай, когда в запросе нет ни одного известного тега (см. CasesCommand::handleUnknownTags,
// §5.1.7 ТЗ) — без настроенного провайдера (нет api_key/model в .env) команда просто вернётся
// к прежнему поведению (подсказка с примерами вместо подбора через LLM).
$llmCaseMatcher = null;
$llmProviderName = $config['llm']['provider'];
$llmProviderConfig = $config['llm'][$llmProviderName] ?? [];
if (($llmProviderConfig['api_key'] ?? '') !== '' && ($llmProviderConfig['model'] ?? '') !== '') {
    $llmCaseMatcher = new LlmCaseMatcher(ProviderFactory::create($llmProviderName, $config['llm']));
}

$casesCommand = new CasesCommand(
    $vkTeamsClient,
    $catalog,
    $presentations,
    $tagTaxonomy,
    $config['max_slides_per_deck'],
    $llmCaseMatcher,
);

$controller = new ChatBotController([$casesCommand]);

$self = $vkTeamsClient->selfGet();
fwrite(STDOUT, "Подключено как @{$self['nick']} (userId {$self['userId']})\n");

$lastEventId = 0;

while (true) {
    try {
        $events = $vkTeamsClient->getEvents($lastEventId);
        foreach ($events as $event) {
            $lastEventId = max($lastEventId, (int) $event['eventId']);
            $controller->handleEvent($event);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Ошибка long polling: ' . $e->getMessage() . "\n");
        sleep(5);
    }
}
