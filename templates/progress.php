<section class="progress-panel" data-progress-page data-status-url="?action=status" data-complete-url="?action=compare">
  <div class="progress-symbol material-symbols-outlined" aria-hidden="true">database</div>
  <div class="eyebrow">SQLITE ANALYSIS</div>
  <h1>SQLを解析しています</h1>
  <p data-progress-message><?= $e($state['message'] ?? '準備しています') ?></p>
  <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $e($state['progress'] ?? 0) ?>">
    <span data-progress-bar style="width:<?= $e($state['progress'] ?? 0) ?>%"></span>
  </div>
  <strong class="progress-value" data-progress-value><?= $e($state['progress'] ?? 0) ?>%</strong>
  <div class="progress-steps">
    <span><i></i>基準DB</span><span><i></i>追加側DB</span><span><i></i>投稿比較</span>
  </div>
  <p class="progress-note">大容量SQLでは数分かかることがあります。この画面は自動的に更新されます。</p>
  <div class="progress-error" data-progress-error hidden><span class="material-symbols-outlined" aria-hidden="true">error</span><p></p><a class="button secondary" href="?action=home">最初からやり直す</a></div>
</section>
