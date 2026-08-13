<?php
$labels = ['matched' => '完全一致', 'candidate' => '同一候補', 'conflict' => '要確認', 'additional' => '追加', 'base_only' => '基準DBのみ'];
$currentFilter = $_GET['filter'] ?? 'all';
$perPage = $result['perPage'];
?>
<section class="page-title">
  <div><div class="eyebrow">STEP 02 — REVIEW</div><h1>比較結果を確認</h1><p>候補を確認し、必要な項目だけ採用してください。</p>
    <div class="prefix-summary"><span>基準DB <code><?= $e($state['base']['prefix']) ?></code></span><span class="material-symbols-outlined" aria-hidden="true">compare_arrows</span><span>追加側 <code><?= $e($state['incoming']['prefix']) ?></code></span></div>
  </div>
  <form id="merge-form" method="post" action="?action=merge" data-confirm="選択内容で統合SQLを作成します。よろしいですか？">
    <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
    <button class="button primary" type="submit">統合SQLを作成 <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
  </form>
</section>

<?php
$urlNormalization = is_array($state['url_normalization'] ?? null) ? $state['url_normalization'] : null;
$hasUrlCandidates = $urlNormalization !== null && array_filter(
    (array) ($urlNormalization['tables'] ?? []),
    static fn (mixed $counts): bool => !is_array($counts) || (int) ($counts['url'] ?? 0) + (int) ($counts['host'] ?? 0) > 0
) !== [];
?>
<?php if ($urlNormalization !== null): ?>
  <section class="panel domain-review">
    <header>
      <div><span class="step">URL</span><div><h2>ドメイン置換を確認</h2><p>URL・ホスト名とメールアドレスを分けて、テーブルごとに選択できます。</p></div></div>
      <?php if ($hasUrlCandidates): ?><button class="text-button" type="button" data-domain-select-all>URL・ホストをすべて解除</button><?php endif; ?>
    </header>
    <div class="domain-mappings">
      <div class="domain-mapping"><b>URL</b><code><?= $e(implode(' / ', $urlNormalization['incoming_urls'] ?? [])) ?></code><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span><code><?= $e($urlNormalization['target_origin'] ?? '') ?></code></div>
      <div class="domain-mapping email"><b>メール</b><code>候補ごとに指定</code><small>初期状態では変換しません</small></div>
    </div>
    <?php if (($urlNormalization['tables'] ?? []) === []): ?>
      <p class="domain-empty">置換候補は検出されませんでした。</p>
    <?php else: ?>
      <div class="domain-table-list">
        <?php foreach ($urlNormalization['tables'] as $table => $replacementCounts): $replacementCounts = is_array($replacementCounts) ? $replacementCounts : ['total' => (int) $replacementCounts]; ?>
          <div class="domain-table-item"><div class="domain-table-head"><code><?= $e($table) ?></code><small class="domain-kind-counts"><?php if (($replacementCounts['url'] ?? 0) > 0): ?><b>URL <?= $e($replacementCounts['url']) ?></b><?php endif; ?><?php if (($replacementCounts['host'] ?? 0) > 0): ?><b>ホスト <?= $e($replacementCounts['host']) ?></b><?php endif; ?><?php if (($replacementCounts['email'] ?? 0) > 0): ?><b class="email">メール <?= $e($replacementCounts['email']) ?></b><?php endif; ?><em>計<?= $e($replacementCounts['total'] ?? 0) ?>件</em></small></div><div class="domain-table-choices"><?php if ((int) ($replacementCounts['url'] ?? 0) + (int) ($replacementCounts['host'] ?? 0) > 0): ?><label><input type="checkbox" name="url_normalization_tables[]" value="<?= $e($table) ?>" form="merge-form" checked data-domain-checkbox> URL・ホスト名を置換</label><?php else: ?><small>URL・ホスト名の候補なし</small><?php endif; ?></div></div>
        <?php endforeach; ?>
      </div>
      <?php $emailCandidates = (array) ($urlNormalization['email_candidates'] ?? []); ?>
      <?php if ($emailCandidates !== []): ?>
        <?php $emailStateKey = hash('sha256', implode(',', array_map(static fn (array $candidate): string => (string) ($candidate['id'] ?? ''), $emailCandidates))); ?>
        <section class="email-review-list" data-email-settings data-email-state-key="<?= $e($emailStateKey) ?>"><header><div><h3>Stgメールの変換先ドメインを指定</h3><p>変換する候補だけチェックし、@より後ろのドメインを入力してください。ローカル部は変更しません。入力内容はページ移動後も保持されます。</p></div><b><?= $e(count($emailCandidates)) ?>種類</b></header>
          <div class="email-bulk-controls"><label><span>共通の変換先ドメイン</span><input type="text" placeholder="例: example.com" inputmode="url" data-email-bulk-target></label><button class="button secondary small" type="button" data-email-bulk-apply>全候補に設定してチェック</button><small>個別に異なるドメインが必要な候補は、適用後に各行で変更できます。</small></div>
          <div><?php foreach ($emailCandidates as $candidate): $source = (string) ($candidate['source'] ?? ''); $localPart = strstr($source, '@', true); $tables = array_keys((array) ($candidate['tables'] ?? [])); ?><div class="email-review-item"><input type="checkbox" name="email_normalization_candidates[]" value="<?= $e($candidate['id'] ?? '') ?>" form="merge-form" aria-label="<?= $e($source) ?> を変換する" data-email-checkbox><span><span><code><?= $e($source) ?></code><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span><span class="email-target-compose"><code><?= $e($localPart === false ? '' : $localPart) ?>@</code><input class="email-target-input" type="text" name="email_normalization_targets[<?= $e($candidate['id'] ?? '') ?>]" value="" placeholder="例: example.com" form="merge-form" aria-label="<?= $e($source) ?> の変換後ドメイン" inputmode="url" data-email-target></span></span><small><?= $e(implode(' / ', $tables)) ?> · <?= $e($candidate['count'] ?? 0) ?>箇所</small></span></div><?php endforeach; ?></div>
        </section>
      <?php endif; ?>
      <p class="domain-note">候補件数は比較時点の概算です。選択した投稿内容により、実際の置換件数が変わる場合があります。</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="stats-grid">
  <?php foreach ($labels as $key => $label): ?>
    <article class="stat <?= $e($key) ?>"><span><?= $e($label) ?></span><strong><?= $e($counts[$key] ?? 0) ?></strong></article>
  <?php endforeach; ?>
</section>

<nav class="filter-tabs" aria-label="比較結果フィルター">
  <a class="<?= $currentFilter === 'all' ? 'active' : '' ?>" href="?action=compare&amp;per_page=<?= $e($perPage) ?>">すべて <b><?= $e(array_sum($counts)) ?></b></a>
  <?php foreach ($labels as $key => $label): ?>
    <a class="<?= $currentFilter === $key ? 'active' : '' ?>" href="?action=compare&amp;filter=<?= $e($key) ?>&amp;per_page=<?= $e($perPage) ?>"><?= $e($label) ?> <b><?= $e($counts[$key] ?? 0) ?></b></a>
  <?php endforeach; ?>
</nav>

<form class="page-size" method="get">
  <input type="hidden" name="action" value="compare"><input type="hidden" name="filter" value="<?= $e($currentFilter) ?>">
  <label>1ページあたり
    <select name="per_page" data-auto-submit>
      <?php foreach ([20, 50, 100, 200] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?>件</option><?php endforeach; ?>
    </select>
  </label>
  <noscript><button class="button secondary small" type="submit">変更</button></noscript>
</form>

<form id="bulk-form" class="bulk-toolbar" method="post" action="?action=bulk-decide">
  <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
  <input type="hidden" name="page" value="<?= $e($result['page']) ?>">
  <input type="hidden" name="filter" value="<?= $e($currentFilter) ?>">
  <input type="hidden" name="per_page" value="<?= $e($perPage) ?>">
  <button class="text-button" type="button" data-select-all>このページをすべて選択</button>
  <span data-selection-count>0件選択</span>
  <label>一括適用
    <select name="bulk_winner">
      <option value="recommended">推奨側を採用</option>
      <option value="base">基準DBを採用</option>
      <option value="incoming">追加側を採用</option>
    </select>
  </label>
  <button class="button secondary small" type="submit">選択項目に適用</button>
</form>

<section class="comparison-list">
  <?php if ($result['items'] === []): ?><div class="empty">この条件に該当するデータはありません。</div><?php endif; ?>
  <?php foreach ($result['items'] as $item):
    $basePost = $item['base']; $incomingPost = $item['incoming'];
    $recommended = $item['decision']['winner'] ?? $item['recommended'];
    $titleText = $incomingPost['post_title'] ?? $basePost['post_title'] ?? '（タイトルなし）';
  ?>
    <article class="comparison-card" id="comparison-<?= $e($item['id']) ?>">
      <header>
        <?php if ($basePost && $incomingPost): ?><label class="row-selector" title="一括編集に選択"><input type="checkbox" name="comparison_ids[]" value="<?= $e($item['id']) ?>" form="bulk-form" data-bulk-checkbox><span></span></label><?php endif; ?>
        <div class="comparison-summary"><span class="kind <?= $e($item['kind']) ?>"><?= $e($labels[$item['kind']] ?? $item['kind']) ?></span><h2><?= $e($titleText) ?></h2>
          <p><?= $e($basePost['post_type'] ?? $incomingPost['post_type'] ?? '') ?> · 一致度 <?= $e((int) round((float) $item['score'] * 100)) ?>%</p></div>
        <?php if ($basePost && $incomingPost): ?><button class="text-button" type="button" data-toggle="details-<?= $e($item['id']) ?>">詳細を比較</button><?php endif; ?>
      </header>
      <?php if ($basePost && $incomingPost): ?>
        <form method="post" action="?action=decide" class="decision-form">
          <input type="hidden" name="_token" value="<?= $e($csrf) ?>"><input type="hidden" name="comparison_id" value="<?= $e($item['id']) ?>"><input type="hidden" name="page" value="<?= $e($result['page']) ?>"><input type="hidden" name="filter" value="<?= $e($currentFilter) ?>"><input type="hidden" name="per_page" value="<?= $e($perPage) ?>">
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

<?php if ($result['pages'] > 1): ?><nav class="pagination" aria-label="比較結果ページ">
  <?php if ($result['page'] > 1): ?><a class="pager-button" href="?action=compare&amp;page=<?= $result['page'] - 1 ?>&amp;filter=<?= $e($currentFilter) ?>&amp;per_page=<?= $e($perPage) ?>"><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>前へ</a><?php else: ?><span class="pager-button disabled"><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>前へ</span><?php endif; ?>
  <span class="page-position"><?= $e($result['page']) ?> / <?= $e($result['pages']) ?>ページ</span>
  <?php if ($result['page'] < $result['pages']): ?><a class="pager-button" href="?action=compare&amp;page=<?= $result['page'] + 1 ?>&amp;filter=<?= $e($currentFilter) ?>&amp;per_page=<?= $e($perPage) ?>">次へ<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a><?php else: ?><span class="pager-button disabled">次へ<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></span><?php endif; ?>
</nav><?php endif; ?>
