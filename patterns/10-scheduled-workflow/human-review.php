<?php

require_once __DIR__ . '/database.php';

/**
 * Human Review Interface - 人工審核介面
 *
 * 這是一個 CLI 工具，用於人工審核 AI 撰寫的文章
 *
 * 使用方式：php human-review.php
 *
 * 功能：
 * - 列出所有待審核的文章
 * - 查看文章完整內容
 * - 批准或拒絕文章
 * - 記錄審核時間
 */

echo "=== 文章審核系統 ===" . PHP_EOL;
echo "時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    $db = initDatabase();

    while (true) {
        echo "----------------------------------------" . PHP_EOL;
        echo "指令：" . PHP_EOL;
        echo "  list          - 列出待審核文章" . PHP_EOL;
        echo "  view [id]     - 查看文章完整內容" . PHP_EOL;
        echo "  approve [id]  - 批准文章" . PHP_EOL;
        echo "  reject [id]   - 拒絕文章" . PHP_EOL;
        echo "  exit          - 離開系統" . PHP_EOL;
        echo "----------------------------------------" . PHP_EOL;
        echo PHP_EOL;

        echo "請輸入指令: ";
        $input = trim(fgets(STDIN));

        if (empty($input)) {
            continue;
        }

        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $argument = $parts[1] ?? null;

        echo PHP_EOL;

        switch ($command) {
            case 'list':
                listPendingArticles($db);
                break;

            case 'view':
                if (!$argument) {
                    echo "✗ 請提供文章 ID：view [id]" . PHP_EOL;
                } else {
                    viewArticle($db, (int)$argument);
                }
                break;

            case 'approve':
                if (!$argument) {
                    echo "✗ 請提供文章 ID：approve [id]" . PHP_EOL;
                } else {
                    approveArticle($db, (int)$argument);
                }
                break;

            case 'reject':
                if (!$argument) {
                    echo "✗ 請提供文章 ID：reject [id]" . PHP_EOL;
                } else {
                    rejectArticle($db, (int)$argument);
                }
                break;

            case 'exit':
            case 'quit':
                echo "再見！" . PHP_EOL;
                exit(0);

            default:
                echo "✗ 未知指令: {$command}" . PHP_EOL;
        }

        echo PHP_EOL;
    }

} catch (Exception $e) {
    echo "✗ 錯誤: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

/**
 * 列出待審核文章
 */
function listPendingArticles(PDO $db): void {
    $articles = getArticlesByStatus($db, 'pending_review');

    if (empty($articles)) {
        echo "目前沒有待審核的文章" . PHP_EOL;
        return;
    }

    echo "=== 待審核文章 ({" . count($articles) . "}) ===" . PHP_EOL . PHP_EOL;

    foreach ($articles as $article) {
        $keywords = json_decode($article['keywords'], true);
        $firstTopic = $keywords['topics'][0] ?? ['keyword' => 'N/A'];

        // 截取內容預覽
        $contentPreview = mb_substr(strip_tags($article['content'] ?? ''), 0, 150);
        if (mb_strlen($article['content'] ?? '') > 150) {
            $contentPreview .= '...';
        }

        echo "ID: {$article['id']}" . PHP_EOL;
        echo "標題: {$article['title']}" . PHP_EOL;
        echo "關鍵字: {$firstTopic['keyword']}" . PHP_EOL;
        echo "建立時間: {$article['created_at']}" . PHP_EOL;
        echo "內容預覽: {$contentPreview}" . PHP_EOL;
        echo PHP_EOL;
    }
}

/**
 * 查看文章完整內容
 */
function viewArticle(PDO $db, int $id): void {
    $article = getArticleById($db, $id);

    if (!$article) {
        echo "✗ 找不到文章 ID: {$id}" . PHP_EOL;
        return;
    }

    if ($article['status'] !== 'pending_review') {
        echo "⚠ 此文章狀態為: {$article['status']}（非待審核）" . PHP_EOL . PHP_EOL;
    }

    $keywords = json_decode($article['keywords'], true);

    echo "==================================" . PHP_EOL;
    echo "文章 ID: {$article['id']}" . PHP_EOL;
    echo "狀態: {$article['status']}" . PHP_EOL;
    echo "建立時間: {$article['created_at']}" . PHP_EOL;
    echo "==================================" . PHP_EOL . PHP_EOL;

    echo "【標題】" . PHP_EOL;
    echo $article['title'] . PHP_EOL . PHP_EOL;

    echo "【關鍵字資料】" . PHP_EOL;
    if ($keywords && isset($keywords['topics'])) {
        foreach ($keywords['topics'] as $i => $topic) {
            echo ($i + 1) . ". {$topic['keyword']} - {$topic['title_suggestion']}" . PHP_EOL;
        }
    }
    echo PHP_EOL;

    echo "【文章內容】" . PHP_EOL;
    echo $article['content'] . PHP_EOL;
    echo PHP_EOL;

    echo "==================================" . PHP_EOL;
    echo "字數: " . mb_strlen(strip_tags($article['content'])) . " 字元" . PHP_EOL;
    echo "==================================" . PHP_EOL;
}

/**
 * 批准文章
 */
function approveArticle(PDO $db, int $id): void {
    $article = getArticleById($db, $id);

    if (!$article) {
        echo "✗ 找不到文章 ID: {$id}" . PHP_EOL;
        return;
    }

    if ($article['status'] !== 'pending_review') {
        echo "✗ 此文章狀態為 {$article['status']}，無法批准" . PHP_EOL;
        return;
    }

    // 二次確認
    echo "確定要批准這篇文章嗎？" . PHP_EOL;
    echo "標題: {$article['title']}" . PHP_EOL;
    echo "輸入 'yes' 確認: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'yes') {
        echo "已取消" . PHP_EOL;
        return;
    }

    // 更新狀態
    updateArticleStatus($db, $id, 'approved', 'reviewed_at');
    logExecution($db, 'human-review', $id, 'approve', "文章已批准: {$article['title']}");

    echo PHP_EOL;
    echo "✓ 文章已批准" . PHP_EOL;
    echo "✓ 文章將在下次 midnight cron 執行時發布並分析績效" . PHP_EOL;
}

/**
 * 拒絕文章
 */
function rejectArticle(PDO $db, int $id): void {
    $article = getArticleById($db, $id);

    if (!$article) {
        echo "✗ 找不到文章 ID: {$id}" . PHP_EOL;
        return;
    }

    if ($article['status'] !== 'pending_review') {
        echo "✗ 此文章狀態為 {$article['status']}，無法拒絕" . PHP_EOL;
        return;
    }

    // 詢問拒絕原因
    echo "請輸入拒絕原因（選填，按 Enter 跳過）: ";
    $reason = trim(fgets(STDIN));

    // 二次確認
    echo "確定要拒絕這篇文章嗎？" . PHP_EOL;
    echo "標題: {$article['title']}" . PHP_EOL;
    echo "輸入 'yes' 確認: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'yes') {
        echo "已取消" . PHP_EOL;
        return;
    }

    // 記錄拒絕原因
    if ($reason) {
        $performanceData = ['rejection_reason' => $reason, 'rejected_at' => date('Y-m-d H:i:s')];
        updateArticle($db, $id, [
            'performance_data' => json_encode($performanceData, JSON_UNESCAPED_UNICODE)
        ]);
    }

    // 更新狀態
    updateArticleStatus($db, $id, 'rejected', 'reviewed_at');
    logExecution($db, 'human-review', $id, 'reject', "文章已拒絕" . ($reason ? ": {$reason}" : ''));

    echo PHP_EOL;
    echo "✓ 文章已拒絕" . PHP_EOL;
    echo "✓ 此文章不會被發布" . PHP_EOL;
}
