<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

use WpDbSafeMerge\Domain\ComparisonEngine;
use WpDbSafeMerge\Domain\ComparisonStore;
use WpDbSafeMerge\Domain\MergeEngine;
use WpDbSafeMerge\Domain\SerializedValueTransformer;
use WpDbSafeMerge\Domain\TermAssignmentInspector;
use WpDbSafeMerge\Domain\UrlNormalizationPreview;
use WpDbSafeMerge\Domain\UrlValueTransformer;
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
    expect($base->meta('home') === 'https://base.test', '基準DBのhome URLを検出');
    expect($incoming->meta('siteurl') === 'https://www.incoming.test', '追加DBのsiteurl URLを検出');
    expect($baseInfo['database_name'] === null && $incomingInfo['database_name'] === null, 'DB名の記載がないダンプを明示');
    $databaseNamedSql = $temporary . '/database-named.sql';
    file_put_contents($databaseNamedSql, "CREATE DATABASE IF NOT EXISTS `example_wp`;\nUSE `example_wp`;\n");
    expect($importer->detectDatabaseName($databaseNamedSql) === 'example_wp', 'SQLダンプのUSE文からDB名を検出');
    file_put_contents($databaseNamedSql, "-- MySQL dump\n-- Host: localhost    Database: sembastg_wp\n");
    expect($importer->detectDatabaseName($databaseNamedSql) === 'sembastg_wp', 'mysqldumpのHost行からDB名を検出');
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
    $urlPreview = (new UrlNormalizationPreview())->inspect(__DIR__ . '/fixtures/base.sql', $base, $incoming);
    expect(
        isset($urlPreview['tables']['wp_posts'], $urlPreview['tables']['wp_postmeta'], $urlPreview['tables']['wp_plugin_cache'])
            && in_array('www.incoming.test', $urlPreview['source_hosts'] ?? [], true)
            && in_array('incoming.test', $urlPreview['source_hosts'] ?? [], true)
            && ($urlPreview['target_host'] ?? null) === 'base.test'
            && ($urlPreview['email_source_hosts'] ?? []) === ['www.incoming.test']
            && array_filter(
                $urlPreview['email_candidates'] ?? [],
                static fn (array $candidate): bool => ($candidate['source'] ?? '') === 'admin@www.incoming.test'
                    && isset($candidate['tables']['wp_plugin_cache'])
            ) !== [],
        'ドメイン置換候補を出力先テーブル別に検出'
    );
    $compareTemplate = (string) file_get_contents(__DIR__ . '/../templates/compare.php');
    $resultTemplate = (string) file_get_contents(__DIR__ . '/../templates/result.php');
    $appSource = (string) file_get_contents(__DIR__ . '/../src/App.php');
    $termsTemplate = (string) file_get_contents(__DIR__ . '/../templates/terms.php');
    expect(
        str_contains($compareTemplate, 'data-email-checkbox')
            && !str_contains($compareTemplate, 'checked data-email-checkbox')
            && str_contains($compareTemplate, 'email_normalization_targets[')
            && str_contains($compareTemplate, '変換後ドメイン')
            && str_contains($compareTemplate, 'ローカル部は変更しません')
            && str_contains($compareTemplate, 'data-email-state-key')
            && str_contains($compareTemplate, 'data-email-bulk-apply')
            && str_contains($compareTemplate, 'data-email-clear')
            && str_contains($compareTemplate, "state['id']")
            && str_contains($compareTemplate, '初期状態では変換しません'),
        'メール設定を保持し、変換後ドメインを個別・一括入力できるようにする'
    );
    expect(
        str_contains($compareTemplate, '基準DB（SQL')
            && str_contains($compareTemplate, '追加側（SQL')
            && str_contains($compareTemplate, 'DB名')
            && str_contains($compareTemplate, 'テーブル接頭辞'),
        'DB名と誤解しないようSQL A/Bとテーブル接頭辞を明示'
    );
    expect(
        !str_contains($resultTemplate, 'type=delta')
            && !str_contains($resultTemplate, '統合差分SQL')
            && str_contains($resultTemplate, '統合済み全体SQL'),
        '結果画面では完全版SQLと統合レポートだけを提供'
    );
    expect(
        str_contains($compareTemplate, '[20, 50, 100, 200, 500]')
            && str_contains($appSource, '[20, 50, 100, 200, 500]')
            && substr_count($appSource, "['per_page'] ?? 100") === 3
            && str_contains($appSource, '? $perPage : 100;'),
        '比較一覧を初期100件表示にして500件の選択肢を用意'
    );
    expect(
        str_contains($compareTemplate, 'name="term_ids[]"')
            && str_contains($compareTemplate, 'data-term-option')
            && str_contains($compareTemplate, '記事に紐付けるターム')
            && str_contains($compareTemplate, 'A+Bをすべて選択')
            && str_contains($compareTemplate, 'A・B共通')
            && str_contains($compareTemplate, 'Aのみ')
            && str_contains($compareTemplate, 'Bのみ')
            && str_contains($compareTemplate, 'name="bulk_terms"')
            && str_contains($compareTemplate, '記事の採用側に合わせる')
            && str_contains($compareTemplate, 'タームなし')
            && str_contains($compareTemplate, "term['taxonomy']")
            && str_contains($compareTemplate, "term['slug']"),
        '基準DBと追加側のターム一覧と採用側を比較画面へ表示'
    );
    expect(
        str_contains($termsTemplate, 'ターム追加候補を確認')
            && str_contains($termsTemplate, 'name="term_addition_ids[]"')
            && str_contains($termsTemplate, '安全のため自動削除しません'),
        'ターム追加候補を別画面で確認し、未使用タームは警告だけ表示'
    );
    expect($counts['candidate'] === 1, 'スラッグと公開日から同一記事候補を作成');
    expect($counts['matched'] === 1, '完全一致するACFフィールド定義を検出');
    expect($counts['additional'] === 2, 'ID衝突を同一記事と誤判定しない');
    expect($counts['base_only'] === 1, '基準DBだけの記事を維持');
    $candidatePage = $comparison->page(1, 25, 'candidate');
    expect($candidatePage['perPage'] === 25, 'ページごとの表示件数を比較結果へ保持');
    $candidateId = (int) $candidatePage['items'][0]['id'];
    $termInspector = new TermAssignmentInspector();
    $baseAssignments = $termInspector->inspect($base, 'wp_', [1]);
    $incomingAssignments = $termInspector->inspect($incoming, 'site_', [99]);
    expect(
        ($baseAssignments[1][0]['taxonomy'] ?? null) === 'category'
            && ($baseAssignments[1][0]['name'] ?? null) === 'News'
            && ($baseAssignments[1][0]['slug'] ?? null) === 'news'
            && ($incomingAssignments[99][0]['taxonomy'] ?? null) === 'category'
            && ($incomingAssignments[99][0]['name'] ?? null) === 'Updates'
            && ($incomingAssignments[99][0]['slug'] ?? null) === 'updates',
        '投稿ごとに両DBのターム名・タクソノミー・スラッグを取得'
    );
    expect($comparison->bulkDecide([$candidateId], 'base') === 1, '選択した比較候補を一括更新');
    $candidatePage = $comparison->page(1, 25, 'candidate');
    expect(
        ($candidatePage['items'][0]['decision']['winner'] ?? null) === 'base'
            && ($candidatePage['items'][0]['decision']['fields']['_terms'] ?? null) === 'base',
        '一括更新した投稿とタームの採用側を保存'
    );
    $comparison->decide($candidateId, ['winner' => 'incoming', 'fields' => ['_terms' => 'incoming'], 'decided_at' => gmdate(DATE_ATOM)]);

    $serialized = (new SerializedValueTransformer())->transform('a:1:{i:0;i:15;}', [15 => 202]);
    expect($serialized === 'a:1:{i:0;i:202;}', 'シリアライズ値を復元・再生成してIDを変換');
    $urlTransformer = new UrlValueTransformer('https://base.test', 'https://www.incoming.test');
    $serializedUrl = $urlTransformer->transform('a:1:{s:3:"url";s:34:"https://www.incoming.test/download";}');
    expect(
        $serializedUrl['value'] === 'a:1:{s:3:"url";s:26:"https://base.test/download";}'
            && $serializedUrl['replacements'] === 1,
        'PHPシリアライズ値の文字列長を更新して追加側URLを変換'
    );
    $jsonUrl = $urlTransformer->transform('{"url":"https:\/\/www.incoming.test"}');
    expect(
        $jsonUrl['value'] === '{"url":"https:\/\/base.test"}' && $jsonUrl['replacements'] === 1,
        'パスなし・JSONエスケープ形式の追加側URLを変換'
    );
    $hostAndEmail = $urlTransformer->withEmailDomains(true)->transform('host=www.incoming.test mail=info@www.incoming.test');
    expect(
        $hostAndEmail['value'] === 'host=base.test mail=info@base.test'
            && $hostAndEmail['replacements'] === 2
            && $hostAndEmail['kinds'] === ['url' => 0, 'host' => 1, 'email' => 1],
        '通常ホストとメールアドレスの追加側ドメインを変換'
    );
    $emailOff = $urlTransformer->withEmailDomains(false)->transform('https://www.incoming.test info@www.incoming.test');
    expect(
        $emailOff['value'] === 'https://base.test info@www.incoming.test'
            && $emailOff['kinds'] === ['url' => 1, 'host' => 0, 'email' => 0],
        'メール置換を選択しない場合はURLだけを変換'
    );
    $emailOnly = $urlTransformer->withUrlAndHosts(false)->withEmailDomains(true)->transform('https://www.incoming.test info@www.incoming.test');
    expect(
        $emailOnly['value'] === 'https://www.incoming.test info@base.test'
            && $emailOnly['kinds'] === ['url' => 0, 'host' => 0, 'email' => 1],
        'URLと分離してメールアドレスだけを変換'
    );
    $validatedEmail = $urlTransformer->withUrlAndHosts(false)->withEmailDomains(true)->transform(
        '@www.incoming.test <first.last+tag@www.incoming.test> invalid..dots@www.incoming.test'
    );
    expect(
        $validatedEmail['value'] === '@www.incoming.test <first.last+tag@base.test> invalid..dots@www.incoming.test'
            && $validatedEmail['replacements'] === 1,
        'ローカル部を含む有効なメール形式だけを置換候補として判定'
    );
    $variantEmail = $urlTransformer->withUrlAndHosts(false)->withEmailDomains(true)->transform('info@incoming.test');
    expect(
        $variantEmail['value'] === 'info@incoming.test' && $variantEmail['replacements'] === 0,
        'メールはwww有無を補完せず検出した置換元ドメインとの完全一致だけを対象にする'
    );
    $customEmailTransformer = $urlTransformer->withUrlAndHosts(false)->withEmailDomains(true)->withEmailReplacements([
        'info@www.incoming.test' => 'contact@incoming-mail.test',
    ]);
    $customEmail = $customEmailTransformer->transform('info@www.incoming.test admin@www.incoming.test');
    expect(
        $customEmail['value'] === 'contact@incoming-mail.test admin@www.incoming.test'
            && $customEmail['replacements'] === 1,
        '選択したメール候補を個別に指定したメールアドレスへ変換'
    );
    $serializedEmail = $customEmailTransformer->transform('a:1:{s:4:"mail";s:22:"info@www.incoming.test";}');
    expect(
        $serializedEmail['value'] === 'a:1:{s:4:"mail";s:26:"contact@incoming-mail.test";}',
        '個別指定したメール変換後にPHPシリアライズ文字列長を再生成'
    );

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
    expect(
        ($report['url_normalization']['target_origin'] ?? null) === 'https://base.test'
            && ($report['url_normalization']['replacements'] ?? 0) > 0
            && isset($report['url_normalization']['tables']['wp_posts'])
            && isset($report['url_normalization']['tables']['wp_plugin_cache']),
        'URL変換先・合計件数・テーブル別件数を統合レポートへ記録'
    );
    expect($report['added'] === 2 && $report['updated'] === 1, '追加と更新を統合');
    expect(str_contains($merged, "'Hello updated'"), '新しい投稿タイトルを反映');
    expect(str_contains($merged, "a:1:{i:0;i:202;}"), 'ACF gallery内のattachment IDを参照先まで再採番');
    expect(
        !str_contains($merged, 'https://incoming.test')
            && !str_contains($merged, 'https://www.incoming.test')
            && !str_contains($merged, 'http://base.test')
            && str_contains($merged, 'https://base.test/image.jpg')
            && str_contains($merged, 'https://base.test/legacy'),
        '全テーブルで追加側URLを基準DBのHTTPS URLへ統一'
    );
    expect(
        str_contains($merged, 'a:1:{s:3:"url";s:26:"https://base.test/download";}')
            && str_contains($merged, 'a:1:{s:3:"url";s:25:"https://base.test/history";}'),
        '管理対象と対象外テーブルのPHPシリアライズURLを安全に変換'
    );
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
            && str_contains($merged, 'unsupported-extra-value https://base.test/cached https://base.test/legacy admin@www.incoming.test'),
        'memo.txtの対象外プラグインデータも基準DBからそのまま保持'
    );
    expect(
        !str_contains($merged, 'admin@base.test'),
        '明示的なメール変換ルールがなければメールアドレスを変更しない'
    );
    expect(
        str_contains($merged, 'CREATE TABLE `wp_simple_history`')
            && str_contains($merged, "(41,'2026-01-04 12:00:00','SimplePostLogger','info','Post updated')")
            && str_contains($merged, 'CREATE TABLE `wp_simple_history_contexts`')
            && str_contains($merged, "(81,41,'post_id','1')"),
        'Simple Historyの履歴とコンテキストを基準DBからそのまま保持'
    );
    expect(is_array(json_decode((string) file_get_contents($temporary . '/report.json'), true)), 'JSON統合レポートを作成');

    $baseTermsComparison = new ComparisonStore($temporary . '/base-terms-comparison.sqlite');
    (new ComparisonEngine())->compare($base, $incoming, $baseTermsComparison);
    $baseTermsCandidate = $baseTermsComparison->page(1, 25, 'candidate')['items'][0];
    $baseTermsComparison->decide((int) $baseTermsCandidate['id'], [
        'winner' => 'incoming',
        'fields' => ['_terms' => 'base'],
        'decided_at' => gmdate(DATE_ATOM),
    ]);
    (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/base-terms.sql', $base, $incoming, $baseTermsComparison,
        $temporary . '/base-terms-report.json'
    );
    $baseTermsMerged = new DumpStore($temporary . '/base-terms-merged.sqlite');
    $importer->import($temporary . '/base-terms.sql', $baseTermsMerged);
    $postOneTaxonomies = [];
    foreach ($baseTermsMerged->rowsByReference('wp_term_relationships', 'object_id', 1) as $relationship) {
        $postOneTaxonomies[] = (int) ($relationship['term_taxonomy_id'] ?? 0);
    }
    expect($postOneTaxonomies === [1], '投稿本文に追加側を採用してもタームで基準DBを選べば削除済みタームを復活させない');

    $termReview = $termInspector->review($base, $incoming, 'wp_', 'site_');
    $updatesTermId = TermAssignmentInspector::id('category', 'updates');
    $newsTermId = TermAssignmentInspector::id('category', 'news');
    expect(
        in_array($updatesTermId, array_column($termReview['additions'], 'id'), true),
        '追加側だけにあるタームとタクソノミーの組み合わせを追加候補として検出'
    );
    $mixedTermsComparison = new ComparisonStore($temporary . '/mixed-terms-comparison.sqlite');
    (new ComparisonEngine())->compare($base, $incoming, $mixedTermsComparison);
    $mixedCandidate = $mixedTermsComparison->page(1, 25, 'candidate')['items'][0];
    $mixedTermsComparison->decide((int) $mixedCandidate['id'], [
        'winner' => 'base', 'fields' => [], 'terms' => [$newsTermId, $updatesTermId], 'decided_at' => gmdate(DATE_ATOM),
    ]);
    (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/mixed-terms.sql', $base, $incoming, $mixedTermsComparison,
        $temporary . '/mixed-terms-report.json', null, null, null, null, [$updatesTermId]
    );
    $mixedTermsMerged = new DumpStore($temporary . '/mixed-terms-merged.sqlite');
    $importer->import($temporary . '/mixed-terms.sql', $mixedTermsMerged);
    $mixedTaxonomies = [];
    foreach ($mixedTermsMerged->rowsByReference('wp_term_relationships', 'object_id', 1) as $relationship) {
        $mixedTaxonomies[] = (int) ($relationship['term_taxonomy_id'] ?? 0);
    }
    sort($mixedTaxonomies);
    expect($mixedTaxonomies === [1, 2], '記事内容と独立してA/Bのタームを個別に混在選択');

    $excludedReport = (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/excluded.sql', $base, $incoming, $comparison,
        $temporary . '/excluded-report.json', null, $temporary . '/excluded-delta.sql', ['wp_posts'], []
    );
    $excludedSql = (string) file_get_contents($temporary . '/excluded.sql');
    $excludedDelta = (string) file_get_contents($temporary . '/excluded-delta.sql');
    expect(
        str_contains($excludedSql, 'unsupported-extra-value https://incoming.test/cached http://base.test/legacy admin@www.incoming.test')
            && str_contains($excludedSql, 'https://www.incoming.test/download')
            && str_contains($excludedDelta, 'https://www.incoming.test/download')
            && !isset($excludedReport['url_normalization']['tables']['wp_plugin_cache'])
            && !isset($excludedReport['url_normalization']['tables']['wp_postmeta']),
        '選択を外したテーブルでは完全版・差分SQLのドメインを置換しない'
    );

    (new MergeEngine())->merge(
        __DIR__ . '/fixtures/base.sql', $temporary . '/email-selected.sql', $base, $incoming, $comparison,
        $temporary . '/email-selected-report.json', null, null, [], [
            'wp_plugin_cache' => ['admin@www.incoming.test' => 'contact@example-mail.test'],
        ]
    );
    $emailSelectedSql = (string) file_get_contents($temporary . '/email-selected.sql');
    expect(
        str_contains($emailSelectedSql, 'https://incoming.test/cached http://base.test/legacy contact@example-mail.test'),
        '個別に選択したメールアドレスを指定した値へ置換'
    );

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
