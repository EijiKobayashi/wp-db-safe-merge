<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Support;

use RuntimeException;

final class Workspace
{
    public function __construct(private readonly string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('作業ディレクトリを作成できません。');
        }
    }

    public function create(): string
    {
        $id = bin2hex(random_bytes(16));
        $path = $this->root . '/' . $id;
        if (!mkdir($path, 0700, true)) {
            throw new RuntimeException('作業領域を作成できません。');
        }
        $this->saveState($id, ['id' => $id, 'created_at' => time(), 'status' => 'created']);
        return $id;
    }

    public function path(string $id, string $file = ''): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('無効な作業IDです。');
        }
        $base = $this->root . '/' . $id;
        if (!is_dir($base)) {
            throw new RuntimeException('作業領域が見つかりません。');
        }
        return $file === '' ? $base : $base . '/' . basename($file);
    }

    /** @return array<string,mixed> */
    public function state(string $id): array
    {
        $json = file_get_contents($this->path($id, 'state.json'));
        $state = $json === false ? null : json_decode($json, true);
        if (!is_array($state)) {
            throw new RuntimeException('作業状態を読み込めません。');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    public function saveState(string $id, array $state): void
    {
        $path = $this->path($id, 'state.json');
        $temporary = $path . '.tmp';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new RuntimeException('作業状態を保存できません。');
        }
        chmod($path, 0600);
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $target = $path . '/' . $item;
                if (is_file($target)) {
                    unlink($target);
                }
            }
        }
        rmdir($path);
    }

    public function cleanup(int $ttl): void
    {
        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            if ((filemtime($path) ?: time()) < time() - $ttl) {
                $id = basename($path);
                try {
                    $this->delete($id);
                } catch (RuntimeException) {
                    // A concurrent request may still own the workspace.
                }
            }
        }
    }
}
