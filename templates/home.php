<section class="hero">
  <div class="eyebrow">WORDPRESS DATABASE TOOL</div>
  <h1>安全に、WordPressデータを<br><span>ひとつに。</span></h1>
  <p>2つのSQLをSQLite上で比較し、投稿・カスタムフィールド・タームを確認しながら統合します。元ファイルは変更しません。</p>
</section>

<section class="panel upload-panel">
  <div class="section-heading">
    <div><span class="step">01</span><h2>比較するSQLを選択</h2></div>
    <p>一般的なWordPressのmysqldump形式に対応</p>
  </div>
  <form method="post" action="?action=upload" enctype="multipart/form-data" data-upload-form>
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <div class="upload-grid">
      <?php foreach (['a' => 'データベース A', 'b' => 'データベース B'] as $key => $label): ?>
        <label class="dropzone" data-dropzone>
          <input type="file" name="sql_<?= $key ?>" accept=".sql,text/plain,application/sql" required>
          <span class="dropzone-icon material-symbols-outlined" aria-hidden="true">database</span>
          <strong><?= $e($label) ?></strong>
          <span class="file-copy" data-file-copy>SQLファイルをドロップ、または選択</span>
          <span class="file-limit">最大 <?= $e(number_format($max / 1024 / 1024 / 1024, 1)) ?> GB</span>
        </label>
      <?php endforeach; ?>
    </div>
    <fieldset class="base-choice">
      <legend>統合後SQLの土台にする基準DB</legend>
      <label><input type="radio" name="base_side" value="a" checked><span><b>Aを基準にする</b><small>設定・ユーザー・対象外テーブルはAを維持</small></span></label>
      <label><input type="radio" name="base_side" value="b"><span><b>Bを基準にする</b><small>設定・ユーザー・対象外テーブルはBを維持</small></span></label>
    </fieldset>
    <div class="notice"><span class="material-symbols-outlined" aria-hidden="true">verified_user</span><b>処理について</b><span>アップロードしたSQLはこのサーバー内の一時領域だけで処理され、24時間後に自動削除されます。</span></div>
    <button class="button primary wide" type="submit" data-submit>比較を開始 <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
  </form>
</section>

<section class="feature-grid">
  <article><span class="material-symbols-outlined" aria-hidden="true">compare_arrows</span><h3>項目単位で比較</h3><p>タイトル、本文、ステータス、カスタムフィールドを個別に確認。</p></article>
  <article><span class="material-symbols-outlined" aria-hidden="true">merge</span><h3>ID衝突を回避</h3><p>投稿・メディア・タームIDを再採番し、参照先も更新。</p></article>
  <article><span class="material-symbols-outlined" aria-hidden="true">verified_user</span><h3>元SQLを保護</h3><p>変更は新しいSQLへ出力。元の2ファイルには触れません。</p></article>
</section>
