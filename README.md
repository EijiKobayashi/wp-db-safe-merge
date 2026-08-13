# WP DB Safety Merge

2つのWordPress SQLダンプを一時SQLiteへ取り込み、投稿単位で比較・確認して、安全な統合SQLを生成するローカルファーストのPHP Webアプリです。元のSQLは変更しません。

現在のバージョン：**v0.2.5**

## 必要環境

- PHP 8.2以上
- PDO SQLite / SQLite 3
- mbstring / JSON
- 一般的なMySQL・MariaDBのWordPress SQLダンプ（`CREATE TABLE` と `INSERT ... VALUES` を含むもの）

## Localやサーバーに設置して使用する

このアプリはWordPressプラグインではなく、独立したPHP Webアプリです。`wp-content/plugins`へは設置せず、リポジトリのファイル一式をLocalなどのローカル環境、またはPHPが動作するサーバーのWeb公開ディレクトリへ配置して使用します。

例えばLocalでは、対象サイトの `app/public`配下へ次のように配置します。

```text
app/public/wp-db-safe-merge/
```

サーバーでは、ドキュメントルート配下のアクセス制限したディレクトリへ配置します。

```text
/path/to/document-root/wp-db-safe-merge/
```

配置後は、そのディレクトリのURLをブラウザーで開きます。

```text
https://example.test/wp-db-safe-merge/
```

実行に必要な主なファイルとディレクトリは次のとおりです。

- `index.php`
- `bootstrap.php`
- `assets/`
- `src/`
- `templates/`
- `storage/`
- `.htaccess`
- `.user.ini`

`storage/workspaces/` にはWebサーバープロセスが書き込める権限が必要です。配布済みのCSS・JavaScriptを `assets/` に同梱しているため、通常の利用ではComposerやnpmのインストールは必要ありません。

一式をコピーする例：

```bash
destination="/path/to/document-root/wp-db-safe-merge"
mkdir -p "$destination"
rsync -a \
  --exclude='.git/' \
  --exclude='.DS_Store' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='storage/workspaces/*' \
  ./ "$destination/"
```

`.htaccess` と `storage/.htaccess` は、内部PHPファイル、アップロードSQL、SQLiteへの直接アクセスを禁止するため、削除せず一緒に配置してください。Apache以外のWebサーバーでは、同等のアクセス禁止設定が別途必要です。

このアプリは機密情報を含むSQLを扱います。公開インターネット上へ無制限で設置せず、Localなどのローカル環境、VPN内、または認証で保護されたサーバーで使用してください。

## 開発用サーバーで起動する

```bash
composer serve
```

Composerを使わない場合：

```bash
php -S 127.0.0.1:8080 index.php
```

`http://127.0.0.1:8080` を開き、SQL A・Bと基準DBを選択します。

## 処理の流れ

1. SQL A・Bをサーバー内の権限を限定した一時領域へ保存
2. SQLを逐次解析し、それぞれ個別のSQLiteへ格納
3. スラッグ、公開日時、タイトル、本文から同一記事候補を作成
4. 更新日時、同日時の場合は公開日時から推奨側を表示
5. 投稿項目、カスタムフィールド、投稿に紐付くタームの採用側をGUIで確認
6. 投稿・attachment・term IDを再採番し、参照とPHPシリアライズ値を更新
7. 追加側の `home` / `siteurl` のURL・ホスト名・メールアドレスのドメインを、UIで選択したテーブルのみ基準DB側へ正規化
8. SQLite上の統合済みデータから主要テーブルを再生成した完全版SQL、基準DBへ単独適用できる統合差分SQL、JSONレポートを出力
9. ダウンロード後、元SQL・SQLite・出力物を画面から一括削除

期限切れの作業領域は24時間後に自動削除の対象になります。アップロード内容を外部サービスへ送信する処理はありません。

## 対応対象

- 投稿、固定ページ、カスタム投稿タイプ、attachment
- postmeta、ACFの画像・ファイル・ギャラリー・投稿参照・関連フィールド
- ターム、タクソノミー、投稿との関連（投稿ごとに両DBから個別・一括選択し、追加候補は別画面で確認）
- Contact Form 7、MW WP Form、ACF Proの投稿・メタデータ
- Yoast SEOの `indexable`、`primary_term`、`seo_links` 関連行

ユーザー、コメント、WordPress設定、対象外のテーブルは基準DB側を維持します。上記にないプラグインのテーブル・設定・データも、基準DBに含まれるものは削除せずそのまま引き継ぎます。追加側の独自プラグインテーブルは、主キー衝突や参照ID破損を避けるため、専用の統合定義がない限り自動統合しません。追加側にしかないデータを「削除」として反映することはありません。

## URLの正規化

基準DBと追加側DBの `options` テーブルから `home` / `siteurl` を検出します。比較画面ではURL・ホスト名の置換候補をテーブル別に表示し、チェックしたテーブルだけを置換できます。メールアドレスはサイトのホストと正しいメールドメインが異なる場合があるため初期状態では変換しません。追加側ホストを持つメールをアドレス別に一覧化し、候補ごとに変換するかを選んで変換後のドメインだけを明示します。同じメールが複数テーブルにある場合は1件に集約し、ローカル部を維持したまま該当箇所すべてへ適用します。完全版SQLと統合差分SQLでは、追加側ホストの `http://`、`https://`、www有無のURL、単独のホスト名を基準DBの `home`（なければ `siteurl`）と同じホストへ統一します。URLのパス、クエリ、フラグメントは維持します。

ドメイン変換は投稿・メタ・Yoastなどの統合対象だけでなく、基準DBから引き継ぐ対象外プラグインテーブルも選択できます。PHPシリアライズ値は復元してから再生成するため、文字数の変化によるデータ破損を防ぎます。選択テーブル、変換総数、テーブル別件数はJSONレポートの `url_normalization` に記録します。

外部ホストのHTTP URLは変更しません。圧縮・暗号化・独自バイナリ形式の値、およびPHPオブジェクトとしてシリアライズされた値は自動変換の対象外です。

## CSS

画面はTailwind CSS 4のソースで管理し、配布用CSSを同梱しています。再ビルドする場合：

```bash
npm install
npm run css:build
```

フォントは `Gen Interface JP` を先頭候補とし、利用環境にない場合は日本語システムフォントへフォールバックします。

UIアイコンにはGoogle Material Symbols Outlinedの必要なグリフだけを同梱しています。Google Fontsを実行時に読み込まないため、アプリの表示によって外部へ通信することはありません。

## テスト

```bash
composer test
```

テストは、プレフィックスの異なるWordPressダンプを使って、SQLite取込、同一記事判定、ID衝突、ACFシリアライズ値、ターム、Yoast、全体SQL・統合差分SQL、統合レポートを検証します。

v0.2.0以降の完全版SQLは、A側SQLへB側差分を末尾追加する方式ではありません。投稿・メタ・ターム・対応プラグイン行をSQLite上で統合した後、各テーブルの確定データとして複数行INSERTで再生成します。v0.2.2では、MySQLの`max_allowed_packet`を超えない小さなINSERTと、インポート時間を抑える大きなトランザクション確定単位を分離しています。

## セキュリティ上の注意

- 公開インターネットへそのまま設置せず、ローカル環境またはアクセス制限された環境で使用してください。
- SQLにはユーザー情報などが含まれるため、作業後は画面の「すべて削除」を実行してください。
- 本番DBへ適用する前に、生成SQLのバックアップとステージング環境での確認を行ってください。
