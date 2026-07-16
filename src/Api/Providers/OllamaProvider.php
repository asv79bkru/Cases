<?php

namespace CasesBot\Api\Providers;

// ============================================================
// OllamaProvider — провайдер "ollama": self-hosted OpenWebUI/Ollama
// шлюз с OpenAI-совместимым чат-API (/api/v1/chat/completions)
// ============================================================

final class OllamaProvider extends AbstractHttpChatProvider
{
    protected function label(): string
    {
        return 'Ollama API';
    }
}
