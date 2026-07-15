<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

use CasesBot\Presentation\SlideTextExtractor;
use CasesBot\Storage\LocalPresentationsClient;

/**
 * Обходит презентации (временно — локальная папка на сервере, см. LocalPresentationsClient;
 * интеграция с Google Drive отложена), через SlideTextExtractor предлагает теги,
 * сохраняет в каталог только после подтверждения эксперта (§5.1.8 ТЗ).
 */
class Indexer
{
}
