# CasesBot

Бот-сборщик кейс-презентаций для отдела продаж (VK Teams + PHP-монолит).
Полное описание задачи, требований и архитектуры — в [`ТЗ_бот-сборщик кейс-презентаций v1.1.md`](./ТЗ_бот-сборщик%20кейс-презентаций%20v1.1.md).

## Структура проекта

```
CasesBot/
├── bin/                # CLI-скрипты: index.php, poll.php (VK Teams), presentations-list.php, build-test.php
├── config/             # Config, справочник тегов (TagTaxonomy), .env
├── presentations/       # Исходные .pptx — НЕ в git (см. .gitignore), заливаются на сервер отдельно при деплое
├── public/              # Точка входа для вебхука VK Teams
├── python/              # SlideTextExtractor / SlideCloner (python-pptx, lxml) — вызываются из PHP как подпроцесс
├── src/
│   ├── Api/Providers/   # LLM-провайдеры (Ollama, OpenCodeZen) для дополнения тегов кейсов
│   ├── Bot/             # ChatBotController, VkTeamsClient
│   ├── Catalog/         # CaseItem, CatalogRepository, TagTaxonomy, Indexer, LlmTagSuggester
│   ├── Query/           # QueryParser, Matcher
│   ├── Presentation/    # SlideTextExtractor, SlideCloner, PresentationBuilder (PHP-обёртки)
│   ├── Storage/         # LocalPresentationsClient (временно вместо Google Drive)
│   └── Config.php
├── storage/
│   ├── catalog/         # Каталог кейсов: catalog.sqlite + schema.sql + images/ (картинки со слайдов)
│   ├── incoming/        # Резерв на будущее (если источник вернётся на скачивание, напр. Google Drive)
│   ├── output/          # Собранные pptx перед отправкой в чат
│   └── logs/            # Журнал запросов (P1)
├── tests/
│   ├── Unit/
│   └── Fixtures/        # Тестовые pptx для проверки сохранения форматирования
└── docs/                # Дополнительная документация по мере появления
```

## Индексация каталога

`php bin/index.php` обходит `presentations/`, извлекает текст и картинки слайдов
(`python/slide_text_extractor.py`), но только для слайдов, помеченных экспертом меткой `#кейс#`
в заметках докладчика (не видна ни на слайде, ни в показе) — остальное (обложка, о компании,
контакты) пропускается автоматически.

Предложенные теги для помеченных слайдов собираются из трёх источников: (1) теги, которые эксперт
сам перечислил в тех же заметках после метки (`#кейс#\nтехнология:1с, индустрия:ритейл`) — им
доверяем больше всего; (2) LLM по содержимому слайда (`LlmTagSuggester` через `src/Api/Providers`,
провайдер и модель — в `.env`: `AI_PROVIDER`, `OLLAMA_*`/`OPENCODEZEN_*`); (3) простое совпадение
по словарю `config/tags.php`. Эксперт подтверждает/правит итог за один проход (Enter — принять
предложенное, `skip` — пропустить, свои теги — `категория:тег[,категория:тег...]`), плюс
необязательную ссылку на сайт (`site_url`) — LLM дополняет подсказку, не решает за эксперта (§5.1.8 ТЗ).

Полный текст сохраняется в `cases.content`, картинки — файлами в `storage/catalog/images/` со
ссылками в `case_images`. Повторный запуск обновляет те же записи, не создавая дублей.

## Сборка презентации

`SlideCloner` (`python/slide_cloner.py`) копирует слайды между презентациями на уровне сырых частей
OOXML-пакета (python-pptx opc/package API, не через объектную модель) — слайд, его layout, master,
theme и все media копируются как есть, с ремаппингом id/media/layout-master; заметки докладчика
(там могут быть внутренние `#кейс#`/теги эксперта) не копируются. Проверено: форматирование
(кастомные шрифты, цвета, размеры) сохраняется побайтово идентично оригиналу.

`PresentationBuilder` вызывает `SlideCloner` для нужных слайдов, затем сам добавляет титульный слайд
(тема + дата) и сохраняет финальный файл в `storage/output/`.

Проверка без чат-интеграции (Matcher ещё не реализован — прямой поиск по тегу):
```
php bin/build-test.php industry:ритейл
```

## Этапы разработки (§9 ТЗ)

1. **Недели 1–2** — `Indexer`, `CatalogRepository`, `TagTaxonomy`: построение каталога, проверка тегирования/поиска через `bin/index.php`. ✅
2. **Недели 3–4** — `SlideCloner`, `PresentationBuilder`: сборка pptx (готово, проверено на форматировании) ✅; интеграция с VK Teams Bot API (`QueryParser`/`Matcher` — ещё нет).
3. **Неделя 5+** — пилот на реальных запросах, донастройка справочника тегов и лимитов.
