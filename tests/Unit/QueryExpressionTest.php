<?php

declare(strict_types=1);

use CasesBot\Catalog\CaseItem;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\SlideMatcher;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Query\QueryParser;

/**
 * Общий парсер запроса (§5.1.2): чат и веб-интерфейс разметки должны распознавать теги
 * одинаково, а веб-дополнение — операторы И/ИЛИ.
 *
 * Хелперы freshCommandDb/dropRepository описаны в соседних тестах (общий процесс,
 * run-tests.php).
 */
function makeParser(string $sqlitePath): QueryParser
{
    $taxonomy = new TagTaxonomy(__DIR__ . '/../../config/tags.php', __DIR__ . '/../../config/tag_relations.php');

    return new QueryParser($taxonomy, new CatalogRepository($sqlitePath, __DIR__ . '/../../storage/catalog/schema.sql', null));
}

test('QueryParser распознаёт теги ровно как команда «кейсы» в чате', function (): void {
    $path = freshCommandDb();
    $parser = makeParser($path);

    assertSame(['честный знак'], $parser->parseQuery('честный знак'));
    assertSame(['честный знак'], $parser->parseQuery('«честный знак»'));
    assertSameSet(['итилиум'], $parser->parseQuery('1с:итилиум'));
    assertSameSet(['честный знак', 'маркировка'], $parser->parseQuery('честный знак, маркировка'));
    // В чистом разборе (чат) «и» между тегами — мусор, а не оператор: оба тега через ИЛИ.
    assertSameSet(['честный знак', 'маркировка'], $parser->parseQuery('честный знак и маркировка'));

    dropRepository(null, $path);
});

test('операторы И/ИЛИ разбираются в выражение с приоритетом (И сильнее ИЛИ)', function (): void {
    $path = freshCommandDb();
    $parser = makeParser($path);

    assertSame([[['ритейл'], ['1с']]], $parser->parseExpression('ритейл И 1с'));
    assertSame([[['ритейл'], ['1с']], [['маркировка']]], $parser->parseExpression('ритейл И 1с ИЛИ маркировка'));
    assertSame([[['ритейл'], ['1с']]], $parser->parseExpression('ритейл и 1с'));
    // Перенос строки и запятая — тоже ИЛИ.
    assertSame([[['ритейл'], ['1с']], [['маркировка']]], $parser->parseExpression("ритейл И 1с\nмаркировка"));
    assertSame([[['ритейл'], ['1с']]], $parser->parseExpression('ритейл & 1с'));
    // Оператор — только обособленное слово: «итилиум» не разбивается по «или».
    assertSame([[['итилиум']]], $parser->parseExpression('итилиум'));
    // Нераспознанные слова пропускаются, как в чате.
    assertSame([[['ритейл'], ['1с']]], $parser->parseExpression('ритейл И совершенно неизвестное И 1с'));
    assertSame([], $parser->parseExpression('совершенно неизвестное'));

    dropRepository(null, $path);
});

test('matchCaseIds: «И» пересекает, «ИЛИ» объединяет, «не кейсы» не находятся', function (): void {
    $path = freshCommandDb();
    $parser = makeParser($path);
    $catalog = new CatalogRepository($path, __DIR__ . '/../../storage/catalog/schema.sql', null);

    $retailErp = $catalog->insertCase(
        new CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Ритейл и 1С', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Ритейл и 1С', 'Текст'), false),
        false,
        ['ритейл', '1с']
    );
    $marking = $catalog->insertCase(
        new CaseItem(null, 'f.pptx', 'f.pptx', 2, 'Маркировка', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Маркировка', 'Текст'), false),
        false,
        ['маркировка']
    );
    // «Не кейс» с тегом «ритейл» — findByTags его не отдаёт, и фильтр не должен тоже.
    $catalog->insertCase(
        new CaseItem(null, 'f.pptx', 'f.pptx', 3, 'Обложка ритейла', 'Текст', [], [], date(DATE_ATOM), null, false, null, SlideMatcher::contentHash('Обложка ритейла', 'Текст'), false),
        true,
        ['ритейл']
    );

    // ИЛИ: любой из тегов; синоним «розница» дублит «ритейл» внутри условия.
    assertSame([$retailErp, $marking], $parser->matchCaseIds('ритейл ИЛИ маркировка')['ids']);
    assertSame([$retailErp, $marking], $parser->matchCaseIds('розница ИЛИ маркировка')['ids']);
    // И: строго оба тега.
    assertSame([$retailErp], $parser->matchCaseIds('ритейл И 1с')['ids']);
    assertSame([$retailErp], $parser->matchCaseIds('ритейл')['ids']);
    // Мусор в строгом списке выбрасывается (как в чате) — остаётся требование ритейл.
    assertSame([$retailErp], $parser->matchCaseIds('ритейл И совершенный мусор')['ids']);
    // Пересечение пусто — результатов нет.
    assertSame([], $parser->matchCaseIds('ритейл И маркировка')['ids']);

    dropRepository($catalog, $path);
});
