<?php

declare(strict_types=1);

/**
 * Точка входа вебхука VK Teams (§6.1 ТЗ).
 * VkTeamsClient принимает вебхук -> ChatBotController -> QueryParser -> Matcher -> PresentationBuilder -> ответ.
 */

require __DIR__ . '/../vendor/autoload.php';
