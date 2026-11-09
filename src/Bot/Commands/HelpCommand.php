<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

use CasesBot\Bot\VkTeamsClient;

/**
 * Команда «/help» — справка: что умеет бот и какими командами пользоваться.
 * Список команд держится в тексте самой команды: новый обработчик добавляется в боте
 * отдельным классом (CommandInterface) в bin/poll.php, здесь дописывается одна строка.
 */
class HelpCommand implements CommandInterface
{
    private const TRIGGER = '/help';

    private const TEXT = "Команды бота:\n"
        . "\n"
        . "/main_presentation — ссылка на скачивание презентации направления\n"
        . "    из слайдов с тегом «основной» (отмечаются в веб-интерфейсе разметки).\n"
        . "    Пример: «/main_presentation Экспертиза направления»\n"
        . "\n"
        . "кейсы <тема> — подборка кейсов по тегам: собранный pptx плюс\n"
        . "    ссылка на оригинал презентации.\n"
        . "    Пример: «кейсы ритейл», «кейсы производство, 1с»";

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
