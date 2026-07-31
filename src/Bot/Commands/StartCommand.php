<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;

/** Команда «/start» — приветственное сообщение с объяснением, как пользоваться ботом. */
class StartCommand implements CommandInterface
{
    private const TRIGGER = '/start';

    private const TEXT = 'Этот бот подбирает кейсы направления Бизнес-автоматизации по запросы '
        . 'и создает презентацию с найденными кейсами. Чтобы это сделать, отправь сообщение '
        . '"кейсы тема", где кейсы это обязательное слово в сообщении, а тема - это то, про что '
        . 'нужны кейсы. Например "кейсы ритейл", "кейсы маркировка"';

    public function __construct(
        private VkTeamsClient $vkTeamsClient,
    ) {
    }

    public function matches(string $text): bool
    {
        return mb_stripos(ltrim($text), self::TRIGGER) === 0;
    }

    public function handle(string $chatId, string $text): void
    {
        $this->vkTeamsClient->sendText($chatId, self::TEXT);
    }
}
