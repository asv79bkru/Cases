<?php

namespace CasesBot\Api\Providers;

use InvalidArgumentException;

// ============================================================
// ProviderFactory — подключает провайдера чат-API по имени
// (см. config/config.php -> 'llm'). Чтобы добавить нового провайдера:
//   1. Создайте класс в этой папке, реализующий ChatProviderInterface.
//   2. Добавьте его конфиг (URL/ключ/модель) в config/config.php -> 'llm'.
//   3. Добавьте ветку match() ниже — вызывающий код трогать не нужно.
// ============================================================

final class ProviderFactory
{
    /** @param array<string, mixed> $llmConfig Секция 'llm' из config/config.php */
    public static function create(string $name, array $llmConfig): ChatProviderInterface
    {
        return match (strtolower($name)) {
            'ollama' => new OllamaProvider(
                $llmConfig['ollama']['api_url'],
                $llmConfig['ollama']['api_key'],
                $llmConfig['ollama']['model'],
            ),
            'opencodezen' => new OpenCodeZenProvider(
                $llmConfig['opencodezen']['api_key'],
                $llmConfig['opencodezen']['model'],
            ),
            default => throw new InvalidArgumentException(
                "Неизвестный провайдер чат-API: \"$name\". Доступны: ollama, opencodezen."
            ),
        };
    }
}
