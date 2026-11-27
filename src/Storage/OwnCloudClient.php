<?php

declare(strict_types=1);

namespace CasesBot\Storage;

use RuntimeException;

/**
 * Выгружает скачанные презентации в ownCloud (cloud.simbirsoft1.com) по WebDAV.
 *
 * PUT по адресу <корень WebDAV>/<каталог>/<имя> — если файл там уже есть,
 * ownCloud молча заменяет его содержимое (per-file overwrite, ревизии сохраняются).
 * Каталог, если его нет, создаётся каскадом MKCOL (409 у ownCloud означает
 * «нет промежуточного каталога» — поднимаемся от корня и создаём по одному).
 *
 * Учётные данные — Basic Auth из env (OWNCLOUD_USERNAME / OWNCLOUD_PASSWORD):
 * логин подходит как обычный, так и пароль приложения (Настройки → Безопасность),
 * пароль приложения предпочтительнее: его можно отозвать, не меняя основной пароль.
 */
class OwnCloudClient
{
    private const CONNECT_TIMEOUT_SEC = 15;
    private const UPLOAD_TIMEOUT_SEC = 300;

    public function __construct(
        private string $baseUrl,
        private string $username,
        private string $password,
    ) {
    }

    /** Есть ли достаточно настроек для выгрузки (без них — пропускаем, а не роняем sync). */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * Загружает локальный файл в ownCloud под тем же именем, с заменой прежнего.
     * $remotePath — путь относительно WebDAV-корня аккаунта, например
     * "Presales BA/Презентации БА/Кейсы направления Бизнес автоматизации.pptx".
     * Возвращает размер загруженного файла в байтах.
     */
    public function putFile(string $localPath, string $remotePath): int
    {
        $localPath = str_replace('\\', '/', $localPath);
        if (!is_file($localPath) || is_readable($localPath) === false) {
            throw new RuntimeException("ownCloud: локальный файл не найден/не читается: {$localPath}");
        }

        $bytes = (int) filesize($localPath);
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ownCloud: не открыть на чтение {$localPath}");
        }

        try {
            $this->ensureDirectory(implode('/', array_slice(explode('/', $remotePath), 0, -1)));

            // rawurlencode по сегментам: кириллица и пробелы в пути должны уйти в %XX,
            // а слэши между каталогами — остаться разделителями.
            $url = rtrim($this->baseUrl, '/') . '/'
                . implode('/', array_map(rawurlencode(...), explode('/', $this->normalizePath($remotePath))));

            // Файл отдаём потоком (CURLOPT_INFILE): pptx может быть 100+ МБ. Content-Length
            // curl ставит сам — дублирующий заголовок ownCloud отвечает на него HTTP 400.
            $code = $this->request($url, 'PUT', 'Content-Type: application/octet-stream', null, $stream);
        } finally {
            fclose($stream);
        }

        if ($code !== 201 && $code !== 204) {
            throw new RuntimeException("ownCloud: HTTP {$code} при PUT «{$remotePath}» (ожидались 201/204).");
        }

        return $bytes;
    }

    /** Проверка доступа (PROPFIND по корню) — для ручной проверки настроек из CLI. */
    public function verifyConnection(): void
    {
        $code = $this->request(rtrim($this->baseUrl, '/') . '/', 'PROPFIND', 'Depth: 0', '');
        if ($code !== 207) {
            throw new RuntimeException("ownCloud: HTTP {$code} на PROPFIND (ожидался 207) — проверьте логин/пароль.");
        }
    }

    /** Создаёт каталог и всех предков, если их ещё нет (MKCOL; 405/409 обрабатываются). */
    private function ensureDirectory(string $dir): void
    {
        $dir = $this->normalizePath($dir);
        if ($dir === '') {
            return;
        }

        $segments = explode('/', $dir);
        for ($i = 1, $n = count($segments); $i <= $n; $i++) {
            $url = rtrim($this->baseUrl, '/') . '/'
                . implode('/', array_map(rawurlencode(...), array_slice($segments, 0, $i)));

            $code = $this->request($url, 'MKCOL', '', '');

            if ($code === 201) {
                continue; // создан
            }
            if ($code === 405) {
                continue; // уже существует — то, что нужно
            }
            if ($code === 409) {
                throw new RuntimeException("ownCloud: HTTP 409 при MKCOL «{$dir}» — нет родительского каталога.");
            }

            throw new RuntimeException("ownCloud: HTTP {$code} при MKCOL «{$dir}».");
        }
    }

    private function request(string $url, string $method, string $extraHeader, ?string $body, $inputStream = null): int
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
        ];
        if ($extraHeader !== '') {
            $headers[] = $extraHeader;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SEC,
            CURLOPT_TIMEOUT => self::UPLOAD_TIMEOUT_SEC,
        ]);
        if ($inputStream !== null) {
            curl_setopt($ch, CURLOPT_INFILE, $inputStream);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("ownCloud: сетевая ошибка ({$method} {$url}): {$error}");
        }

        return $code;
    }

    /** Убирает ведущие слэши и «пустые» сегменты, оставляя единый формат «a/b/c». */
    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return trim(preg_replace('#/+#', '/', $path), '/');
    }
}
