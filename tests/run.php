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
    $invalidPrefixSql = $temporary . '/invalid-prefix.sql';
    file_put_contents($invalidPrefixSql, str_replace(
        'COMMIT;',
        "INSERT INTO `wppostmeta` (`post_id`,`meta_key`,`meta_value`) VALUES (53447,'_mw-wp-form_data','test');\nCOMMIT;",
        (string) file_get_contents(__DIR__ . '/fixtures/base.sql')
    ));
    $invalidPrefixDetected = false;
    try {
        $importer->import($invalidPrefixSql, new DumpStore($temporary . '/invalid-prefix.sqlite'));
    } catch (RuntimeException $e) {
        $invalidPrefixDetected = str_contains($e->getMessage(), 'wppostmeta')
            && str_contains($e->getMessage(), 'CREATE TABLE');
    }
    expect($invalidPrefixDetected, '接頭辞の置換漏れでCREATE TABLEのないpostmetaへのINSERTを検出');
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
        __DIR__ . '/fixtures/base.sql', $temporary . '/merged.sql', $base, $incoming, $comparison,
        $temporary . '/report.json', null, $temporary . '/merge-delta.sql'
    );
    $merged = file_get_contents($temporary . '/merged.sql');
    $canonicalPosition = strpos($merged, '-- WP DB Safety Merge canonical table data');
    expect($canonicalPosition !== false && !str_contains($merged, '-- WP DB Safety Merge generated operations'), '完全版SQLをSQLiteの統合済みテーブルから再生成');
    expect(
        preg_match('/^(?:UPDATE|DELETE\s+FROM) `wp_(?:posts|postmeta|terms|term_taxonomy|term_relationships)`/m', $merged) !== 1,
        '完全版の再生成対象テーブルへ差分UPDATE・DELETEを残さない'
    );
    expect(
        substr_count($merged, 'START TRANSACTION;') >= 6
            && substr_count($merged, 'START TRANSACTION;') === substr_count($merged, 'COMMIT;'),
        '再生成した各テーブルを確定可能なトランザクションへ分割'
    );
    expect(
        str_contains($merged, 'SET @WPDBSM_OLD_SQL_MODE=@@SESSION.SQL_MODE;')
            && str_contains($merged, 'SET SESSION SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';')
            && str_contains($merged, 'SET SESSION SQL_MODE=@WPDBSM_OLD_SQL_MODE;'),
        'WordPressのゼロ日時を統合できるSQLモードを一時適用して復元'
    );
    $delta = (string) file_get_contents($temporary . '/merge-delta.sql');
    expect(
        str_starts_with($delta, "-- WP DB Safety Merge generated operations\nSTART TRANSACTION;\n")
            && substr_count($delta, 'START TRANSACTION;') >= 3
            && substr_count($delta, 'START TRANSACTION;') === substr_count($delta, 'COMMIT;')
            && str_ends_with($delta, "-- WP DB Safety Merge generated operations end\n"),
        '基準DB取込後に単独適用できる統合差分SQLを出力'
    );
    expect(!str_contains($delta, 'CREATE TABLE `wp_posts`') && str_contains($delta, "'Hello updated'"), '差分SQLから基準ダンプを除外して統合操作を保持');
    expect(($report['delta_bytes'] ?? 0) === strlen($delta), '統合レポートへ差分SQLサイズを記録');
    expect($report['added'] === 2 && $report['updated'] === 1, '追加と更新を統合');
    expect(str_contains($merged, "'Hello updated'"), '新しい投稿タイトルを反映');
    expect(str_contains($merged, "a:1:{i:0;i:202;}"), 'ACF gallery内のattachment IDを参照先まで再採番');
    expect(str_contains($merged, '`wp_terms`'), '追加側タームを基準プレフィックスへ出力');
    expect(
        str_contains($delta, 'DELETE FROM `wp_term_relationships` WHERE `object_id`=\'1\'')
            && !str_contains($merged, "INSERT INTO `wp_term_relationships` (`object_id`,`term_taxonomy_id`,`term_order`) VALUES ('1','1','0')"),
        '更新記事から削除されたターム紐付けを完全版と差分版へ反映'
    );
    expect(str_contains($merged, "('201','2','0')"), '追加記事のカテゴリー紐付けを新しい投稿IDへ再採番');
    expect(str_contains($merged, '`wp_yoast_indexable`'), 'Yoast関連データを出力');
    expect(
        str_contains($merged, 'CREATE TABLE `wp_plugin_cache`')
            && str_contains($merged, "VALUES (1,'cache','unsupported-extra-value')"),
        'memo.txtの対象外プラグインデータも基準DBからそのまま保持'
    );
    expect(
        str_contains($merged, 'CREATE TABLE `wp_simple_history`')
            && str_contains($merged, "(41,'2026-01-04 12:00:00','SimplePostLogger','info','Post updated')")
            && str_contains($merged, 'CREATE TABLE `wp_simple_history_contexts`')
            && str_contains($merged, "(81,41,'post_id','1')"),
        'Simple Historyの履歴とコンテキストを基準DBからそのまま保持'
    );
    expect(is_array(json_decode((string) file_get_contents($temporary . '/report.json'), true)), 'JSON統合レポートを作成');

    $mergedIncoming = new DumpStore($temporary . '/merged-incoming.sqlite');
    $importer->import($temporary . '/merged.sql', $mergedIncoming);
    $mergedRelationshipFound = false;
    foreach ($mergedIncoming->rows('wp_term_relationships') as $relationship) {
        if ((int) ($relationship['object_id'] ?? 0) === 201 && (int) ($relationship['term_taxonomy_id'] ?? 0) === 2) {
            $mergedRelationshipFound = true;
            break;
        }
    }
    expect($mergedRelationshipFound, '1回目の統合SQLを再入力してカテゴリー紐付けを保持');

    $secondBase = new DumpStore($temporary . '/second-base.sqlite');
    $importer->import(__DIR__ . '/fixtures/base.sql', $secondBase);
    $secondBase->row('wp_posts', [
        'ID' => '500', 'post_title' => 'Third environment only', 'post_name' => 'third-only',
        'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_type' => 'post',
        'post_date' => '2026-04-01 00:00:00', 'post_modified' => '2026-04-01 00:00:00',
    ]);
    $secondComparison = new ComparisonStore($temporary . '/second-comparison.sqlite');
    (new ComparisonEngine())->compare($secondBase, $mergedIncoming, $secondComparison);
    (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/second-merged.sql', $secondBase, $mergedIncoming,
        $secondComparison, $temporary . '/second-report.json'
    );
    $secondMerged = file_get_contents($temporary . '/second-merged.sql');
    expect(str_contains($secondMerged, "('501','2','0')"), '3環境目の統合でもカテゴリー紐付けを再採番');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) { is_file($file) && unlink($file); }
    @rmdir($temporary);
}

echo "All tests passed.\n";
