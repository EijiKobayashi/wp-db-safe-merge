<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

use Generator;
use PDO;
use RuntimeException;

final class DumpStore
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dump_tables (name TEXT PRIMARY KEY, columns_json TEXT NOT NULL, create_sql TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dump_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, data_json TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS dump_rows_table ON dump_rows(table_name)');
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
        $statement = $this->pdo->prepare('INSERT INTO dump_rows(table_name,data_json) VALUES(?,?)');
        $statement->execute([$table, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]);
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

    public function rowCount(string $table): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM dump_rows WHERE table_name=?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn();
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
