<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * DTO: исходный файл, номер слайда, заголовок, теги (индустрия/продукт/технология/клиент),
 * дата добавления, необязательная ссылка на сайт.
 * В ТЗ (§6.2) класс называется `Case` — переименован в `CaseItem`, так как `case` зарезервированное слово PHP.
 */
class CaseItem
{
    /** @param array<int, array{category: string, tag: string}> $tags */
    public function __construct(
        public ?int $id,
        public string $sourceFileId,
        public string $sourceFileName,
        public int $slideNumber,
        public ?string $title,
        public array $tags,
        public string $addedAt,
        public ?string $siteUrl = null,
        public bool $isHidden = false,
    ) {
    }
}
