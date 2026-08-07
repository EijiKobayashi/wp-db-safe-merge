# WP DB Safety Merge

2つのWordPress SQLダンプを一時SQLiteへ取り込み、投稿単位で比較・確認して、安全な統合SQLを生成するローカルファーストのPHP Webアプリです。元のSQLは変更しません。

現在のバージョン：**v0.1.2**

## 必要環境

- PHP 8.2以上
- PDO SQLite / SQLite 3
- mbstring / JSON
- 一般的なMySQL・MariaDBのWordPress SQLダンプ（`CREATE TABLE` と `INSERT ... VALUES` を含むもの）

## 起動

```bash
composer serve
```

Composerを使わない場合：

```bash
php -S 127.0.0.1:8080 index.php
```

`http://127.0.0.1:8080` を開き、SQL A・Bと基準DBを選択します。

## Web公開ディレクトリへの配置

リポジトリのルートがそのままドキュメントルートになる構成です。LocalなどのURLに対応するディレクトリへ、リポジトリの内容をコピーして使用できます。

```bash
destination="/path/to/app/public/wp-db-safe-merge"
mkdir -p "$destination"
rsync -a \
  --exclude='.git/' \
  --exclude='.DS_Store' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='storage/workspaces/*' \
  ./ "$destination/"
```

`index.php`、`assets/`、`bootstrap.php`、`src/`、`templates/`、`storage/` は同じ階層に配置されます。`.htaccess` と `storage/.htaccess` は内部PHPファイル、アップロードSQL、SQLiteへの直接アクセスを禁止するため、削除せず一緒に配置してください。

このアプリは機密情報を含むSQLを扱います。フラット配置も公開インターネットでは使用せず、Localなどのローカル環境または別途アクセス制限された環境に限定してください。

## 処理の流れ

1. SQL A・Bをサーバー内の権限を限定した一時領域へ保存
2. SQLを逐次解析し、それぞれ個別のSQLiteへ格納
3. スラッグ、公開日時、タイトル、本文から同一記事候補を作成
4. 更新日時、同日時の場合は公開日時から推奨側を表示
5. 投稿項目とカスタムフィールドの採用側をGUIで確認
6. 投稿・attachment・term IDを再採番し、参照とPHPシリアライズ値を更新
7. 基準SQLにトランザクション形式の統合操作を追記した新規SQLとJSONレポートを出力
8. ダウンロード後、元SQL・SQLite・出力物を画面から一括削除

期限切れの作業領域は24時間後に自動削除の対象になります。アップロード内容を外部サービスへ送信する処理はありません。

## 対応対象

- 投稿、固定ページ、カスタム投稿タイプ、attachment
- postmeta、ACFの画像・ファイル・ギャラリー・投稿参照・関連フィールド
- ターム、タクソノミー、投稿との関連
- Contact Form 7、MW WP Form、ACF Proの投稿・メタデータ
- Yoast SEOの `indexable`、`primary_term`、`seo_links` 関連行

ユーザー、コメント、WordPress設定、対象外のテーブルは基準DB側を維持します。上記にないプラグインのテーブル・設定・データも、基準DBに含まれるものは削除せずそのまま引き継ぎます。追加側の独自プラグインテーブルは、主キー衝突や参照ID破損を避けるため、専用の統合定義がない限り自動統合しません。追加側にしかないデータを「削除」として反映することはありません。

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

テストは、プレフィックスの異なるWordPressダンプを使って、SQLite取込、同一記事判定、ID衝突、ACFシリアライズ値、ターム、Yoast、統合レポートを検証します。

## セキュリティ上の注意

- 公開インターネットへそのまま設置せず、ローカル環境またはアクセス制限された環境で使用してください。
- SQLにはユーザー情報などが含まれるため、作業後は画面の「すべて削除」を実行してください。
- 本番DBへ適用する前に、生成SQLのバックアップとステージング環境での確認を行ってください。
