<?php

namespace CasesBot\Api\Providers;

// ============================================================
// ChatProviderInterface — контракт провайдера чат-API для генерации
// протокола встречи. Каждый провайдер (Ollama, OpenCodeZen, ...) — свой
// класс в этой папке, подключается по имени через ProviderFactory.
// ============================================================

interface ChatProviderInterface
{
    public function chat(string $systemPrompt, string $userMessage, int $timeout): string;

    // Название модели — используется в статусных сообщениях пользователю
    public function getModel(): string;
}
