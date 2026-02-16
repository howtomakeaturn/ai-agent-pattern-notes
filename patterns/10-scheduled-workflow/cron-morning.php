<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';

// 載入環境變數
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Morning Cron Job - 關鍵字研究
 *
 * 執行時間：建議每天早上 8:00
 * Cron 設定：0 8 * * * cd /path/to/project && php patterns/10-scheduled-workflow/cron-morning.php
 *
 * 流程：
 * 1. 檢查是否有 pending_research 狀態的文章
 * 2. 如果沒有，建立一篇新文章
 * 3. 呼叫 OpenAI API 研究熱門關鍵字
 * 4. 將關鍵字存入資料庫
 * 5. 更新文章狀態為 pending_write
 */

echo "=== Morning Cron: 關鍵字研究 ===" . PHP_EOL;
echo "執行時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    // 初始化資料庫
    $db = initDatabase();

    // 檢查是否有待研究的文章
    $articles = getArticlesByStatus($db, 'pending_research');

    if (empty($articles)) {
        echo "沒有待研究的文章，建立新文章..." . PHP_EOL;
        $articleId = createArticle($db, 'pending_research');
        $article = getArticleById($db, $articleId);
        logExecution($db, 'cron-morning', $articleId, 'create_article', '建立新文章 ID: ' . $articleId);
    } else {
        $article = $articles[0];
        $articleId = $article['id'];
        echo "找到待研究文章 ID: {$articleId}" . PHP_EOL;
    }

    echo PHP_EOL . "正在研究關鍵字..." . PHP_EOL;

    // 初始化 OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // 呼叫 OpenAI API 研究關鍵字
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一位專業的內容策略師和 SEO 專家。你的任務是為部落格研究熱門話題和關鍵字。'
            ],
            [
                'role' => 'user',
                'content' => '請為技術部落格研究 3-5 個熱門話題，這些話題應該：
1. 與 AI、軟體開發、或科技趨勢相關
2. 具有良好的搜尋熱度
3. 能夠產出有價值的內容

請以 JSON 格式回覆，格式如下：
{
  "topics": [
    {
      "keyword": "關鍵字",
      "title_suggestion": "建議標題",
      "reason": "為什麼選這個主題",
      "search_intent": "搜尋意圖（資訊型/教學型/商業型）"
    }
  ]
}'
            ]
        ],
        'temperature' => 0.8,
    ]);

    $content = $response->choices[0]->message->content;

    echo "AI 回覆：" . PHP_EOL;
    echo $content . PHP_EOL . PHP_EOL;

    // 嘗試解析 JSON
    $keywordsData = extractJson($content);

    if ($keywordsData) {
        // 儲存關鍵字資料
        updateArticle($db, $articleId, [
            'keywords' => json_encode($keywordsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        // 更新狀態為 pending_write
        updateArticleStatus($db, $articleId, 'pending_write');

        $topicCount = count($keywordsData['topics'] ?? []);
        echo "✓ 成功研究 {$topicCount} 個關鍵字主題" . PHP_EOL;
        echo "✓ 文章狀態更新為: pending_write" . PHP_EOL;

        logExecution($db, 'cron-morning', $articleId, 'research_keywords', "研究完成，找到 {$topicCount} 個主題");
    } else {
        echo "✗ 無法解析 AI 回覆為 JSON 格式" . PHP_EOL;
        logExecution($db, 'cron-morning', $articleId, 'research_keywords', 'Error: 無法解析 JSON');
    }

} catch (Exception $e) {
    echo "✗ 執行失敗: " . $e->getMessage() . PHP_EOL;
    if (isset($db) && isset($articleId)) {
        logExecution($db, 'cron-morning', $articleId, 'error', $e->getMessage());
    }
    exit(1);
}

echo PHP_EOL . "=== Morning Cron 完成 ===" . PHP_EOL;

/**
 * 從 AI 回覆中提取 JSON（處理可能包含 markdown 包裝的情況）
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
