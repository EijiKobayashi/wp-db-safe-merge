<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use PDO;

final class ComparisonStore
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS content (
            side TEXT NOT NULL, source_id INTEGER NOT NULL, post_type TEXT NOT NULL,
            slug TEXT NOT NULL, title_norm TEXT NOT NULL, published TEXT NOT NULL,
            modified TEXT NOT NULL, content_hash TEXT NOT NULL, payload_json TEXT NOT NULL,
            PRIMARY KEY(side, source_id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS content_slug ON content(side,slug)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS content_title ON content(side,title_norm)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS content_date ON content(side,published)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS comparisons (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, base_id INTEGER,
            incoming_id INTEGER, score REAL NOT NULL DEFAULT 0, recommended TEXT NOT NULL,
            decision_json TEXT, UNIQUE(base_id,incoming_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS comparison_meta (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
    }

    /** @param array<string,mixed> $row */
    public function addContent(string $side, array $row): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO content VALUES(?,?,?,?,?,?,?,?,?)');
        $statement->execute([
            $side, (int) ($row['ID'] ?? 0), (string) ($row['post_type'] ?? 'post'),
            self::normalizeSlug((string) ($row['post_name'] ?? '')),
            self::normalizeText((string) ($row['post_title'] ?? '')),
            (string) ($row['post_date_gmt'] ?? $row['post_date'] ?? ''),
            (string) ($row['post_modified_gmt'] ?? $row['post_modified'] ?? ''),
            hash('sha256', self::normalizeText((string) ($row['post_content'] ?? ''))),
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ]);
    }

    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) { $this->pdo->beginTransaction(); }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) { $this->pdo->commit(); }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
    }

    /** @return iterable<array<string,mixed>> */
    public function content(string $side): iterable
    {
        $statement = $this->pdo->prepare('SELECT * FROM content WHERE side=? ORDER BY source_id');
        $statement->execute([$side]);
        while ($row = $statement->fetch()) {
            yield $row;
        }
    }

    /** @return list<array<string,mixed>> */
    public function candidates(array $incoming): array
    {
        $conditions = [];
        $values = [];
        foreach (['slug', 'title_norm', 'published'] as $field) {
            if ((string) $incoming[$field] !== '' && (string) $incoming[$field] !== '0000-00-00 00:00:00') {
                $conditions[] = "$field = ?";
                $values[] = $incoming[$field];
            }
        }
        if ($conditions === []) {
            return [];
        }
        $sql = "SELECT * FROM content WHERE side='base' AND post_type=? AND (" . implode(' OR ', $conditions) . ') LIMIT 30';
        array_unshift($values, $incoming['post_type']);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll();
    }

    public function addComparison(string $kind, ?int $baseId, ?int $incomingId, float $score, string $recommended): void
    {
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO comparisons(kind,base_id,incoming_id,score,recommended) VALUES(?,?,?,?,?)');
        $statement->execute([$kind, $baseId, $incomingId, $score, $recommended]);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function page(int $page = 1, int $perPage = 25, string $filter = 'all'): array
    {
        $where = $filter === 'all' ? '' : 'WHERE c.kind = :filter';
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM comparisons c $where");
        if ($filter !== 'all') { $count->bindValue(':filter', $filter); }
        $count->execute();
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $query = $this->pdo->prepare("SELECT c.*, b.payload_json AS base_json, i.payload_json AS incoming_json
            FROM comparisons c
            LEFT JOIN content b ON b.side='base' AND b.source_id=c.base_id
            LEFT JOIN content i ON i.side='incoming' AND i.source_id=c.incoming_id
            $where ORDER BY CASE c.kind WHEN 'conflict' THEN 0 WHEN 'candidate' THEN 1 ELSE 2 END, c.id LIMIT :limit OFFSET :offset");
        if ($filter !== 'all') { $query->bindValue(':filter', $filter); }
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();
        $items = [];
        foreach ($query->fetchAll() as $row) {
            $row['base'] = $row['base_json'] ? json_decode($row['base_json'], true) : null;
            $row['incoming'] = $row['incoming_json'] ? json_decode($row['incoming_json'], true) : null;
            $row['decision'] = $row['decision_json'] ? json_decode($row['decision_json'], true) : null;
            unset($row['base_json'], $row['incoming_json'], $row['decision_json']);
            $items[] = $row;
        }
        return compact('items', 'total', 'page', 'pages');
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $result = ['matched' => 0, 'candidate' => 0, 'conflict' => 0, 'additional' => 0, 'base_only' => 0];
        foreach ($this->pdo->query('SELECT kind,COUNT(*) amount FROM comparisons GROUP BY kind') as $row) {
            $result[$row['kind']] = (int) $row['amount'];
        }
        return $result;
    }

    /** @param array<string,mixed> $decision */
    public function decide(int $id, array $decision): void
    {
        $statement = $this->pdo->prepare('UPDATE comparisons SET decision_json=? WHERE id=?');
        $statement->execute([json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $id]);
    }

    /** @return iterable<array<string,mixed>> */
    public function allComparisons(): iterable
    {
        $query = $this->pdo->query("SELECT c.*,b.payload_json base_json,i.payload_json incoming_json FROM comparisons c
            LEFT JOIN content b ON b.side='base' AND b.source_id=c.base_id
            LEFT JOIN content i ON i.side='incoming' AND i.source_id=c.incoming_id ORDER BY c.id");
        while ($row = $query->fetch()) {
            $row['base'] = $row['base_json'] ? json_decode($row['base_json'], true) : null;
            $row['incoming'] = $row['incoming_json'] ? json_decode($row['incoming_json'], true) : null;
            $row['decision'] = $row['decision_json'] ? json_decode($row['decision_json'], true) : null;
            yield $row;
        }
    }

    private static function normalizeSlug(string $value): string
    {
        return mb_strtolower(trim(rawurldecode($value)), 'UTF-8');
    }

    private static function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtolower(preg_replace('/\s+/u', '', trim($value)) ?? trim($value), 'UTF-8');
    }
}
