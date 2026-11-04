<?php

declare(strict_types=1);

use CasesBot\Bot\Commands\CasesCommand;
use CasesBot\Bot\VkTeamsClient;
use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;
use CasesBot\Storage\LocalPresentationsClient;

/** Реквизит для parseQuery: нужны репозиторий (теги каталога) и словарь, остальное — заглушки. */
function makeCommand(string $sqlitePath): CasesCommand
{
    $taxonomy = new TagTaxonomy(__DIR__ . '/../../config/tags.php', __DIR__ . '/../../config/tag_relations.php');

    return new CasesCommand(
        new VkTeamsClient('', ''),
        new CatalogRepository($sqlitePath, __DIR__ . '/../../storage/catalog/schema.sql', null),
        new LocalPresentationsClient(sys_get_temp_dir()),
        $taxonomy,
        10,
        5
    );
}

function freshCommandDb(): string
{
    $path = sys_get_temp_dir() . '/casesbot-test-' . uniqid('', true) . '.sqlite';
    if (is_file($path)) {
        unlink($path);
    }

    return $path;
}

test('составной тег находится и с кавычками, и без', function (): void {
    $path = freshCommandDb();
    $command = makeCommand($path);

    assertSame(['честный знак'], $command->parseQuery('честный знак'));
    assertSame(['честный знак'], $command->parseQuery('"честный знак"'));
    assertSame(['честный знак'], $command->parseQuery('«честный знак»'));
    assertSame(['честный знак'], $command->parseQuery('"честный знак"'));

    dropRepository(null, $path);
});

test('составной тег в списке через запятую и вперемешку с одиночными', function (): void {
    $path = freshCommandDb();
    $command = makeCommand($path);

    assertSameSet(['честный знак', 'маркировка'], $command->parseQuery('честный знак, маркировка'));
    assertSameSet(['производство', '1с'], $command->parseQuery('производство, 1с'));

    dropRepository(null, $path);
});

test('жадный разбор: слова-связки не съедают соседние теги', function (): void {
    $path = freshCommandDb();
    $command = makeCommand($path);

    // «и» и прочий мусор между тегами молча пропускается.
    assertSameSet(['честный знак', 'маркировка'], $command->parseQuery('честный знак и маркировка'));
    assertSameSet(['ритейл'], $command->parseQuery('ритейл ритейл'));

    dropRepository(null, $path);
});

test('теги, которых нет в словаре, ищутся в каталоге; совсем неизвестные — пропускаются', function (): void {
    $path = freshCommandDb();
    $command = makeCommand($path);

    $catalog = new CatalogRepository($path, __DIR__ . '/../../storage/catalog/schema.sql', null);
    $catalog->insertCase(
        new CasesBot\Catalog\CaseItem(null, 'f.pptx', 'f.pptx', 1, 'Кейс', 'Текст', [], [], date(DATE_ATOM), null, false, null, CasesBot\Catalog\SlideMatcher::contentHash('Кейс', 'Текст'), false),
        false,
        ['автопром']
    );

    assertSame(['автопром'], $command->parseQuery('автопром'));
    assertSame([], $command->parseQuery('совершенно неизвестное'));

    dropRepository($catalog, $path);
});
