<?php

declare(strict_types=1);

namespace CasesBot\Bot;

/**
 * Обрабатывает входящее сообщение: QueryParser -> Matcher -> PresentationBuilder -> ответ (§6.1, §6.2 ТЗ).
 *
 * Временная реализация: QueryParser/Matcher/PresentationBuilder ещё не реализованы (Этап 1/2 из §9 ТЗ),
 * поэтому контроллер только подтверждает приём запроса — минимальный echo-цикл для проверки связи с VK Teams.
 */
class ChatBotController
{
    public function __construct(
        private VkTeamsClient $vkTeamsClient,
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

        $this->vkTeamsClient->sendText(
            (string) $chatId,
            "Принял запрос: «{$text}».\nПоиск и сборка кейсов ещё в разработке (Этап 1/2), скоро здесь будет готовая подборка."
        );
    }
}
