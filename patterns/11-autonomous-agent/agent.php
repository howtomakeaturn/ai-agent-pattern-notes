<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';

// 載入環境變數
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Autonomous Agent - 自主決策 AI Agent
 *
 * 執行時間：建議每小時執行一次
 * Cron 設定：0 * * * * cd /path/to/project && php patterns/11-autonomous-agent/agent.php
 *
 * 核心概念：
 * - 不是固定時間做固定事情（Pattern 10）
 * - 而是每次執行讓 AI 自己決定「現在該做什麼」
 * - 使用 Tool Functions 讓 AI 呼叫各種動作
 * - 使用 Dynamic Routing 根據狀態提供不同工具
 */

echo "╔═══════════════════════════════════════════╗" . PHP_EOL;
echo "║  Autonomous Agent - 自主決策 AI Agent   ║" . PHP_EOL;
echo "╚═══════════════════════════════════════════╝" . PHP_EOL;
echo "執行時間: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

try {
    // 初始化
    $db = initDatabase();
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    // Step 1: 讀取系統當前狀態
    echo "→ 讀取系統狀態..." . PHP_EOL;
    $systemState = getSystemState($db);

    echo "  當前文章數量：" . PHP_EOL;
    foreach ($systemState['counts'] as $status => $count) {
        if ($count > 0) {
            echo "    - {$status}: {$count}" . PHP_EOL;
        }
    }
    echo PHP_EOL;

    // Step 2: Dynamic Routing - 根據狀態決定可用的工具
    echo "→ 決定可用工具..." . PHP_EOL;
    $availableTools = getAvailableTools($systemState);

    echo "  可用動作：" . PHP_EOL;
    foreach ($availableTools as $tool) {
        echo "    - {$tool['function']['name']}: {$tool['function']['description']}" . PHP_EOL;
    }
    echo PHP_EOL;

    // Step 3: 讓 AI 分析狀態並決定動作
    echo "→ AI 分析與決策..." . PHP_EOL . PHP_EOL;

    $messages = [
        [
            'role' => 'system',
            'content' => getSystemPrompt()
        ],
        [
            'role' => 'user',
            'content' => buildDecisionPrompt($systemState)
        ]
    ];

    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'tools' => $availableTools,
        'tool_choice' => 'auto',
        'temperature' => 0.7,
    ]);

    $assistantMessage = $response->choices[0]->message;

    // AI 的思考過程
    if ($assistantMessage->content) {
        echo "【AI 思考】" . PHP_EOL;
        echo $assistantMessage->content . PHP_EOL . PHP_EOL;
    }

    // Step 4: 執行 AI 選擇的動作
    if ($assistantMessage->toolCalls && count($assistantMessage->toolCalls) > 0) {
        $toolCall = $assistantMessage->toolCalls[0];
        $functionName = $toolCall->function->name;
        $arguments = json_decode($toolCall->function->arguments, true);

        echo "【執行動作】{$functionName}" . PHP_EOL;
        echo "參數: " . json_encode($arguments, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;

        // 記錄決策
        $reasoning = $assistantMessage->content ?? "AI 選擇執行 {$functionName}";
        logAgentDecision(
            $db,
            $systemState,
            array_map(fn($t) => $t['function']['name'], $availableTools),
            $functionName,
            $reasoning
        );

        // 執行工具函數
        $result = executeToolFunction($db, $client, $functionName, $arguments);

        echo PHP_EOL . "【執行結果】" . PHP_EOL;
        echo $result['message'] . PHP_EOL;

        // 記錄執行日誌
        logExecution(
            $db,
            'agent',
            $result['article_id'] ?? null,
            $functionName,
            $result['message'],
            $reasoning
        );

    } else {
        echo "【無動作】AI 認為目前不需要執行任何動作" . PHP_EOL;
        logExecution($db, 'agent', null, 'no_action', 'AI 決定不執行動作', $assistantMessage->content);
    }

} catch (Exception $e) {
    echo "✗ 執行失敗: " . $e->getMessage() . PHP_EOL;
    if (isset($db)) {
        logExecution($db, 'agent', null, 'error', $e->getMessage());
    }
    exit(1);
}

echo PHP_EOL . "╔═══════════════════════════════════════════╗" . PHP_EOL;
echo "║          Agent 執行完成                   ║" . PHP_EOL;
echo "╚═══════════════════════════════════════════╝" . PHP_EOL;

// ==================== Dynamic Routing ====================

/**
 * 根據系統狀態決定可用的工具（Dynamic Routing）
 *
 * 這是 Pattern 11 的關鍵：不是給 AI 所有工具，而是根據當前狀態
 * 只提供「有意義」的工具，減少 token 使用並提高決策品質
 */
function getAvailableTools(array $systemState): array {
    $tools = [];
    $counts = $systemState['counts'];

    // 如果沒有任何文章，或待研究的少於 2 篇 → 可以研究新關鍵字
    if ($counts['pending_research'] < 2) {
        $tools[] = getToolDefinition('research_keywords');
    }

    // 如果有待撰寫的文章 → 可以撰寫文章
    if ($counts['pending_write'] > 0) {
        $tools[] = getToolDefinition('write_article');
    }

    // 如果有待審核的文章 → 可以自我審查品質
    if ($counts['pending_review'] > 0) {
        $tools[] = getToolDefinition('self_review_quality');
    }

    // 如果有已批准的文章 → 可以發布並分析
    if ($counts['approved'] > 0) {
        $tools[] = getToolDefinition('publish_and_analyze');
    }

    // 始終可用：查看系統狀態詳情
    $tools[] = getToolDefinition('get_article_details');

    // 如果沒有其他動作可做 → 提供「等待」選項
    if (count($tools) == 1) {  // 只有 get_article_details
        $tools[] = getToolDefinition('wait');
    }

    return $tools;
}

/**
 * 取得工具定義
 */
function getToolDefinition(string $toolName): array {
    $definitions = [
        'research_keywords' => [
            'type' => 'function',
            'function' => [
                'name' => 'research_keywords',
                'description' => '研究熱門關鍵字並建立新文章企劃。適合在沒有足夠待處理文章時執行。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'focus_area' => [
                            'type' => 'string',
                            'description' => '想聚焦的主題領域，如：AI、軟體開發、科技趨勢等'
                        ]
                    ],
                    'required' => ['focus_area']
                ]
            ]
        ],
        'write_article' => [
            'type' => 'function',
            'function' => [
                'name' => 'write_article',
                'description' => '為已有關鍵字的文章撰寫完整內容。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'article_id' => [
                            'type' => 'integer',
                            'description' => '要撰寫的文章 ID'
                        ]
                    ],
                    'required' => ['article_id']
                ]
            ]
        ],
        'self_review_quality' => [
            'type' => 'function',
            'function' => [
                'name' => 'self_review_quality',
                'description' => '自我審查文章品質，評估是否達到發布標準。如果品質不足會自動重寫。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'article_id' => [
                            'type' => 'integer',
                            'description' => '要審查的文章 ID'
                        ]
                    ],
                    'required' => ['article_id']
                ]
            ]
        ],
        'publish_and_analyze' => [
            'type' => 'function',
            'function' => [
                'name' => 'publish_and_analyze',
                'description' => '發布已批准的文章並分析績效。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'article_id' => [
                            'type' => 'integer',
                            'description' => '要發布的文章 ID'
                        ]
                    ],
                    'required' => ['article_id']
                ]
            ]
        ],
        'get_article_details' => [
            'type' => 'function',
            'function' => [
                'name' => 'get_article_details',
                'description' => '查看特定文章的詳細資訊。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'article_id' => [
                            'type' => 'integer',
                            'description' => '文章 ID'
                        ]
                    ],
                    'required' => ['article_id']
                ]
            ]
        ],
        'wait' => [
            'type' => 'function',
            'function' => [
                'name' => 'wait',
                'description' => '目前沒有需要執行的動作，等待下次檢查。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => [
                            'type' => 'string',
                            'description' => '等待的原因'
                        ]
                    ],
                    'required' => ['reason']
                ]
            ]
        ]
    ];

    return $definitions[$toolName];
}

// ==================== System Prompt ====================

function getSystemPrompt(): string {
    return "你是一個自主運作的 AI 內容創作助理。你的任務是管理整個部落格內容生產流程。

你的能力：
- 研究熱門關鍵字並規劃文章
- 撰寫高品質的部落格文章
- 自我評估文章品質（如果不夠好，你會選擇重寫而不是送審）
- 發布文章並分析績效

重要原則：
1. **品質優先**：寧可多花時間重寫，也不要送出品質不佳的文章
2. **避免過載**：不要一次建立太多待處理文章（pending_research < 2, pending_write < 3）
3. **平衡產出**：確保有穩定的內容產出，但不要操之過急
4. **學習改進**：從已發布文章的績效中學習，調整未來的策略

目前你是以自主模式運作，每小時會被喚醒一次。每次你需要：
1. 分析當前系統狀態
2. 判斷最重要/最緊急的任務是什麼
3. 選擇並執行一個動作
4. 說明你的決策理由";
}

function buildDecisionPrompt(array $systemState): string {
    $counts = $systemState['counts'];

    $prompt = "目前系統狀態：\n\n";
    $prompt .= "文章數量統計：\n";
    foreach ($counts as $status => $count) {
        $prompt .= "- {$status}: {$count} 篇\n";
    }

    $prompt .= "\n最近文章：\n";
    if (!empty($systemState['recent_articles'])) {
        foreach (array_slice($systemState['recent_articles'], 0, 3) as $article) {
            $prompt .= "- ID {$article['id']}: {$article['status']}";
            if ($article['title']) {
                $prompt .= " - {$article['title']}";
            }
            $prompt .= " (更新於 {$article['updated_at']})\n";
        }
    } else {
        $prompt .= "（沒有任何文章）\n";
    }

    $prompt .= "\n請分析當前狀態，並決定接下來應該執行什麼動作。\n";
    $prompt .= "說明你的決策理由，然後選擇一個工具來執行。";

    return $prompt;
}

// ==================== Tool Function Executor ====================

/**
 * 執行工具函數
 */
function executeToolFunction(PDO $db, $client, string $functionName, array $arguments): array {
    switch ($functionName) {
        case 'research_keywords':
            return toolResearchKeywords($db, $client, $arguments);

        case 'write_article':
            return toolWriteArticle($db, $client, $arguments);

        case 'self_review_quality':
            return toolSelfReviewQuality($db, $client, $arguments);

        case 'publish_and_analyze':
            return toolPublishAndAnalyze($db, $client, $arguments);

        case 'get_article_details':
            return toolGetArticleDetails($db, $arguments);

        case 'wait':
            return toolWait($arguments);

        default:
            return ['success' => false, 'message' => "未知的工具: {$functionName}"];
    }
}

// ==================== Tool Functions Implementation ====================

function toolResearchKeywords(PDO $db, $client, array $args): array {
    $focusArea = $args['focus_area'] ?? 'AI 與軟體開發';

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
            'message' => "✓ 成功研究 {$topicCount} 個關鍵字主題，文章狀態: pending_write"
        ];
    } else {
        return [
            'success' => false,
            'article_id' => $articleId,
            'message' => "✗ 關鍵字研究失敗，無法解析 AI 回覆"
        ];
    }
}

function toolWriteArticle(PDO $db, $client, array $args): array {
    $articleId = $args['article_id'];
    $article = getArticleById($db, $articleId);

    if (!$article) {
        return ['success' => false, 'message' => "✗ 找不到文章 ID: {$articleId}"];
    }

    if ($article['status'] !== 'pending_write') {
        return ['success' => false, 'message' => "✗ 文章狀態為 {$article['status']}，無法撰寫"];
    }

    echo "  文章 ID: {$articleId}" . PHP_EOL;

    $keywordsData = json_decode($article['keywords'], true);
    if (!$keywordsData) {
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
        updateArticle($db, $articleId, [
            'title' => $articleData['title'],
            'content' => $articleData['content']
        ]);
        updateArticleStatus($db, $articleId, 'pending_review');

        return [
            'success' => true,
            'article_id' => $articleId,
            'message' => "✓ 文章撰寫完成: {$articleData['title']}\n  狀態: pending_review（等待自我審查）"
        ];
    } else {
        return [
            'success' => false,
            'article_id' => $articleId,
            'message' => "✗ 文章撰寫失敗，無法解析 AI 回覆"
        ];
    }
}

function toolSelfReviewQuality(PDO $db, $client, array $args): array {
    $articleId = $args['article_id'];
    $article = getArticleById($db, $articleId);

    if (!$article || $article['status'] !== 'pending_review') {
        return ['success' => false, 'message' => "✗ 無法審查文章 ID: {$articleId}"];
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

        updateArticle($db, $articleId, [
            'quality_score' => $score
        ]);

        if ($verdict === 'approved' && $score >= 7) {
            // 品質通過，等待人工批准
            // 注意：這裡不自動改為 approved，而是保持 pending_review 等待人類最終決定
            return [
                'success' => true,
                'article_id' => $articleId,
                'message' => "✓ 品質評分: {$score}/10 - 通過自我審查\n  理由: {$review['reasoning']}\n  ⚠️  仍需人工批准（執行 human-review.php）"
            ];
        } else {
            // 品質不足，回到 pending_write 狀態準備重寫
            updateArticleStatus($db, $articleId, 'pending_write');
            incrementRevisionCount($db, $articleId);

            return [
                'success' => true,
                'article_id' => $articleId,
                'message' => "⚠️  品質評分: {$score}/10 - 需要修改\n  理由: {$review['reasoning']}\n  已退回 pending_write 狀態，下次將重寫"
            ];
        }
    } else {
        return [
            'success' => false,
            'article_id' => $articleId,
            'message' => "✗ 品質審查失敗"
        ];
    }
}

function toolPublishAndAnalyze(PDO $db, $client, array $args): array {
    $articleId = $args['article_id'];
    $article = getArticleById($db, $articleId);

    if (!$article || $article['status'] !== 'approved') {
        return ['success' => false, 'message' => "✗ 文章未批准或不存在"];
    }

    echo "  發布文章: {$article['title']}" . PHP_EOL;

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

    echo "  模擬績效數據已生成" . PHP_EOL;

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

    updateArticle($db, $articleId, [
        'performance_data' => json_encode($performanceData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ]);
    updateArticleStatus($db, $articleId, 'published', 'published_at');

    $rating = $analysis['performance_rating'] ?? 'N/A';
    return [
        'success' => true,
        'article_id' => $articleId,
        'message' => "✓ 文章已發布並分析\n  績效評估: {$rating}\n  瀏覽: {$performanceData['metrics']['views']}, 點擊: {$performanceData['metrics']['clicks']}"
    ];
}

function toolGetArticleDetails(PDO $db, array $args): array {
    $articleId = $args['article_id'];
    $article = getArticleById($db, $articleId);

    if (!$article) {
        return ['success' => false, 'message' => "✗ 找不到文章 ID: {$articleId}"];
    }

    $details = "文章 ID {$articleId}:\n";
    $details .= "- 標題: " . ($article['title'] ?: '(未設定)') . "\n";
    $details .= "- 狀態: {$article['status']}\n";
    $details .= "- 建立: {$article['created_at']}\n";
    $details .= "- 更新: {$article['updated_at']}\n";

    if ($article['quality_score']) {
        $details .= "- 品質評分: {$article['quality_score']}/10\n";
    }

    if ($article['revision_count'] > 0) {
        $details .= "- 修訂次數: {$article['revision_count']}\n";
    }

    return [
        'success' => true,
        'article_id' => $articleId,
        'message' => $details
    ];
}

function toolWait(array $args): array {
    $reason = $args['reason'] ?? '目前沒有需要處理的任務';

    return [
        'success' => true,
        'message' => "⏸️  等待中\n  理由: {$reason}"
    ];
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
