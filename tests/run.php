<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

use WpDbSafeMerge\Domain\ComparisonEngine;
use WpDbSafeMerge\Domain\ComparisonStore;
use WpDbSafeMerge\Domain\MergeEngine;
use WpDbSafeMerge\Domain\SerializedValueTransformer;
use WpDbSafeMerge\Infrastructure\DumpImporter;
use WpDbSafeMerge\Infrastructure\DumpStore;
use WpDbSafeMerge\Infrastructure\SqlSyntax;

function expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException("FAIL: $message"); }
    echo "PASS: $message\n";
}

$temporary = sys_get_temp_dir() . '/wpdbsm-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
try {
    $base = new DumpStore($temporary . '/base.sqlite');
    $incoming = new DumpStore($temporary . '/incoming.sqlite');
    $importer = new DumpImporter();
    $baseInfo = $importer->import(__DIR__ . '/fixtures/base.sql', $base);
    $incomingInfo = $importer->import(__DIR__ . '/fixtures/incoming.sql', $incoming);
    expect($baseInfo['prefix'] === 'wp_', '基準DBのプレフィックスを検出');
    expect($incomingInfo['prefix'] === 'site_', '追加DBのプレフィックスを検出');
    expect(in_array('wp_plugin_cache', $baseInfo['ignored_tables'], true), '比較対象外プラグインテーブルをSQLite取込から除外');
    $qualifiedCreate = SqlSyntax::parseCreate('CREATE TABLE `example_db`.`custom_posts` (`ID` bigint, PRIMARY KEY (`ID`)) ENGINE=InnoDB');
    $qualifiedInsert = SqlSyntax::parseInsert('INSERT INTO example_db.custom_posts (`ID`) VALUES (1)');
    expect($qualifiedCreate !== null && $qualifiedCreate['table'] === 'custom_posts' && $qualifiedCreate['columns'] === ['ID'], 'スキーマ名付きCREATE TABLEと制約を正規化');
    expect($qualifiedInsert !== null && $qualifiedInsert['table'] === 'custom_posts', 'スキーマ名付きINSERTを正規化');
    expect($base->rowCount('wp_posts') === 3, '複数行INSERTを解析');

    $comparison = new ComparisonStore($temporary . '/comparison.sqlite');
    $counts = (new ComparisonEngine())->compare($base, $incoming, $comparison);
    expect($counts['candidate'] === 1, 'スラッグと公開日から同一記事候補を作成');
    expect($counts['matched'] === 1, '完全一致するACFフィールド定義を検出');
    expect($counts['additional'] === 2, 'ID衝突を同一記事と誤判定しない');
    expect($counts['base_only'] === 1, '基準DBだけの記事を維持');
    $candidatePage = $comparison->page(1, 25, 'candidate');
    expect($candidatePage['perPage'] === 25, 'ページごとの表示件数を比較結果へ保持');
    $candidateId = (int) $candidatePage['items'][0]['id'];
    expect($comparison->bulkDecide([$candidateId], 'base') === 1, '選択した比較候補を一括更新');
    $candidatePage = $comparison->page(1, 25, 'candidate');
    expect(($candidatePage['items'][0]['decision']['winner'] ?? null) === 'base', '一括更新した採用側を保存');
    $comparison->decide($candidateId, ['winner' => 'incoming', 'fields' => [], 'decided_at' => gmdate(DATE_ATOM)]);

    $serialized = (new SerializedValueTransformer())->transform('a:1:{i:0;i:15;}', [15 => 202]);
    expect($serialized === 'a:1:{i:0;i:202;}', 'シリアライズ値を復元・再生成してIDを変換');

    $report = (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/merged.sql', $base, $incoming, $comparison, $temporary . '/report.json'
    );
    $merged = file_get_contents($temporary . '/merged.sql');
    expect($report['added'] === 2 && $report['updated'] === 1, '追加と更新を統合');
    expect(str_contains($merged, "'Hello updated'"), '新しい投稿タイトルを反映');
    expect(str_contains($merged, "a:1:{i:0;i:202;}"), 'ACF gallery内のattachment IDを参照先まで再採番');
    expect(str_contains($merged, '`wp_terms`'), '追加側タームを基準プレフィックスへ出力');
    expect(str_contains($merged, '`wp_yoast_indexable`'), 'Yoast関連データを出力');
    expect(is_array(json_decode((string) file_get_contents($temporary . '/report.json'), true)), 'JSON統合レポートを作成');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) { is_file($file) && unlink($file); }
    @rmdir($temporary);
}

echo "All tests passed.\n";
