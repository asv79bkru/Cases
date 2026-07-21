<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Хранилище кейсов: CRUD и поиск по тегам (§5.1.9 ТЗ).
 * Реализация — SQLite, схема — storage/catalog/schema.sql
 * (таблицы cases, case_images, tags, case_tags; P1 — query_log, query_log_tags).
 */
class CatalogRepository
{
    private \PDO $pdo;

    public function __construct(string $sqlitePath, string $schemaPath)
    {
        $isNew = !is_file($sqlitePath);

        $this->pdo = new \PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        if ($isNew) {
            $this->pdo->exec((string) file_get_contents($schemaPath));
        }
    }

    /**
     * Сохраняет кейс с текстом, картинками и тегами. Повторная индексация того же слайда
     * (source_file_id + slide_number) обновляет запись вместо создания дубля (§5.1.9 ТЗ).
     */
    public function saveCase(CaseItem $case): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cases (source_file_id, source_file_name, slide_number, title, content, site_url, added_at)
             VALUES (:source_file_id, :source_file_name, :slide_number, :title, :content, :site_url, :added_at)
             ON CONFLICT (source_file_id, slide_number) DO UPDATE SET
                title = excluded.title,
                content = excluded.content,
                site_url = excluded.site_url,
                added_at = excluded.added_at'
        );
        $stmt->execute([
            'source_file_id' => $case->sourceFileId,
            'source_file_name' => $case->sourceFileName,
            'slide_number' => $case->slideNumber,
            'title' => $case->title,
            'content' => $case->content,
            'site_url' => $case->siteUrl,
            'added_at' => $case->addedAt,
        ]);

        $find = $this->pdo->prepare(
            'SELECT id FROM cases WHERE source_file_id = :source_file_id AND slide_number = :slide_number'
        );
        $find->execute(['source_file_id' => $case->sourceFileId, 'slide_number' => $case->slideNumber]);
        $caseId = (int) $find->fetchColumn();

        $this->pdo->prepare('DELETE FROM case_tags WHERE case_id = :case_id')->execute(['case_id' => $caseId]);
        foreach ($case->tags as $tag) {
            $tagId = $this->findOrCreateTag($tag);
            $this->pdo
                ->prepare('INSERT OR IGNORE INTO case_tags (case_id, tag_id) VALUES (:case_id, :tag_id)')
                ->execute(['case_id' => $caseId, 'tag_id' => $tagId]);
        }

        $this->pdo->prepare('DELETE FROM case_images WHERE case_id = :case_id')->execute(['case_id' => $caseId]);
        foreach ($case->images as $imagePath) {
            $this->pdo
                ->prepare('INSERT INTO case_images (case_id, file_path) VALUES (:case_id, :file_path)')
                ->execute(['case_id' => $caseId, 'file_path' => $imagePath]);
        }

        return $caseId;
    }

    private function findOrCreateTag(string $name): int
    {
        $select = $this->pdo->prepare('SELECT id FROM tags WHERE name = :name');
        $select->execute(['name' => $name]);
        $id = $select->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $this->pdo
            ->prepare('INSERT INTO tags (name) VALUES (:name)')
            ->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Все видимые кейсы (без is_hidden) с тегами и картинками — для проверки результата индексации.
     *
     * @return array<int, array{id: int, source_file_name: string, slide_number: int, title: ?string, content: ?string, site_url: ?string, tags: ?string, images: ?string}>
     */
    public function all(): array
    {
        $sql = "SELECT c.id, c.source_file_name, c.slide_number, c.title, c.content, c.site_url,
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
                WHERE c.is_hidden = 0 AND t.name IN (' . implode(', ', $placeholders) . ')
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
                WHERE c.is_hidden = 0
                ORDER BY t.name";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Тег по точному имени — резерв для слов, которых ещё нет в config/tags.php, но которые уже
     * есть как канонический тег в каталоге (например, добавленные LLM при индексации).
     */
    public function findTagsByName(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM tags WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute(['name' => trim($name)]);

        $found = $stmt->fetchColumn();

        return $found !== false ? $found : null;
    }
}
