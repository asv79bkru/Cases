-- Схема каталога кейсов (SQLite), см. §5.1.9 и §6.2 ТЗ.
-- БД: storage/catalog/catalog.sqlite (путь задаётся в config/config.php -> catalog.storage_path).
-- Применение: sqlite3 storage/catalog/catalog.sqlite < storage/catalog/schema.sql

PRAGMA foreign_keys = ON;

-- ============================================================
-- P0 — обязательный минимум каталога (§5.1.9)
-- ============================================================

-- Кейсы (DTO CaseItem, §6.2): исходный файл, номер слайда, заголовок, дата добавления.
CREATE TABLE IF NOT EXISTS cases (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    source_file_id    TEXT    NOT NULL,             -- id файла на Google Drive
    source_file_name  TEXT    NOT NULL,             -- человекочитаемое имя презентации (для UI индексации)
    slide_number      INTEGER NOT NULL,             -- номер слайда в исходной презентации
    title             TEXT,                          -- заголовок слайда (подсказка от SlideTextExtractor)
    is_hidden         INTEGER NOT NULL DEFAULT 0,    -- P1 §5.2.3: "не показывать", без удаления из каталога
    added_at          TEXT    NOT NULL,              -- ISO 8601, момент подтверждения экспертом

    UNIQUE (source_file_id, slide_number)            -- повторная индексация той же презентации не создаёт дублей
);

-- Справочник тегов. Канонические имена и синонимы поддерживаются в config/tags.php (TagTaxonomy);
-- здесь хранятся только канонические теги, реально присвоенные кейсам.
CREATE TABLE IF NOT EXISTS tags (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT    NOT NULL CHECK (category IN ('industry', 'product', 'technology', 'client')),
    name     TEXT    NOT NULL,                       -- канонический тег (после нормализации синонимов)

    UNIQUE (category, name)
);

-- Многие-ко-многим: теги, подтверждённые экспертом для конкретного кейса при индексации.
CREATE TABLE IF NOT EXISTS case_tags (
    case_id INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,

    PRIMARY KEY (case_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_case_tags_tag_id ON case_tags (tag_id);

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
