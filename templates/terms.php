<section class="page-title"><div><div class="eyebrow">STEP 03 — TERMS</div><h1>ターム追加候補を確認</h1><p>追加側にしかないターム／タクソノミーの組み合わせです。必要なものだけ追加してください。</p></div></section>

<form method="post" action="?action=merge" data-confirm="選択内容で統合SQLを作成します。よろしいですか？" class="term-review-form">
  <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
  <section class="panel term-review-panel"><header><div><h2>追加候補</h2><p>チェックした定義だけ基準DBへ追加します。記事で選択したB側タームに必要な定義も選択してください。</p></div><b><?= $e(count($review['additions'])) ?>件</b></header>
    <div class="term-review-actions"><button type="button" class="text-button" data-review-select-all>すべて選択</button><span data-review-count>0件選択</span></div>
    <div class="term-review-list"><?php if ($review['additions'] === []): ?><p class="domain-empty">追加候補はありません。</p><?php else: ?><?php foreach ($review['additions'] as $term): ?><label><input type="checkbox" name="term_addition_ids[]" value="<?= $e($term['id']) ?>" data-review-checkbox><span><b><?= $e($term['name']) ?></b><small><?= $e($term['taxonomy']) ?> · <?= $e($term['slug']) ?> · Bで<?= $e($term['references']) ?>記事</small></span></label><?php endforeach; ?><?php endif; ?></div>
  </section>
  <section class="panel term-warning-panel"><header><div><h2>A側の未使用ターム</h2><p>現在どの記事にも紐付いていません。安全のため自動削除しません。</p></div><b><?= $e(count($review['unused_base'])) ?>件</b></header>
    <div class="term-warning-list"><?php foreach (array_slice($review['unused_base'], 0, 200) as $term): ?><span><b><?= $e($term['name']) ?></b><small><?= $e($term['taxonomy']) ?> · <?= $e($term['slug']) ?></small></span><?php endforeach; ?></div>
    <?php if (count($review['unused_base']) > 200): ?><p class="domain-note">先頭200件を表示しています。</p><?php endif; ?>
  </section>
  <div class="term-review-submit"><a class="button secondary" href="?action=compare">比較画面へ戻る</a><button class="button primary" type="submit">統合SQLを作成 <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div>
</form>
