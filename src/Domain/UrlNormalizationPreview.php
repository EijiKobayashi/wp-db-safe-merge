<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use WpDbSafeMerge\Infrastructure\DumpStore;
use WpDbSafeMerge\Infrastructure\SqlStatementReader;

final class UrlNormalizationPreview
{
    /** @return array{preview_version:int,base_url:string,incoming_urls:list<string>,source_hosts:list<string>,email_source_hosts:list<string>,target_origin:string,target_host:string,tables:array<string,array{total:int,url:int,host:int,email:int}>,email_candidates:list<array{id:string,source:string,count:int,tables:array<string,int>}>}|null */
    public function inspect(string $baseSql, DumpStore $base, DumpStore $incoming): ?array
    {
        $baseUrl = $base->meta('home') ?? $base->meta('siteurl');
        $incomingUrls = array_values(array_unique(array_filter([
            $incoming->meta('home'),
            $incoming->meta('siteurl'),
        ], static fn (?string $url): bool => $url !== null && $url !== '')));
        if ($baseUrl === null || $incomingUrls === []) { return null; }

        try {
            $transformer = (new UrlValueTransformer($baseUrl, ...$incomingUrls))->withEmailDomains(true);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $basePrefix = $base->meta('prefix') ?? 'wp_';
        $incomingPrefix = $incoming->meta('prefix') ?? 'wp_';
        $managed = array_fill_keys(array_values(array_filter(array_map(
            static fn (string $suffix): string => $basePrefix . $suffix,
            ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'yoast_indexable', 'yoast_primary_term', 'yoast_seo_links']
        ), static fn (string $table): bool => in_array($table, $base->tables(), true))), true);
        $counts = [];
        $emailCandidates = [];

        foreach ((new SqlStatementReader())->readRaw($baseSql) as $raw) {
            $table = $this->insertTable($raw);
            if ($table === null || isset($managed[$table])) { continue; }
            $result = $transformer->transformSql($raw);
            if ($result['replacements'] > 0) { $this->addCounts($counts, $emailCandidates, $table, $result); }
        }

        foreach ($managed as $targetTable => $_) {
            $this->scanTable($base, $targetTable, $targetTable, $transformer, $counts, $emailCandidates);
            $suffix = str_starts_with($targetTable, $basePrefix) ? substr($targetTable, strlen($basePrefix)) : $targetTable;
            $this->scanTable($incoming, $incomingPrefix . $suffix, $targetTable, $transformer, $counts, $emailCandidates);
        }

        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        $sourceHosts = [];
        $emailSourceHosts = [];
        foreach ($incomingUrls as $incomingUrl) {
            $host = parse_url($incomingUrl, PHP_URL_HOST);
            if (!is_string($host) || $host === '') { continue; }
            $emailSourceHosts[] = strtolower($host);
            $sourceHosts[] = strtolower($host);
            $sourceHosts[] = str_starts_with(strtolower($host), 'www.') ? substr(strtolower($host), 4) : 'www.' . strtolower($host);
        }
        $targetHost = parse_url($transformer->targetOrigin(), PHP_URL_HOST);
        $emailCandidateList = array_values($emailCandidates);
        usort($emailCandidateList, static fn (array $left, array $right): int => strtolower($left['source']) <=> strtolower($right['source']));
        return [
            'preview_version' => 5,
            'base_url' => $baseUrl,
            'incoming_urls' => $incomingUrls,
            'source_hosts' => array_values(array_unique($sourceHosts)),
            'email_source_hosts' => array_values(array_unique($emailSourceHosts)),
            'target_origin' => $transformer->targetOrigin(),
            'target_host' => is_string($targetHost) ? strtolower($targetHost) : '',
            'tables' => $counts,
            'email_candidates' => $emailCandidateList,
        ];
    }

    /** @param array<string,array{total:int,url:int,host:int,email:int}> $counts @param array<string,array{id:string,source:string,count:int,tables:array<string,int>}> $emailCandidates */
    private function scanTable(
        DumpStore $store,
        string $sourceTable,
        string $targetTable,
        UrlValueTransformer $transformer,
        array &$counts,
        array &$emailCandidates,
    ): void {
        if (!in_array($sourceTable, $store->tables(), true)) { return; }
        foreach ($store->rows($sourceTable) as $row) {
            foreach ($row as $value) {
                $result = $transformer->transform($value);
                if ($result['replacements'] > 0) { $this->addCounts($counts, $emailCandidates, $targetTable, $result); }
            }
        }
    }

    /**
     * @param array<string,array{total:int,url:int,host:int,email:int}> $counts
     * @param array<string,array{id:string,source:string,count:int,tables:array<string,int>}> $emailCandidates
     * @param array{replacements:int,kinds:array{url:int,host:int,email:int},emails:array<string,array{source:string,target:string,count:int}>} $result
     */
    private function addCounts(array &$counts, array &$emailCandidates, string $table, array $result): void
    {
        $counts[$table] ??= ['total' => 0, 'url' => 0, 'host' => 0, 'email' => 0];
        $counts[$table]['total'] += $result['replacements'];
        foreach ($result['kinds'] as $kind => $count) { $counts[$table][$kind] += $count; }
        foreach ($result['emails'] as $email) {
            $key = strtolower($email['source']);
            $emailCandidates[$key] ??= [
                'id' => hash('sha256', $key),
                'source' => $email['source'],
                'count' => 0,
                'tables' => [],
            ];
            $emailCandidates[$key]['count'] += $email['count'];
            $emailCandidates[$key]['tables'][$table] = ($emailCandidates[$key]['tables'][$table] ?? 0) + $email['count'];
        }
    }

    private function insertTable(string $sql): ?string
    {
        $identifier = '(?:`([^`]+)`|([A-Za-z0-9_$-]+))(?:\\s*\\.\\s*(?:`([^`]+)`|([A-Za-z0-9_$-]+)))?';
        if (preg_match('/\\b(?:INSERT|REPLACE)(?:\\s+IGNORE)?\\s+INTO\\s+' . $identifier . '/i', $sql, $match) !== 1) { return null; }
        return (string) (($match[3] ?? '') !== '' ? $match[3] : (($match[4] ?? '') !== '' ? $match[4] : (($match[1] ?? '') !== '' ? $match[1] : $match[2])));
    }
}
