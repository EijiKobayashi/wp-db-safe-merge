<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use WpDbSafeMerge\Infrastructure\DumpStore;

final class ComparisonEngine
{
    /** @return array<string,int> */
    public function compare(DumpStore $base, DumpStore $incoming, ComparisonStore $comparison): array
    {
        $comparison->begin();
        try {
            $this->copyPosts($base, 'base', $comparison);
            $this->copyPosts($incoming, 'incoming', $comparison);

            $usedBase = [];
            foreach ($comparison->content('incoming') as $source) {
                $best = null;
                $bestScore = 0.0;
                foreach ($comparison->candidates($source) as $candidate) {
                    if (isset($usedBase[(int) $candidate['source_id']])) {
                        continue;
                    }
                    $score = $this->score($candidate, $source);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $candidate;
                    }
                }
                if ($best === null || $bestScore < 0.45) {
                    $comparison->addComparison('additional', null, (int) $source['source_id'], 0, 'incoming');
                    continue;
                }
                $baseId = (int) $best['source_id'];
                $incomingId = (int) $source['source_id'];
                $usedBase[$baseId] = true;
                $recommended = $this->newerSide($best, $source);
                $kind = $bestScore >= 0.99 ? 'matched' : ($recommended === 'manual' ? 'conflict' : 'candidate');
                $comparison->addComparison($kind, $baseId, $incomingId, $bestScore, $recommended);
            }
            foreach ($comparison->content('base') as $basePost) {
                if (!isset($usedBase[(int) $basePost['source_id']])) {
                    $comparison->addComparison('base_only', (int) $basePost['source_id'], null, 0, 'base');
                }
            }
            $comparison->commit();
        } catch (\Throwable $e) {
            $comparison->rollback();
            throw $e;
        }
        return $comparison->counts();
    }

    private function copyPosts(DumpStore $dump, string $side, ComparisonStore $comparison): void
    {
        $table = ($dump->meta('prefix') ?? 'wp_') . 'posts';
        foreach ($dump->rows($table) as $row) {
            $type = (string) ($row['post_type'] ?? 'post');
            $status = (string) ($row['post_status'] ?? 'publish');
            if (in_array($type, ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache'], true) || $status === 'auto-draft') {
                continue;
            }
            $comparison->addContent($side, $row);
        }
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $incoming */
    private function score(array $base, array $incoming): float
    {
        $score = 0.0;
        if ($base['slug'] !== '' && $base['slug'] === $incoming['slug']) { $score += 0.4; }
        if ($base['published'] !== '' && $base['published'] === $incoming['published']) { $score += 0.2; }
        if ($base['title_norm'] !== '' && $base['title_norm'] === $incoming['title_norm']) { $score += 0.25; }
        if ($base['content_hash'] === $incoming['content_hash']) { $score += 0.15; }
        return round($score, 2);
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $incoming */
    private function newerSide(array $base, array $incoming): string
    {
        $modified = strcmp((string) $incoming['modified'], (string) $base['modified']);
        if ($modified !== 0) { return $modified > 0 ? 'incoming' : 'base'; }
        $published = strcmp((string) $incoming['published'], (string) $base['published']);
        if ($published !== 0) { return $published > 0 ? 'incoming' : 'base'; }
        return 'manual';
    }
}
