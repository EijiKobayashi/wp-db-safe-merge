<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Infrastructure;

use Generator;
use RuntimeException;

final class SqlStatementReader
{
    /** @return Generator<int,string> SQLをコメントや書式ごと保持して文単位で返す。 */
    public function readRaw(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) { throw new RuntimeException('SQLファイルを開けません。'); }
        $statement = '';
        $quote = null;
        $escaped = $lineComment = $blockComment = false;
        $previous = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) { throw new RuntimeException('SQLファイルの読み込みに失敗しました。'); }
                $length = strlen($chunk);
                for ($i = 0; $i < $length; $i++) {
                    $char = $chunk[$i];
                    $next = $i + 1 < $length ? $chunk[$i + 1] : '';
                    $statement .= $char;
                    if ($lineComment) {
                        if ($char === "\n") { $lineComment = false; }
                        continue;
                    }
                    if ($blockComment) {
                        if ($previous === '*' && $char === '/') { $blockComment = false; }
                        $previous = $char;
                        continue;
                    }
                    if ($quote !== null) {
                        if ($escaped) { $escaped = false; }
                        elseif ($char === '\\') { $escaped = true; }
                        elseif ($char === $quote) {
                            if ($next === $quote && $quote !== '`') { $statement .= $next; $i++; }
                            else { $quote = null; }
                        }
                        continue;
                    }
                    if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($chunk[$i + 2]))) || $char === '#') {
                        $lineComment = true;
                        if ($char === '-') { $statement .= $next; $i++; }
                    } elseif ($char === '/' && $next === '*') {
                        $blockComment = true; $previous = ''; $statement .= $next; $i++;
                    } elseif ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                    } elseif ($char === ';') {
                        yield $statement;
                        $statement = '';
                    }
                }
            }
            if ($statement !== '') { yield $statement; }
        } finally { fclose($handle); }
    }

    /** @return Generator<int,string> */
    public function read(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('SQLファイルを開けません。');
        }

        $statement = '';
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $previous = '';

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('SQLファイルの読み込みに失敗しました。');
                }
                $length = strlen($chunk);
                for ($i = 0; $i < $length; $i++) {
                    $char = $chunk[$i];
                    $next = $i + 1 < $length ? $chunk[$i + 1] : '';

                    if ($lineComment) {
                        if ($char === "\n") {
                            $lineComment = false;
                            $statement .= ' ';
                        }
                        continue;
                    }
                    if ($blockComment) {
                        if ($previous === '*' && $char === '/') {
                            $blockComment = false;
                        }
                        $previous = $char;
                        continue;
                    }
                    if ($quote !== null) {
                        $statement .= $char;
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === $quote) {
                            if ($next === $quote && $quote !== '`') {
                                $statement .= $next;
                                $i++;
                            } else {
                                $quote = null;
                            }
                        }
                        continue;
                    }

                    if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($chunk[$i + 2]))) || $char === '#') {
                        $lineComment = true;
                        if ($char === '-') {
                            $i++;
                        }
                        continue;
                    }
                    if ($char === '/' && $next === '*') {
                        $blockComment = true;
                        $previous = '';
                        $i++;
                        continue;
                    }
                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $statement .= $char;
                        continue;
                    }
                    if ($char === ';') {
                        $trimmed = trim($statement);
                        if ($trimmed !== '') {
                            yield $trimmed;
                        }
                        $statement = '';
                        continue;
                    }
                    $statement .= $char;
                }
            }

            if ($quote !== null || $blockComment) {
                throw new RuntimeException('SQLが途中で終了しています（引用符またはコメントが閉じられていません）。');
            }
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                yield $trimmed;
            }
        } finally {
            fclose($handle);
        }
    }
}
