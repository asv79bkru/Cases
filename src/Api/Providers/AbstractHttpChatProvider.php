<?php

namespace CasesBot\Api\Providers;

use RuntimeException;

// ============================================================
// AbstractHttpChatProvider — общая часть для провайдеров с OpenAI-совместимым
// /chat/completions: сам HTTP-запрос и одна повторная попытка через 2 минуты
// при временных ошибках (модель перегружена/недоступна, сетевой сбой).
// ============================================================

abstract class AbstractHttpChatProvider implements ChatProviderInterface
{
    private const RETRY_DELAY_SECONDS = 120;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $model,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // Название провайдера для текста ошибок, напр. "Ollama API"
    abstract protected function label(): string;

    public function chat(string $systemPrompt, string $userMessage, int $timeout): string
    {
        try {
            return $this->request($systemPrompt, $userMessage, $timeout);
        } catch (TransientProviderException $e) {
            echo "[{$this->label()}] Временная ошибка: {$e->getMessage()}. Повтор через "
                . self::RETRY_DELAY_SECONDS . ' сек...' . PHP_EOL;
            sleep(self::RETRY_DELAY_SECONDS);

            return $this->request($systemPrompt, $userMessage, $timeout);
        }
    }

    private function request(string $systemPrompt, string $userMessage, int $timeout): string
    {
        $payload = json_encode([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new TransientProviderException("{$this->label()} cURL error: $err");
        }
        if ($code !== 200) {
            $message = "{$this->label()} вернул HTTP $code: $body";
            if ($this->isTransient($code, $body)) {
                throw new TransientProviderException($message);
            }
            throw new RuntimeException($message);
        }

        $data    = json_decode($body, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new RuntimeException("{$this->label()}: не удалось разобрать ответ: $body");
        }

        return trim($content);
    }

    // 429/5xx и явные "модель временно недоступна" в теле ответа — стоит повторить.
    // Остальное (401 неверный ключ, 400 некорректный запрос и т.п.) — нет, повтор не поможет.
    private function isTransient(int $code, string $body): bool
    {
        if ($code === 429 || $code >= 500) {
            return true;
        }

        return stripos($body, 'temporarily unavailable') !== false;
    }
}
