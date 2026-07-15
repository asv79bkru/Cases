<?php

declare(strict_types=1);

/**
 * CLI-инструмент индексации (§5.1.8, Этап 1 из §9 ТЗ).
 * Обходит презентации на Google Drive, предлагает теги через SlideTextExtractor,
 * сохраняет в CatalogRepository только после подтверждения эксперта.
 */

require __DIR__ . '/../vendor/autoload.php';
