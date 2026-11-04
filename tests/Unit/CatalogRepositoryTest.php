<?php

declare(strict_types=1);

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\SlideMatcher;

/** Старая схема (до переезда разметки в веб) — на ней проверяется миграция ensureSchema. */
function createOldSchemaDatabase(string $path): void
{
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }

    $pdo = new \PDO('sqlite:' . $path);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_file_id TEXT NOT NULL,
            source_file_name TEXT NOT NULL,
            slide_number INTEGER NOT NULL,
            title TEXT,
            content TEXT,
            site_url TEXT,
            is_hidden INTEGER NOT NULL DEFAULT 0,
            added_at TEXT NOT NULL,
            UNIQUE (source_file_id, slide_number)
        );
        CREATE TABLE case_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            case_id INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
            file_path TEXT NOT NULL
        );
        CREATE TABLE tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );
        CREATE TABLE case_tags (
            case_id INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
            tag_id INTEGER NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
            PRIMARY KEY (case_id, tag_id)
        );
    ");

    // Слайд-кейс с тегами (эксперт подтверждал при старом процессе) — миграция должна
    // признать его размеченным; обложек в старой базе не было вовсе.
    $pdo->exec("INSERT INTO cases (source_file_id, source_file_name, slide_number, title, content, added_at)
                VALUES ('кейсы.pptx', 'кейсы.pptx', 2, 'Кейс: ритейл', 'Текст', '2026-01-01T00:00:00+00:00')");
    $pdo->exec("INSERT INTO tags (name) VALUES ('ритейл')");
    $pdo->exec('INSERT INTO case_tags (case_id, tag_id) VALUES (1, 1)');
}

function makeRepository(string $path): CatalogRepository
{
    return new CatalogRepository($path, __DIR__ . '/../../storage/catalog/schema.sql', null);
}

/** Закрывает соединение SQLite и удаляет файл базы (Windows держит файл, пока PDO жив). */
function dropRepository(?CatalogRepository $catalog, string $path): void
{
    $catalog = null;
    gc_collect_cycles();
    if (is_file($path)) {
        @unlink($path);
    }
}

test('миграция старой базы: новые колонки и tags_reviewed по уже существующим тегам', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    createOldSchemaDatabase($path);

    $catalog = makeRepository($path);

    $case = $catalog->caseById(1);
    assertTrue($case !== null);
    assertSame(0, (int) $case['is_not_case']);
    assertSame(1, (int) $case['tags_reviewed'], 'кейс с тегами из старого процесса считается размеченным');
    assertSame(['ритейл'], $catalog->tagsForCase(1));

    dropRepository($catalog, $path);
});

test('переиндексация сохраняет экспертные теги, метки «не кейс» и переживает перестановку слайдов', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $addedAt = date(DATE_ATOM);

    // Исходный порядок: слайд 1 — обложка (не кейс), слайд 2 — кейс с экспертными тегами.
    $coverId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Обложка', 'О компании', [], [], $addedAt, null, false, null, SlideMatcher::contentHash('Обложка', 'О компании'), true),
        true,
        []
    );
    $caseId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 2, 'Кейс: авто', 'Текст кейса', [], [], $addedAt, null, false, null, SlideMatcher::contentHash('Кейс: авто', 'Текст кейса'), false),
        false,
        []
    );
    $catalog->setCaseTags($caseId, ['автопром', '1с']);
    $catalog->attachTags($coverId, ['ит'], 'notes');

    // Переиндексация: слайды переставили местами (кейс стал первым), обложка — вторым.
    $catalog->stageSlidePositionsForReindex('f.pptx');
    $catalog->updateCaseContent($caseId, new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс: авто', 'Текст кейса', [], [], $addedAt, null, false, null, SlideMatcher::contentHash('Кейс: авто', 'Текст кейса'), false));
    $catalog->updateCaseContent($coverId, new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 2, 'Обложка', 'О компании', [], [], $addedAt, null, false, null, SlideMatcher::contentHash('Обложка', 'О компании'), true));

    // Теги приплыли вместе со слайдом, «не кейс» остался у обложки.
    assertSameSet(['автопром', '1с'], $catalog->tagsForCase($caseId));
    assertSame(['ит'], $catalog->tagsForCase($coverId));
    assertSame(1, (int) $catalog->caseById($coverId)['is_not_case']);
    assertSame(1, (int) $catalog->caseById($caseId)['tags_reviewed']);
    assertSame(1, (int) $catalog->caseById($caseId)['slide_number']);

    // В поиске по тегам обложка не участвует.
    assertSame(1, count($catalog->findByTags(['автопром', 'ит'])));
    assertSame([], array_map('intval', array_column($catalog->findByTags(['ит']), 'id')), 'тег обложки-«не кейс» не находится в поиске кейсов');

    dropRepository($catalog, $path);
});

test('setCaseTags заменяет набор целиком, включая удаление старых тегов', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $caseId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Кейс', 'Текст'), false),
        false,
        ['ритейл', 'старый-тег']
    );

    $catalog->setCaseTags($caseId, ['ритейл', 'новый-тег']);

    assertSameSet(['ритейл', 'новый-тег'], $catalog->tagsForCase($caseId));
    assertSame(1, (int) $catalog->caseById($caseId)['tags_reviewed']);

    // Пустой набор легален: эксперт подтвердил, что тегов нет.
    $catalog->setCaseTags($caseId, []);
    assertSame([], $catalog->tagsForCase($caseId));
    assertSame(1, (int) $catalog->caseById($caseId)['tags_reviewed']);

    dropRepository($catalog, $path);
});

test('очередь предложений: без дублей, отклонённое не предлагается повторно, приёмка делает тег экспертным', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $caseId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Кейс', 'Текст'), false),
        false,
        ['erp']
    );

    // Уже присвоенный тег не предлагается; повторный вызов не плодит дубли.
    $catalog->refreshSuggestions($caseId, ['erp', 'ритейл'], ['Ритейл', '1с']);
    $pending = $catalog->pendingSuggestionsForCase($caseId);
    assertSameSet(['ритейл', '1с'], array_column($pending, 'tag'));

    // Слайд изменился — pending-очередь пересоздаётся из свежих предложений.
    $catalog->refreshSuggestions($caseId, ['ритейл'], ['wms']);
    $pending = $catalog->pendingSuggestionsForCase($caseId);
    assertSameSet(['ритейл', 'wms'], array_column($pending, 'tag'));

    // Отклонённое не возвращается при следующем обновлении предложений.
    $reject = array_values(array_filter($pending, fn (array $s): bool => $s['tag'] === 'wms'))[0];
    $catalog->resolveSuggestion($reject['id'], false);
    $catalog->refreshSuggestions($caseId, ['ритейл'], ['wms', 'кабинет']);
    assertSameSet(['ритейл', 'кабинет'], array_column($catalog->pendingSuggestionsForCase($caseId), 'tag'));

    // Принятые предложения становятся экспертными тегами и снимаются с очереди.
    $accept = array_values(array_filter($catalog->pendingSuggestionsForCase($caseId), fn (array $s): bool => $s['tag'] === 'ритейл'))[0];
    $catalog->resolveSuggestion($accept['id'], true);

    assertSameSet(['erp', 'ритейл'], $catalog->tagsForCase($caseId));
    assertSameSet(['erp'], $catalog->tagsForCase($caseId, 'notes'));
    assertSameSet(['ритейл'], $catalog->tagsForCase($caseId, 'expert'));
    assertSame(1, (int) $catalog->caseById($caseId)['tags_reviewed']);
    assertSameSet(['кабинет'], array_column($catalog->pendingSuggestionsForCase($caseId), 'tag'));

    dropRepository($catalog, $path);
});

test('переименование тега в имя существующего выполняет слияние', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $caseId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Кейс', 'Текст'), false),
        false,
        ['авто']
    );
    $caseId2 = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 2, 'Кейс 2', 'Текст 2', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Кейс 2', 'Текст 2'), false),
        false,
        ['автопром']
    );

    // «авто» -> «автопром»: тег-дубль исчезает, кейсы объединяются на целевом теге.
    $autoId = tagIdByName($catalog, 'авто');
    assertSame('merged', $catalog->renameTag($autoId, 'автопром'));

    assertSameSet(['автопром'], $catalog->tagsForCase($caseId));
    assertSameSet(['автопром'], $catalog->tagsForCase($caseId2));

    $names = array_column($catalog->allTagsWithCounts(), 'name');
    assertFalse(in_array('авто', $names, true), 'тег-дубль удалён после слияния');

    dropRepository($catalog, $path);
});

function tagIdByName(CatalogRepository $catalog, string $name): int
{
    foreach ($catalog->allTagsWithCounts() as $tag) {
        if ($tag['name'] === $name) {
            return (int) $tag['id'];
        }
    }

    fail("тег {$name} не найден");
}

test('pruneSlidesOfFile удаляет исчезнувшие слайды, pruneMissing — целые файлы', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $make = fn (int $slideNumber): CasesBot\Catalog\CaseItem => new CasesBot\Catalog\CaseItem(
        null, 'f.pptx', 'f.pptx', $slideNumber, "Слайд {$slideNumber}", 'Текст', [], [], date(DATE_ATOM),
        null, false, null, SlideMatcher::contentHash("Слайд {$slideNumber}", 'Текст'), false
    );
    $catalog->insertCase($make(1), false, []);
    $catalog->insertCase($make(2), false, []);
    $other = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'g.pptx', 'g.pptx', 1, 'Другой', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Другой', 'Текст'), false),
        false,
        []
    );

    $catalog->pruneSlidesOfFile('f.pptx', [2]);

    $remaining = array_map('intval', array_column($catalog->slideRowsForFile('f.pptx'), 'slide_number'));
    assertSame([2], $remaining);

    $catalog->pruneMissing(['f.pptx']);
    assertTrue($catalog->caseById($other) === null, 'слайды удалённого файла вычищены');

    dropRepository($catalog, $path);
});

test('syncNotesTags обновляет notes-теги, не трогая экспертные', function (): void {
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        dropRepository($catalog, $path);
    }
    $catalog = makeRepository($path);

    $caseId = $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Кейс', 'Текст'), false),
        false,
        ['ритейл', 'старый']
    );
    $catalog->setCaseTags($caseId, ['ритейл', 'экспертный']);

    // Заметки поправили: остался «ритейл», «старый» ушёл, появился «новый».
    $catalog->syncNotesTags($caseId, ['ритейл', 'новый']);

    assertSameSet(['ритейл', 'новый', 'экспертный'], $catalog->tagsForCase($caseId));
    assertSameSet(['ритейл', 'новый'], $catalog->tagsForCase($caseId, 'notes'));
    assertSameSet(['экспертный'], $catalog->tagsForCase($caseId, 'expert'));

    dropRepository($catalog, $path);
});
