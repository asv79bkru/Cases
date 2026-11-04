<?php

declare(strict_types=1);

namespace CasesBot\Storage;

use RuntimeException;

/**
 * Скачивает презентации из Google Drive под сервисным аккаунтом (§6.2 ТЗ).
 *
 * Авторизация — JWT сервисного аккаунта (RS256, openssl) -> OAuth2 access token,
 * без SDK: нам нужны только files.get + скачивание pptx. Право доступа к файлу/
 * папке выдаётся расшариванием на адрес аккаунта (casesbot@casesbot.iam.gserviceaccount.com).
 * Токен кэшируется на диске (истекает раньше, чем у Google — с запасом 60 секунд).
 *
 * Нативные файлы Google Slides скачиваются через экспорт в pptx (.../export/pptx);
 * загруженные на Drive оригинальные .pptx — как есть (alt=media).
 */
class GoogleDriveClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    private const API_FILES_URL = 'https://www.googleapis.com/drive/v3/files/';
    private const EXPORT_URL = 'https://docs.google.com/presentation/d/';

    private ?string $accessToken = null;

    /** Unix-time, когда кэшированный токен считается истёкшим (с запасом раньше реального). */
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private string $serviceAccountJsonPath,
        private string $tokenCachePath = '',
    ) {
    }

    /**
     * Метаданные файла Drive: name, mimeType, modifiedTime, md5Checksum (нативным Slides
     * не выдаётся), size (тоже может отсутствовать).
     *
     * @return array{name: string, mimeType: string, modifiedTime: string, md5Checksum: ?string, size: ?int}
     */
    public function getFileInfo(string $fileId): array
    {
        $response = $this->httpGetJson(
            self::API_FILES_URL . rawurlencode($fileId) . '?fields=name,mimeType,modifiedTime,md5Checksum,size'
        );

        return [
            'name' => (string) $response['name'],
            'mimeType' => (string) $response['mimeType'],
            'modifiedTime' => (string) $response['modifiedTime'],
            'md5Checksum' => $response['md5Checksum'] ?? null,
            'size' => isset($response['size']) ? (int) $response['size'] : null,
        ];
    }

    /**
     * Скачивает файл как .pptx в $destination (tmp->rename делается вызывающим кодом).
     * Возвращает размер записанного файла в байтах.
     */
    public function downloadAsPptx(string $fileId, string $mimeType, string $destination): int
    {
        $isNativeSlides = str_starts_with($mimeType, 'application/vnd.google-apps.presentation');
        $url = $isNativeSlides
            ? self::EXPORT_URL . rawurlencode($fileId) . '/export/pptx'
            : self::API_FILES_URL . rawurlencode($fileId) . '?alt=media';

        $bytes = $this->httpDownload($url, $destination);

        if ($bytes === 0) {
            throw new RuntimeException("Drive: пустой ответ при скачивании файла {$fileId}.");
        }

        $magic = (string) @file_get_contents($destination, false, null, 0, 2);
        if ($magic !== 'PK') {
            throw new RuntimeException("Drive: скачанный файл {$fileId} не похож на pptx (нет zip-сигнатуры).");
        }

        return $bytes;
    }

    // ------------------------------------------------------------------

    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time()) {
            return $this->accessToken;
        }

        if ($this->tokenCachePath !== '' && is_file($this->tokenCachePath)) {
            $cached = json_decode((string) file_get_contents($this->tokenCachePath), true);
            if (is_array($cached)
                && isset($cached['access_token'], $cached['expires_at'])
                && (int) $cached['expires_at'] > time()) {
                $this->accessToken = (string) $cached['access_token'];
                $this->accessTokenExpiresAt = (int) $cached['expires_at'];

                return $this->accessToken;
            }
        }

        $keyJson = json_decode((string) file_get_contents($this->serviceAccountJsonPath), true, 512, JSON_THROW_ON_ERROR);
        $privateKey = openssl_pkey_get_private((string) $keyJson['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Drive: не удалось прочитать private_key из JSON сервисного аккаунта.');
        }

        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $body = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            . '.' . $b64(json_encode([
                'iss' => (string) $keyJson['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'exp' => $now + 3600,
                'iat' => $now,
            ], JSON_THROW_ON_ERROR));

        $signature = '';
        if (!openssl_sign($body, $signature, $privateKey, 'sha256')) {
            throw new RuntimeException('Drive: не удалось подписать JWT (openssl_sign).');
        }
        $jwt = $body . '.' . $b64($signature);

        $tokenResponse = $this->httpPostForm(self::TOKEN_URL, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));

        if (!isset($tokenResponse['access_token'])) {
            throw new RuntimeException('Drive: токен не выдан: ' . json_encode($tokenResponse, JSON_UNESCAPED_UNICODE));
        }

        $this->accessToken = (string) $tokenResponse['access_token'];
        // expires_in у Google — секунды; 60 секунд берём как запас против расхождения часов.
        $this->accessTokenExpiresAt = $now + (int) max(120, ($tokenResponse['expires_in'] ?? 3600)) - 60;

        if ($this->tokenCachePath !== '') {
            $dir = dirname($this->tokenCachePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents(
                $this->tokenCachePath,
                json_encode([
                    'access_token' => $this->accessToken,
                    'expires_at' => $this->accessTokenExpiresAt,
                ], JSON_THROW_ON_ERROR)
            );
        }

        return $this->accessToken;
    }

    /** @return array<string, mixed> */
    private function httpGetJson(string $url): array
    {
        $token = $this->accessToken();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("Drive: сетевая ошибка ({$url}): {$error}");
        }
        if ($code !== 200) {
            throw new RuntimeException("Drive: HTTP {$code} на {$url}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Drive: некорректный JSON в ответе на {$url}.");
        }

        return $decoded;
    }

    /**
     * Скачивает файл, отдаёт размер в байтах.
     */
    private function httpDownload(string $url, string $destination): int
    {
        $token = $this->accessToken();
        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Drive: не открыть на запись {$destination}.");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($error !== '') {
            throw new RuntimeException("Drive: сетевая ошибка при скачивании ({$url}): {$error}");
        }
        if ($code !== 200) {
            @unlink($destination);
            throw new RuntimeException("Drive: HTTP {$code} при скачивании {$url}.");
        }

        return (int) (filesize($destination) ?: 0);
    }

    /** @return array<string, mixed> */
    private function httpPostForm(string $url, string $formBody): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $formBody,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("Drive: сетевая ошибка ({$url}): {$error}");
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
