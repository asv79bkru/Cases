<?php

namespace CasesBot\Api\Providers;

// ============================================================
// OpenCodeZenProvider — провайдер "opencodezen": OpenAI-совместимый
// чат-API opencode.ai/zen (https://opencode.ai/docs/zen/)
// ============================================================

final class OpenCodeZenProvider extends AbstractHttpChatProvider
{
    private const BASE_URL = 'https://opencode.ai/zen/v1';

    public function __construct(string $apiKey, string $model)
    {
        parent::__construct(self::BASE_URL, $apiKey, $model);
    }

    protected function label(): string
    {
        return 'OpenCodeZen API';
    }
}
