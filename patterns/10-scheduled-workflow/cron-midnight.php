<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';

// 載入環境變數
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Midnight Cron Job - 績效分析
 *
 * 執行時間：建議每天凌晨 0:00
 * Cron 設定：0 0 * * * cd /path/to/project && php patterns/10-scheduled-workflow/cron-midnight.php
 *
 * 流程：
 * 1. 查詢 approved 狀態的文章
 * 2. 模擬發布到 WordPress（預留 API 接口）
 * 3. Mock 績效數據（或從 WordPress API 讀取真實數據）
 * 4. 呼叫 OpenAI API 分析績效
 * 5. 儲存分析結果
 * 6. 更新文章狀態為 published
 */

echo "=== Midnight Cron: 績效分析 ===" . PHP_EOL;
echo "執行時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    // 初始化資料庫
    $db = initDatabase();

    // 查詢已批准的文章
    $articles = getArticlesByStatus($db, 'approved');

    if (empty($articles)) {
        echo "沒有已批准待發布的文章" . PHP_EOL;
        logExecution($db, 'cron-midnight', null, 'check_articles', '沒有已批准的文章');
        exit(0);
    }

    $article = $articles[0];
    $articleId = $article['id'];

    echo "找到已批准文章 ID: {$articleId}" . PHP_EOL;
    echo "標題: {$article['title']}" . PHP_EOL . PHP_EOL;

    // ===== 模擬發布到 WordPress =====
    echo "--- 發布文章 ---" . PHP_EOL;

    // TODO: 實際整合 WordPress API
    // $wordpressUrl = 'https://your-site.com/wp-json/wp/v2/posts';
    // $response = postToWordPress($wordpressUrl, $article['title'], $article['content']);

    echo "⚠ POC 模式：模擬發布（實際應整合 WordPress API）" . PHP_EOL;
    echo "✓ 模擬發布成功" . PHP_EOL . PHP_EOL;

    // ===== 收集或模擬績效數據 =====
    echo "--- 收集績效數據 ---" . PHP_EOL;

    // TODO: 從 WordPress 或 Google Analytics 讀取真實數據
    // $performanceData = fetchPerformanceData($articleId);

    // POC: 使用 Mock 數據
    $performanceData = generateMockPerformance();

    echo "📊 績效數據（Mock）：" . PHP_EOL;
    echo "  - 瀏覽數: {$performanceData['views']}" . PHP_EOL;
    echo "  - 點擊數: {$performanceData['clicks']}" . PHP_EOL;
    echo "  - 互動率: " . ($performanceData['engagement_rate'] * 100) . "%" . PHP_EOL;
    echo "  - 平均停留時間: {$performanceData['avg_time_on_page']}秒" . PHP_EOL . PHP_EOL;

    // ===== AI 分析績效 =====
    echo "--- AI 分析績效 ---" . PHP_EOL;

    // 初始化 OpenAI client
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // 準備分析資料
    $keywordsData = json_decode($article['keywords'], true);
    $selectedTopic = $keywordsData['topics'][0] ?? ['keyword' => 'unknown'];

    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一位數據分析專家和內容策略顧問。你的任務是分析部落格文章的績效，並提供具體的改進建議。'
            ],
            [
                'role' => 'user',
                'content' => "請分析以下部落格文章的績效：

文章標題：{$article['title']}
關鍵字：{$selectedTopic['keyword']}

績效數據：
- 瀏覽數：{$performanceData['views']}
- 點擊數：{$performanceData['clicks']}
- 互動率：" . ($performanceData['engagement_rate'] * 100) . "%
- 平均停留時間：{$performanceData['avg_time_on_page']}秒

請提供：
1. 績效評估（好/中/差）
2. 分析這些數據說明什麼
3. 這個關鍵字/主題是否值得繼續深入
4. 給未來文章撰寫的 3 個具體建議

請以 JSON 格式回覆：
{
  \"performance_rating\": \"好/中/差\",
  \"analysis\": \"綜合分析\",
  \"topic_recommendation\": \"是否推薦繼續這個主題\",
  \"suggestions\": [\"建議1\", \"建議2\", \"建議3\"]
}"
            ]
        ],
        'temperature' => 0.6,
    ]);

    $content = $response->choices[0]->message->content;

    echo "AI 分析完成" . PHP_EOL . PHP_EOL;

    // 解析分析結果
    $analysisData = extractJson($content);

    if ($analysisData) {
        // 合併績效數據和 AI 分析
        $fullPerformanceData = [
            'metrics' => $performanceData,
            'ai_analysis' => $analysisData,
            'analyzed_at' => date('Y-m-d H:i:s')
        ];

        // 儲存到資料庫
        updateArticle($db, $articleId, [
            'performance_data' => json_encode($fullPerformanceData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);

        // 更新狀態為 published
        updateArticleStatus($db, $articleId, 'published', 'published_at');

        echo "✓ 績效評估: {$analysisData['performance_rating']}" . PHP_EOL;
        echo "✓ 分析結果已儲存" . PHP_EOL;
        echo "✓ 文章狀態更新為: published" . PHP_EOL;

        logExecution($db, 'cron-midnight', $articleId, 'analyze_performance', "分析完成，評估: {$analysisData['performance_rating']}");
    } else {
        echo "✗ 無法解析 AI 分析結果" . PHP_EOL;

        // 仍然儲存 mock 數據
        updateArticle($db, $articleId, [
            'performance_data' => json_encode(['metrics' => $performanceData], JSON_UNESCAPED_UNICODE)
        ]);
        updateArticleStatus($db, $articleId, 'published', 'published_at');

        logExecution($db, 'cron-midnight', $articleId, 'error', '無法解析 AI 分析，但仍標記為已發布');
    }

} catch (Exception $e) {
    echo "✗ 執行失敗: " . $e->getMessage() . PHP_EOL;
    if (isset($db) && isset($articleId)) {
        logExecution($db, 'cron-midnight', $articleId, 'error', $e->getMessage());
    }
    exit(1);
}

echo PHP_EOL . "=== Midnight Cron 完成 ===" . PHP_EOL;

/**
 * 生成 Mock 績效數據
 */
function generateMockPerformance(): array {
    return [
        'views' => rand(100, 1000),
        'clicks' => rand(10, 100),
        'engagement_rate' => round(rand(5, 15) / 100, 2),  // 0.05 ~ 0.15
        'avg_time_on_page' => rand(60, 300),  // 60-300 秒
        'bounce_rate' => round(rand(30, 70) / 100, 2),  // 0.30 ~ 0.70
    ];
}

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

/**
 * 發布到 WordPress（預留接口）
 *
 * 使用 WordPress REST API:
 * POST https://your-site.com/wp-json/wp/v2/posts
 *
 * Headers:
 * - Authorization: Bearer {token}
 * - Content-Type: application/json
 *
 * Body:
 * {
 *   "title": "文章標題",
 *   "content": "文章內容",
 *   "status": "publish"
 * }
 */
function postToWordPress(string $url, string $title, string $content): array {
    // 實作範例（需要配置認證）
    /*
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_ENV['WORDPRESS_TOKEN'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'title' => $title,
        'content' => $content,
        'status' => 'publish'
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
    */

    return ['success' => true, 'id' => rand(1000, 9999)];
}
