<?php

declare(strict_types=1);

use CasesBot\Catalog\SlideMatcher;

function row(int $id, int $slideNumber, ?string $title, ?string $content, ?string $hash = null): array
{
    return [
        'id' => $id,
        'slide_number' => $slideNumber,
        'title' => $title,
        'content' => $content,
        'content_hash' => $hash ?? SlideMatcher::contentHash($title, (string) $content),
    ];
}

function slide(int $number, string $title, string $text): array
{
    return [
        'slide_number' => $number,
        'title' => $title,
        'text' => $text,
        'notes' => '',
        'is_case' => true,
        'images' => [],
        'comments' => '',
    ];
}

test('неизменённый слайд сопоставляется по позиции и хэшу', function (): void {
    $matcher = new SlideMatcher();
    $slides = [slide(1, 'Кейс: ритейл', 'Текст про ритейл')];
    $rows = [row(10, 1, 'Кейс: ритейл', 'Текст про ритейл')];

    $plan = $matcher->match($slides, $rows);

    assertSame([1 => 10], $plan['matched']);
    assertSame([], $plan['new']);
    assertSame([], $plan['removed']);
});

test('переставленный слайд узнаётся по content_hash, позиция обновляется', function (): void {
    $matcher = new SlideMatcher();
    // В каталоге слайд №2 (id 11); в новой версии файла он стал слайдом №5.
    $rows = [
        row(10, 1, 'Обложка', 'О компании'),
        row(11, 2, 'Кейс: 1с', 'Текст кейса про 1с'),
    ];
    $slides = [
        slide(1, 'Обложка', 'О компании'),
        slide(2, 'Что-то новое', 'Новый слайд без пары'),
        slide(3, 'Титул', 'Титульный текст'),
        slide(5, 'Кейс: 1с', 'Текст кейса про 1с'),
    ];

    $plan = $matcher->match($slides, $rows);

    assertSame([1 => 10, 5 => 11], $plan['matched']);
    assertSame([2, 3], $plan['new']);
    assertSame([], $plan['removed']);
});

test('отредактированный слайд остаётся тем же кейсом по заголовку', function (): void {
    $matcher = new SlideMatcher();
    $rows = [row(7, 3, 'Кейс: логистика', 'Старый текст кейса про логистику')];
    $slides = [slide(3, 'Кейс: логистика', 'Обновлённый текст кейса, добавили цифры и результат')];

    $plan = $matcher->match($slides, $rows);

    assertSame([3 => 7], $plan['matched']);
    assertSame([], $plan['new']);
});

test('радикально переписанный слайд считается новым, старая строка — удалённой', function (): void {
    $matcher = new SlideMatcher();
    $rows = [row(7, 3, 'Кейс: логистика', 'Совершенно другой текст про склады и паллеты')];
    $slides = [slide(3, 'Кейс: банки', 'Кейс о внедрении в банке, совершенно новый текст')];

    $plan = $matcher->match($slides, $rows);

    assertSame([], $plan['matched']);
    assertSame([3], $plan['new']);
    assertSame([7], $plan['removed']);
});

test('дубли слайдов сопоставляются взаимно однозначно', function (): void {
    $matcher = new SlideMatcher();
    $rows = [row(7, 1, 'Дубль', 'Одинаковый текст')];
    $slides = [
        slide(1, 'Дубль', 'Одинаковый текст'),
        slide(2, 'Дубль', 'Одинаковый текст'),
    ];

    $plan = $matcher->match($slides, $rows);

    // Первый занимает существующую строку, второй становится новой записью.
    assertSame([1 => 7], $plan['matched']);
    assertSame([2], $plan['new']);
    assertSame([], $plan['removed']);
});

test('content_hash устойчив к регистру и пробелам', function (): void {
    assertSame(
        SlideMatcher::contentHash('Кейс: Ритейл', 'Текст  с  пробелами'),
        SlideMatcher::contentHash('кейс: ритейл', 'текст с пробелами')
    );
});
