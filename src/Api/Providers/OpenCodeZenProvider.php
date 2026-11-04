<?php

namespace CasesBot\Api\Providers;

// ============================================================
// OpenCodeZenProvider — провайдер "opencodezen": OpenAI-совместимый
// чат-API opencode.ai (Console, https://opencode.ai/docs/zen/).
// Базовый URL настраивается: pay-as-you-go по умолчанию, для подписки
// OpenCode Go — https://opencode.ai/zen/go/v1 (см. config/config.php).
// ============================================================

final class OpenCodeZenProvider extends AbstractHttpChatProvider
{
    private const DEFAULT_BASE_URL = 'https://opencode.ai/zen/v1';

    public function __construct(
        string $apiKey,
        string $model,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private string $sessionId = '',
    ) {
        parent::__construct($baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL, $apiKey, $model);
    }

    protected function label(): string
    {
        return 'OpenCodeZen API';
    }

    protected function extraHeaders(): array
    {
        // Go-эндпоинт отклоняет запросы без стабильного id сессии в заголовке
        // x-opencode-session (нужен для маршрутизации и промпт-кэша); на
        // pay-as-you-go этот заголовок просто игнорируется.
        if ($this->sessionId === '') {
            return [];
        }

        return ['x-opencode-session: ' . $this->sessionId];
    }
}
