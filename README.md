# CasesBot

Бот-сборщик кейс-презентаций для отдела продаж (VK Teams + PHP-монолит).
Полное описание задачи, требований и архитектуры — в [`ТЗ_бот-сборщик кейс-презентаций v1.1.md`](./ТЗ_бот-сборщик%20кейс-презентаций%20v1.1.md).

## Структура проекта

```
CasesBot/
├── bin/                # CLI-скрипты: index.php, poll.php (VK Teams), presentations-list.php
├── config/             # Config, справочник тегов (TagTaxonomy), .env
├── presentations/       # Исходные .pptx (кладутся через git — источник для LocalPresentationsClient)
├── public/              # Точка входа для вебхука VK Teams
├── python/              # SlideTextExtractor / SlideCloner (python-pptx, lxml) — вызываются из PHP как подпроцесс
├── src/
│   ├── Bot/             # ChatBotController, VkTeamsClient
│   ├── Catalog/         # CaseItem, CatalogRepository, TagTaxonomy, Indexer
│   ├── Query/           # QueryParser, Matcher
│   ├── Presentation/    # SlideTextExtractor, SlideCloner, PresentationBuilder (PHP-обёртки)
│   ├── Storage/         # LocalPresentationsClient (временно вместо Google Drive)
│   └── Config.php
├── storage/
│   ├── catalog/         # Каталог кейсов: catalog.sqlite + schema.sql (см. ниже)
│   ├── incoming/        # Резерв на будущее (если источник вернётся на скачивание, напр. Google Drive)
│   ├── output/          # Собранные pptx перед отправкой в чат
│   └── logs/            # Журнал запросов (P1)
├── tests/
│   ├── Unit/
│   └── Fixtures/        # Тестовые pptx для проверки сохранения форматирования
└── docs/                # Дополнительная документация по мере появления
```

## Этапы разработки (§9 ТЗ)

1. **Недели 1–2** — `Indexer`, `CatalogRepository`, `TagTaxonomy`: построение каталога, проверка тегирования/поиска через `bin/index.php`.
2. **Недели 3–4** — `SlideCloner`, `PresentationBuilder`: сборка pptx, интеграция с VK Teams Bot API (`public/index.php`).
3. **Неделя 5+** — пилот на реальных запросах, донастройка справочника тегов и лимитов.
