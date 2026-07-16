<?php

declare(strict_types=1);

/**
 * Минимальный рабочий цикл приёма сообщений VK Teams (long polling events/get).
 * Промежуточное решение до готовности QueryParser/Matcher/PresentationBuilder (Этап 1/2, §9 ТЗ):
 * ChatBotController пока только подтверждает приём запроса.
 *
 * Запуск: php bin/poll.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Bot\ChatBotController;
use CasesBot\Bot\VkTeamsClient;

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

$client = new VkTeamsClient($config['vk_teams']['bot_token'], $config['vk_teams']['api_url']);
$controller = new ChatBotController($client);

$self = $client->selfGet();
fwrite(STDOUT, "Подключено как @{$self['nick']} (userId {$self['userId']})\n");

$lastEventId = 0;

while (true) {
    try {
        $events = $client->getEvents($lastEventId);
        foreach ($events as $event) {
            $lastEventId = max($lastEventId, (int) $event['eventId']);
            $controller->handleEvent($event);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Ошибка long polling: ' . $e->getMessage() . "\n");
        sleep(5);
    }
}
