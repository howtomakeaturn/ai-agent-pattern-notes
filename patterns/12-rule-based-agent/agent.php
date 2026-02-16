<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';

// 載入環境變數
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Rule-Based Agent - 基於規則的 Agent
 *
 * 執行時間：建議每小時執行一次
 * Cron 設定：0 * * * * cd /path/to/project && php patterns/12-rule-based-agent/agent.php
 *
 * 核心概念：
 * - 使用固定優先級規則決定動作（不需要 LLM 決策）
 * - 優先級：發布 > 審查 > 撰寫 > 研究
 * - 成本更低（省下決策的 API 調用）
 * - 行為完全可預測
 *
 * 與 Pattern 11 的差異：
 * - Pattern 11：每次執行都讓 LLM 分析狀態並決定動作
 * - Pattern 12：用固定規則直接決定動作
 */

echo "╔═══════════════════════════════════════════╗" . PHP_EOL;
echo "║  Rule-Based Agent - 基於規則的 Agent    ║" . PHP_EOL;
echo "╚═══════════════════════════════════════════╝" . PHP_EOL;
echo "執行時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    // 初始化
    $db = initDatabase();
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Step 1: 讀取系統當前狀態
    echo "→ 讀取系統狀態..." . PHP_EOL;
    $systemState = getSystemState($db);
    $counts = $systemState['counts'];

    echo "  當前文章數量：" . PHP_EOL;
    foreach ($counts as $status => $count) {
        if ($count > 0) {
            echo "    - {$status}: {$count}" . PHP_EOL;
        }
    }
    echo PHP_EOL;

    // Step 2: 根據固定優先級規則決定動作（不需要 LLM）
    echo "→ 應用規則決策..." . PHP_EOL . PHP_EOL;

    $action = determineAction($counts);

    echo "【規則決策】" . PHP_EOL;
    echo "選擇動作: {$action['name']}" . PHP_EOL;
    echo "理由: {$action['reason']}" . PHP_EOL . PHP_EOL;

    // Step 3: 執行動作
    $result = executeAction($db, $client, $action, $counts);

    echo PHP_EOL . "【執行結果】" . PHP_EOL;
    echo $result['message'] . PHP_EOL;

    // 記錄執行日誌
    logExecution(
        $db,
        'rule_based_agent',
        $result['article_id'] ?? null,
        $action['name'],
        $result['message'],
        $action['reason']
    );

} catch (Exception $e) {
    echo "✗ 執行失敗: " . $e->getMessage() . PHP_EOL;
    if (isset($db)) {
        logExecution($db, 'rule_based_agent', null, 'error', $e->getMessage());
    }
    exit(1);
}

echo PHP_EOL . "╔═══════════════════════════════════════════╗" . PHP_EOL;
echo "║          Agent 執行完成                   ║" . PHP_EOL;
echo "╚═══════════════════════════════════════════╝" . PHP_EOL;

// ==================== 固定優先級規則 ====================

/**
 * 根據系統狀態應用固定規則決定動作
 *
 * 優先級順序：
 * 1. 發布（approved）- 最高優先級，快速產出
 * 2. 審查（pending_review）- 確保品質
 * 3. 撰寫（pending_write）- 完成內容創作
 * 4. 研究（pending_research < 2）- 保持內容管道
 * 5. 等待 - 無需執行
 */
function determineAction(array $counts): array {
    // 優先級 1：如果有已批准的文章，立即發布
    if ($counts['approved'] > 0) {
        return [
            'name' => 'publish',
            'reason' => "有 {$counts['approved']} 篇已批准文章等待發布（最高優先級）"
        ];
    }

    // 優先級 2：如果有待審查的文章，進行品質審查
    if ($counts['pending_review'] > 0) {
        return [
            'name' => 'review',
            'reason' => "有 {$counts['pending_review']} 篇文章等待品質審查"
        ];
    }

    // 優先級 3：如果有待撰寫的文章，進行撰寫
    if ($counts['pending_write'] > 0) {
        return [
            'name' => 'write',
            'reason' => "有 {$counts['pending_write']} 篇文章等待撰寫"
        ];
    }

    // 優先級 4：如果待研究的文章少於 2 篇，研究新關鍵字
    if ($counts['pending_research'] < 2) {
        return [
            'name' => 'research',
            'reason' => "待研究文章數 ({$counts['pending_research']}) 少於 2，需要補充內容管道"
        ];
    }

    // 無需執行
    return [
        'name' => 'wait',
        'reason' => '系統狀態良好，無需執行任何動作'
    ];
}

/**
 * 執行決定的動作
 */
function executeAction(PDO $db, $client, array $action, array $counts): array {
    switch ($action['name']) {
        case 'publish':
            return actionPublish($db, $client);

        case 'review':
            return actionReview($db, $client);

        case 'write':
            return actionWrite($db, $client);

        case 'research':
            return actionResearch($db, $client);

        case 'wait':
            return [
                'success' => true,
                'message' => "⏸️  等待中\n  " . $action['reason']
            ];

        default:
            return [
                'success' => false,
                'message' => "未知的動作: {$action['name']}"
            ];
    }
}

// ==================== 動作實作 ====================

function actionPublish(PDO $db, $client): array {
    echo "【執行動作】發布文章" . PHP_EOL;

    // 取得最舊的已批准文章
    $article = getOldestArticleByStatus($db, 'approved');

    if (!$article) {
        return ['success' => false, 'message' => "✗ 找不到已批准的文章"];
    }

    echo "  文章: {$article['title']}" . PHP_EOL;
    echo "  發布中..." . PHP_EOL;

    // Mock 發布（實際應呼叫 WordPress API）
    echo "  ⚠️ POC 模式：模擬發布" . PHP_EOL;

    // Mock 績效數據
    $performanceData = [
        'metrics' => [
            'views' => rand(100, 1000),
            'clicks' => rand(10, 100),
            'engagement_rate' => round(rand(5, 15) / 100, 2),
            'avg_time_on_page' => rand(60, 300),
        ]
    ];

    // AI 分析績效
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是數據分析專家，分析部落格績效並提供改進建議。'
            ],
            [
                'role' => 'user',
                'content' => "分析文章績效：

標題：{$article['title']}
績效：" . json_encode($performanceData['metrics'], JSON_UNESCAPED_UNICODE) . "

JSON 格式：
{
  \"performance_rating\": \"好/中/差\",
  \"analysis\": \"分析\",
  \"suggestions\": [\"建議1\", \"建議2\"]
}"
            ]
        ],
        'temperature' => 0.6,
    ]);

    $content = $response->choices[0]->message->content;
    $analysis = extractJson($content);

    if ($analysis) {
        $performanceData['ai_analysis'] = $analysis;
    }

    updateArticle($db, $article['id'], [
        'performance_data' => json_encode($performanceData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ]);
    updateArticleStatus($db, $article['id'], 'published', 'published_at');

    $rating = $analysis['performance_rating'] ?? 'N/A';
    return [
        'success' => true,
        'article_id' => $article['id'],
        'message' => "✓ 文章已發布: {$article['title']}\n  績效評估: {$rating}\n  瀏覽: {$performanceData['metrics']['views']}, 點擊: {$performanceData['metrics']['clicks']}"
    ];
}

function actionReview(PDO $db, $client): array {
    echo "【執行動作】審查文章品質" . PHP_EOL;

    // 取得最舊的待審查文章
    $article = getOldestArticleByStatus($db, 'pending_review');

    if (!$article) {
        return ['success' => false, 'message' => "✗ 找不到待審查的文章"];
    }

    echo "  文章: {$article['title']}" . PHP_EOL;
    echo "  審查中..." . PHP_EOL;

    // AI 自我評估品質
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是嚴格的內容品質審查員。評估文章是否達到發布標準。'
            ],
            [
                'role' => 'user',
                'content' => "請評估這篇文章的品質：

標題：{$article['title']}

內容：
{$article['content']}

評估標準：
- 內容深度與準確性
- 結構完整性
- 實用性
- SEO 優化

請給出 1-10 分的評分，並說明理由。如果低於 7 分，建議重寫。

JSON 格式：
{
  \"score\": 8.5,
  \"verdict\": \"approved/needs_revision\",
  \"reasoning\": \"評估理由\"
}"
            ]
        ],
        'temperature' => 0.3,
    ]);

    $content = $response->choices[0]->message->content;
    $review = extractJson($content);

    if ($review) {
        $score = $review['score'];
        $verdict = $review['verdict'];

        updateArticle($db, $article['id'], [
            'quality_score' => $score
        ]);

        if ($verdict === 'approved' && $score >= 7) {
            // 品質通過，保持 pending_review 等待人工批准
            return [
                'success' => true,
                'article_id' => $article['id'],
                'message' => "✓ 品質評分: {$score}/10 - 通過自我審查\n  理由: {$review['reasoning']}\n  ⚠️  仍需人工批准（執行 human-review.php）"
            ];
        } else {
            // 品質不足，退回重寫
            updateArticleStatus($db, $article['id'], 'pending_write');
            incrementRevisionCount($db, $article['id']);

            return [
                'success' => true,
                'article_id' => $article['id'],
                'message' => "⚠️  品質評分: {$score}/10 - 需要修改\n  理由: {$review['reasoning']}\n  已退回 pending_write 狀態，下次將重寫"
            ];
        }
    } else {
        return [
            'success' => false,
            'article_id' => $article['id'],
            'message' => "✗ 品質審查失敗"
        ];
    }
}

function actionWrite(PDO $db, $client): array {
    echo "【執行動作】撰寫文章" . PHP_EOL;

    // 取得最舊的待撰寫文章
    $article = getOldestArticleByStatus($db, 'pending_write');

    if (!$article) {
        return ['success' => false, 'message' => "✗ 找不到待撰寫的文章"];
    }

    echo "  文章 ID: {$article['id']}" . PHP_EOL;

    $keywordsData = json_decode($article['keywords'], true);
    if (!$keywordsData || empty($keywordsData['topics'])) {
        return ['success' => false, 'message' => "✗ 文章沒有關鍵字資料"];
    }

    $selectedTopic = $keywordsData['topics'][0];
    echo "  主題: {$selectedTopic['keyword']}" . PHP_EOL;

    // 撰寫文章
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是專業的技術部落格作家，文章清晰、實用、有深度。'
            ],
            [
                'role' => 'user',
                'content' => "請撰寫技術部落格文章：

關鍵字：{$selectedTopic['keyword']}
建議標題：{$selectedTopic['title_suggestion']}

要求：
- 800-1200 字
- 包含實用範例
- 結構清晰
- SEO 友善

JSON 格式回覆：
{
  \"title\": \"文章標題\",
  \"content\": \"完整內容（Markdown）\"
}"
            ]
        ],
        'temperature' => 0.7,
    ]);

    $content = $response->choices[0]->message->content;
    $articleData = extractJson($content);

    if ($articleData && isset($articleData['title']) && isset($articleData['content'])) {
        updateArticle($db, $article['id'], [
            'title' => $articleData['title'],
            'content' => $articleData['content']
        ]);
        updateArticleStatus($db, $article['id'], 'pending_review');

        return [
            'success' => true,
            'article_id' => $article['id'],
            'message' => "✓ 文章撰寫完成: {$articleData['title']}\n  狀態: pending_review（等待品質審查）"
        ];
    } else {
        return [
            'success' => false,
            'article_id' => $article['id'],
            'message' => "✗ 文章撰寫失敗，無法解析 AI 回覆"
        ];
    }
}

function actionResearch(PDO $db, $client): array {
    echo "【執行動作】研究關鍵字" . PHP_EOL;

    $focusArea = 'AI 與軟體開發';  // 可設定為動態
    echo "  研究領域: {$focusArea}" . PHP_EOL;

    // 建立新文章
    $articleId = createArticle($db, 'pending_research');
    echo "  建立文章 ID: {$articleId}" . PHP_EOL;

    // 呼叫 OpenAI 研究關鍵字
    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是 SEO 專家，擅長尋找熱門且有價值的文章主題。'
            ],
            [
                'role' => 'user',
                'content' => "請為部落格研究 {$focusArea} 相關的 3-5 個熱門關鍵字。

要求：
- 具有搜尋熱度
- 能產出有價值的內容
- 適合技術部落格

請以 JSON 格式回覆：
{
  \"topics\": [
    {
      \"keyword\": \"關鍵字\",
      \"title_suggestion\": \"建議標題\",
      \"reason\": \"為什麼選這個主題\",
      \"search_intent\": \"搜尋意圖\"
    }
  ]
}"
            ]
        ],
        'temperature' => 0.8,
    ]);

    $content = $response->choices[0]->message->content;
    $keywordsData = extractJson($content);

    if ($keywordsData) {
        updateArticle($db, $articleId, [
            'keywords' => json_encode($keywordsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ]);
        updateArticleStatus($db, $articleId, 'pending_write');

        $topicCount = count($keywordsData['topics'] ?? []);
        return [
            'success' => true,
            'article_id' => $articleId,
            'message' => "✓ 成功研究 {$topicCount} 個關鍵字主題\n  文章狀態: pending_write"
        ];
    } else {
        return [
            'success' => false,
            'article_id' => $articleId,
            'message' => "✗ 關鍵字研究失敗，無法解析 AI 回覆"
        ];
    }
}

// ==================== Helper Functions ====================

function extractJson(string $content): ?array {
    $content = preg_replace('/```json\s*/s', '', $content);
    $content = preg_replace('/```\s*$/s', '', $content);
    $content = trim($content);

    $decoded = json_decode($content, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    return null;
}
