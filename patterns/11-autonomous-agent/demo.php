<?php

require_once __DIR__ . '/database.php';

/**
 * Demo - Pattern 11 測試工具
 *
 * 展示自主 AI Agent 的運作
 */

echo "╔════════════════════════════════════════════╗" . PHP_EOL;
echo "║  Pattern 11: Autonomous Agent Demo       ║" . PHP_EOL;
echo "╚════════════════════════════════════════════╝" . PHP_EOL . PHP_EOL;

try {
    $db = initDatabase();

    while (true) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        echo "選單：" . PHP_EOL;
        echo "  1. 執行 Agent (讓 AI 自主決策)" . PHP_EOL;
        echo "  2. 多次執行 Agent (模擬連續運作)" . PHP_EOL;
        echo "  3. 進入 Human Review 模式" . PHP_EOL;
        echo "  4. 顯示所有文章狀態" . PHP_EOL;
        echo "  5. 顯示 Agent 決策歷史" . PHP_EOL;
        echo "  6. 顯示執行日誌" . PHP_EOL;
        echo "  7. 重置資料庫" . PHP_EOL;
        echo "  0. 離開" . PHP_EOL;
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        echo PHP_EOL;

        echo "請選擇 (0-7): ";
        $choice = trim(fgets(STDIN));

        echo PHP_EOL;

        switch ($choice) {
            case '1':
                executeAgent();
                break;

            case '2':
                executeAgentMultipleTimes();
                break;

            case '3':
                executeHumanReview();
                break;

            case '4':
                showAllArticles($db);
                break;

            case '5':
                showAgentDecisions($db);
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

function executeAgent(): void {
    echo "▶ 執行 Agent..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/agent.php');
}

function executeAgentMultipleTimes(): void {
    echo "要執行幾次？(建議 3-5 次): ";
    $times = (int)trim(fgets(STDIN));

    if ($times < 1 || $times > 10) {
        echo "✗ 請輸入 1-10 之間的數字" . PHP_EOL;
        return;
    }

    echo PHP_EOL;

    for ($i = 1; $i <= $times; $i++) {
        echo "╔═══════════════════════════════════════════╗" . PHP_EOL;
        echo "║  第 {$i} 次執行                            ║" . PHP_EOL;
        echo "╚═══════════════════════════════════════════╝" . PHP_EOL . PHP_EOL;

        passthru('php ' . __DIR__ . '/agent.php');

        if ($i < $times) {
            echo PHP_EOL . "等待 2 秒..." . PHP_EOL . PHP_EOL;
            sleep(2);
        }
    }
}

function executeHumanReview(): void {
    echo "▶ 進入 Human Review 模式..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/human-review.php');
}

function showAllArticles(PDO $db): void {
    $articles = getAllArticles($db);

    if (empty($articles)) {
        echo "資料庫中沒有文章" . PHP_EOL;
        echo "提示：執行選項 1 讓 Agent 開始工作" . PHP_EOL;
        return;
    }

    echo "=== 所有文章 (" . count($articles) . ") ===" . PHP_EOL . PHP_EOL;

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

        if ($article['quality_score']) {
            echo "AI 評分: {$article['quality_score']}/10" . PHP_EOL;
        }

        if ($article['revision_count'] > 0) {
            echo "修訂次數: {$article['revision_count']}" . PHP_EOL;
        }

        echo "建立: {$article['created_at']}" . PHP_EOL;

        if ($article['reviewed_at']) {
            echo "審核: {$article['reviewed_at']}" . PHP_EOL;
        }

        if ($article['published_at']) {
            echo "發布: {$article['published_at']}" . PHP_EOL;
        }

        if ($article['performance_data']) {
            $perfData = json_decode($article['performance_data'], true);
            if (isset($perfData['metrics'])) {
                echo "瀏覽: {$perfData['metrics']['views']}, 點擊: {$perfData['metrics']['clicks']}" . PHP_EOL;
            }
            if (isset($perfData['ai_analysis']['performance_rating'])) {
                echo "績效: {$perfData['ai_analysis']['performance_rating']}" . PHP_EOL;
            }
        }

        echo PHP_EOL;
    }
}

function showAgentDecisions(PDO $db): void {
    $stmt = $db->query("SELECT * FROM agent_decisions ORDER BY created_at DESC LIMIT 10");
    $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($decisions)) {
        echo "沒有 Agent 決策記錄" . PHP_EOL;
        return;
    }

    echo "=== Agent 決策歷史 (" . count($decisions) . ") ===" . PHP_EOL . PHP_EOL;

    foreach ($decisions as $decision) {
        $systemState = json_decode($decision['system_state'], true);
        $availableActions = json_decode($decision['available_actions'], true);

        echo "[{$decision['created_at']}]" . PHP_EOL;
        echo "系統狀態: ";
        if (isset($systemState['counts'])) {
            $summary = [];
            foreach ($systemState['counts'] as $status => $count) {
                if ($count > 0) {
                    $summary[] = "{$status}={$count}";
                }
            }
            echo implode(', ', $summary);
        }
        echo PHP_EOL;

        echo "可用動作: " . implode(', ', $availableActions) . PHP_EOL;
        echo "選擇: {$decision['chosen_action']}" . PHP_EOL;
        echo "理由: {$decision['reasoning']}" . PHP_EOL;
        echo PHP_EOL;
    }
}

function showExecutionLogs(PDO $db): void {
    $logs = getRecentLogs($db, 15);

    if (empty($logs)) {
        echo "沒有執行日誌" . PHP_EOL;
        return;
    }

    echo "=== 最近執行日誌 (" . count($logs) . ") ===" . PHP_EOL . PHP_EOL;

    foreach ($logs as $log) {
        $articleInfo = $log['article_id'] ? "Article#{$log['article_id']}" : "N/A";
        echo "[{$log['executed_at']}] {$log['script_name']}" . PHP_EOL;
        echo "  ├─ 文章: {$articleInfo}" . PHP_EOL;
        echo "  ├─ 動作: {$log['action']}" . PHP_EOL;

        if ($log['decision_reason']) {
            echo "  ├─ 決策: {$log['decision_reason']}" . PHP_EOL;
        }

        echo "  └─ 結果: {$log['result']}" . PHP_EOL;
        echo PHP_EOL;
    }
}

function resetDatabasePrompt(PDO $db): void {
    echo "⚠️  警告：這將刪除所有文章、日誌和決策記錄！" . PHP_EOL;
    echo "輸入 'RESET' 確認重置資料庫: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'RESET') {
        echo "已取消" . PHP_EOL;
        return;
    }

    resetDatabase($db);

    echo PHP_EOL;
    echo "✓ 資料庫已重置" . PHP_EOL;
    echo "提示：執行選項 1 讓 Agent 開始新的工作循環" . PHP_EOL;
}

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
