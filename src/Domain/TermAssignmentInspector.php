<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use WpDbSafeMerge\Infrastructure\DumpStore;

final class TermAssignmentInspector
{
    /**
     * @param list<int> $postIds
     * @return array<int,list<array{taxonomy:string,name:string,slug:string}>>
     */
    public function inspect(DumpStore $store, string $prefix, array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));
        $result = array_fill_keys($postIds, []);
        if ($postIds === []) { return $result; }

        foreach (['terms', 'term_taxonomy', 'term_relationships'] as $suffix) {
            if (!in_array($prefix . $suffix, $store->tables(), true)) { return $result; }
        }

        $terms = [];
        foreach ($store->rows($prefix . 'terms') as $term) {
            $terms[(int) ($term['term_id'] ?? 0)] = $term;
        }
        $taxonomies = [];
        foreach ($store->rows($prefix . 'term_taxonomy') as $taxonomy) {
            $taxonomies[(int) ($taxonomy['term_taxonomy_id'] ?? 0)] = $taxonomy;
        }

        foreach ($postIds as $postId) {
            foreach ($store->rowsByReference($prefix . 'term_relationships', 'object_id', $postId) as $relationship) {
                $taxonomy = $taxonomies[(int) ($relationship['term_taxonomy_id'] ?? 0)] ?? null;
                $term = $taxonomy === null ? null : ($terms[(int) ($taxonomy['term_id'] ?? 0)] ?? null);
                if ($taxonomy === null || $term === null) { continue; }
                $result[$postId][] = [
                    'taxonomy' => (string) ($taxonomy['taxonomy'] ?? ''),
                    'name' => (string) ($term['name'] ?? ''),
                    'slug' => (string) ($term['slug'] ?? ''),
                ];
            }
            usort($result[$postId], static fn (array $left, array $right): int =>
                [$left['taxonomy'], $left['name'], $left['slug']] <=> [$right['taxonomy'], $right['name'], $right['slug']]
            );
        }
        return $result;
    }
}
