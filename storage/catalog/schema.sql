-- Схема каталога кейсов (SQLite), см. §5.1.9 и §6.2 ТЗ.
-- БД: storage/catalog/catalog.sqlite (путь задаётся в config/config.php -> catalog.storage_path).
-- Применение: sqlite3 storage/catalog/catalog.sqlite < storage/catalog/schema.sql

PRAGMA foreign_keys = ON;

-- ============================================================
-- P0 — обязательный минимум каталога (§5.1.9)
-- ============================================================

-- Кейсы (DTO CaseItem, §6.2): исходный файл, номер слайда, заголовок, дата добавления.
-- Начиная с версии «разметка в веб-интерфейсе» в cases лежат ВСЕ слайды презентаций,
-- а не только кейсы: is_not_case = 1 помечает служебные слайды (обложка, о компании,
-- контакты), отмечается экспертом в веб-интерфейсе. Метки «не кейс», теги и
-- tags_reviewed переживают переиндексацию (сопоставление слайдов по content_hash,
-- см. SlideMatcher); контент и reviewer_comments — исходные данные, обновляются всегда.
CREATE TABLE IF NOT EXISTS cases (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    source_file_id    TEXT    NOT NULL,             -- id файла на Google Drive
    source_file_name  TEXT    NOT NULL,             -- человекочитаемое имя презентации (для UI индексации)
    slide_number      INTEGER NOT NULL,             -- номер слайда в исходной презентации
    title             TEXT,                          -- заголовок слайда (подсказка от SlideTextExtractor)
    content           TEXT,                          -- полный текст слайда (все текстовые блоки)
    reviewer_comments TEXT,                          -- текст комментариев рецензентов к слайду (есть в pptx, наружу не отдаётся)
    content_hash      TEXT,                          -- sha1 нормализованного текста — стабильное сопоставление слайдов при переиндексации
    slide_object_id   TEXT,                          -- резерв: стабильный objectId слайда из Google Slides API
    site_url          TEXT,                          -- необязательная ссылка на сайт (например, кейс на сайте компании)
    is_hidden         INTEGER NOT NULL DEFAULT 0,    -- P1 §5.2.3: "не показывать", без удаления из каталога
    is_not_case       INTEGER NOT NULL DEFAULT 0,    -- инверсная метка «это не кейс» (обложка, о компании, контакты) — из веб-интерфейса
    tags_reviewed     INTEGER NOT NULL DEFAULT 0,    -- теги подтверждены экспертом (веб-интерфейс); не reviewed — из заметок докладчика
    added_at          TEXT    NOT NULL,              -- ISO 8601, момент подтверждения экспертом

    UNIQUE (source_file_id, slide_number)            -- повторная индексация той же презентации не создаёт дублей
);

-- Картинки, извлечённые со слайда (файлы — в storage/catalog/images/, путь тут относительный).
CREATE TABLE IF NOT EXISTS case_images (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id   INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
    file_path TEXT    NOT NULL                        -- относительный путь внутри storage/catalog/images/
);

CREATE INDEX IF NOT EXISTS idx_case_images_case_id ON case_images (case_id);

-- Справочник тегов. Канонические имена и синонимы поддерживаются в config/tags.php (TagTaxonomy);
-- здесь хранятся только канонические теги, реально присвоенные кейсам.
CREATE TABLE IF NOT EXISTS tags (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT    NOT NULL UNIQUE                      -- канонический тег (после нормализации синонимов)
);

-- Многие-ко-многим: теги, присвоенные кейсу. source — откуда тег: 'notes' (перенесён
-- из заметок докладчика при индексации) или 'expert' (подтверждён в веб-интерфейсе).
-- Теги с source='expert' переиндексация не трогает никогда.
CREATE TABLE IF NOT EXISTS case_tags (
    case_id INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    source  TEXT    NOT NULL DEFAULT 'notes',           -- 'notes' | 'expert'

    PRIMARY KEY (case_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_case_tags_tag_id ON case_tags (tag_id);

-- Очередь предложенных тегов (словарь TagTaxonomy и LlmTagSuggester): предложение
-- становится тегом только после подтверждения эксперта в веб-интерфейсе.
-- UNIQUE (case_id, tag): отклонённое предложение не предлагается повторно.
CREATE TABLE IF NOT EXISTS tag_suggestions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id    INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
    tag        TEXT    NOT NULL,                       -- каноническая форма (после TagTaxonomy::normalize)
    source     TEXT    NOT NULL,                       -- 'dict' | 'llm'
    status     TEXT    NOT NULL DEFAULT 'pending',     -- 'pending' | 'accepted' | 'rejected'
    created_at TEXT    NOT NULL,

    UNIQUE (case_id, tag)
);

-- ============================================================
-- P1 — статистика запросов и журнал сборок (§5.2.1, §5.2.4)
-- Не блокирует запуск v1, но использует те же таблицы cases/tags, поэтому фиксируется сразу.
-- ============================================================

-- Журнал собранных подборок: кто, когда, что запросил, что получил.
CREATE TABLE IF NOT EXISTS query_log (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    requested_at      TEXT    NOT NULL,              -- ISO 8601
    chat_id           TEXT    NOT NULL,              -- чат/тред VK Teams, куда отправлен ответ
    user_id           TEXT,                          -- отправитель запроса (если доступно из вебхука)
    query_text        TEXT    NOT NULL,              -- исходный текст запроса пользователя
    matched_case_ids  TEXT,                          -- JSON-массив id кейсов в подборке (для повторной генерации)
    result_file_path  TEXT,                          -- путь к собранному pptx в storage/output
    status            TEXT    NOT NULL CHECK (status IN ('ok', 'no_results', 'error'))
);

-- Теги, распознанные QueryParser в конкретном запросе — основа отчёта "что запрашивают чаще всего".
CREATE TABLE IF NOT EXISTS query_log_tags (
    query_log_id INTEGER NOT NULL REFERENCES query_log (id) ON DELETE CASCADE,
    tag_id       INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,

    PRIMARY KEY (query_log_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_query_log_tags_tag_id ON query_log_tags (tag_id);
CREATE INDEX IF NOT EXISTS idx_query_log_requested_at ON query_log (requested_at);
