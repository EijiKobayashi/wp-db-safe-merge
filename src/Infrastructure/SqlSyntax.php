<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

use Generator;
use RuntimeException;

final class SqlSyntax
{
    /** @return array{table:string,columns:list<string>,create_sql:string}|null */
    public static function parseCreate(string $sql): ?array
    {
        $identifier = '((?:`[^`]+`|[A-Za-z0-9_$-]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_$-]+))?)';
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $identifier . '\s*\((.*)\)\s*(.*)$/is', $sql, $match)) {
            return null;
        }
        $table = self::canonicalTable($match[1]);
        $columns = [];
        foreach (self::splitTopLevel($match[2]) as $definition) {
            if (preg_match('/^\s*(?:PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK|FULLTEXT|SPATIAL)\b/i', $definition)) {
                continue;
            }
            if (preg_match('/^\s*`([^`]+)`\s+/s', $definition, $column)) {
                $columns[] = $column[1];
            } elseif (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_$]*)\s+/i', $definition, $column)) {
                $columns[] = $column[1];
            }
        }
        return ['table' => $table, 'columns' => $columns, 'create_sql' => $sql];
    }

    /** @return array{table:string,columns:list<string>,values:string}|null */
    public static function parseInsert(string $sql): ?array
    {
        $identifier = '((?:`[^`]+`|[A-Za-z0-9_$-]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_$-]+))?)';
        if (!preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+' . $identifier . '\s*(?:\((.*?)\))?\s+VALUES\s*(.*)$/is', $sql, $match)) {
            return null;
        }
        $columns = [];
        if (isset($match[2]) && trim($match[2]) !== '') {
            foreach (self::splitTopLevel($match[2]) as $column) {
                $columns[] = trim(trim($column), "` \t\r\n");
            }
        }
        return [
            'table' => self::canonicalTable($match[1]),
            'columns' => $columns,
            'values' => trim($match[3]),
        ];
    }

    private static function canonicalTable(string $identifier): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($identifier)) ?: [];
        return trim((string) end($parts), "` \t\r\n");
    }

    /** @return Generator<int,list<mixed>> */
    public static function rows(string $values): Generator
    {
        $length = strlen($values);
        $depth = 0;
        $quote = null;
        $escaped = false;
        $field = '';
        $fields = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];
            $next = $i + 1 < $length ? $values[$i + 1] : '';
            if ($quote !== null) {
                $field .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $field .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $field .= $char;
                continue;
            }
            if ($char === '(') {
                if ($depth++ > 0) {
                    $field .= $char;
                }
                continue;
            }
            if ($char === ')') {
                if ($depth <= 0) {
                    throw new RuntimeException('INSERT文の括弧が不正です。');
                }
                $depth--;
                if ($depth === 0) {
                    $fields[] = self::decodeValue($field);
                    yield $fields;
                    $field = '';
                    $fields = [];
                } else {
                    $field .= $char;
                }
                continue;
            }
            if ($char === ',' && $depth === 1) {
                $fields[] = self::decodeValue($field);
                $field = '';
                continue;
            }
            if ($depth > 0) {
                $field .= $char;
            } elseif (!ctype_space($char) && $char !== ',') {
                throw new RuntimeException('INSERT VALUESの後に未対応の構文があります。');
            }
        }
        if ($depth !== 0 || $quote !== null) {
            throw new RuntimeException('INSERT文が途中で終了しています。');
        }
    }

    /** @return list<string> */
    private static function splitTopLevel(string $input): array
    {
        $result = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
            } elseif ($char === '(') {
                $depth++;
                $buffer .= $char;
            } elseif ($char === ')') {
                $depth--;
                $buffer .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $result[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if (trim($buffer) !== '') {
            $result[] = $buffer;
        }
        return $result;
    }

    private static function decodeValue(string $raw): mixed
    {
        $raw = trim($raw);
        if (strcasecmp($raw, 'NULL') === 0) {
            return null;
        }
        if (strlen($raw) >= 2 && (($raw[0] === "'" && $raw[-1] === "'") || ($raw[0] === '"' && $raw[-1] === '"'))) {
            $value = substr($raw, 1, -1);
            $value = preg_replace_callback('/\\\\([0nrZ\\\'"%_])/', static fn (array $m): string => match ($m[1]) {
                '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", default => $m[1],
            }, $value) ?? $value;
            return str_replace([$raw[0] . $raw[0]], [$raw[0]], $value);
        }
        return $raw;
    }
}
