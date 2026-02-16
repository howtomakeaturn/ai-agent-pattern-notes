<?php

require_once __DIR__ . '/database.php';

/**
 * Pattern 12: Rule-Based Agent - Demo & Testing Tool
 *
 * 互動式測試工具，可以：
 * - 執行 Agent（一次或多次）
 * - 人工審核
 * - 查看文章狀態
 * - 查看執行日誌
 */

function showMenu() {
    echo PHP_EOL;
    echo "╔════════════════════════════════════════════════╗" . PHP_EOL;
    echo "║  Pattern 12: Rule-Based Agent - Demo Tool    ║" . PHP_EOL;
    echo "╚════════════════════════════════════════════════╝" . PHP_EOL;
    echo PHP_EOL;
    echo "1. 執行 Agent（一次）" . PHP_EOL;
    echo "2. 執行 Agent（多次）" . PHP_EOL;
    echo "3. 人工審核文章" . PHP_EOL;
    echo "4. 顯示所有文章" . PHP_EOL;
    echo "5. 顯示執行日誌" . PHP_EOL;
    echo "6. 重置資料庫" . PHP_EOL;
    echo "7. 與 Pattern 11 比較" . PHP_EOL;
    echo "0. 離開" . PHP_EOL;
    echo PHP_EOL;
}

function executeAgent() {
    echo PHP_EOL . "執行 Agent..." . PHP_EOL;
    echo str_repeat("=", 50) . PHP_EOL . PHP_EOL;

    passthru('php ' . __DIR__ . '/agent.php');

    echo PHP_EOL . str_repeat("=", 50) . PHP_EOL;
}

function executeAgentMultipleTimes() {
    echo PHP_EOL . "要執行幾次？" . PHP_EOL;
    $times = (int) trim(fgets(STDIN));

    if ($times < 1 || $times > 20) {
        echo "請輸入 1-20 之間的數字" . PHP_EOL;
        return;
    }

    echo PHP_EOL . "將執行 Agent {$times} 次..." . PHP_EOL . PHP_EOL;

    for ($i = 1; $i <= $times; $i++) {
        echo "【第 {$i}/{$times} 次執行】" . PHP_EOL;
        echo str_repeat("-", 50) . PHP_EOL;
        passthru('php ' . __DIR__ . '/agent.php');
        echo PHP_EOL;

        if ($i < $times) {
            sleep(1);  // 稍微延遲避免 API rate limit
        }
    }

    echo PHP_EOL . "✓ 完成 {$times} 次執行" . PHP_EOL;
}

function humanReview() {
    echo PHP_EOL . "啟動人工審核介面..." . PHP_EOL . PHP_EOL;
    passthru('php ' . __DIR__ . '/human-review.php');
}

function showAllArticles() {
    $db = initDatabase();

    $stmt = $db->query("
        SELECT id, title, status, quality_score, revision_count,
               created_at, updated_at
        FROM articles
        ORDER BY id DESC
    ");

    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo PHP_EOL . "【所有文章】" . PHP_EOL . PHP_EOL;

    if (empty($articles)) {
        echo "  (沒有任何文章)" . PHP_EOL;
        return;
    }

    foreach ($articles as $article) {
        $statusEmoji = [
            'pending_research' => '🔍',
            'pending_write' => '✍️',
            'pending_review' => '👀',
            'approved' => '✅',
            'rejected' => '❌',
            'published' => '📰'
        ];

        $emoji = $statusEmoji[$article['status']] ?? '•';

        echo "{$emoji} ID {$article['id']}: ";
        echo $article['title'] ?: '(未命名)';
        echo " [{$article['status']}]" . PHP_EOL;

        if ($article['quality_score']) {
            echo "   品質: {$article['quality_score']}/10";
        }
        if ($article['revision_count'] > 0) {
            echo "   修訂: {$article['revision_count']} 次";
        }
        echo PHP_EOL;
        echo "   建立: {$article['created_at']} | 更新: {$article['updated_at']}" . PHP_EOL;
        echo PHP_EOL;
    }
}

function showExecutionLogs() {
    $db = initDatabase();

    $stmt = $db->query("
        SELECT * FROM execution_logs
        ORDER BY id DESC
        LIMIT 20
    ");

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo PHP_EOL . "【執行日誌】（最近 20 筆）" . PHP_EOL . PHP_EOL;

    if (empty($logs)) {
        echo "  (沒有執行日誌)" . PHP_EOL;
        return;
    }

    foreach ($logs as $log) {
        echo "[{$log['executed_at']}] ";
        echo "{$log['process_type']} → {$log['action']}" . PHP_EOL;

        if ($log['article_id']) {
            echo "  文章 ID: {$log['article_id']}" . PHP_EOL;
        }

        if ($log['details']) {
            $details = substr($log['details'], 0, 100);
            if (strlen($log['details']) > 100) {
                $details .= '...';
            }
            echo "  {$details}" . PHP_EOL;
        }

        echo PHP_EOL;
    }
}

function resetDatabaseInteractive() {
    echo PHP_EOL . "⚠️  確定要重置資料庫嗎？所有資料將被清除！" . PHP_EOL;
    echo "輸入 'yes' 確認: ";

    $confirm = trim(fgets(STDIN));

    if ($confirm !== 'yes') {
        echo "取消重置" . PHP_EOL;
        return;
    }

    $db = initDatabase();

    // 清空所有表格
    $db->exec("DELETE FROM articles");
    $db->exec("DELETE FROM execution_logs");
    $db->exec("DELETE FROM agent_decisions");

    // 重置自動遞增
    $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('articles', 'execution_logs', 'agent_decisions')");

    echo "✓ 資料庫已重置" . PHP_EOL;
}

function compareWithPattern11() {
    echo PHP_EOL;
    echo "╔════════════════════════════════════════════════════════════╗" . PHP_EOL;
    echo "║          Pattern 11 vs Pattern 12 比較                    ║" . PHP_EOL;
    echo "╚════════════════════════════════════════════════════════════╝" . PHP_EOL;
    echo PHP_EOL;

    $comparison = [
        ['項目', 'Pattern 11 (LLM 決策)', 'Pattern 12 (固定規則)'],
        ['───────', '──────────────────', '─────────────────'],
        ['決策方式', 'LLM 分析狀態並決定', '固定優先級規則'],
        ['API 調用', '2+ 次/執行 (決策+動作)', '1 次/執行 (僅動作)'],
        ['成本估算', '高 (~$0.002/次)', '低 (~$0.001/次，省 50%)'],
        ['執行速度', '較慢 (2-3 秒)', '較快 (1-2 秒)'],
        ['可預測性', '低 (AI 可能有變化)', '高 (完全確定)'],
        ['靈活性', '高 (可動態調整優先級)', '低 (固定規則)'],
        ['決策記錄', 'agent_decisions 表', '執行日誌 (可選)'],
        ['適合場景', '複雜工作流、需要判斷', '簡單固定流程'],
        ['錯誤風險', '可能做出意外決策', '按固定邏輯執行'],
        ['擴展性', '易於加入新動作/邏輯', '需修改 if-else'],
    ];

    foreach ($comparison as $row) {
        printf("%-12s %-25s %-25s\n", $row[0], $row[1], $row[2]);
    }

    echo PHP_EOL;
    echo "建議：" . PHP_EOL;
    echo "• 如果工作流程固定且優先級明確 → Pattern 12" . PHP_EOL;
    echo "• 如果需要 AI 判斷和動態調整 → Pattern 11" . PHP_EOL;
    echo "• 成本敏感的生產環境 → Pattern 12" . PHP_EOL;
    echo "• 實驗性/研究性專案 → Pattern 11" . PHP_EOL;
    echo PHP_EOL;
}

// ==================== Main Loop ====================

while (true) {
    showMenu();
    echo "請選擇: ";

    $choice = trim(fgets(STDIN));

    switch ($choice) {
        case '1':
            executeAgent();
            break;

        case '2':
            executeAgentMultipleTimes();
            break;

        case '3':
            humanReview();
            break;

        case '4':
            showAllArticles();
            break;

        case '5':
            showExecutionLogs();
            break;

        case '6':
            resetDatabaseInteractive();
            break;

        case '7':
            compareWithPattern11();
            break;

        case '0':
            echo PHP_EOL . "再見！👋" . PHP_EOL . PHP_EOL;
            exit(0);

        default:
            echo "無效的選項，請重新選擇" . PHP_EOL;
    }
}
