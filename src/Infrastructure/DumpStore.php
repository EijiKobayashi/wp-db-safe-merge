<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

use Generator;
use PDO;
use RuntimeException;

final class DumpStore
{
    private PDO $pdo;
    private ?\PDOStatement $rowInsert = null;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dump_tables (name TEXT PRIMARY KEY, columns_json TEXT NOT NULL, create_sql TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dump_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, data_json TEXT NOT NULL)');
        $columns = $this->pdo->query('PRAGMA table_info(dump_rows)')->fetchAll();
        if (!in_array('ref_id', array_column($columns, 'name'), true)) {
            $this->pdo->exec('ALTER TABLE dump_rows ADD COLUMN ref_id INTEGER');
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS dump_rows_table ON dump_rows(table_name)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS dump_rows_reference ON dump_rows(table_name,ref_id)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dump_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    }

    /** @param list<string> $columns */
    public function table(string $name, array $columns, ?string $createSql = null): void
    {
        $statement = $this->pdo->prepare('INSERT INTO dump_tables(name, columns_json, create_sql) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET columns_json=excluded.columns_json, create_sql=COALESCE(excluded.create_sql,dump_tables.create_sql)');
        $statement->execute([$name, json_encode(array_values($columns), JSON_THROW_ON_ERROR), $createSql]);
    }

    /** @param array<string,mixed> $row */
    public function row(string $table, array $row): void
    {
        $this->rowInsert ??= $this->pdo->prepare('INSERT INTO dump_rows(table_name,data_json,ref_id) VALUES(?,?,?)');
        $reference = null;
        foreach (['post_id', 'object_id', 'ID', 'term_taxonomy_id', 'term_id', 'id'] as $column) {
            if (array_key_exists($column, $row)) { $reference = (int) $row[$column]; break; }
        }
        $this->rowInsert->execute([$table, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), $reference]);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $statement = $this->pdo->prepare('SELECT columns_json FROM dump_tables WHERE name=?');
        $statement->execute([$table]);
        $value = $statement->fetchColumn();
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->pdo->query('SELECT name FROM dump_tables ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return Generator<int,array<string,mixed>> */
    public function rows(string $table): Generator
    {
        $statement = $this->pdo->prepare('SELECT data_json FROM dump_rows WHERE table_name=? ORDER BY id');
        $statement->execute([$table]);
        while (($json = $statement->fetchColumn()) !== false) {
            yield json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    /** @return Generator<int,array<string,mixed>> */
    public function rowsByReference(string $table, string $column, int $reference): Generator
    {
        if (!in_array($column, ['post_id', 'object_id'], true)) {
            throw new RuntimeException('未対応の参照列です。');
        }
        $path = '$.' . $column;
        $migrate = $this->pdo->prepare('UPDATE dump_rows SET ref_id=CAST(json_extract(data_json, ?) AS INTEGER) WHERE table_name=? AND ref_id IS NULL');
        $migrate->execute([$path, $table]);
        $statement = $this->pdo->prepare('SELECT data_json FROM dump_rows WHERE table_name=? AND ref_id=? ORDER BY id');
        $statement->execute([$table, $reference]);
        while (($json = $statement->fetchColumn()) !== false) {
            yield json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    public function rowCount(string $table): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM dump_rows WHERE table_name=?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $conditions */
    public function deleteWhere(string $table, array $conditions): void
    {
        $sql = 'DELETE FROM dump_rows WHERE table_name=?';
        $values = [$table];
        foreach ($conditions as $column => $value) {
            if (in_array($column, ['post_id', 'object_id', 'ID'], true)) {
                $sql .= ' AND ref_id=?';
                $values[] = (int) $value;
            } else {
                $sql .= ' AND CAST(json_extract(data_json, ?) AS TEXT)=?';
                $values[] = '$.' . $column;
                $values[] = (string) $value;
            }
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
    }

    /** @param array<string,mixed> $row */
    public function replaceWhere(string $table, array $conditions, array $row): void
    {
        $this->deleteWhere($table, $conditions);
        $this->row($table, $row);
    }

    public function begin(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }

    public function setMeta(string $key, string $value): void
    {
        $statement = $this->pdo->prepare('INSERT INTO dump_meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        $statement->execute([$key, $value]);
    }

    public function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM dump_meta WHERE key=?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }
}
