<?php

declare(strict_types=1);

namespace CasesBot\Bot\Commands;

/**
 * Одна команда чат-бота — свой триггер и обработка, отдельным классом (§6.1 ТЗ).
 * ChatBotController перебирает зарегистрированные команды и передаёт управление первой подошедшей.
 */
interface CommandInterface
{
    /** Подходит ли текст сообщения под эту команду (по триггеру, обычно в начале сообщения). */
    public function matches(string $text): bool;

    /** Обрабатывает сообщение и сама отправляет ответ (VkTeamsClient) — по этому же чату. */
    public function handle(string $chatId, string $text): void;
}
