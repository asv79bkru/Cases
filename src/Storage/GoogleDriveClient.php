<?php

declare(strict_types=1);

namespace CasesBot\Storage;

/**
 * Скачивает исходные презентации и их обновления из папки на Google Drive (§6.2 ТЗ).
 *
 * Авторизация — сервисный аккаунт (§8 «Открытые вопросы»): JSON-ключ обменивается на
 * access token напрямую через Google OAuth2 token endpoint (JWT Bearer, RS256), без
 * SDK google/apiclient — только ext-curl/ext-openssl, тем же стилем, что и VkTeamsClient.
 * Папку нужно расшарить сервисному аккаунту (client_email из JSON-ключа) на чтение.
 */
class GoogleDriveClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    private const DRIVE_API = 'https://www.googleapis.com/drive/v3';
    private const PRESENTATION_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private string $serviceAccountPath,
        private string $folderId,
    ) {
    }

    /**
     * Презентации (.pptx) в папке: id, имя, дата изменения — Indexer сверяет с каталогом,
     * чтобы понять, какие файлы новые/обновились с прошлой индексации.
     *
     * @return array<int, array{id: string, name: string, modifiedTime: string}>
     */
    public function listPresentations(): array
    {
        $files = [];
        $pageToken = null;

        do {
            $query = [
                'q' => sprintf(
                    "'%s' in parents and mimeType='%s' and trashed=false",
                    $this->folderId,
                    self::PRESENTATION_MIME
                ),
                'fields' => 'nextPageToken, files(id, name, modifiedTime)',
                'pageSize' => 100,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request(self::DRIVE_API . '/files?' . http_build_query($query));
            $files = array_merge($files, $response['files'] ?? []);
            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $files;
    }

    /** Скачивает файл презентации целиком в $destinationPath (обычно storage/incoming). */
    public function downloadFile(string $fileId, string $destinationPath): void
    {
        $url = self::DRIVE_API . "/files/{$fileId}?alt=media&supportsAllDrives=true";

        $fh = fopen($destinationPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Не удалось открыть {$destinationPath} для записи");
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->getAccessToken()],
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            unlink($destinationPath);
            throw new \RuntimeException("Не удалось скачать файл {$fileId} (HTTP {$status}): {$error}");
        }
    }

    /** @return array<string, mixed> */
    private function request(string $url): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->getAccessToken()],
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new \RuntimeException("Google Drive API request failed: {$error}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || $status >= 400) {
            throw new \RuntimeException("Google Drive API error (HTTP {$status}): {$body}");
        }

        return $decoded;
    }

    /** Access token сервисного аккаунта (JWT Bearer flow), кэшируется в памяти до истечения. */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $raw = file_get_contents($this->serviceAccountPath);
        $credentials = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($credentials) || !isset($credentials['private_key'], $credentials['client_email'])) {
            throw new \RuntimeException("Некорректный файл сервисного аккаунта: {$this->serviceAccountPath}");
        }

        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();

        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode((string) json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        $signed = openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new \RuntimeException('Не удалось подписать JWT сервисного аккаунта');
        }

        $jwt = sprintf('%s.%s.%s', $header, $claims, $this->base64UrlEncode($signature));

        $curl = curl_init($tokenUri);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = $body !== false ? json_decode($body, true) : null;
        if (!is_array($decoded) || !isset($decoded['access_token']) || $status >= 400) {
            throw new \RuntimeException("Не удалось получить access token Google (HTTP {$status}): {$body}");
        }

        $this->accessToken = $decoded['access_token'];
        $this->accessTokenExpiresAt = $now + (int) ($decoded['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
