<?php

declare(strict_types=1);

/**
 * Проверка источника презентаций: выводит список .pptx в папке PRESENTATIONS_FOLDER_PATH (.env).
 *
 * Запуск: php bin/presentations-list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Storage\LocalPresentationsClient;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

if ($config['presentations']['folder_path'] === '') {
    fwrite(STDERR, "PRESENTATIONS_FOLDER_PATH не задан в .env\n");
    exit(1);
}

$client = new LocalPresentationsClient($config['presentations']['folder_path']);

foreach ($client->listPresentations() as $file) {
    printf("%s\t%s\n", $file['modifiedTime'], $file['name']);
}
