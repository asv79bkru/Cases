<?php

declare(strict_types=1);

/**
 * Мини-раннер тестов без внешних зависимостей (PHPUnit в проекте не используется).
 * Запуск: php tests/run-tests.php
 * Каждый файл tests/Unit/*Test.php регистрирует тесты через test() и содержит проверки
 * на обычных assert*-функциях из этого файла.
 */

require __DIR__ . '/../vendor/autoload.php';

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function fail(string $message): void
{
    throw new RuntimeException($message);
}

/** @param mixed $expected @param mixed $actual */
function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '')
            . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        fail($message !== '' ? $message : 'условие ложно');
    }
}

function assertFalse(bool $condition, string $message = ''): void
{
    assertTrue(!$condition, $message);
}

/** Рекурсивно сравнивает массивы (порядок ключей не важен). */
function assertSameSet(array $expected, array $actual, string $message = ''): void
{
    sort($expected);
    sort($actual);
    assertSame($expected, $actual, $message);
}

require __DIR__ . '/Unit/SlideMatcherTest.php';
require __DIR__ . '/Unit/CatalogRepositoryTest.php';

$failed = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        fwrite(STDOUT, "OK   {$name}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "FAIL {$name}\n     {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, '----' . "\n" . ($failed === 0 ? 'Все тесты прошли.' : "Провалено: {$failed}") . "\n");
exit($failed === 0 ? 0 : 1);
