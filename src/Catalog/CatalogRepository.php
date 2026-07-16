<?php

declare(strict_types=1);

namespace CasesBot\Catalog;

/**
 * Хранилище кейсов: CRUD и поиск по тегам (§5.1.9 ТЗ).
 * Реализация — SQLite, схема — storage/catalog/schema.sql
 * (таблицы cases, tags, case_tags; P1 — query_log, query_log_tags).
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
     * Сохраняет кейс с тегами. Повторная индексация того же слайда (source_file_id + slide_number)
     * обновляет запись вместо создания дубля (§5.1.9 ТЗ).
     */
    public function saveCase(CaseItem $case): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cases (source_file_id, source_file_name, slide_number, title, site_url, added_at)
             VALUES (:source_file_id, :source_file_name, :slide_number, :title, :site_url, :added_at)
             ON CONFLICT (source_file_id, slide_number) DO UPDATE SET
                title = excluded.title,
                site_url = excluded.site_url,
                added_at = excluded.added_at'
        );
        $stmt->execute([
            'source_file_id' => $case->sourceFileId,
            'source_file_name' => $case->sourceFileName,
            'slide_number' => $case->slideNumber,
            'title' => $case->title,
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
            $tagId = $this->findOrCreateTag($tag['category'], $tag['tag']);
            $this->pdo
                ->prepare('INSERT OR IGNORE INTO case_tags (case_id, tag_id) VALUES (:case_id, :tag_id)')
                ->execute(['case_id' => $caseId, 'tag_id' => $tagId]);
        }

        return $caseId;
    }

    private function findOrCreateTag(string $category, string $name): int
    {
        $select = $this->pdo->prepare('SELECT id FROM tags WHERE category = :category AND name = :name');
        $select->execute(['category' => $category, 'name' => $name]);
        $id = $select->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $this->pdo
            ->prepare('INSERT INTO tags (category, name) VALUES (:category, :name)')
            ->execute(['category' => $category, 'name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Все видимые кейсы (без is_hidden) с присвоенными тегами — для проверки результата индексации.
     *
     * @return array<int, array{id: int, source_file_name: string, slide_number: int, title: ?string, site_url: ?string, tags: ?string}>
     */
    public function all(): array
    {
        $sql = "SELECT c.id, c.source_file_name, c.slide_number, c.title, c.site_url,
                       GROUP_CONCAT(t.category || ':' || t.name, ', ') AS tags
                FROM cases c
                LEFT JOIN case_tags ct ON ct.case_id = c.id
                LEFT JOIN tags t ON t.id = ct.tag_id
                WHERE c.is_hidden = 0
                GROUP BY c.id
                ORDER BY c.source_file_name, c.slide_number";

        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
