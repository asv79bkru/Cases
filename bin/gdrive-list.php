<?php

declare(strict_types=1);

/**
 * Проверка коннектора к Google Drive: выводит список презентаций (.pptx) в настроенной папке.
 * Требует .env: GOOGLE_DRIVE_CREDENTIALS_PATH (JSON-ключ сервисного аккаунта) и GOOGLE_DRIVE_FOLDER_ID
 * (папка должна быть расшарена на чтение для client_email из ключа).
 *
 * Запуск: php bin/gdrive-list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Storage\GoogleDriveClient;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

$config = require __DIR__ . '/../config/config.php';

if ($config['google_drive']['credentials_path'] === '' || $config['google_drive']['folder_id'] === '') {
    fwrite(STDERR, "GOOGLE_DRIVE_CREDENTIALS_PATH / GOOGLE_DRIVE_FOLDER_ID не заданы в .env\n");
    exit(1);
}

$client = new GoogleDriveClient(
    $config['google_drive']['credentials_path'],
    $config['google_drive']['folder_id']
);

foreach ($client->listPresentations() as $file) {
    printf("%s\t%s\t%s\n", $file['id'], $file['modifiedTime'], $file['name']);
}
