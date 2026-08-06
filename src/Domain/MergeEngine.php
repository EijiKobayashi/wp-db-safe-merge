<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use RuntimeException;
use WpDbSafeMerge\Infrastructure\DumpStore;
use WpDbSafeMerge\Infrastructure\SqlWriter;

final class MergeEngine
{
    /** @var array<int,array<string,string>> */
    private array $acfTypeCache = [];

    private const CORE_FIELDS = [
        'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
        'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping',
        'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent',
        'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count',
    ];

    public function __construct(private readonly SerializedValueTransformer $serialized = new SerializedValueTransformer()) {}

    /** @return array<string,mixed> */
    public function merge(
        string $baseSql,
        string $outputSql,
        DumpStore $base,
        DumpStore $incoming,
        ComparisonStore $comparison,
        string $reportPath,
    ): array {
        if (!copy($baseSql, $outputSql)) {
            throw new RuntimeException('基準SQLを出力先へコピーできません。');
        }
        $handle = fopen($outputSql, 'ab');
        if ($handle === false) {
            throw new RuntimeException('統合SQLを作成できません。');
        }

        $basePrefix = $base->meta('prefix') ?? 'wp_';
        $incomingPrefix = $incoming->meta('prefix') ?? 'wp_';
        $postTable = $basePrefix . 'posts';
        $postColumns = $base->columns($postTable);
        if ($postColumns === []) {
            throw new RuntimeException('基準DBのpostsテーブル定義がありません。');
        }

        $comparisons = iterator_to_array($comparison->allComparisons(), false);
        $maxPostId = $this->maxId($base, $postTable, 'ID');
        $postMap = [];
        foreach ($comparisons as $item) {
            if ($item['incoming_id'] === null) { continue; }
            if ($item['base_id'] !== null) {
                $postMap[(int) $item['incoming_id']] = (int) $item['base_id'];
            } else {
                $postMap[(int) $item['incoming_id']] = ++$maxPostId;
            }
        }

        $report = [
            'generated_at' => gmdate(DATE_ATOM), 'base_prefix' => $basePrefix,
            'incoming_prefix' => $incomingPrefix, 'post_id_map' => $postMap,
            'updated' => 0, 'added' => 0, 'meta_rows' => 0, 'term_relationships' => 0,
            'plugin_rows' => 0, 'warnings' => [], 'decisions' => [],
        ];

        fwrite($handle, "\n\n-- WP DB Safety Merge generated operations\nSTART TRANSACTION;\nSET FOREIGN_KEY_CHECKS=0;\n");
        try {
            foreach ($comparisons as $item) {
                $incomingRow = $item['incoming'];
                if (!is_array($incomingRow)) { continue; }
                $decision = is_array($item['decision']) ? $item['decision'] : [];
                $winner = (string) ($decision['winner'] ?? $item['recommended']);
                if ($winner === 'manual') { $winner = 'base'; }
                if ($item['base_id'] === null) {
                    $newId = $postMap[(int) $item['incoming_id']];
                    $incomingRow['ID'] = (string) $newId;
                    $incomingRow['post_parent'] = $postMap[(int) ($incomingRow['post_parent'] ?? 0)] ?? ($incomingRow['post_parent'] ?? '0');
                    fwrite($handle, SqlWriter::insert($postTable, $this->align($postColumns, $incomingRow)));
                    $this->writePostMeta($handle, $incoming, $incomingPrefix, $basePrefix, (int) $item['incoming_id'], $newId, $postMap, false, $report);
                    $report['added']++;
                } else {
                    $values = [];
                    $fieldChoices = is_array($decision['fields'] ?? null) ? $decision['fields'] : [];
                    foreach (self::CORE_FIELDS as $field) {
                        if (array_key_exists($field, $incomingRow) && (($fieldChoices[$field] ?? $winner) === 'incoming')) {
                            $values[$field] = $field === 'post_parent'
                                ? ($postMap[(int) $incomingRow[$field]] ?? $incomingRow[$field])
                                : $incomingRow[$field];
                        }
                    }
                    if ($values !== []) {
                        fwrite($handle, SqlWriter::update($postTable, $values, 'ID', $item['base_id']));
                    }
                    if (($fieldChoices['_meta'] ?? $winner) === 'incoming') {
                        $this->writePostMeta($handle, $incoming, $incomingPrefix, $basePrefix, (int) $item['incoming_id'], (int) $item['base_id'], $postMap, true, $report);
                    }
                    if ($values !== [] || (($fieldChoices['_meta'] ?? $winner) === 'incoming')) {
                        $report['updated']++;
                    }
                }
                $report['decisions'][] = ['comparison_id' => (int) $item['id'], 'kind' => $item['kind'], 'winner' => $winner];
            }

            $this->writeTerms($handle, $base, $incoming, $basePrefix, $incomingPrefix, $postMap, $report);
            $this->writePluginTables($handle, $base, $incoming, $basePrefix, $incomingPrefix, $postMap, $report);
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\nCOMMIT;\n");
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($outputSql);
            throw $e;
        }
        fclose($handle);
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $report;
    }

    /** @param resource $handle @param array<int,int> $postMap @param array<string,mixed> $report */
    private function writePostMeta($handle, DumpStore $source, string $sourcePrefix, string $targetPrefix, int $sourceId, int $targetId, array $postMap, bool $replace, array &$report): void
    {
        $sourceTable = $sourcePrefix . 'postmeta';
        if (!in_array($sourceTable, $source->tables(), true)) { return; }
        if ($replace) {
            fwrite($handle, 'DELETE FROM ' . SqlWriter::identifier($targetPrefix . 'postmeta') . ' WHERE `post_id`=' . SqlWriter::value($targetId) . ";\n");
        }
        $rows = [];
        foreach ($source->rows($sourceTable) as $row) {
            if ((int) ($row['post_id'] ?? 0) === $sourceId) { $rows[] = $row; }
        }
        $metaByKey = [];
        foreach ($rows as $row) { $metaByKey[(string) ($row['meta_key'] ?? '')] = (string) ($row['meta_value'] ?? ''); }
        $acfTypes = $this->acfTypes($source);
        $directReferenceKeys = ['_thumbnail_id', '_menu_item_object_id'];
        $acfPostReferenceTypes = ['image', 'file', 'gallery', 'post_object', 'relationship', 'page_link'];
        foreach ($rows as $row) {
            unset($row['meta_id']);
            $row['post_id'] = (string) $targetId;
            $metaKey = (string) ($row['meta_key'] ?? '');
            $acfFieldKey = $metaByKey['_' . $metaKey] ?? '';
            $isReference = in_array($metaKey, $directReferenceKeys, true)
                || ($acfFieldKey !== '' && in_array($acfTypes[$acfFieldKey] ?? '', $acfPostReferenceTypes, true));
            if ($isReference) {
                $row['meta_value'] = $this->serialized->transform($row['meta_value'] ?? '', $postMap, $isReference);
            }
            fwrite($handle, SqlWriter::insert($targetPrefix . 'postmeta', $row));
            $report['meta_rows']++;
        }
    }

    /** @return array<string,string> */
    private function acfTypes(DumpStore $store): array
    {
        $cacheKey = spl_object_id($store);
        if (isset($this->acfTypeCache[$cacheKey])) { return $this->acfTypeCache[$cacheKey]; }
        $types = [];
        $posts = ($store->meta('prefix') ?? 'wp_') . 'posts';
        foreach ($store->rows($posts) as $row) {
            if (($row['post_type'] ?? '') !== 'acf-field') { continue; }
            $config = @unserialize((string) ($row['post_content'] ?? ''), ['allowed_classes' => false]);
            if (is_array($config) && isset($config['type'])) {
                $types[(string) ($row['post_name'] ?? '')] = (string) $config['type'];
            }
        }
        return $this->acfTypeCache[$cacheKey] = $types;
    }

    /** @param resource $handle @param array<int,int> $postMap @param array<string,mixed> $report */
    private function writeTerms($handle, DumpStore $base, DumpStore $incoming, string $basePrefix, string $incomingPrefix, array $postMap, array &$report): void
    {
        $required = ['terms', 'term_taxonomy', 'term_relationships'];
        foreach ($required as $suffix) {
            if (!in_array($incomingPrefix . $suffix, $incoming->tables(), true) || !in_array($basePrefix . $suffix, $base->tables(), true)) { return; }
        }
        $baseTerms = $this->keyRows($base, $basePrefix . 'terms', 'term_id');
        $incomingTerms = $this->keyRows($incoming, $incomingPrefix . 'terms', 'term_id');
        $baseTax = $this->keyRows($base, $basePrefix . 'term_taxonomy', 'term_taxonomy_id');
        $incomingTax = $this->keyRows($incoming, $incomingPrefix . 'term_taxonomy', 'term_taxonomy_id');
        $maxTerm = $baseTerms === [] ? 0 : max(array_keys($baseTerms));
        $maxTax = $baseTax === [] ? 0 : max(array_keys($baseTax));
        $termMap = [];
        $taxMap = [];
        foreach ($incomingTax as $sourceTaxId => $tax) {
            $term = $incomingTerms[(int) ($tax['term_id'] ?? 0)] ?? null;
            if ($term === null) { continue; }
            $existingTaxId = null;
            foreach ($baseTax as $baseTaxId => $baseTaxRow) {
                $baseTerm = $baseTerms[(int) ($baseTaxRow['term_id'] ?? 0)] ?? null;
                if ($baseTerm && ($baseTaxRow['taxonomy'] ?? '') === ($tax['taxonomy'] ?? '') && ($baseTerm['slug'] ?? '') === ($term['slug'] ?? '')) {
                    $existingTaxId = $baseTaxId;
                    $termMap[(int) $term['term_id']] = (int) $baseTerm['term_id'];
                    break;
                }
            }
            if ($existingTaxId !== null) {
                $taxMap[$sourceTaxId] = $existingTaxId;
                continue;
            }
            $newTermId = ++$maxTerm;
            $newTaxId = ++$maxTax;
            $termMap[(int) $term['term_id']] = $newTermId;
            $taxMap[$sourceTaxId] = $newTaxId;
            $term['term_id'] = (string) $newTermId;
            $tax['term_taxonomy_id'] = (string) $newTaxId;
            $tax['term_id'] = (string) $newTermId;
            $tax['parent'] = isset($termMap[(int) ($tax['parent'] ?? 0)]) ? (string) $termMap[(int) $tax['parent']] : '0';
            fwrite($handle, SqlWriter::insert($basePrefix . 'terms', $term));
            fwrite($handle, SqlWriter::insert($basePrefix . 'term_taxonomy', $tax));
        }
        foreach ($incoming->rows($incomingPrefix . 'term_relationships') as $relationship) {
            $sourcePost = (int) ($relationship['object_id'] ?? 0);
            $sourceTax = (int) ($relationship['term_taxonomy_id'] ?? 0);
            if (!isset($postMap[$sourcePost], $taxMap[$sourceTax])) { continue; }
            $relationship['object_id'] = (string) $postMap[$sourcePost];
            $relationship['term_taxonomy_id'] = (string) $taxMap[$sourceTax];
            fwrite($handle, 'INSERT IGNORE INTO ' . SqlWriter::identifier($basePrefix . 'term_relationships') . ' (`object_id`,`term_taxonomy_id`,`term_order`) VALUES ('
                . SqlWriter::value($relationship['object_id']) . ',' . SqlWriter::value($relationship['term_taxonomy_id']) . ',' . SqlWriter::value($relationship['term_order'] ?? '0') . ");\n");
            $report['term_relationships']++;
        }
    }

    /** @param resource $handle @param array<int,int> $postMap @param array<string,mixed> $report */
    private function writePluginTables($handle, DumpStore $base, DumpStore $incoming, string $basePrefix, string $incomingPrefix, array $postMap, array &$report): void
    {
        $suffixes = ['yoast_indexable', 'yoast_primary_term', 'yoast_seo_links'];
        foreach ($suffixes as $suffix) {
            $sourceTable = $incomingPrefix . $suffix;
            $targetTable = $basePrefix . $suffix;
            if (!in_array($sourceTable, $incoming->tables(), true) || !in_array($targetTable, $base->tables(), true)) { continue; }
            foreach ($incoming->rows($sourceTable) as $row) {
                $reference = null;
                foreach (['object_id', 'post_id', 'target_post_id'] as $column) {
                    if (isset($row[$column]) && isset($postMap[(int) $row[$column]])) {
                        $row[$column] = (string) $postMap[(int) $row[$column]];
                        $reference = true;
                    }
                }
                if ($reference === null) { continue; }
                unset($row['id']);
                $targetColumns = array_flip($base->columns($targetTable));
                $row = array_intersect_key($row, $targetColumns);
                if ($row === []) { continue; }
                fwrite($handle, SqlWriter::insert($targetTable, $row));
                $report['plugin_rows']++;
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function keyRows(DumpStore $store, string $table, string $key): array
    {
        $result = [];
        foreach ($store->rows($table) as $row) { $result[(int) ($row[$key] ?? 0)] = $row; }
        return $result;
    }

    private function maxId(DumpStore $store, string $table, string $column): int
    {
        $max = 0;
        foreach ($store->rows($table) as $row) { $max = max($max, (int) ($row[$column] ?? 0)); }
        return $max;
    }

    /** @param list<string> $columns @param array<string,mixed> $row @return array<string,mixed> */
    private function align(array $columns, array $row): array
    {
        $aligned = [];
        foreach ($columns as $column) { $aligned[$column] = $row[$column] ?? null; }
        return $aligned;
    }
}
