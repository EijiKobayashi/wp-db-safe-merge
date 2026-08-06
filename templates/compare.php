<?php
$labels = ['matched' => '完全一致', 'candidate' => '同一候補', 'conflict' => '要確認', 'additional' => '追加', 'base_only' => '基準DBのみ'];
$currentFilter = $_GET['filter'] ?? 'all';
?>
<section class="page-title">
  <div><div class="eyebrow">STEP 02 — REVIEW</div><h1>比較結果を確認</h1><p>候補を確認し、必要な項目だけ採用してください。</p>
    <div class="prefix-summary"><span>基準DB <code><?= $e($state['base']['prefix']) ?></code></span><span class="material-symbols-outlined" aria-hidden="true">compare_arrows</span><span>追加側 <code><?= $e($state['incoming']['prefix']) ?></code></span></div>
  </div>
  <form method="post" action="?action=merge" data-confirm="選択内容で統合SQLを作成します。よろしいですか？">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <button class="button primary" type="submit">統合SQLを作成 <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
  </form>
</section>

<section class="stats-grid">
  <?php foreach ($labels as $key => $label): ?>
    <article class="stat <?= $e($key) ?>"><span><?= $e($label) ?></span><strong><?= $e($counts[$key] ?? 0) ?></strong></article>
  <?php endforeach; ?>
</section>

<nav class="filter-tabs" aria-label="比較結果フィルター">
  <a class="<?= $currentFilter === 'all' ? 'active' : '' ?>" href="?action=compare">すべて <b><?= $e(array_sum($counts)) ?></b></a>
  <?php foreach ($labels as $key => $label): ?>
    <a class="<?= $currentFilter === $key ? 'active' : '' ?>" href="?action=compare&amp;filter=<?= $e($key) ?>"><?= $e($label) ?> <b><?= $e($counts[$key] ?? 0) ?></b></a>
  <?php endforeach; ?>
</nav>

<section class="comparison-list">
  <?php if ($result['items'] === []): ?><div class="empty">この条件に該当するデータはありません。</div><?php endif; ?>
  <?php foreach ($result['items'] as $item):
    $basePost = $item['base']; $incomingPost = $item['incoming'];
    $recommended = $item['decision']['winner'] ?? $item['recommended'];
    $titleText = $incomingPost['post_title'] ?? $basePost['post_title'] ?? '（タイトルなし）';
  ?>
    <article class="comparison-card">
      <header>
        <div><span class="kind <?= $e($item['kind']) ?>"><?= $e($labels[$item['kind']] ?? $item['kind']) ?></span><h2><?= $e($titleText) ?></h2>
          <p><?= $e($basePost['post_type'] ?? $incomingPost['post_type'] ?? '') ?> · 一致度 <?= $e((int) round((float) $item['score'] * 100)) ?>%</p></div>
        <?php if ($basePost && $incomingPost): ?><button class="text-button" type="button" data-toggle="details-<?= $e($item['id']) ?>">詳細を比較</button><?php endif; ?>
      </header>
      <?php if ($basePost && $incomingPost): ?>
        <form method="post" action="?action=decide" class="decision-form">
          <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="comparison_id" value="<?= $e($item['id']) ?>"><input type="hidden" name="page" value="<?= $e($result['page']) ?>">
          <div class="side-choice">
            <label><input type="radio" name="winner" value="base" <?= $recommended === 'base' ? 'checked' : '' ?>><span><b>基準DB</b><small>更新 <?= $e($basePost['post_modified'] ?? '') ?></small></span></label>
            <label><input type="radio" name="winner" value="incoming" <?= $recommended === 'incoming' ? 'checked' : '' ?>><span><b>追加側<?= $item['recommended'] === 'incoming' ? '・推奨' : '' ?></b><small>更新 <?= $e($incomingPost['post_modified'] ?? '') ?></small></span></label>
          </div>
          <div class="details" id="details-<?= $e($item['id']) ?>" hidden>
            <div class="diff-head"><b>項目</b><b>基準DB</b><b>追加側</b></div>
            <?php foreach (['post_title' => 'タイトル','post_name' => 'スラッグ','post_status' => 'ステータス','post_date' => '公開日時','post_modified' => '更新日時','post_excerpt' => '抜粋','post_content' => '本文','_meta' => 'カスタムフィールド'] as $field => $label): ?>
              <div class="diff-row"><strong><?= $e($label) ?></strong>
                <label><input type="radio" name="field[<?= $e($field) ?>]" value="base" <?= (($item['decision']['fields'][$field] ?? $recommended) === 'base') ? 'checked' : '' ?>><span><?= $e($field === '_meta' ? '基準DB側を維持' : mb_strimwidth((string) ($basePost[$field] ?? ''), 0, 180, '…', 'UTF-8')) ?></span></label>
                <label><input type="radio" name="field[<?= $e($field) ?>]" value="incoming" <?= (($item['decision']['fields'][$field] ?? $recommended) === 'incoming') ? 'checked' : '' ?>><span><?= $e($field === '_meta' ? '追加側から反映' : mb_strimwidth((string) ($incomingPost[$field] ?? ''), 0, 180, '…', 'UTF-8')) ?></span></label>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="button secondary small" type="submit">この選択を保存</button>
        </form>
      <?php elseif ($incomingPost): ?>
        <div class="single-info"><span>ID <?= $e($incomingPost['ID'] ?? '') ?></span><span><?= $e($incomingPost['post_status'] ?? '') ?></span><b>新しいIDで追加されます</b></div>
      <?php else: ?>
        <div class="single-info"><span>ID <?= $e($basePost['ID'] ?? '') ?></span><b>基準DBのデータを維持します</b></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<?php if ($result['pages'] > 1): ?><nav class="pagination">
  <?php for ($p = 1; $p <= $result['pages']; $p++): ?><a class="<?= $p === $result['page'] ? 'active' : '' ?>" href="?action=compare&amp;page=<?= $p ?>&amp;filter=<?= $e($currentFilter) ?>"><?= $p ?></a><?php endfor; ?>
</nav><?php endif; ?>
