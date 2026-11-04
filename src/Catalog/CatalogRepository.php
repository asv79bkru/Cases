<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Хранилище кейсов: CRUD и поиск по тегам (§5.1.9 ТЗ).
 * Реализация — SQLite, схема — storage/catalog/schema.sql
 * (таблицы cases, case_images, tags, case_tags, tag_suggestions; P1 — query_log, query_log_tags).
 *
 * Модель данных после переезда разметки в веб-интерфейс:
 *  - в cases лежат ВСЕ слайды презентаций; is_not_case = 1 — служебные (обложка, о компании, контакты);
 *  - у тега в case_tags есть source: 'notes' (перенесён из заметок докладчика) или 'expert'
 *    (подтверждён в веб-интерфейсе). Экспертные теги и метки is_not_case/tags_reviewed
 *    переиндексация не трогает — она обновляет только контент (Indexer + SlideMatcher);
 *  - словарные/LLM-предложения копятся в tag_suggestions и становятся тегами только
 *    после подтверждения эксперта.
 */
class CatalogRepository
{
    private \PDO $pdo;

    /** Каталог картинок (storage/catalog/images) — для чистки файлов удалённых/обновлённых слайдов. */
    private ?string $imagesDir;

    public function __construct(string $sqlitePath, string $schemaPath, ?string $imagesDir = null)
    {
        $this->imagesDir = $imagesDir !== null ? rtrim($imagesDir, '/\\') : null;
        $isNew = !is_file($sqlitePath);

        $this->pdo = new \PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // WAL: читатели (бот, poll.php) не блокируются на время записи (переиндексация).
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Ждать освобождения базы до 30 сек вместо мгновенного "database is locked".
        $this->pdo->exec('PRAGMA busy_timeout = 30000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        if ($isNew) {
            $this->pdo->exec((string) file_get_contents($schemaPath));
        }

        $this->ensureSchema();
    }

    /**
     * Миграция схемы на лету — для баз, созданных до переезда разметки в веб-интерфейс.
     * Идемпотентна: каждая колонка/таблица добавляется, только если её ещё нет.
     * Базы, собранные по старой схеме, получают новые колонки с безопасными значениями
     * по умолчанию (is_not_case = 0 — существующие строки были именно кейсами: старый
     * индексатор сохранял только слайды с меткой #кейс#); строкам, уже имеющим теги,
     * проставляется tags_reviewed = 1 — при старом процессе теги подтверждал эксперт.
     */
    private function ensureSchema(): void
    {
        $this->addColumnIfMissing('cases', 'reviewer_comments', 'TEXT');
        $this->addColumnIfMissing('cases', 'content_hash', 'TEXT');
        $this->addColumnIfMissing('cases', 'slide_object_id', 'TEXT');

        if ($this->addColumnIfMissing('cases', 'is_not_case', 'INTEGER NOT NULL DEFAULT 0')) {
            // Существующие строки — кейсы, помеченные меткой #кейс# в старой модели.
            $this->pdo->exec('UPDATE cases SET is_not_case = 0');
        }

        if ($this->addColumnIfMissing('cases', 'tags_reviewed', 'INTEGER NOT NULL DEFAULT 0')) {
            // Теги, уже лежащие в базе, при старом процессе писал/подтверждал эксперт.
            $this->pdo->exec(
                'UPDATE cases SET tags_reviewed = 1
                 WHERE id IN (SELECT DISTINCT case_id FROM case_tags)'
            );
        }

        $this->addColumnIfMissing('case_tags', 'source', "TEXT NOT NULL DEFAULT 'notes'");

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS tag_suggestions (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                case_id    INTEGER NOT NULL REFERENCES cases (id) ON DELETE CASCADE,
                tag        TEXT    NOT NULL,
                source     TEXT    NOT NULL,
                status     TEXT    NOT NULL DEFAULT 'pending',
                created_at TEXT    NOT NULL,
                UNIQUE (case_id, tag)
            )"
        );
    }

    /** Добавляет колонку, если её нет; возвращает true, если колонка была добавлена сейчас. */
    private function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        $stmt = $this->pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $info) {
            if (strcasecmp((string) $info['name'], $column) === 0) {
                return false;
            }
        }

        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");

        return true;
    }

    /**
     * Выполняет $fn внутри одной транзакции: при любом исключении — ROLLBACK и база остаётся
     * ровно в прежнем состоянии. Основа атомарной переиндексации (Indexer::runAutomatic):
     * обновление + вставка + удаление либо применяются целиком, либо не применяются вовсе —
     * «пустого каталога» при сбое посреди прохода не бывает.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transactional(callable $fn)
    {
        $this->pdo->beginTransaction();

        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Слайды: вставка/обновление/удаление (переиндексация, Indexer + SlideMatcher)
    // ------------------------------------------------------------------

    /**
     * Строки каталога одной презентации — вход сопоставления слайдов при переиндексации.
     *
     * @return array<int, array{id: int, slide_number: int, title: ?string, content: ?string, content_hash: ?string, tags_reviewed: int}>
     */
    public function slideRowsForFile(string $sourceFileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slide_number, title, content, content_hash, tags_reviewed
             FROM cases WHERE source_file_id = :source_file_id
             ORDER BY slide_number'
        );
        $stmt->execute(['source_file_id' => $sourceFileId]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['slide_number'] = (int) $row['slide_number'];
            $row['tags_reviewed'] = (int) $row['tags_reviewed'];
        }

        return $rows;
    }

    /**
     * Перед расстановкой новых номеров слайдов существующие номера временно
     * делаются отрицательными: иначе UPDATE slide_number по цепочке переставленных
     * слайдов натыкается на UNIQUE (source_file_id, slide_number). Вызывается
     * в транзакции переиндексации, до обновления содержимого.
     */
    public function stageSlidePositionsForReindex(string $sourceFileId): void
    {
        $this->pdo
            ->prepare('UPDATE cases SET slide_number = -slide_number WHERE source_file_id = :source_file_id')
            ->execute(['source_file_id' => $sourceFileId]);
    }

    /** Вставляет новый слайд (с тегами из заметок и картинками), возвращает id строки. */
    public function insertCase(CaseItem $case, bool $isNotCase, array $notesTags): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cases (source_file_id, source_file_name, slide_number, title, content,
                                reviewer_comments, content_hash, site_url, is_not_case, tags_reviewed, added_at)
             VALUES (:source_file_id, :source_file_name, :slide_number, :title, :content,
                     :reviewer_comments, :content_hash, :site_url, :is_not_case, :tags_reviewed, :added_at)'
        );
        $stmt->execute([
            'source_file_id' => $case->sourceFileId,
            'source_file_name' => $case->sourceFileName,
            'slide_number' => $case->slideNumber,
            'title' => $case->title,
            'content' => $case->content,
            'reviewer_comments' => $case->reviewerComments,
            'content_hash' => $case->contentHash,
            'site_url' => $case->siteUrl,
            'is_not_case' => $isNotCase ? 1 : 0,
            'tags_reviewed' => $case->tagsReviewed ? 1 : 0,
            'added_at' => $case->addedAt,
        ]);

        $caseId = (int) $this->pdo->lastInsertId();
        $this->replaceImages($caseId, $case->images);
        $this->attachTags($caseId, $notesTags, 'notes');

        return $caseId;
    }

    /**
     * Обновляет контент существующего слайда (в т.ч. позицию — см. stageSlidePositionsForReindex)
     * и картинки. Ручное (теги, is_not_case, tags_reviewed) не трогается.
     */
    public function updateCaseContent(int $id, CaseItem $case): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cases SET slide_number = :slide_number, title = :title, content = :content,
                              reviewer_comments = :reviewer_comments, content_hash = :content_hash,
                              source_file_name = :source_file_name
             WHERE id = :id'
        );
        $stmt->execute([
            'slide_number' => $case->slideNumber,
            'title' => $case->title,
            'content' => $case->content,
            'reviewer_comments' => $case->reviewerComments,
            'content_hash' => $case->contentHash,
            'source_file_name' => $case->sourceFileName,
            'id' => $id,
        ]);

        $oldImages = $this->imagesForCase($id);
        $removed = array_diff($oldImages, $case->images);
        $this->replaceImages($id, $case->images);

        foreach ($removed as $imagePath) {
            $this->unlinkImageFile((string) $imagePath);
        }
    }

    /**
     * Удаляет слайды презентаций, которых больше нет в списке файлов-источников
     * (файл удалён из presentations/). Вызывается в транзакции переиндексации;
     * ON DELETE CASCADE подчищает теги, картинки и предложения.
     *
     * @param string[] $keepFileIds
     */
    public function pruneMissing(array $keepFileIds): void
    {
        if ($keepFileIds === []) {
            $this->pdo->exec('DELETE FROM cases');
        } else {
            $placeholders = [];
            $params = [];
            foreach (array_values($keepFileIds) as $i => $fileId) {
                $placeholders[] = ":file{$i}";
                $params["file{$i}"] = (string) $fileId;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM cases WHERE source_file_id NOT IN (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
        }

        // Картинки удалённых строк: ON DELETE CASCADE чистит таблицу, файлы — руками.
        $this->unlinkOrphanImages();
    }

    /**
     * Удаляет слайды одной презентации, не вошедшие в $keepSlideNumbers (слайд удалён
     * или презентация исчезла целиком). @return int Число удалённых строк.
     *
     * @param int[] $keepSlideNumbers
     */
    public function pruneSlidesOfFile(string $sourceFileId, array $keepSlideNumbers): int
    {
        if ($keepSlideNumbers === []) {
            $stmt = $this->pdo->prepare('DELETE FROM cases WHERE source_file_id = :source_file_id');
            $stmt->execute(['source_file_id' => $sourceFileId]);
        } else {
            $placeholders = [];
            $params = ['source_file_id' => $sourceFileId];
            foreach (array_values($keepSlideNumbers) as $i => $number) {
                $placeholders[] = ":keep{$i}";
                $params["keep{$i}"] = (int) $number;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM cases
                 WHERE source_file_id = :source_file_id AND slide_number NOT IN (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
        }

        $removed = (int) $stmt->rowCount();
        if ($removed > 0) {
            $this->unlinkOrphanImages();
        }

        return $removed;
    }

    // ------------------------------------------------------------------
    // Теги кейсов: источники notes/expert, ручное редактирование из веб-интерфейса
    // ------------------------------------------------------------------

    /**
     * Теги кейса; $source фильтрует по источнику (null — все теги).
     *
     * @return string[]
     */
    public function tagsForCase(int $caseId, ?string $source = null): array
    {
        $sql = 'SELECT t.name FROM case_tags ct JOIN tags t ON t.id = ct.tag_id
                WHERE ct.case_id = :case_id';
        $params = ['case_id' => $caseId];
        if ($source !== null) {
            $sql .= ' AND ct.source = :source';
            $params['source'] = $source;
        }
        $sql .= ' ORDER BY t.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Добавляет теги кейсу (source — 'notes' или 'expert'). Существующие связи
     * обновляются до $source — тег, подтверждённый экспертом, не должен «слететь»
     * обратно в notes-статус.
     *
     * @param string[] $tags
     */
    public function attachTags(int $caseId, array $tags, string $source): void
    {
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }

            $tagId = $this->findOrCreateTag($tag);
            $this->pdo
                ->prepare(
                    'INSERT INTO case_tags (case_id, tag_id, source) VALUES (:case_id, :tag_id, :source)
                     ON CONFLICT (case_id, tag_id) DO UPDATE SET source = :source2'
                )
                ->execute(['case_id' => $caseId, 'tag_id' => $tagId, 'source' => $source, 'source2' => $source]);
        }
    }

    /**
     * Синхронизирует notes-теги с заметками докладчика: убирает то, чего в заметках
     * больше нет. Экспертные теги (source='expert') не трогаются никогда.
     *
     * @param string[] $notesTags
     */
    public function syncNotesTags(int $caseId, array $notesTags): void
    {
        $current = $this->tagsForCase($caseId, 'notes');
        $keep = [];
        foreach ($notesTags as $tag) {
            $keep[mb_strtolower(trim($tag))] = true;
        }

        $obsolete = [];
        foreach ($current as $tag) {
            if (!isset($keep[mb_strtolower($tag)])) {
                $obsolete[] = $tag;
            }
        }

        if ($obsolete !== []) {
            $this->removeTags($caseId, $obsolete);
        }

        $this->attachTags($caseId, array_keys($keep), 'notes');
    }

    /**
     * Убирает теги у кейса (по именам, независимо от источника) — правка списка
     * утверждённых тегов в веб-интерфейсе.
     *
     * @param string[] $tags
     */
    public function removeTags(int $caseId, array $tags): void
    {
        foreach ($tags as $tag) {
            $tagId = $this->findTagId($tag);
            if ($tagId === null) {
                continue;
            }

            $this->pdo
                ->prepare('DELETE FROM case_tags WHERE case_id = :case_id AND tag_id = :tag_id')
                ->execute(['case_id' => $caseId, 'tag_id' => $tagId]);
        }
    }

    /**
     * Заменяет утверждённый набор тегов кейса (веб-интерфейс, кнопка сохранения карточки):
     * переданный список становится полным набором тегов с source='expert'. Случай пустого
     * списка легален — эксперт подтвердил, что тегов у кейса нет.
     *
     * @param string[] $tags
     */
    public function setCaseTags(int $caseId, array $tags): void
    {
        $desired = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $desired[mb_strtolower($tag)] = $tag;
            }
        }

        $current = $this->tagsForCase($caseId);

        $obsolete = array_udiff($current, array_values($desired), 'strcasecmp');
        if ($obsolete !== []) {
            $this->removeTags($caseId, $obsolete);
        }

        $this->attachTags($caseId, array_values($desired), 'expert');
        $this->setTagsReviewed($caseId, true);
    }

    public function setTagsReviewed(int $caseId, bool $reviewed = true): void
    {
        $this->pdo
            ->prepare('UPDATE cases SET tags_reviewed = :reviewed WHERE id = :id')
            ->execute(['reviewed' => $reviewed ? 1 : 0, 'id' => $caseId]);
    }

    public function setNotCase(int $caseId, bool $notCase): void
    {
        $this->pdo
            ->prepare('UPDATE cases SET is_not_case = :not_case WHERE id = :id')
            ->execute(['not_case' => $notCase ? 1 : 0, 'id' => $caseId]);
    }

    /** @return string[] Имена файлов картинок кейса (относительные пути внутри images-каталога). */
    public function imagesForCase(int $caseId): array
    {
        $stmt = $this->pdo->prepare('SELECT file_path FROM case_images WHERE case_id = :case_id');
        $stmt->execute(['case_id' => $caseId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function replaceImages(int $caseId, array $images): void
    {
        $this->pdo
            ->prepare('DELETE FROM case_images WHERE case_id = :case_id')
            ->execute(['case_id' => $caseId]);

        foreach ($images as $imagePath) {
            $this->pdo
                ->prepare('INSERT INTO case_images (case_id, file_path) VALUES (:case_id, :file_path)')
                ->execute(['case_id' => $caseId, 'file_path' => $imagePath]);
        }
    }

    private function findTagId(string $name): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function findOrCreateTag(string $name): int
    {
        $found = $this->findTagId($name);
        if ($found !== null) {
            return $found;
        }

        $this->pdo
            ->prepare('INSERT INTO tags (name) VALUES (:name)')
            ->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Очередь предложений тегов (словарь / LLM -> подтверждение эксперта)
    // ------------------------------------------------------------------

    /**
     * Кладёт предложения тегов для кейса. Вызывается при индексации для новых и
     * изменившихся слайдов: накопленные pending-предложения сбрасываются, rejected
     * сохраняются (отклонённое экспертом не предлагается повторно), теги, уже
     * присвоенные кейсу, не предлагаются. Возвращает число новых pending.
     *
     * @param string[] $dictTags
     * @param string[] $llmTags
     */
    public function refreshSuggestions(int $caseId, array $dictTags, array $llmTags): int
    {
        $this->pdo
            ->prepare("DELETE FROM tag_suggestions WHERE case_id = :case_id AND status = 'pending'")
            ->execute(['case_id' => $caseId]);

        $attached = array_map('mb_strtolower', $this->tagsForCase($caseId));
        $now = date(DATE_ATOM);
        $added = 0;

        foreach (['dict' => $dictTags, 'llm' => $llmTags] as $source => $tags) {
            foreach ($tags as $tag) {
                // Теги в системе канонически в нижнем регистре (parseTagList/normalize);
                // приводим и здесь — регистрозависимый UNIQUE не должен плодить дубли.
                $tag = mb_strtolower(trim((string) $tag));
                if ($tag === '' || in_array($tag, $attached, true)) {
                    continue;
                }

                $stmt = $this->pdo->prepare(
                    "INSERT INTO tag_suggestions (case_id, tag, source, status, created_at)
                     VALUES (:case_id, :tag, :source, 'pending', :created_at)
                     ON CONFLICT (case_id, tag) DO NOTHING"
                );
                $stmt->execute(['case_id' => $caseId, 'tag' => $tag, 'source' => $source, 'created_at' => $now]);
                $added += $stmt->rowCount();
            }
        }

        return $added;
    }

    /**
     * Pending-предложения для списка кейсов, сгруппированные по case_id
     * (одним запросом на страницу веб-интерфейса).
     *
     * @param int[] $caseIds
     * @return array<int, array<int, array{id: int, tag: string, source: string}>>
     */
    public function pendingSuggestionsForCases(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($caseIds) as $i => $caseId) {
            $placeholders[] = ":id{$i}";
            $params["id{$i}"] = (int) $caseId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, case_id, tag, source FROM tag_suggestions
             WHERE status = 'pending' AND case_id IN (" . implode(', ', $placeholders) . ')
             ORDER BY id'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['case_id']][] = [
                'id' => (int) $row['id'],
                'tag' => $row['tag'],
                'source' => $row['source'],
            ];
        }

        return $grouped;
    }

    /** @return array<int, array{id: int, tag: string, source: string}> */
    public function pendingSuggestionsForCase(int $caseId): array
    {
        $grouped = $this->pendingSuggestionsForCases([$caseId]);

        return $grouped[$caseId] ?? [];
    }

    /**
     * Решение эксперта по предложению: принять (тег становится экспертным) или отклонить.
     */
    public function resolveSuggestion(int $suggestionId, bool $accept): void
    {
        $stmt = $this->pdo->prepare('SELECT case_id, tag, status FROM tag_suggestions WHERE id = :id');
        $stmt->execute(['id' => $suggestionId]);
        $suggestion = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($suggestion === false || $suggestion['status'] !== 'pending') {
            return;
        }

        if ($accept) {
            $this->attachTags((int) $suggestion['case_id'], [$suggestion['tag']], 'expert');
            $this->setTagsReviewed((int) $suggestion['case_id'], true);
        }

        $this->pdo
            ->prepare('UPDATE tag_suggestions SET status = :status WHERE id = :id')
            ->execute(['status' => $accept ? 'accepted' : 'rejected', 'id' => $suggestionId]);
    }

    /** Принимает все pending-предложения кейса (кнопка «принять все» в веб-интерфейсе). */
    public function acceptAllSuggestions(int $caseId): void
    {
        foreach ($this->pendingSuggestionsForCase($caseId) as $suggestion) {
            $this->resolveSuggestion($suggestion['id'], true);
        }
    }

    // ------------------------------------------------------------------
    // Управление справочником тегов (веб-интерфейс: слияние дублей вида «авто»/«автопром»)
    // ------------------------------------------------------------------

    /**
     * Все теги с числом кейсов (без учёта is_not_case — страницу справочника смотрит эксперт).
     *
     * @return array<int, array{id: int, name: string, case_count: int}>
     */
    public function allTagsWithCounts(): array
    {
        $sql = 'SELECT t.id, t.name, COUNT(ct.case_id) AS case_count
                FROM tags t
                LEFT JOIN case_tags ct ON ct.tag_id = t.id
                GROUP BY t.id
                ORDER BY case_count DESC, t.name';

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Переименовывает тег. Если тег с новым именем уже существует — теги сливаются
     * (связи переносятся, дубль удаляется). Возвращает 'renamed' или 'merged'.
     */
    public function renameTag(int $tagId, string $newName): string
    {
        $newName = trim($newName);
        if ($newName === '') {
            throw new \InvalidArgumentException('Имя тега не может быть пустым.');
        }

        $existing = $this->findTagId($newName);
        if ($existing !== null && $existing !== $tagId) {
            $this->mergeTags($tagId, $existing);

            return 'merged';
        }

        $this->pdo
            ->prepare('UPDATE tags SET name = :name WHERE id = :id')
            ->execute(['name' => $newName, 'id' => $tagId]);

        return 'renamed';
    }

    /** Переносит связи тега на другой тег и удаляет источник (слияние синонимов). */
    public function mergeTags(int $sourceTagId, int $targetTagId): void
    {
        if ($sourceTagId === $targetTagId) {
            return;
        }

        $this->pdo
            ->prepare(
                'INSERT INTO case_tags (case_id, tag_id, source)
                 SELECT case_id, :target, source FROM case_tags WHERE tag_id = :source
                 ON CONFLICT (case_id, tag_id) DO UPDATE SET source = \'expert\''
            )
            ->execute(['target' => $targetTagId, 'source' => $sourceTagId]);

        // Связи, переехавшие из тега-источника, становятся экспертными: слияние — решение эксперта.
        $this->pdo
            ->prepare('UPDATE case_tags SET source = \'expert\' WHERE tag_id = :target AND source = \'notes\'')
            ->execute(['target' => $targetTagId]);

        $this->pdo
            ->prepare('DELETE FROM tags WHERE id = :id')
            ->execute(['id' => $sourceTagId]);
    }

    /** Удаляет тег из справочника (связи уходят каскадом — ON DELETE CASCADE). */
    public function deleteTag(int $tagId): void
    {
        $this->pdo
            ->prepare('DELETE FROM tags WHERE id = :id')
            ->execute(['id' => $tagId]);
    }

    // ------------------------------------------------------------------
    // Данные для веб-интерфейса разметки
    // ------------------------------------------------------------------

    /**
     * Презентации, представленные в каталоге: сколько слайдов, сколько размечено,
     * сколько помечено «не кейс», сколько pending-предложений ждёт эксперта.
     *
     * @return array<int, array{source_file_id: string, source_file_name: string, total: int, reviewed: int, not_case: int, pending: int}>
     */
    public function presentationsInCatalog(): array
    {
        $sql = "SELECT c.source_file_id, c.source_file_name,
                       COUNT(*) AS total,
                       COALESCE(SUM(c.tags_reviewed), 0) AS reviewed,
                       COALESCE(SUM(c.is_not_case), 0) AS not_case,
                       (SELECT COUNT(*) FROM tag_suggestions s
                        JOIN cases c2 ON c2.id = s.case_id
                        WHERE s.status = 'pending' AND c2.source_file_id = c.source_file_id) AS pending
                FROM cases c
                GROUP BY c.source_file_id
                ORDER BY c.source_file_name";

        $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['total'] = (int) $row['total'];
            $row['reviewed'] = (int) $row['reviewed'];
            $row['not_case'] = (int) $row['not_case'];
            $row['pending'] = (int) $row['pending'];
        }

        return $rows;
    }

    /**
     * Все слайды презентации (включая помеченные «не кейс») с тегами — список карточек
     * веб-интерфейса разметки.
     *
     * @return array<int, array{id: int, slide_number: int, title: ?string, content: ?string, reviewer_comments: ?string, is_not_case: int, tags_reviewed: int, tags: ?string}>
     */
    public function casesForFile(string $sourceFileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.slide_number, c.title, c.content, c.reviewer_comments,
                    c.is_not_case, c.tags_reviewed,
                    (SELECT GROUP_CONCAT(t.name, ', ') FROM case_tags ct JOIN tags t ON t.id = ct.tag_id
                     WHERE ct.case_id = c.id) AS tags
             FROM cases c
             WHERE c.source_file_id = :source_file_id
             ORDER BY c.slide_number"
        );
        $stmt->execute(['source_file_id' => $sourceFileId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Одна карточка для обновления после сохранения разметки (fetch из JS). */
    public function caseById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.source_file_id, c.source_file_name, c.slide_number, c.title, c.content,
                    c.reviewer_comments, c.is_not_case, c.tags_reviewed,
                    (SELECT GROUP_CONCAT(t.name, ', ') FROM case_tags ct JOIN tags t ON t.id = ct.tag_id
                     WHERE ct.case_id = c.id) AS tags
             FROM cases c
             WHERE c.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Номера слайдов презентации, помеченных в веб-интерфейсе «не кейс» (обложка,
     * о компании, контакты) — они всегда остаются в подборке (CaseDeckBuilder).
     *
     * @return int[]
     */
    public function notCaseSlideNumbers(string $sourceFileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slide_number FROM cases
             WHERE source_file_id = :source_file_id AND is_not_case = 1 AND is_hidden = 0
             ORDER BY slide_number'
        );
        $stmt->execute(['source_file_id' => $sourceFileId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------
    // Поиск по каталогу (команда «кейсы») — только слайды-кейсы
    // ------------------------------------------------------------------

    /**
     * Все видимые кейсы (без is_hidden) с тегами и картинками — для проверки результата индексации.
     *
     * @return array<int, array{id: int, source_file_name: string, slide_number: int, title: ?string, content: ?string, site_url: ?string, tags: ?string, images: ?string, is_not_case: int}>
     */
    public function all(): array
    {
        $sql = "SELECT c.id, c.source_file_name, c.slide_number, c.title, c.content, c.site_url,
                       c.is_not_case,
                       GROUP_CONCAT(t.name, ', ') AS tags,
                       (SELECT GROUP_CONCAT(ci.file_path, ', ') FROM case_images ci WHERE ci.case_id = c.id) AS images
                FROM cases c
                LEFT JOIN case_tags ct ON ct.case_id = c.id
                LEFT JOIN tags t ON t.id = ct.tag_id
                WHERE c.is_hidden = 0
                GROUP BY c.id
                ORDER BY c.source_file_name, c.slide_number";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Кейсы с заданным тегом. Частный случай findByTags() — оставлен для CLI-проверок.
     *
     * @return array<int, array{id: int, source_file_id: string, slide_number: int, title: ?string}>
     */
    public function findByTag(string $tag): array
    {
        return $this->findByTags([$tag]);
    }

    /**
     * Ищет и ранжирует кейсы по совпавшим тегам: сначала — кейсы с бо́льшим числом совпадений
     * (§5.1.3 ТЗ). Кейс попадает в выдачу, если совпал хотя бы один тег из $tags.
     * Слайды, помеченные «не кейс» (обложка, о компании), в выдачу не попадают.
     *
     * @param string[] $tags
     * @return array<int, array{id: int, source_file_id: string, slide_number: int, title: ?string, match_count: int}>
     */
    public function findByTags(array $tags, int $limit = 0): array
    {
        if ($tags === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($tags as $i => $tag) {
            $placeholders[] = ":tag{$i}";
            $params["tag{$i}"] = $tag;
        }

        $sql = 'SELECT c.id, c.source_file_id, c.slide_number, c.title, COUNT(*) AS match_count
                FROM cases c
                JOIN case_tags ct ON ct.case_id = c.id
                JOIN tags t ON t.id = ct.tag_id
                WHERE c.is_hidden = 0 AND c.is_not_case = 0 AND t.name IN (' . implode(', ', $placeholders) . ')
                GROUP BY c.id
                ORDER BY match_count DESC, c.id ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Кейсы с заданными id, в том порядке, в котором переданы id (LLM отдаёт их по убыванию
     * релевантности в LlmCaseMatcher::match) — резерв на случай, когда запрос не совпал ни
     * с одним известным тегом (§5.1.7 ТЗ).
     *
     * @param int[] $ids
     * @return array<int, array{id: int, source_file_id: string, slide_number: int, title: ?string}>
     */
    public function findByIds(array $ids, int $limit = 0): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        $orderCases = [];
        foreach (array_values($ids) as $position => $id) {
            $id = (int) $id;
            $placeholders[] = ":id{$position}";
            $params["id{$position}"] = $id;
            $orderCases[] = "WHEN {$id} THEN {$position}";
        }

        $sql = 'SELECT c.id, c.source_file_id, c.slide_number, c.title
                FROM cases c
                WHERE c.is_hidden = 0 AND c.is_not_case = 0 AND c.id IN (' . implode(', ', $placeholders) . ')
                ORDER BY CASE c.id ' . implode(' ', $orderCases) . ' END';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Текст всех видимых кейсов каталога (id, заголовок, содержимое, теги) — источник данных для
     * LlmCaseMatcher, когда запрос не совпал ни с одним известным тегом. Текст уже лежит в
     * cases.content с момента индексации, повторно читать презентации не нужно.
     *
     * @return array<int, array{id: int, title: ?string, content: ?string, tags: ?string}>
     */
    public function allForMatching(): array
    {
        $sql = "SELECT c.id, c.title, c.content,
                       GROUP_CONCAT(t.name, ', ') AS tags
                FROM cases c
                LEFT JOIN case_tags ct ON ct.case_id = c.id
                LEFT JOIN tags t ON t.id = ct.tag_id
                WHERE c.is_hidden = 0 AND c.is_not_case = 0
                GROUP BY c.id
                ORDER BY c.id";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Все теги, реально присвоенные видимым кейсам — подсказка «доступные темы»
     * в сообщении об отсутствии результатов (§5.1.7 ТЗ).
     *
     * @return string[]
     */
    public function allTags(): array
    {
        $sql = "SELECT DISTINCT t.name
                FROM tags t
                JOIN case_tags ct ON ct.tag_id = t.id
                JOIN cases c ON c.id = ct.case_id
                WHERE c.is_hidden = 0 AND c.is_not_case = 0
                ORDER BY t.name";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Тег по точному имени — резерв для слов, которых ещё нет в config/tags.php, но которые уже
     * есть как канонический тег в каталоге (например, добавленные экспертом через веб-интерфейс).
     */
    public function findTagsByName(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM tags WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute(['name' => trim($name)]);

        $found = $stmt->fetchColumn();

        return $found !== false ? $found : null;
    }

    // ------------------------------------------------------------------
    // Внутреннее: файлы картинок
    // ------------------------------------------------------------------

    private function unlinkImageFile(string $relativePath): void
    {
        if ($this->imagesDir === null || $this->imagesDir === '') {
            return;
        }

        $path = $this->imagesDir . '/' . basename($relativePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Убирает файлы картинок, на которые больше не ссылается ни одна строка каталога. */
    private function unlinkOrphanImages(): void
    {
        $imagesDir = $this->imagesDir;
        if ($imagesDir === null || $imagesDir === '' || !is_dir($imagesDir)) {
            return;
        }

        $referenced = $this->pdo->query('SELECT DISTINCT file_path FROM case_images')->fetchAll(\PDO::FETCH_COLUMN);

        foreach (glob($imagesDir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = basename($file);
            foreach ($referenced as $path) {
                if (basename((string) $path) === $name) {
                    continue 2;
                }
            }

            @unlink($file);
        }
    }
}
