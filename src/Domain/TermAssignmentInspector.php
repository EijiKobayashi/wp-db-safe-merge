<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use WpDbSafeMerge\Infrastructure\DumpStore;

final class TermAssignmentInspector
{
    public static function id(string $taxonomy, string $slug): string
    {
        return hash('sha256', $taxonomy . "\0" . $slug);
    }

    /** @return array<string,array{id:string,taxonomy:string,name:string,slug:string,references:int,term_id:int,term_taxonomy_id:int}> */
    public function catalog(DumpStore $store, string $prefix, bool $countReferences = true): array
    {
        foreach (['terms', 'term_taxonomy', 'term_relationships'] as $suffix) {
            if (!in_array($prefix . $suffix, $store->tables(), true)) { return []; }
        }
        $terms = [];
        foreach ($store->rows($prefix . 'terms') as $term) { $terms[(int) ($term['term_id'] ?? 0)] = $term; }
        $references = [];
        if ($countReferences) {
            foreach ($store->rows($prefix . 'term_relationships') as $relationship) {
                $taxId = (int) ($relationship['term_taxonomy_id'] ?? 0);
                $references[$taxId] = ($references[$taxId] ?? 0) + 1;
            }
        }
        $catalog = [];
        foreach ($store->rows($prefix . 'term_taxonomy') as $taxonomy) {
            $term = $terms[(int) ($taxonomy['term_id'] ?? 0)] ?? null;
            if ($term === null) { continue; }
            $taxonomyName = (string) ($taxonomy['taxonomy'] ?? '');
            $slug = (string) ($term['slug'] ?? '');
            $id = self::id($taxonomyName, $slug);
            $taxId = (int) ($taxonomy['term_taxonomy_id'] ?? 0);
            $catalog[$id] = [
                'id' => $id, 'taxonomy' => $taxonomyName, 'name' => (string) ($term['name'] ?? ''),
                'slug' => $slug, 'references' => $references[$taxId] ?? 0,
                'term_id' => (int) ($term['term_id'] ?? 0), 'term_taxonomy_id' => $taxId,
            ];
        }
        uasort($catalog, static fn (array $left, array $right): int =>
            [$left['taxonomy'], $left['name'], $left['slug']] <=> [$right['taxonomy'], $right['name'], $right['slug']]
        );
        return $catalog;
    }

    /** @return array{additions:list<array<string,mixed>>,unused_base:list<array<string,mixed>>} */
    public function review(DumpStore $base, DumpStore $incoming, string $basePrefix, string $incomingPrefix): array
    {
        $baseCatalog = $this->catalog($base, $basePrefix);
        $incomingCatalog = $this->catalog($incoming, $incomingPrefix);
        return [
            'additions' => array_values(array_diff_key($incomingCatalog, $baseCatalog)),
            'unused_base' => array_values(array_filter($baseCatalog, static fn (array $term): bool => $term['references'] === 0)),
        ];
    }

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

        $catalog = $this->catalog($store, $prefix, false);
        $taxonomies = array_column($catalog, null, 'term_taxonomy_id');

        foreach ($postIds as $postId) {
            foreach ($store->rowsByReference($prefix . 'term_relationships', 'object_id', $postId) as $relationship) {
                $term = $taxonomies[(int) ($relationship['term_taxonomy_id'] ?? 0)] ?? null;
                if ($term === null) { continue; }
                $result[$postId][] = [
                    'id' => (string) $term['id'], 'taxonomy' => (string) $term['taxonomy'],
                    'name' => (string) $term['name'], 'slug' => (string) $term['slug'],
                ];
            }
            usort($result[$postId], static fn (array $left, array $right): int =>
                [$left['taxonomy'], $left['name'], $left['slug']] <=> [$right['taxonomy'], $right['name'], $right['slug']]
            );
        }
        return $result;
    }
}
