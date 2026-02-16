<?php

require_once __DIR__ . '/database.php';

/**
 * Demo - 互動測試工具
 *
 * 用於開發和測試 Scheduled Workflow Pattern
 *
 * 功能：
 * - 手動觸發各個 cron 階段
 * - 查看所有文章狀態
 * - 進入審核模式
 * - 重置資料庫
 */

echo "╔════════════════════════════════════════╗" . PHP_EOL;
echo "║  Pattern 10: Scheduled Workflow Demo  ║" . PHP_EOL;
echo "╚════════════════════════════════════════╝" . PHP_EOL . PHP_EOL;

try {
    $db = initDatabase();

    while (true) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        echo "選單：" . PHP_EOL;
        echo "  1. 執行 Morning Cron (研究關鍵字)" . PHP_EOL;
        echo "  2. 執行 Afternoon Cron (撰寫文章)" . PHP_EOL;
        echo "  3. 進入 Human Review 模式 (審核文章)" . PHP_EOL;
        echo "  4. 執行 Midnight Cron (分析績效)" . PHP_EOL;
        echo "  5. 顯示所有文章狀態" . PHP_EOL;
        echo "  6. 顯示執行日誌" . PHP_EOL;
        echo "  7. 重置資料庫" . PHP_EOL;
        echo "  0. 離開" . PHP_EOL;
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        echo PHP_EOL;

        echo "請選擇 (0-7): ";
        $choice = trim(fgets(STDIN));

        echo PHP_EOL;

        switch ($choice) {
            case '1':
                executeMorningCron();
                break;

            case '2':
                executeAfternoonCron();
                break;

            case '3':
                executeHumanReview();
                break;

            case '4':
                executeMidnightCron();
                break;

            case '5':
                showAllArticles($db);
                break;

            case '6':
                showExecutionLogs($db);
                break;

            case '7':
                resetDatabasePrompt($db);
                break;

            case '0':
                echo "再見！" . PHP_EOL;
                exit(0);

            default:
                echo "✗ 無效的選擇" . PHP_EOL;
        }

        echo PHP_EOL;
        echo "按 Enter 繼續...";
        fgets(STDIN);
        echo PHP_EOL . PHP_EOL;
    }

} catch (Exception $e) {
    echo "✗ 錯誤: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

/**
 * 執行 Morning Cron
 */
function executeMorningCron(): void {
    echo "▶ 執行 Morning Cron..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/cron-morning.php');
}

/**
 * 執行 Afternoon Cron
 */
function executeAfternoonCron(): void {
    echo "▶ 執行 Afternoon Cron..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/cron-afternoon.php');
}

/**
 * 執行 Human Review
 */
function executeHumanReview(): void {
    echo "▶ 進入 Human Review 模式..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/human-review.php');
}

/**
 * 執行 Midnight Cron
 */
function executeMidnightCron(): void {
    echo "▶ 執行 Midnight Cron..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/cron-midnight.php');
}

/**
 * 顯示所有文章狀態
 */
function showAllArticles(PDO $db): void {
    $articles = getAllArticles($db);

    if (empty($articles)) {
        echo "資料庫中沒有文章" . PHP_EOL;
        return;
    }

    echo "=== 所有文章 ({" . count($articles) . "}) ===" . PHP_EOL . PHP_EOL;

    foreach ($articles as $article) {
        $statusEmoji = getStatusEmoji($article['status']);

        echo "ID: {$article['id']} {$statusEmoji}" . PHP_EOL;
        echo "狀態: {$article['status']}" . PHP_EOL;

        if ($article['title']) {
            echo "標題: {$article['title']}" . PHP_EOL;
        }

        if ($article['keywords']) {
            $keywords = json_decode($article['keywords'], true);
            if ($keywords && isset($keywords['topics'][0])) {
                echo "關鍵字: {$keywords['topics'][0]['keyword']}" . PHP_EOL;
            }
        }

        echo "建立: {$article['created_at']}" . PHP_EOL;

        if ($article['reviewed_at']) {
            echo "審核: {$article['reviewed_at']}" . PHP_EOL;
        }

        if ($article['published_at']) {
            echo "發布: {$article['published_at']}" . PHP_EOL;
        }

        // 顯示績效資料
        if ($article['performance_data']) {
            $perfData = json_decode($article['performance_data'], true);
            if (isset($perfData['metrics'])) {
                echo "瀏覽數: {$perfData['metrics']['views']}, ";
                echo "點擊數: {$perfData['metrics']['clicks']}" . PHP_EOL;
            }
            if (isset($perfData['ai_analysis']['performance_rating'])) {
                echo "AI 評估: {$perfData['ai_analysis']['performance_rating']}" . PHP_EOL;
            }
        }

        echo PHP_EOL;
    }
}

/**
 * 顯示執行日誌
 */
function showExecutionLogs(PDO $db): void {
    $logs = getRecentLogs($db, 15);

    if (empty($logs)) {
        echo "沒有執行日誌" . PHP_EOL;
        return;
    }

    echo "=== 最近執行日誌 ({" . count($logs) . "}) ===" . PHP_EOL . PHP_EOL;

    foreach ($logs as $log) {
        $articleInfo = $log['article_id'] ? "Article#{$log['article_id']}" : "N/A";
        echo "[{$log['executed_at']}] {$log['script_name']}" . PHP_EOL;
        echo "  ├─ 文章: {$articleInfo}" . PHP_EOL;
        echo "  ├─ 動作: {$log['action']}" . PHP_EOL;
        echo "  └─ 結果: {$log['result']}" . PHP_EOL;
        echo PHP_EOL;
    }
}

/**
 * 重置資料庫
 */
function resetDatabasePrompt(PDO $db): void {
    echo "⚠️  警告：這將刪除所有文章和日誌！" . PHP_EOL;
    echo "輸入 'RESET' 確認重置資料庫: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'RESET') {
        echo "已取消" . PHP_EOL;
        return;
    }

    resetDatabase($db);

    echo PHP_EOL;
    echo "✓ 資料庫已重置" . PHP_EOL;
}

/**
 * 取得狀態對應的 emoji
 */
function getStatusEmoji(string $status): string {
    $emojiMap = [
        'pending_research' => '🔍',
        'pending_write' => '✍️',
        'pending_review' => '👀',
        'approved' => '✅',
        'rejected' => '❌',
        'published' => '🚀',
    ];

    return $emojiMap[$status] ?? '❓';
}
