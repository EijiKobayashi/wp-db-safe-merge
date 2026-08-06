<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

final class SqlWriter
{
    public static function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    public static function value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return "'" . strtr((string) $value, [
            "\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r",
            "\x1a" => "\\Z", "'" => "\\'",
        ]) . "'";
    }

    /** @param array<string,mixed> $row */
    public static function insert(string $table, array $row): string
    {
        $columns = implode(',', array_map(self::identifier(...), array_keys($row)));
        $values = implode(',', array_map(self::value(...), array_values($row)));
        return 'INSERT INTO ' . self::identifier($table) . " ($columns) VALUES ($values);\n";
    }

    /** @param array<string,mixed> $values */
    public static function update(string $table, array $values, string $idColumn, mixed $id): string
    {
        $sets = [];
        foreach ($values as $column => $value) {
            $sets[] = self::identifier($column) . '=' . self::value($value);
        }
        return 'UPDATE ' . self::identifier($table) . ' SET ' . implode(',', $sets)
            . ' WHERE ' . self::identifier($idColumn) . '=' . self::value($id) . ";\n";
    }
}
