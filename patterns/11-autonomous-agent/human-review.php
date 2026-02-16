<?php

require_once __DIR__ . '/database.php';

/**
 * Human Review Interface - 人工審核介面
 *
 * Pattern 11 的人工審核：AI 會先自我評估品質，通過後才會進入 pending_review
 * 人類的角色是做最終把關
 */

echo "=== 文章審核系統 (Pattern 11: Autonomous Agent) ===" . PHP_EOL;
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

function listPendingArticles(PDO $db): void {
    $articles = getArticlesByStatus($db, 'pending_review');

    if (empty($articles)) {
        echo "目前沒有待審核的文章" . PHP_EOL;
        echo "提示：執行 php agent.php 讓 AI 自主工作" . PHP_EOL;
        return;
    }

    echo "=== 待審核文章 (" . count($articles) . ") ===" . PHP_EOL . PHP_EOL;

    foreach ($articles as $article) {
        $keywords = json_decode($article['keywords'], true);
        $firstTopic = $keywords['topics'][0] ?? ['keyword' => 'N/A'];

        $contentPreview = mb_substr(strip_tags($article['content'] ?? ''), 0, 150);
        if (mb_strlen($article['content'] ?? '') > 150) {
            $contentPreview .= '...';
        }

        echo "ID: {$article['id']}" . PHP_EOL;
        echo "標題: {$article['title']}" . PHP_EOL;
        echo "關鍵字: {$firstTopic['keyword']}" . PHP_EOL;

        if ($article['quality_score']) {
            echo "AI 品質評分: {$article['quality_score']}/10 ⭐" . PHP_EOL;
        }

        if ($article['revision_count'] > 0) {
            echo "修訂次數: {$article['revision_count']}" . PHP_EOL;
        }

        echo "建立時間: {$article['created_at']}" . PHP_EOL;
        echo "內容預覽: {$contentPreview}" . PHP_EOL;
        echo PHP_EOL;
    }
}

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
    if ($article['quality_score']) {
        echo "AI 品質評分: {$article['quality_score']}/10" . PHP_EOL;
    }
    if ($article['revision_count'] > 0) {
        echo "修訂次數: {$article['revision_count']}" . PHP_EOL;
    }
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

    echo "確定要批准這篇文章嗎？" . PHP_EOL;
    echo "標題: {$article['title']}" . PHP_EOL;
    if ($article['quality_score']) {
        echo "AI 評分: {$article['quality_score']}/10" . PHP_EOL;
    }
    echo "輸入 'yes' 確認: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'yes') {
        echo "已取消" . PHP_EOL;
        return;
    }

    updateArticleStatus($db, $id, 'approved', 'reviewed_at');
    logExecution($db, 'human-review', $id, 'approve', "文章已批准: {$article['title']}");

    echo PHP_EOL;
    echo "✓ 文章已批准" . PHP_EOL;
    echo "✓ Agent 下次執行時會自動發布並分析績效" . PHP_EOL;
}

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

    echo "請輸入拒絕原因（選填，按 Enter 跳過）: ";
    $reason = trim(fgets(STDIN));

    echo "確定要拒絕這篇文章嗎？" . PHP_EOL;
    echo "標題: {$article['title']}" . PHP_EOL;
    echo "輸入 'yes' 確認: ";

    $confirmation = trim(fgets(STDIN));

    if ($confirmation !== 'yes') {
        echo "已取消" . PHP_EOL;
        return;
    }

    if ($reason) {
        $performanceData = ['rejection_reason' => $reason, 'rejected_at' => date('Y-m-d H:i:s')];
        updateArticle($db, $id, [
            'performance_data' => json_encode($performanceData, JSON_UNESCAPED_UNICODE)
        ]);
    }

    updateArticleStatus($db, $id, 'rejected', 'reviewed_at');
    logExecution($db, 'human-review', $id, 'reject', "文章已拒絕" . ($reason ? ": {$reason}" : ''));

    echo PHP_EOL;
    echo "✓ 文章已拒絕" . PHP_EOL;
    echo "✓ 此文章不會被發布" . PHP_EOL;
}
