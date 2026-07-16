<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * DTO: исходный файл, номер слайда, заголовок, полный текст слайда, картинки, теги
 * (индустрия/продукт/технология/клиент), дата добавления, необязательная ссылка на сайт.
 * В ТЗ (§6.2) класс называется `Case` — переименован в `CaseItem`, так как `case` зарезервированное слово PHP.
 */
class CaseItem
{
    /**
     * @param array<int, array{category: string, tag: string}> $tags
     * @param string[] $images Имена файлов картинок в storage/catalog/images/
     */
    public function __construct(
        public ?int $id,
        public string $sourceFileId,
        public string $sourceFileName,
        public int $slideNumber,
        public ?string $title,
        public ?string $content,
        public array $tags,
        public array $images,
        public string $addedAt,
        public ?string $siteUrl = null,
        public bool $isHidden = false,
    ) {
    }
}
