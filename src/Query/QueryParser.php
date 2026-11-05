<?php

declare(strict_types=1);

namespace CasesBot\Query;

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;

/**
 * Превращает текст запроса пользователя в структурированный набор тегов (§5.1.2 ТЗ) — общий
 * алгоритм для чата (CasesCommand) и веб-интерфейса разметки (public/tags.php).
 *
 * Распознавание тегов (parseQuery): запрос делится на фразы запятыми/переводами строк,
 * кавычки вокруг фразы необязательны (снимаются); слова сопоставляются жадно, начиная с
 * самых длинных последовательностей: «честный знак и маркировка» -> теги «честный знак»,
 * «маркировка». Каждый фрагмент ищется сначала в словаре синонимов (TagTaxonomy::normalize),
 * затем прямым совпадением по тегам каталога (CatalogRepository::findTagsByName). Узнавание
 * по одному короткому слову внутри составного тега не «съедает» его соседей: «знак» сам по
 * себе тегом не считается. Нераспознанные фрагменты молча пропускаются.
 *
 * Логическая структура запроса (parseExpression): слова-операторы «И» и «ИЛИ» (и аналоги
 * AND/OR/&/|) делят теги на условия: «И» соединяет строго (все теги обязательны), «ИЛИ»
 * и запятые/переводы строк — гибко (достаточно любого); «И» связывает сильнее «ИЛИ»,
 * как в SQL: «ритейл И 1с ИЛИ маркировка» = «ритейл И 1с» либо «маркировка». Выражение —
 * список ИЛИ-групп, каждая группа — список И-условий, каждое условие — список
 * распознанных тегов (внутри условия теги дублируют друг друга по ИЛИ: в чате «и» между
 * тегами — просто мусор, и здесь условие «честный знак, знак качества» сработает, если
 * на кейсе есть любой из них).
 */
class QueryParser
{
    public function __construct(
        private TagTaxonomy $tagTaxonomy,
        private ?CatalogRepository $catalog = null,
    ) {
    }

    /**
     * Разбивает запрос на фразы (через запятую/перевод строки) и распознаёт в них теги.
     * Формат и правила распознавания — в докблоке класса.
     *
     * @return string[]
     */
    public function parseQuery(string $query): array
    {
        $tags = [];

        foreach (preg_split('/[,\n]+/u', $query) as $part) {
            // Кавычки-ограничители вокруг фразы: «честный знак», "честный знак", „честный знак“.
            // Именно preg_replace, а не trim($part, «...»): trim работает по БАЙТАМ и вторые
            // байты UTF-8-кавычек (например, \xBB у ») ломают последнее слово фразы
            // («ритейл» -> «ритей\xD0»), после чего preg_split с /u возвращает false.
            $part = preg_replace('/^[\s«»„“”"\'’‘]+|[\s«»„“”"\'’‘]+$/u', '', $part);
            if ($part === null || $part === '') {
                continue;
            }

            $words = preg_split('/\s+/u', $part) ?: [];
            $position = 0;

            while ($position < count($words)) {
                $matched = false;

                // Самая длинная последовательность слов с текущей позиции — первый кандидат.
                for ($length = count($words) - $position; $length >= 1; $length--) {
                    $ngram = implode(' ', array_slice($words, $position, $length));

                    $found = $this->matchTag($ngram);
                    if ($found === null) {
                        continue;
                    }

                    $tags[mb_strtolower($found)] = $found;
                    $position += $length;
                    $matched = true;
                    break;
                }

                // Слово не распознано — сдвигаемся на одно слово и пробуем соседние цепочки.
                if (!$matched) {
                    $position++;
                }
            }
        }

        return array_values($tags);
    }

    /**
     * Запрос с операторами И/ИЛИ -> ИЛИ-группы И-условий:
     *      ритейл, маркировка         -> [ [[ритейл], [маркировка]] ]
     *      ритейл И 1с                -> [ [[ритейл], [1с]] ]
     *      ритейл И 1с ИЛИ маркировка -> [ [[ритейл], [1с]], [[маркировка]] ]
     * Условие из одних нераспознанных слов выбрасывается (как в чате): «ритейл И неизвестное»
     * ищет просто ритейл — мировоззрение пользователя правит он сам, увидев список
     * распознанных тегов в интерфейсе.
     *
     * @return array<int, array<int, string[]>> — ИЛИ-группы; каждая — список И-условий;
     *         каждое условие — список канонических тегов (внутри условия — по ИЛИ).
     */
    public function parseExpression(string $query): array
    {
        // Слова-операторы заменяем на маркеры. Оператор — обособленное слово: перед и после
        // него разделитель, конец строки или не буквенно-цифровой символ, поэтому «и»/«или»
        // внутри слов («итилиум», «1с») оператором не считаются.
        $query = preg_replace('/(?<![\pL\pN_-])(?:или|or|\|\||\|)(?![\pL\pN])/iu', ' §или§ ', $query) ?? '';
        $query = preg_replace('/(?<![\pL\pN_-])(?:и|and|&)(?![\pL\pN])/iu', ' §и§ ', $query) ?? '';

        $expression = [];
        foreach (preg_split('/[,;\r\n]+|§или§/u', $query) as $orPart) {
            if (!is_string($orPart) || trim($orPart) === '') {
                continue;
            }

            $group = [];
            foreach (explode('§и§', $orPart) as $andPart) {
                $tags = $this->parseQuery($andPart);
                if ($tags !== []) {
                    $group[] = $tags;
                }
            }

            if ($group !== []) {
                $expression[] = $group;
            }
        }

        return $expression;
    }

    /**
     * Поиск по запросу целиком: parseExpression + пересечение/объединение id кейсов
     * по каждому тегу. Поиск по отдельному тегу — CatalogRepository::findByTags: тот же
     * поиск, что и у команды «кейсы» в чате (без «не кейсов» и скрытых слайдов), поэтому
     * веб-фильтр и чат по одному запросу дают один и тот же список кейсов.
     *
     * @return array{tags: string[], ids: int[]} — распознанные канонические теги
     *         (для вывода в интерфейсе) и id подходящих кейсов (сортировка по id —
     *         для фильтра карточек порядок не важен, важна детерминированность).
     */
    public function matchCaseIds(string $query): array
    {
        $expression = $this->parseExpression($query);
        if ($expression === [] || $this->catalog === null) {
            return ['tags' => [], 'ids' => []];
        }

        $recognized = [];
        $idCache = []; // mb_strtolower(тег) -> int[] — id кейсов с этим тегом
        $resultIds = [];

        foreach ($expression as $orGroup) {
            $groupIds = null;

            foreach ($orGroup as $term) {
                $termIds = [];

                // Теги внутри условия дублируют друг друга (по ИЛИ).
                foreach ($term as $tag) {
                    $recognized[$tag] = true;
                    $key = mb_strtolower($tag);
                    $idCache[$key] ??= array_map(
                        static fn (array $row): int => (int) $row['id'],
                        $this->catalog->findByTags([$tag], 0)
                    );
                    $termIds = array_values(array_unique(array_merge($termIds, $idCache[$key])));
                }

                // Теги условия объединены, условия группы пересекаются.
                $groupIds = $groupIds === null ? $termIds : array_values(array_intersect($groupIds, $termIds));
            }

            $resultIds = array_values(array_unique(array_merge($resultIds, $groupIds ?? [])));
        }

        sort($resultIds, SORT_NUMERIC);

        return ['tags' => array_keys($recognized), 'ids' => $resultIds];
    }

    /** Тег по слову/фразе: словарь синонимов, затем прямое совпадение по тегам каталога. */
    private function matchTag(string $term): ?string
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

        return $this->tagTaxonomy->normalize($term)
            ?? $this->catalog?->findTagsByName($term);
    }
}
