<?php

declare(strict_types=1);

/**
 * Разовая миграция storage/catalog/catalog.sqlite (переход на плоские теги, см. schema.sql):
 * убирает category у tags, схлопывая дубли вроде industry:торговля / product:торговля
 * в один плоский тег "торговля".
 * Запуск: php bin/migrate_flatten_tags.php [путь к .sqlite]
 * Безопасно прогонять повторно — если колонки category уже нет, скрипт ничего не делает.
 */

$path = $argv[1] ?? __DIR__ . '/../storage/catalog/catalog.sqlite';

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = OFF');

$columns = $pdo->query("PRAGMA table_info(tags)")->fetchAll(PDO::FETCH_ASSOC);
$hasCategory = false;
foreach ($columns as $column) {
    if ($column['name'] === 'category') {
        $hasCategory = true;
        break;
    }
}

if (!$hasCategory) {
    fwrite(STDOUT, "У tags уже нет колонки category — миграция не нужна.\n");
    exit(0);
}

$pdo->beginTransaction();

$pdo->exec('CREATE TABLE tags_new (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)');

$oldTags = $pdo->query('SELECT id, name FROM tags ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$oldIdToNewId = [];
$nameToNewId = [];
foreach ($oldTags as $row) {
    $key = mb_strtolower($row['name']);
    if (!isset($nameToNewId[$key])) {
        $pdo->prepare('INSERT INTO tags_new (name) VALUES (:name)')->execute(['name' => $row['name']]);
        $nameToNewId[$key] = (int) $pdo->lastInsertId();
    }
    $oldIdToNewId[(int) $row['id']] = $nameToNewId[$key];
}

// Ссылается на "tags" (не "tags_new") напрямую: FK не проверяются немедленно (foreign_keys=OFF
// на время миграции), а к моменту переименования tags_new -> tags имя уже будет верным.
$pdo->exec('CREATE TABLE case_tags_new (
    case_id INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    PRIMARY KEY (case_id, tag_id)
)');
$insertCaseTag = $pdo->prepare('INSERT OR IGNORE INTO case_tags_new (case_id, tag_id) VALUES (:case_id, :tag_id)');
foreach ($pdo->query('SELECT case_id, tag_id FROM case_tags') as $row) {
    $insertCaseTag->execute([
        'case_id' => $row['case_id'],
        'tag_id' => $oldIdToNewId[(int) $row['tag_id']],
    ]);
}

$queryLogTagsExists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'query_log_tags'")->fetchColumn();
if ($queryLogTagsExists !== false) {
    $pdo->exec('CREATE TABLE query_log_tags_new (
        query_log_id INTEGER NOT NULL REFERENCES query_log (id) ON DELETE CASCADE,
        tag_id       INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
        PRIMARY KEY (query_log_id, tag_id)
    )');
    $insertQueryLogTag = $pdo->prepare('INSERT OR IGNORE INTO query_log_tags_new (query_log_id, tag_id) VALUES (:query_log_id, :tag_id)');
    foreach ($pdo->query('SELECT query_log_id, tag_id FROM query_log_tags') as $row) {
        $insertQueryLogTag->execute([
            'query_log_id' => $row['query_log_id'],
            'tag_id' => $oldIdToNewId[(int) $row['tag_id']],
        ]);
    }
    $pdo->exec('DROP TABLE query_log_tags');
    $pdo->exec('ALTER TABLE query_log_tags_new RENAME TO query_log_tags');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_query_log_tags_tag_id ON query_log_tags (tag_id)');
}

$pdo->exec('DROP TABLE case_tags');
$pdo->exec('ALTER TABLE case_tags_new RENAME TO case_tags');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_tags_tag_id ON case_tags (tag_id)');

$pdo->exec('DROP TABLE tags');
$pdo->exec('ALTER TABLE tags_new RENAME TO tags');

$pdo->commit();

$total = (int) $pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
fwrite(STDOUT, "Готово. Тегов после схлопывания дублей: {$total}.\n");
