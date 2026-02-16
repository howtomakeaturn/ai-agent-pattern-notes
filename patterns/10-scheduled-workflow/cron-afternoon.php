<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';

// 載入環境變數
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Afternoon Cron Job - 撰寫文章
 *
 * 執行時間：建議每天下午 2:00
 * Cron 設定：0 14 * * * cd /path/to/project && php patterns/10-scheduled-workflow/cron-afternoon.php
 *
 * 流程：
 * 1. 查詢 pending_write 狀態的文章
 * 2. 讀取關鍵字資料
 * 3. 呼叫 OpenAI API 撰寫部落格文章
 * 4. 儲存標題和內容
 * 5. 更新文章狀態為 pending_review
 */

echo "=== Afternoon Cron: 撰寫文章 ===" . PHP_EOL;
echo "執行時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    // 初始化資料庫
    $db = initDatabase();

    // 查詢待撰寫的文章
    $articles = getArticlesByStatus($db, 'pending_write');

    if (empty($articles)) {
        echo "沒有待撰寫的文章" . PHP_EOL;
        logExecution($db, 'cron-afternoon', null, 'check_articles', '沒有待撰寫的文章');
        exit(0);
    }

    $article = $articles[0];
    $articleId = $article['id'];

    echo "找到待撰寫文章 ID: {$articleId}" . PHP_EOL;

    // 解析關鍵字資料
    $keywordsData = json_decode($article['keywords'], true);

    if (!$keywordsData || empty($keywordsData['topics'])) {
        echo "✗ 文章沒有關鍵字資料，無法撰寫" . PHP_EOL;
        logExecution($db, 'cron-afternoon', $articleId, 'error', '缺少關鍵字資料');
        exit(1);
    }

    // 選擇第一個主題來撰寫（實際可以讓 AI 自己選）
    $selectedTopic = $keywordsData['topics'][0];

    echo "選定主題: {$selectedTopic['keyword']}" . PHP_EOL;
    echo "建議標題: {$selectedTopic['title_suggestion']}" . PHP_EOL;
    echo PHP_EOL . "正在撰寫文章..." . PHP_EOL;

    // 初始化 OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // 呼叫 OpenAI API 撰寫文章
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一位專業的技術部落格作家。你的文章風格清晰、實用、具有深度，能夠為讀者提供真正的價值。'
            ],
            [
                'role' => 'user',
                'content' => "請撰寫一篇技術部落格文章：

關鍵字：{$selectedTopic['keyword']}
建議標題：{$selectedTopic['title_suggestion']}
搜尋意圖：{$selectedTopic['search_intent']}
選擇理由：{$selectedTopic['reason']}

要求：
- 文章長度：800-1200 字
- 包含實用的範例或步驟
- 結構清晰（引言、主要內容、結論）
- SEO 友善（自然包含關鍵字）
- 語氣專業但易懂

請以 JSON 格式回覆：
{
  \"title\": \"文章標題\",
  \"content\": \"完整文章內容（使用 Markdown 格式）\"
}"
            ]
        ],
        'temperature' => 0.7,
    ]);

    $content = $response->choices[0]->message->content;

    echo "AI 已完成撰寫" . PHP_EOL . PHP_EOL;

    // 解析 JSON 回覆
    $articleData = extractJson($content);

    if ($articleData && isset($articleData['title']) && isset($articleData['content'])) {
        // 儲存文章
        updateArticle($db, $articleId, [
            'title' => $articleData['title'],
            'content' => $articleData['content']
        ]);

        // 更新狀態為 pending_review
        updateArticleStatus($db, $articleId, 'pending_review');

        echo "✓ 文章標題: {$articleData['title']}" . PHP_EOL;
        echo "✓ 內容長度: " . mb_strlen($articleData['content']) . " 字元" . PHP_EOL;
        echo "✓ 文章狀態更新為: pending_review" . PHP_EOL;

        logExecution($db, 'cron-afternoon', $articleId, 'write_article', "撰寫完成: {$articleData['title']}");
    } else {
        echo "✗ 無法解析 AI 回覆" . PHP_EOL;
        logExecution($db, 'cron-afternoon', $articleId, 'error', '無法解析 AI 回覆');
    }

} catch (Exception $e) {
    echo "✗ 執行失敗: " . $e->getMessage() . PHP_EOL;
    if (isset($db) && isset($articleId)) {
        logExecution($db, 'cron-afternoon', $articleId, 'error', $e->getMessage());
    }
    exit(1);
}

echo PHP_EOL . "=== Afternoon Cron 完成 ===" . PHP_EOL;

/**
 * 從 AI 回覆中提取 JSON
 */
function extractJson(string $content): ?array {
    // 移除可能的 markdown code block
    $content = preg_replace('/```json\s*/s', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = trim($content);

    $decoded = json_decode($content, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    return null;
}
