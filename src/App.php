<?php

declare(strict_types=1);

namespace WpDbSafeMerge;

use RuntimeException;
use Throwable;
use WpDbSafeMerge\Domain\ComparisonEngine;
use WpDbSafeMerge\Domain\ComparisonStore;
use WpDbSafeMerge\Domain\MergeEngine;
use WpDbSafeMerge\Domain\UrlNormalizationPreview;
use WpDbSafeMerge\Infrastructure\DumpImporter;
use WpDbSafeMerge\Infrastructure\DumpStore;
use WpDbSafeMerge\Support\Csrf;
use WpDbSafeMerge\Support\View;
use WpDbSafeMerge\Support\Workspace;

final class App
{
    public const VERSION = '0.2.3';

    private Workspace $workspaces;
    private View $view;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->workspaces = new Workspace((string) $config['storage']);
        $this->view = new View(
            (string) $config['root'] . '/templates',
            (string) $config['public_root'],
            self::VERSION,
        );
    }

    public function run(): void
    {
        if (random_int(1, 100) === 1) {
            $this->workspaces->cleanup((int) $this->config['workspace_ttl']);
        }
        $action = (string) ($_GET['action'] ?? 'home');
        try {
            match ($action) {
                'home' => $this->home(),
                'upload' => $this->upload(),
                'progress' => $this->progress(),
                'status' => $this->status(),
                'compare' => $this->compare(),
                'decide' => $this->decide(),
                'bulk-decide' => $this->bulkDecide(),
                'merge' => $this->merge(),
                'result' => $this->result(),
                'download' => $this->download(),
                'delete' => $this->delete(),
                default => $this->notFound(),
            };
        } catch (Throwable $e) {
            http_response_code($e instanceof RuntimeException ? 422 : 500);
            $this->view->render('error', ['title' => '処理を完了できませんでした', 'message' => $e->getMessage()]);
        }
    }

    private function home(): void
    {
        $this->view->render('home', ['title' => '安全に、WordPressデータをひとつに。', 'csrf' => Csrf::token(), 'max' => $this->config['max_upload_bytes']]);
    }

    private function upload(): void
    {
        $this->postOnly();
        $this->csrf();
        $baseSide = in_array($_POST['base_side'] ?? '', ['a', 'b'], true) ? $_POST['base_side'] : 'a';
        $id = $this->workspaces->create();
        try {
            foreach (['a', 'b'] as $side) {
                $upload = $_FILES['sql_' . $side] ?? null;
                if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("SQL $side のアップロードに失敗しました。");
                }
                if ((int) $upload['size'] > (int) $this->config['max_upload_bytes']) {
                    throw new RuntimeException('SQLファイルが許容サイズを超えています。');
                }
                $name = (string) ($upload['name'] ?? 'database.sql');
                if (!str_ends_with(strtolower($name), '.sql')) {
                    throw new RuntimeException('拡張子が.sqlのファイルを選択してください。');
                }
                $destination = $this->workspaces->path($id, "source_$side.sql");
                if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
                    throw new RuntimeException('SQLファイルを安全な作業領域へ移動できません。');
                }
                chmod($destination, 0600);
            }
            $baseKey = $baseSide;
            $incomingKey = $baseSide === 'a' ? 'b' : 'a';
            $this->workspaces->saveState($id, [
                'id' => $id, 'created_at' => time(), 'status' => 'processing', 'progress' => 5,
                'message' => 'アップロードを確認しました', 'base_side' => $baseKey, 'incoming_side' => $incomingKey,
            ]);
            $_SESSION['workspace'] = $id;

            if (function_exists('fastcgi_finish_request')) {
                header('Location: ?action=progress', true, 303);
                session_write_close();
                fastcgi_finish_request();
                $this->analyzeWorkspace($id);
                exit;
            }

            $this->analyzeWorkspace($id);
            $this->redirect('?action=compare');
        } catch (Throwable $e) {
            try { $this->workspaces->delete($id); } catch (Throwable) {}
            throw $e;
        }
    }

    private function analyzeWorkspace(string $id): void
    {
        try {
            $state = $this->workspaces->state($id);
            $baseKey = (string) $state['base_side'];
            $incomingKey = (string) $state['incoming_side'];
            $importer = new DumpImporter();

            $state['progress'] = 10;
            $state['message'] = '基準DBをSQLiteへ取り込んでいます';
            $this->workspaces->saveState($id, $state);
            $baseStore = new DumpStore($this->workspaces->path($id, 'base.sqlite'));
            $baseInfo = $importer->import($this->workspaces->path($id, "source_$baseKey.sql"), $baseStore);

            $state['progress'] = 40;
            $state['message'] = '追加側DBをSQLiteへ取り込んでいます';
            $state['base'] = $baseInfo;
            $this->workspaces->saveState($id, $state);
            $incomingStore = new DumpStore($this->workspaces->path($id, 'incoming.sqlite'));
            $incomingInfo = $importer->import($this->workspaces->path($id, "source_$incomingKey.sql"), $incomingStore);

            $state['progress'] = 70;
            $state['message'] = '投稿と関連データを比較しています';
            $state['incoming'] = $incomingInfo;
            $this->workspaces->saveState($id, $state);
            $comparison = new ComparisonStore($this->workspaces->path($id, 'comparison.sqlite'));
            $counts = (new ComparisonEngine())->compare($baseStore, $incomingStore, $comparison);

            $state['progress'] = 90;
            $state['message'] = 'ドメイン置換対象を確認しています';
            $this->workspaces->saveState($id, $state);
            $state['url_normalization'] = (new UrlNormalizationPreview())->inspect(
                $this->workspaces->path($id, "source_$baseKey.sql"),
                $baseStore,
                $incomingStore,
            );

            $state['status'] = 'compared';
            $state['progress'] = 100;
            $state['message'] = '比較が完了しました';
            $state['counts'] = $counts;
            $this->workspaces->saveState($id, $state);
        } catch (Throwable $e) {
            $state = $this->workspaces->state($id);
            $state['status'] = 'failed';
            $state['message'] = $e->getMessage();
            $this->workspaces->saveState($id, $state);
        }
    }

    private function progress(): void
    {
        $state = $this->workspaces->state($this->workspaceId());
        $mergeMode = ($_GET['mode'] ?? '') === 'merge';
        if ($mergeMode && ($state['status'] ?? '') === 'merged') { $this->redirect('?action=result'); }
        if (!$mergeMode && ($state['status'] ?? '') === 'compared') { $this->redirect('?action=compare'); }
        $this->view->render('progress', [
            'title' => $mergeMode ? '統合SQLを作成しています' : 'SQLを解析しています',
            'heading' => $mergeMode ? '統合SQLを作成しています' : 'SQLを解析しています',
            'state' => $state,
            'completeStatus' => $mergeMode ? 'merged' : 'compared',
            'completeUrl' => $mergeMode ? '?action=result' : '?action=compare',
        ]);
    }

    private function status(): void
    {
        $state = $this->workspaces->state($this->workspaceId());
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'status' => $state['status'] ?? 'processing',
            'progress' => (int) ($state['progress'] ?? 0),
            'message' => (string) ($state['message'] ?? '処理しています'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function compare(): void
    {
        $id = $this->workspaceId();
        $state = $this->workspaces->state($id);
        $previewTables = $state['url_normalization']['tables'] ?? null;
        $legacyPreview = is_array($previewTables)
            && array_filter($previewTables, static fn (mixed $counts): bool => !is_array($counts)) !== [];
        $missingMappings = is_array($state['url_normalization'] ?? null)
            && !isset($state['url_normalization']['source_hosts'], $state['url_normalization']['email_source_hosts'], $state['url_normalization']['target_host']);
        $outdatedPreview = is_array($state['url_normalization'] ?? null)
            && (int) ($state['url_normalization']['preview_version'] ?? 0) < 4;
        if (!array_key_exists('url_normalization', $state) || $legacyPreview || $missingMappings || $outdatedPreview) {
            $state['url_normalization'] = (new UrlNormalizationPreview())->inspect(
                $this->workspaces->path($id, 'source_' . $state['base_side'] . '.sql'),
                new DumpStore($this->workspaces->path($id, 'base.sqlite')),
                new DumpStore($this->workspaces->path($id, 'incoming.sqlite')),
            );
            $this->workspaces->saveState($id, $state);
        }
        $store = new ComparisonStore($this->workspaces->path($id, 'comparison.sqlite'));
        $perPage = $this->comparisonPerPage((int) ($_GET['per_page'] ?? 20));
        $page = $store->page((int) ($_GET['page'] ?? 1), $perPage, $this->comparisonFilter((string) ($_GET['filter'] ?? 'all')));
        $this->view->render('compare', ['title' => '比較結果', 'csrf' => Csrf::token(), 'state' => $state, 'result' => $page, 'counts' => $store->counts()]);
    }

    private function decide(): void
    {
        $this->postOnly();
        $this->csrf();
        $id = $this->workspaceId();
        $comparisonId = filter_input(INPUT_POST, 'comparison_id', FILTER_VALIDATE_INT);
        if (!$comparisonId) { throw new RuntimeException('比較項目を特定できません。'); }
        $winner = in_array($_POST['winner'] ?? '', ['base', 'incoming'], true) ? $_POST['winner'] : 'base';
        $allowedFields = ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_date', 'post_modified', '_meta'];
        $fields = [];
        foreach ($allowedFields as $field) {
            $value = $_POST['field'][$field] ?? $winner;
            $fields[$field] = in_array($value, ['base', 'incoming'], true) ? $value : $winner;
        }
        $store = new ComparisonStore($this->workspaces->path($id, 'comparison.sqlite'));
        $store->decide((int) $comparisonId, ['winner' => $winner, 'fields' => $fields, 'decided_at' => gmdate(DATE_ATOM)]);
        $filter = $this->comparisonFilter((string) ($_POST['filter'] ?? 'all'));
        $perPage = $this->comparisonPerPage((int) ($_POST['per_page'] ?? 20));
        $this->redirect('?action=compare&page=' . max(1, (int) ($_POST['page'] ?? 1)) . '&filter=' . rawurlencode($filter) . '&per_page=' . $perPage . '#comparison-' . (int) $comparisonId);
    }

    private function bulkDecide(): void
    {
        $this->postOnly();
        $this->csrf();
        $id = $this->workspaceId();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['comparison_ids'] ?? [])), static fn (int $value): bool => $value > 0)));
        if ($ids === []) { throw new RuntimeException('一括変更する項目を選択してください。'); }
        $winner = in_array($_POST['bulk_winner'] ?? '', ['base', 'incoming', 'recommended'], true) ? $_POST['bulk_winner'] : 'recommended';
        $store = new ComparisonStore($this->workspaces->path($id, 'comparison.sqlite'));
        $store->bulkDecide($ids, $winner);
        $filter = $this->comparisonFilter((string) ($_POST['filter'] ?? 'all'));
        $perPage = $this->comparisonPerPage((int) ($_POST['per_page'] ?? 20));
        $this->redirect('?action=compare&page=' . max(1, (int) ($_POST['page'] ?? 1)) . '&filter=' . rawurlencode($filter) . '&per_page=' . $perPage);
    }

    private function merge(): void
    {
        $this->postOnly();
        $this->csrf();
        $id = $this->workspaceId();
        $state = $this->workspaces->state($id);
        if (($state['status'] ?? '') !== 'compared') {
            throw new RuntimeException('比較が完了していないため、統合を開始できません。');
        }
        if (is_array($state['url_normalization'] ?? null)) {
            $candidateTables = array_keys((array) ($state['url_normalization']['tables'] ?? []));
            $requestedTables = array_values(array_unique(array_filter(
                array_map('strval', (array) ($_POST['url_normalization_tables'] ?? [])),
                static fn (string $table): bool => $table !== ''
            )));
            $state['url_normalization_tables'] = array_values(array_intersect($candidateTables, $requestedTables));
            $emailCandidates = [];
            foreach ((array) ($state['url_normalization']['email_candidates'] ?? []) as $candidate) {
                if (!is_array($candidate) || !isset($candidate['id'], $candidate['table'], $candidate['source'])) { continue; }
                $emailCandidates[(string) $candidate['id']] = $candidate;
            }
            $requestedEmailCandidates = array_values(array_unique(array_map(
                'strval',
                (array) ($_POST['email_normalization_candidates'] ?? [])
            )));
            $emailRules = [];
            foreach ($requestedEmailCandidates as $candidateId) {
                if (!isset($emailCandidates[$candidateId])) { continue; }
                $candidate = $emailCandidates[$candidateId];
                $emailRules[(string) $candidate['table']][] = (string) $candidate['source'];
            }
            $state['email_normalization_rules'] = $emailRules;
        } else {
            $state['url_normalization_tables'] = null;
            $state['email_normalization_rules'] = [];
        }
        $state['status'] = 'merging';
        $state['progress'] = 5;
        $state['message'] = '統合SQLの出力を準備しています';
        unset($state['report_summary']);
        $this->workspaces->saveState($id, $state);

        if (function_exists('fastcgi_finish_request')) {
            header('Location: ?action=progress&mode=merge', true, 303);
            session_write_close();
            fastcgi_finish_request();
            $this->executeMerge($id);
            exit;
        }

        $this->executeMerge($id);
        $this->redirect('?action=result');
    }

    private function executeMerge(string $id): void
    {
        $state = $this->workspaces->state($id);
        $baseSql = $this->workspaces->path($id, 'source_' . $state['base_side'] . '.sql');
        try {
            $report = (new MergeEngine())->merge(
                $baseSql,
                $this->workspaces->path($id, 'merged.sql'),
                new DumpStore($this->workspaces->path($id, 'base.sqlite')),
                new DumpStore($this->workspaces->path($id, 'incoming.sqlite')),
                new ComparisonStore($this->workspaces->path($id, 'comparison.sqlite')),
                $this->workspaces->path($id, 'merge-report.json'),
                function (int $progress, string $message) use (&$state, $id): void {
                    $state['progress'] = $progress;
                    $state['message'] = $message;
                    $this->workspaces->saveState($id, $state);
                },
                $this->workspaces->path($id, 'merge-delta.sql'),
                is_array($state['url_normalization_tables'] ?? null) ? $state['url_normalization_tables'] : null,
                is_array($state['email_normalization_rules'] ?? null) ? $state['email_normalization_rules'] : [],
            );
            $state['status'] = 'merged';
            $state['progress'] = 100;
            $state['message'] = '統合SQLを作成しました';
            $state['report_summary'] = $report;
            $this->workspaces->saveState($id, $state);
        } catch (Throwable $e) {
            $state['status'] = 'failed';
            $state['message'] = $e->getMessage();
            $this->workspaces->saveState($id, $state);
        }
    }

    private function result(): void
    {
        $id = $this->workspaceId();
        $state = $this->workspaces->state($id);
        if (($state['status'] ?? '') !== 'merged') { $this->redirect('?action=compare'); }
        $this->view->render('result', ['title' => '統合SQLを作成しました', 'csrf' => Csrf::token(), 'state' => $state]);
    }

    private function download(): void
    {
        $id = $this->workspaceId();
        $type = (string) ($_GET['type'] ?? 'sql');
        $downloads = [
            'sql' => ['merged.sql', 'application/sql', '.sql'],
            'delta' => ['merge-delta.sql', 'application/sql', '-delta.sql'],
            'report' => ['merge-report.json', 'application/json', '.json'],
        ];
        if (!isset($downloads[$type])) { throw new RuntimeException('ダウンロード種別が正しくありません。'); }
        [$file, $contentType, $suffix] = $downloads[$type];
        $path = $this->workspaces->path($id, $file);
        if (!is_file($path)) { throw new RuntimeException('ダウンロードファイルがありません。'); }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="wp-db-safe-merge-' . gmdate('Ymd-His') . $suffix . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    private function delete(): void
    {
        $this->postOnly();
        $this->csrf();
        $id = $this->workspaceId();
        $this->workspaces->delete($id);
        unset($_SESSION['workspace']);
        $this->view->render('deleted', ['title' => '作業データを削除しました']);
    }

    private function workspaceId(): string
    {
        $id = (string) ($_SESSION['workspace'] ?? '');
        if ($id === '') { throw new RuntimeException('作業セッションがありません。最初からやり直してください。'); }
        return $id;
    }

    private function csrf(): void
    {
        if (!Csrf::verify($_POST['_token'] ?? null)) { throw new RuntimeException('画面の有効期限が切れました。'); }
    }

    private function comparisonFilter(string $filter): string
    {
        return in_array($filter, ['all', 'matched', 'candidate', 'conflict', 'additional', 'base_only'], true) ? $filter : 'all';
    }

    private function comparisonPerPage(int $perPage): int
    {
        return in_array($perPage, [20, 50, 100, 200], true) ? $perPage : 20;
    }

    private function postOnly(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new RuntimeException('許可されていない操作です。'); }
    }

    private function redirect(string $location): never
    {
        header('Location: ' . $location, true, 303);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->view->render('error', ['title' => 'ページが見つかりません', 'message' => 'URLを確認してください。']);
    }
}
