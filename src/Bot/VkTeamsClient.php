<?php

declare(strict_types=1);

namespace CasesBot\Bot;

/**
 * Адаптер приёма сообщений и отправки текста/файлов через VK Teams Bot API (§6.2 ТЗ).
 * Публичный API: https://myteam.mail.ru/bot/v1/ (long polling events/get, без вебхука).
 */
class VkTeamsClient
{
    public function __construct(
        private string $token,
        private string $apiUrl,
    ) {
    }

    /** Проверка токена и идентификация бота (self/get). */
    public function selfGet(): array
    {
        return $this->request('self/get');
    }

    /** Long polling: возвращает новые события начиная с $lastEventId. */
    public function getEvents(int $lastEventId = 0, int $pollTime = 30): array
    {
        $response = $this->request('events/get', [
            'lastEventId' => $lastEventId,
            'pollTime' => $pollTime,
        ]);

        return $response['events'] ?? [];
    }

    /** Отправляет текстовое сообщение в чат/тред, откуда пришёл запрос. */
    public function sendText(string $chatId, string $text): array
    {
        return $this->request('messages/sendText', [
            'chatId' => $chatId,
            'text' => $text,
        ]);
    }

    /** Отправляет файл (например, собранный pptx) в чат/тред. */
    public function sendFile(string $chatId, string $filePath, ?string $caption = null): array
    {
        return $this->request('messages/sendFile', array_filter([
            'chatId' => $chatId,
            'caption' => $caption,
        ]), ['file' => $filePath]);
    }

    /** @param array<string, mixed> $files Карта "имя поля" => "путь к файлу" */
    private function request(string $method, array $params = [], array $files = []): array
    {
        $params['token'] = $this->token;
        $url = rtrim($this->apiUrl, '/') . '/' . $method;

        $curl = curl_init();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ];

        if ($files === []) {
            $options[CURLOPT_URL] = $url . '?' . http_build_query($params);
        } else {
            $postFields = $params;
            foreach ($files as $field => $path) {
                $postFields[$field] = curl_file_create($path);
            }
            $options[CURLOPT_URL] = $url;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postFields;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);

        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("VK Teams API request failed ({$method}): {$error}");
        }

        curl_close($curl);

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("VK Teams API returned invalid JSON ({$method}): {$body}");
        }

        return $decoded;
    }
}
