<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

use RuntimeException;
use Throwable;

final class DumpImporter
{
    public function __construct(private readonly SqlStatementReader $reader = new SqlStatementReader()) {}

    /** @return array{tables:int,rows:int,prefix:string,charset:string,ignored_tables:list<string>} */
    public function import(string $sqlPath, DumpStore $store): array
    {
        $tableColumns = [];
        $rowCount = 0;
        $ignoredTables = [];
        $store->begin();
        try {
            foreach ($this->reader->read($sqlPath) as $statement) {
                $create = SqlSyntax::parseCreate($statement);
                if ($create !== null) {
                    $tableColumns[$create['table']] = $create['columns'];
                    $store->table($create['table'], $create['columns'], $create['create_sql']);
                    continue;
                }
                $insert = SqlSyntax::parseInsert($statement);
                if ($insert === null) {
                    continue;
                }
                $columns = $insert['columns'] ?: ($tableColumns[$insert['table']] ?? $store->columns($insert['table']));
                if ($columns === []) {
                    throw new RuntimeException("テーブル {$insert['table']} の列定義がありません。CREATE TABLEを含むダンプを使用してください。");
                }
                $store->table($insert['table'], $columns);
                if (!$this->isMergeTable($insert['table'], $columns)) {
                    $ignoredTables[$insert['table']] = true;
                    continue;
                }
                foreach (SqlSyntax::rows($insert['values']) as $values) {
                    if (count($values) !== count($columns)) {
                        throw new RuntimeException("比較対象テーブル {$insert['table']} の列数と値の数が一致しません。");
                    }
                    $store->row($insert['table'], array_combine($columns, $values));
                    $rowCount++;
                }
            }
            $prefix = $this->detectPrefix($store->tables());
            if ($prefix === null) {
                throw new RuntimeException('WordPressのpostsテーブルを検出できません。');
            }
            $charset = $this->detectCharset($sqlPath);
            $store->setMeta('prefix', $prefix);
            $store->setMeta('charset', $charset);
            $store->commit();
            return [
                'tables' => count($store->tables()), 'rows' => $rowCount, 'prefix' => $prefix, 'charset' => $charset,
                'ignored_tables' => array_keys($ignoredTables),
            ];
        } catch (Throwable $e) {
            $store->rollback();
            throw $e;
        }
    }

    /** @param list<string> $tables */
    private function detectPrefix(array $tables): ?string
    {
        $coreSuffixes = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'options', 'users', 'usermeta', 'comments', 'commentmeta'];
        $scores = [];
        foreach ($tables as $table) {
            foreach ($coreSuffixes as $suffix) {
                if (str_ends_with($table, $suffix)) {
                    $prefix = substr($table, 0, -strlen($suffix));
                    $scores[$prefix] = ($scores[$prefix] ?? 0) + 1;
                }
            }
        }
        arsort($scores);
        foreach (array_keys($scores) as $prefix) {
            if (in_array($prefix . 'posts', $tables, true) && in_array($prefix . 'postmeta', $tables, true)) {
                return $prefix;
            }
        }
        return null;
    }

    /** @param list<string> $columns */
    private function isMergeTable(string $table, array $columns): bool
    {
        $has = static fn (array $required): bool => count(array_diff($required, $columns)) === 0;
        return $has(['ID', 'post_content', 'post_type', 'post_modified'])
            || $has(['post_id', 'meta_key', 'meta_value'])
            || $has(['term_id', 'name', 'slug'])
            || $has(['term_taxonomy_id', 'term_id', 'taxonomy'])
            || $has(['object_id', 'term_taxonomy_id'])
            || str_contains($table, 'yoast_');
    }

    private function detectCharset(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 1024 * 1024) ?: '';
        if (preg_match('/(?:DEFAULT\s+)?CHARSET\s*=\s*([A-Za-z0-9_]+)/i', $sample, $match)) {
            return strtolower($match[1]);
        }
        if (preg_match('/SET\s+NAMES\s+([A-Za-z0-9_]+)/i', $sample, $match)) {
            return strtolower($match[1]);
        }
        return 'utf8mb4';
    }
}
