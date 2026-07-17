<?php

declare(strict_types=1);

namespace CasesBot\Bot;

use CasesBot\Bot\Commands\CommandInterface;

/**
 * Обрабатывает входящее сообщение: находит первую подходящую команду (src/Bot/Commands/)
 * по триггеру и передаёт ей обработку (§6.1 ТЗ). Сообщения без известного триггера игнорируются.
 */
class ChatBotController
{
    /** @param CommandInterface[] $commands */
    public function __construct(
        private array $commands,
    ) {
    }

    /** @param array<string, mixed> $event Событие типа "newMessage" из events/get */
    public function handleEvent(array $event): void
    {
        if (($event['type'] ?? null) !== 'newMessage') {
            return;
        }

        $payload = $event['payload'] ?? [];
        $chatId = $payload['chat']['chatId'] ?? null;
        $text = trim((string) ($payload['text'] ?? ''));

        if ($chatId === null || $text === '') {
            return;
        }

        foreach ($this->commands as $command) {
            if ($command->matches($text)) {
                $command->handle((string) $chatId, $text);

                return;
            }
        }
    }
}
