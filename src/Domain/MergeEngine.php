<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use RuntimeException;
use WpDbSafeMerge\Infrastructure\DumpStore;
use WpDbSafeMerge\Infrastructure\SqlStatementReader;
use WpDbSafeMerge\Infrastructure\SqlWriter;

final class MergeEngine
{
    private const INSERT_BATCH_SIZE = 250;
    private const INSERT_BATCH_MAX_BYTES = 1048576;
    private const TRANSACTION_BATCH_SIZE = 50000;

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
        ?callable $onProgress = null,
        ?string $deltaSql = null,
        ?array $urlNormalizationTables = null,
        ?array $emailNormalizationRules = null,
        ?array $termAdditionIds = null,
    ): array {
        $operationsSql = $outputSql . '.operations.tmp';
        $canonicalPath = $outputSql . '.canonical.sqlite';
        $handle = fopen($operationsSql, 'wb');
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
        $managedTables = array_values(array_filter(array_map(
            static fn (string $suffix): string => $basePrefix . $suffix,
            ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'yoast_indexable', 'yoast_primary_term', 'yoast_seo_links']
        ), static fn (string $table): bool => in_array($table, $base->tables(), true)));
        $canonical = $this->cloneManagedTables($base, $managedTables, $canonicalPath);

        $maxPostId = $this->maxId($base, $postTable, 'ID');
        $postMap = [];
        foreach ($comparison->allComparisons() as $item) {
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
        $urlTransformer = $this->urlTransformer($base, $incoming, $report, $urlNormalizationTables, $emailNormalizationRules);
        $termChoices = [];

        fwrite($handle, "-- WP DB Safety Merge generated operations\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET @WPDBSM_OLD_SQL_MODE=@@SESSION.SQL_MODE;\n");
        fwrite($handle, "SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        try {
            $total = max(1, array_sum($comparison->counts()));
            $processed = 0;
            foreach ($comparison->allComparisons() as $item) {
                $incomingRow = $item['incoming'];
                if (!is_array($incomingRow)) { continue; }
                $decision = is_array($item['decision']) ? $item['decision'] : [];
                $winner = (string) ($decision['winner'] ?? $item['recommended']);
                if ($winner === 'manual') { $winner = 'base'; }
                if ($item['base_id'] === null) {
                    $newId = $postMap[(int) $item['incoming_id']];
                    $termChoices[$newId] = is_array($decision['terms'] ?? null) ? $decision['terms'] : 'incoming';
                    $incomingRow['ID'] = (string) $newId;
                    $incomingRow['post_parent'] = $postMap[(int) ($incomingRow['post_parent'] ?? 0)] ?? ($incomingRow['post_parent'] ?? '0');
                    $aligned = $this->align($postColumns, $incomingRow);
                    fwrite($handle, SqlWriter::insert($postTable, $aligned));
                    $canonical->row($postTable, $aligned);
                    $this->writePostMeta($handle, $incoming, $incomingPrefix, $basePrefix, (int) $item['incoming_id'], $newId, $postMap, false, $report, null, $canonical);
                    $report['added']++;
                } else {
                    $values = [];
                    $baseRow = is_array($item['base']) ? $item['base'] : [];
                    $fieldChoices = is_array($decision['fields'] ?? null) ? $decision['fields'] : [];
                    $termChoices[(int) $item['base_id']] = is_array($decision['terms'] ?? null)
                        ? array_values(array_unique(array_map('strval', $decision['terms'])))
                        : (($fieldChoices['_terms'] ?? $winner) === 'incoming' ? 'incoming' : 'base');
                    foreach (self::CORE_FIELDS as $field) {
                        if (!array_key_exists($field, $incomingRow) || (($fieldChoices[$field] ?? $winner) !== 'incoming')) {
                            continue;
                        }
                        $incomingValue = $field === 'post_parent'
                            ? ($postMap[(int) $incomingRow[$field]] ?? $incomingRow[$field])
                            : $incomingRow[$field];
                        if (!array_key_exists($field, $baseRow) || (string) $baseRow[$field] !== (string) $incomingValue) {
                            $values[$field] = $incomingValue;
                        }
                    }
                    if ($values !== []) {
                        fwrite($handle, SqlWriter::update($postTable, $values, 'ID', $item['base_id']));
                        $canonical->replaceWhere($postTable, ['ID' => $item['base_id']], array_replace($baseRow, $values));
                    }
                    $metaChanged = false;
                    if (($fieldChoices['_meta'] ?? $winner) === 'incoming') {
                        $metaChanged = $this->writePostMeta(
                            $handle, $incoming, $incomingPrefix, $basePrefix, (int) $item['incoming_id'],
                            (int) $item['base_id'], $postMap, true, $report, $base, $canonical
                        );
                    }
                    if ($values !== [] || $metaChanged) {
                        $report['updated']++;
                    }
                }
                $report['decisions'][] = [
                    'comparison_id' => (int) $item['id'],
                    'kind' => $item['kind'],
                    'winner' => $winner,
                    'terms' => $termChoices[(int) ($item['base_id'] ?? $postMap[(int) $item['incoming_id']])] ?? 'base',
                ];
                $processed++;
                if ($processed % self::TRANSACTION_BATCH_SIZE === 0 && $processed < $total) {
                    $this->writeTransactionCheckpoint($handle);
                }
                if ($onProgress !== null && ($processed % max(1, intdiv($total, 20)) === 0 || $processed === $total)) {
                    $onProgress(15 + (int) floor(($processed / $total) * 70), "投稿とカスタムフィールドを統合しています（{$processed}/{$total}）");
                }
            }

            $this->writeTransactionCheckpoint($handle);
            if ($onProgress !== null) { $onProgress(88, 'タームとタクソノミーを統合しています'); }
            $this->writeTerms($handle, $base, $incoming, $basePrefix, $incomingPrefix, $postMap, $termChoices, $report, $canonical, $termAdditionIds);
            $this->writeTransactionCheckpoint($handle);
            if ($onProgress !== null) { $onProgress(94, 'プラグイン関連データを統合しています'); }
            $this->writePluginTables($handle, $base, $incoming, $basePrefix, $incomingPrefix, $postMap, $report, $canonical);
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fwrite($handle, "SET SESSION SQL_MODE=@WPDBSM_OLD_SQL_MODE;\n");
            fwrite($handle, "COMMIT;\n");
            fwrite($handle, "-- WP DB Safety Merge generated operations end\n");
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($outputSql);
            throw $e;
        }
        fclose($handle);
        $this->writeCanonicalFullSql($baseSql, $outputSql, $canonical, $managedTables, $urlTransformer, $urlNormalizationTables, $emailNormalizationRules, $report);
        if ($deltaSql !== null) { $this->writeNormalizedSql($operationsSql, $deltaSql, $urlTransformer, $urlNormalizationTables, $emailNormalizationRules); }
        if ($deltaSql !== null) { $report['delta_bytes'] = filesize($deltaSql) ?: 0; }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        @unlink($operationsSql);
        foreach ([$canonicalPath, $canonicalPath . '-wal', $canonicalPath . '-shm'] as $path) { @unlink($path); }
        return $report;
    }

    /** @param resource $handle */
    private function writeTransactionCheckpoint($handle): void
    {
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($handle, "COMMIT;\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
    }

    /** @param list<string> $managedTables */
    private function cloneManagedTables(DumpStore $base, array $managedTables, string $path): DumpStore
    {
        $canonical = new DumpStore($path);
        $canonical->begin();
        try {
            foreach ($managedTables as $table) {
                $canonical->table($table, $base->columns($table));
                foreach ($base->rows($table) as $row) { $canonical->row($table, $row); }
            }
            $canonical->commit();
        } catch (\Throwable $e) {
            $canonical->rollback();
            throw $e;
        }
        return $canonical;
    }

    /** @param list<string> $managedTables */
    private function writeCanonicalFullSql(
        string $baseSql,
        string $outputSql,
        DumpStore $canonical,
        array $managedTables,
        ?UrlValueTransformer $urlTransformer,
        ?array $urlNormalizationTables,
        ?array $emailNormalizationRules,
        array &$report,
    ): void
    {
        $output = fopen($outputSql, 'wb');
        if ($output === false) { throw new RuntimeException('統合SQLを作成できません。'); }
        $managed = array_fill_keys($managedTables, true);
        $emitted = [];
        try {
            foreach ((new SqlStatementReader())->readRaw($baseSql) as $raw) {
                $insertTable = $this->rawStatementTable($raw, '(?:INSERT|REPLACE)(?:\\s+IGNORE)?\\s+INTO');
                if ($insertTable !== null && isset($managed[$insertTable])) { continue; }
                $tableTransformer = $insertTable !== null && $urlTransformer !== null
                    ? $this->tableTransformer($urlTransformer, $insertTable, $urlNormalizationTables, $emailNormalizationRules)
                    : null;
                if ($tableTransformer !== null) {
                    $normalized = $tableTransformer->transformSql($raw);
                    $raw = $normalized['sql'];
                    $this->recordUrlReplacements($report, $insertTable, $normalized['replacements']);
                }
                fwrite($output, $raw);
                $createTable = $this->rawStatementTable($raw, 'CREATE\\s+TABLE(?:\\s+IF\\s+NOT\\s+EXISTS)?');
                if ($createTable === null || !isset($managed[$createTable]) || isset($emitted[$createTable])) { continue; }
                fwrite($output, "\n-- WP DB Safety Merge canonical table data\nSTART TRANSACTION;\n");
                fwrite($output, "SET @WPDBSM_OLD_SQL_MODE=@@SESSION.SQL_MODE;\nSET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n");
                $count = 0;
                $columns = $canonical->columns($createTable);
                $batch = [];
                $batchBytes = 0;
                $tableTransformer = $urlTransformer !== null
                    ? $this->tableTransformer($urlTransformer, $createTable, $urlNormalizationTables, $emailNormalizationRules)
                    : null;
                foreach ($canonical->rows($createTable) as $row) {
                    $values = array_values($this->align($columns, $row));
                    if ($tableTransformer !== null) {
                        foreach ($values as &$value) {
                            $normalized = $tableTransformer->transform($value);
                            $value = $normalized['value'];
                            $this->recordUrlReplacements($report, $createTable, $normalized['replacements']);
                        }
                        unset($value);
                    }
                    $estimatedBytes = 3;
                    foreach ($values as $value) { $estimatedBytes += strlen((string) $value) * 2 + 3; }
                    if ($batch !== [] && (count($batch) >= self::INSERT_BATCH_SIZE
                        || $batchBytes + $estimatedBytes > self::INSERT_BATCH_MAX_BYTES)) {
                        fwrite($output, SqlWriter::insertRows($createTable, $columns, $batch));
                        $batch = [];
                        $batchBytes = 0;
                    }
                    $batch[] = $values;
                    $batchBytes += $estimatedBytes;
                    $count++;
                    if ($count % self::TRANSACTION_BATCH_SIZE === 0) {
                        if ($batch !== []) {
                            fwrite($output, SqlWriter::insertRows($createTable, $columns, $batch));
                            $batch = [];
                            $batchBytes = 0;
                        }
                        $this->writeTransactionCheckpoint($output);
                    }
                }
                if ($batch !== []) { fwrite($output, SqlWriter::insertRows($createTable, $columns, $batch)); }
                fwrite($output, "SET FOREIGN_KEY_CHECKS=1;\nSET SESSION SQL_MODE=@WPDBSM_OLD_SQL_MODE;\nCOMMIT;\n");
                $emitted[$createTable] = true;
            }
        } finally { fclose($output); }
        $missing = array_values(array_diff($managedTables, array_keys($emitted)));
        if ($missing !== []) {
            @unlink($outputSql);
            throw new RuntimeException('完全版SQLで再構築対象テーブルを検出できません: ' . implode(', ', $missing));
        }
    }

    /** @param array<string,mixed> $report */
    private function urlTransformer(DumpStore $base, DumpStore $incoming, array &$report, ?array $urlNormalizationTables, ?array $emailNormalizationRules): ?UrlValueTransformer
    {
        $baseUrl = $base->meta('home') ?? $base->meta('siteurl');
        $incomingUrls = array_values(array_unique(array_filter([
            $incoming->meta('home'),
            $incoming->meta('siteurl'),
        ], static fn (?string $url): bool => $url !== null && $url !== '')));
        if ($baseUrl === null || $incomingUrls === []) {
            $report['warnings'][] = 'home/siteurlを検出できないため、追加側URLの正規化を実行しませんでした。';
            return null;
        }
        try {
            $transformer = new UrlValueTransformer($baseUrl, ...$incomingUrls);
        } catch (\InvalidArgumentException) {
            $report['warnings'][] = 'home/siteurlの形式が不正なため、追加側URLの正規化を実行しませんでした。';
            return null;
        }
        $report['url_normalization'] = [
            'base_url' => $baseUrl,
            'incoming_urls' => $incomingUrls,
            'target_origin' => $transformer->targetOrigin(),
            'selected_tables' => $urlNormalizationTables,
            'selected_email_rules' => $emailNormalizationRules,
            'replacements' => 0,
            'tables' => [],
        ];
        return $transformer;
    }

    /** @param array<string,mixed> $report */
    private function recordUrlReplacements(array &$report, string $table, int $count): void
    {
        if ($count <= 0 || !isset($report['url_normalization'])) { return; }
        $report['url_normalization']['replacements'] += $count;
        $report['url_normalization']['tables'][$table] = ($report['url_normalization']['tables'][$table] ?? 0) + $count;
    }

    private function writeNormalizedSql(
        string $source,
        string $target,
        ?UrlValueTransformer $transformer,
        ?array $urlNormalizationTables,
        ?array $emailNormalizationRules,
    ): void
    {
        if ($transformer === null) {
            if (!copy($source, $target)) { throw new RuntimeException('統合差分SQLを作成できません。'); }
            return;
        }
        $output = fopen($target, 'wb');
        if ($output === false) { throw new RuntimeException('統合差分SQLを作成できません。'); }
        try {
            foreach ((new SqlStatementReader())->readRaw($source) as $raw) {
                $table = $this->rawStatementTable($raw, '(?:INSERT|REPLACE)(?:\\s+IGNORE)?\\s+INTO')
                    ?? $this->rawStatementTable($raw, 'UPDATE');
                $tableTransformer = $table !== null
                    ? $this->tableTransformer($transformer, $table, $urlNormalizationTables, $emailNormalizationRules)
                    : null;
                fwrite($output, $tableTransformer !== null ? $tableTransformer->transformSql($raw)['sql'] : $raw);
            }
        } finally {
            fclose($output);
        }
    }

    private function tableTransformer(
        UrlValueTransformer $transformer,
        string $table,
        ?array $urlNormalizationTables,
        ?array $emailNormalizationRules,
    ): ?UrlValueTransformer
    {
        $replaceUrl = $urlNormalizationTables === null || in_array($table, $urlNormalizationTables, true);
        $emailReplacements = (array) ($emailNormalizationRules[$table] ?? []);
        $replaceEmail = $emailReplacements !== [];
        if (!$replaceUrl && !$replaceEmail) { return null; }
        return $transformer->withUrlAndHosts($replaceUrl)->withEmailDomains($replaceEmail)->withEmailReplacements($emailReplacements);
    }

    private function rawStatementTable(string $sql, string $operation): ?string
    {
        $identifier = '(?:`([^`]+)`|([A-Za-z0-9_$-]+))(?:\\s*\\.\\s*(?:`([^`]+)`|([A-Za-z0-9_$-]+)))?';
        if (preg_match('/\\b' . $operation . '\\s+' . $identifier . '/i', $sql, $match) !== 1) { return null; }
        return (string) (($match[3] ?? '') !== '' ? $match[3] : (($match[4] ?? '') !== '' ? $match[4] : (($match[1] ?? '') !== '' ? $match[1] : $match[2])));
    }

    /** @param resource $handle @param array<int,int> $postMap @param array<string,mixed> $report */
    private function writePostMeta(
        $handle,
        DumpStore $source,
        string $sourcePrefix,
        string $targetPrefix,
        int $sourceId,
        int $targetId,
        array $postMap,
        bool $replace,
        array &$report,
        ?DumpStore $target = null,
        ?DumpStore $canonical = null,
    ): bool
    {
        $sourceTable = $sourcePrefix . 'postmeta';
        if (!in_array($sourceTable, $source->tables(), true)) { return false; }
        $rows = [];
        foreach ($source->rowsByReference($sourceTable, 'post_id', $sourceId) as $row) { $rows[] = $row; }
        $metaByKey = [];
        foreach ($rows as $row) { $metaByKey[(string) ($row['meta_key'] ?? '')] = (string) ($row['meta_value'] ?? ''); }
        $acfTypes = $this->acfTypes($source);
        $directReferenceKeys = ['_thumbnail_id', '_menu_item_object_id'];
        $acfPostReferenceTypes = ['image', 'file', 'gallery', 'post_object', 'relationship', 'page_link'];
        $prepared = [];
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
            $prepared[] = $row;
        }

        $changedKeys = null;
        if ($replace && $target !== null) {
            $current = [];
            $targetTable = $targetPrefix . 'postmeta';
            if (in_array($targetTable, $target->tables(), true)) {
                foreach ($target->rowsByReference($targetTable, 'post_id', $targetId) as $row) {
                    unset($row['meta_id']);
                    $row['post_id'] = (string) $targetId;
                    $current[] = $row;
                }
            }
            $changedKeys = $this->changedMetaKeys($current, $prepared);
            foreach ($changedKeys as $metaKey) {
                fwrite($handle, 'DELETE FROM ' . SqlWriter::identifier($targetTable)
                    . ' WHERE `post_id`=' . SqlWriter::value($targetId)
                    . ' AND `meta_key`=' . SqlWriter::value($metaKey) . ";\n");
                $canonical?->deleteWhere($targetTable, ['post_id' => $targetId, 'meta_key' => $metaKey]);
            }
        } elseif ($replace) {
            fwrite($handle, 'DELETE FROM ' . SqlWriter::identifier($targetPrefix . 'postmeta') . ' WHERE `post_id`=' . SqlWriter::value($targetId) . ";\n");
            $canonical?->deleteWhere($targetPrefix . 'postmeta', ['post_id' => $targetId]);
        }

        $written = false;
        foreach ($prepared as $row) {
            $metaKey = (string) ($row['meta_key'] ?? '');
            if (is_array($changedKeys) && !in_array($metaKey, $changedKeys, true)) { continue; }
            fwrite($handle, SqlWriter::insert($targetPrefix . 'postmeta', $row));
            $canonical?->row($targetPrefix . 'postmeta', $row);
            $report['meta_rows']++;
            $written = true;
        }
        return $written || (is_array($changedKeys) && $changedKeys !== []);
    }

    /** @param list<array<string,mixed>> $current @param list<array<string,mixed>> $incoming @return list<string> */
    private function changedMetaKeys(array $current, array $incoming): array
    {
        $group = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $key = (string) ($row['meta_key'] ?? '');
                $result[$key][] = (string) ($row['meta_value'] ?? '');
            }
            foreach ($result as &$values) { sort($values, SORT_STRING); }
            unset($values);
            ksort($result, SORT_STRING);
            return $result;
        };
        $currentByKey = $group($current);
        $incomingByKey = $group($incoming);
        $keys = array_values(array_unique(array_merge(array_keys($currentByKey), array_keys($incomingByKey))));
        return array_values(array_filter($keys, static fn (string $key): bool => ($currentByKey[$key] ?? []) !== ($incomingByKey[$key] ?? [])));
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

    /** @param resource $handle @param array<int,int> $postMap @param array<int,string> $termChoices @param array<string,mixed> $report */
    private function writeTerms($handle, DumpStore $base, DumpStore $incoming, string $basePrefix, string $incomingPrefix, array $postMap, array $termChoices, array &$report, ?DumpStore $canonical = null, ?array $termAdditionIds = null): void
    {
        $incomingRelationshipTable = $incomingPrefix . 'term_relationships';
        if (!in_array($incomingRelationshipTable, $incoming->tables(), true)) { return; }

        $required = ['terms', 'term_taxonomy', 'term_relationships'];
        foreach ($required as $suffix) {
            if (!in_array($incomingPrefix . $suffix, $incoming->tables(), true) || !in_array($basePrefix . $suffix, $base->tables(), true)) {
                throw new RuntimeException("カテゴリー紐付けに必要な{$suffix}テーブルがありません。");
            }
        }
        $baseTerms = $this->keyRows($base, $basePrefix . 'terms', 'term_id');
        $incomingTerms = $this->keyRows($incoming, $incomingPrefix . 'terms', 'term_id');
        $baseTax = $this->keyRows($base, $basePrefix . 'term_taxonomy', 'term_taxonomy_id');
        $incomingTax = $this->keyRows($incoming, $incomingPrefix . 'term_taxonomy', 'term_taxonomy_id');
        $maxTerm = $baseTerms === [] ? 0 : max(array_keys($baseTerms));
        $maxTax = $baseTax === [] ? 0 : max(array_keys($baseTax));
        $termMap = [];
        $taxMap = [];
        $baseTaxIds = [];
        foreach ($baseTax as $baseTaxId => $baseTaxRow) {
            $baseTerm = $baseTerms[(int) ($baseTaxRow['term_id'] ?? 0)] ?? null;
            if ($baseTerm !== null) {
                $baseTaxIds[$baseTaxId] = TermAssignmentInspector::id((string) ($baseTaxRow['taxonomy'] ?? ''), (string) ($baseTerm['slug'] ?? ''));
            }
        }
        $incomingTaxIds = [];
        $allowedAdditions = $termAdditionIds === null ? null : array_fill_keys(array_map('strval', $termAdditionIds), true);
        $operationsSinceCheckpoint = 0;
        foreach ($incomingTax as $sourceTaxId => $tax) {
            $term = $incomingTerms[(int) ($tax['term_id'] ?? 0)] ?? null;
            if ($term === null) { continue; }
            $semanticId = TermAssignmentInspector::id((string) ($tax['taxonomy'] ?? ''), (string) ($term['slug'] ?? ''));
            $incomingTaxIds[$sourceTaxId] = $semanticId;
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
            if ($allowedAdditions !== null && !isset($allowedAdditions[$semanticId])) { continue; }
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
            $canonical?->row($basePrefix . 'terms', $term);
            $canonical?->row($basePrefix . 'term_taxonomy', $tax);
            $operationsSinceCheckpoint += 2;
            if ($operationsSinceCheckpoint >= self::TRANSACTION_BATCH_SIZE) {
                $this->writeTransactionCheckpoint($handle);
                $operationsSinceCheckpoint = 0;
            }
        }

        $targetPostIds = array_fill_keys(array_values(array_unique($postMap)), true);
        $baseRelationships = [];
        foreach ($base->rows($basePrefix . 'term_relationships') as $relationship) {
            $targetPostId = (int) ($relationship['object_id'] ?? 0);
            if (!isset($targetPostIds[$targetPostId])) { continue; }
            $baseRelationships[$targetPostId][] = [
                'id' => $baseTaxIds[(int) ($relationship['term_taxonomy_id'] ?? 0)] ?? '',
                'term_taxonomy_id' => (string) ($relationship['term_taxonomy_id'] ?? '0'),
                'term_order' => (string) ($relationship['term_order'] ?? '0'),
            ];
        }
        $incomingRelationships = [];
        foreach ($incoming->rows($incomingRelationshipTable) as $relationship) {
            $sourcePost = (int) ($relationship['object_id'] ?? 0);
            $sourceTax = (int) ($relationship['term_taxonomy_id'] ?? 0);
            if (!isset($postMap[$sourcePost])) { continue; }
            $targetPostId = $postMap[$sourcePost];
            $incomingRelationships[$targetPostId][] = [
                'id' => $incomingTaxIds[$sourceTax] ?? '',
                'term_taxonomy_id' => isset($taxMap[$sourceTax]) ? (string) $taxMap[$sourceTax] : null,
                'term_order' => (string) ($relationship['term_order'] ?? '0'),
            ];
        }
        $normalize = static function (array $relationships): array {
            usort($relationships, static function (array $left, array $right): int {
                return [(int) $left['term_taxonomy_id'], (int) $left['term_order']]
                    <=> [(int) $right['term_taxonomy_id'], (int) $right['term_order']];
            });
            return $relationships;
        };
        foreach (array_keys($targetPostIds) as $targetPostId) {
            $current = $normalize($baseRelationships[$targetPostId] ?? []);
            $choice = $termChoices[$targetPostId] ?? 'base';
            if (is_array($choice)) {
                $available = [];
                foreach (array_merge($baseRelationships[$targetPostId] ?? [], $incomingRelationships[$targetPostId] ?? []) as $relationship) {
                    $available[(string) $relationship['id']] = $relationship;
                }
                $replacement = [];
                foreach ($choice as $semanticId) {
                    $relationship = $available[(string) $semanticId] ?? null;
                    if ($relationship === null || $relationship['term_taxonomy_id'] === null) {
                        throw new RuntimeException('選択したタームの追加が承認されていません。ターム追加候補を確認してください。');
                    }
                    $replacement[] = $relationship;
                }
                $replacement = $normalize($replacement);
            } else {
                if ($choice === 'incoming') {
                    $replacement = $incomingRelationships[$targetPostId] ?? [];
                    if (array_filter($replacement, static fn (array $row): bool => $row['term_taxonomy_id'] === null) !== []) {
                        throw new RuntimeException('記事に必要なB側タームの追加が承認されていません。ターム追加候補を確認してください。');
                    }
                    $replacement = $normalize($replacement);
                } else {
                    $replacement = $current;
                }
            }
            if ($current === $replacement) { continue; }
            fwrite($handle, 'DELETE FROM ' . SqlWriter::identifier($basePrefix . 'term_relationships')
                . ' WHERE `object_id`=' . SqlWriter::value($targetPostId) . ";\n");
            $canonical?->deleteWhere($basePrefix . 'term_relationships', ['object_id' => $targetPostId]);
            $operationsSinceCheckpoint++;
            foreach ($replacement as $relationship) {
                fwrite($handle, 'INSERT IGNORE INTO ' . SqlWriter::identifier($basePrefix . 'term_relationships') . ' (`object_id`,`term_taxonomy_id`,`term_order`) VALUES ('
                    . SqlWriter::value($targetPostId) . ',' . SqlWriter::value($relationship['term_taxonomy_id']) . ',' . SqlWriter::value($relationship['term_order']) . ");\n");
                $canonical?->row($basePrefix . 'term_relationships', [
                    'object_id' => (string) $targetPostId,
                    'term_taxonomy_id' => $relationship['term_taxonomy_id'],
                    'term_order' => $relationship['term_order'],
                ]);
                $report['term_relationships']++;
                $operationsSinceCheckpoint++;
            }
            if ($operationsSinceCheckpoint >= self::TRANSACTION_BATCH_SIZE) {
                $this->writeTransactionCheckpoint($handle);
                $operationsSinceCheckpoint = 0;
            }
        }
    }

    /** @param resource $handle @param array<int,int> $postMap @param array<string,mixed> $report */
    private function writePluginTables($handle, DumpStore $base, DumpStore $incoming, string $basePrefix, string $incomingPrefix, array $postMap, array &$report, ?DumpStore $canonical = null): void
    {
        $suffixes = ['yoast_indexable', 'yoast_primary_term', 'yoast_seo_links'];
        $operationsSinceCheckpoint = 0;
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
                $canonical?->row($targetTable, $row);
                $report['plugin_rows']++;
                $operationsSinceCheckpoint++;
                if ($operationsSinceCheckpoint >= self::TRANSACTION_BATCH_SIZE) {
                    $this->writeTransactionCheckpoint($handle);
                    $operationsSinceCheckpoint = 0;
                }
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
