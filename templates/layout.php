<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$assetVersion = max(
    (int) filemtime($publicRoot . '/assets/app.css'),
    (int) filemtime($publicRoot . '/assets/app.js'),
);
?><!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= $e($title ?? 'WP DB Safety Merge') ?> — WP DB Safety Merge</title>
  <link rel="stylesheet" href="assets/app.css?v=<?= $e($assetVersion) ?>">
</head>
<body>
  <header class="app-header">
    <a class="brand" href="?action=home" aria-label="WP DB Safety Merge ホーム">
      <span class="brand-mark" aria-hidden="true"><span class="material-symbols-outlined">merge</span></span>
      <span><strong>WP DB</strong><small>Safety Merge</small></span>
    </a>
    <div class="privacy-badge"><span class="material-symbols-outlined" aria-hidden="true">lock</span>ローカル処理・外部送信なし</div>
  </header>
  <main class="main-shell">
    <?php require $contentTemplate; ?>
  </main>
  <footer class="footer">WP DB Safety Merge <span>v<?= $e($version) ?> · PHP + SQLite</span></footer>
  <script src="assets/app.js?v=<?= $e($assetVersion) ?>" defer></script>
</body>
</html>
