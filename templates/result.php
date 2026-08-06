<?php $report = $state['report_summary']; ?>
<section class="success-panel">
  <div class="success-mark material-symbols-outlined" aria-hidden="true">check_circle</div><div class="eyebrow">MERGE COMPLETE</div>
  <h1>統合SQLを作成しました</h1><p>元SQLは変更されていません。SQLと統合レポートを保存後、作業データを削除してください。</p>
  <div class="result-stats"><span><b><?= $e($report['updated']) ?></b>更新</span><span><b><?= $e($report['added']) ?></b>追加</span><span><b><?= $e($report['meta_rows']) ?></b>メタデータ</span><span><b><?= $e($report['term_relationships']) ?></b>ターム関連</span></div>
  <div class="download-actions"><a class="button primary" href="?action=download&amp;type=sql"><span class="material-symbols-outlined" aria-hidden="true">download</span>統合SQLをダウンロード</a><a class="button secondary" href="?action=download&amp;type=report"><span class="material-symbols-outlined" aria-hidden="true">folder_zip</span>統合レポートを保存</a></div>
</section>
<section class="danger-panel"><div><h2>作業データの削除</h2><p>アップロードSQL、比較用SQLite、統合SQL、レポートをサーバーから削除します。この操作は取り消せません。</p></div>
  <form method="post" action="?action=delete" data-confirm="ダウンロードは完了しましたか？ 作業データをすべて削除します。"><input type="hidden" name="_token" value="<?= $e($csrf) ?>"><button class="button danger" type="submit"><span class="material-symbols-outlined" aria-hidden="true">delete</span>すべて削除</button></form>
</section>
