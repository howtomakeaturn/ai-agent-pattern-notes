# Pattern 10: Scheduled Workflow

## 概念說明

**Scheduled Workflow** 是一種用排程系統（如 cron）協調多階段 AI 任務的設計模式。

與傳統的即時對話式 AI agent 不同，這個模式適用於：
- 任務需要在特定時間執行（如每天早上、半夜）
- 需要等待外部事件（如人工審核）
- 多個獨立的 PHP process 需要共享狀態
- 長時間運作的工作流程

## 為什麼需要這個 Pattern？

### 問題場景

你想要建立一個「AI 內容自動化系統」：
- 每天早上讓 AI 研究熱門關鍵字
- 下午根據關鍵字撰寫部落格文章
- 晚上由人工審核文章品質
- 半夜發布文章並分析績效

這個流程：
- ❌ 不適合用單一 chatbot 完成（需要跨越數小時）
- ❌ 不能用 graph engine（流程是固定的，不需要資料驅動）
- ✅ 需要 **scheduled workflow**（用 cron 觸發，資料庫持久化狀態）

### 核心特點

1. **時間驅動**：流程由時間觸發（早上 8 點、下午 2 點等），而非用戶輸入
2. **狀態持久化**：每個 cron 是獨立 PHP process，必須透過資料庫共享狀態
3. **Human-in-the-loop**：流程可以暫停等待人工介入（審核、批准等）
4. **長時間運作**：整個流程可能跨越數小時甚至數天

## 架構設計

### 文章狀態流轉

```
pending_research  →  pending_write  →  pending_review  →  approved  →  published
     (待研究)           (待撰寫)           (待審核)        (已批准)    (已發布)
         ↓                  ↓                  ↓              ↓           ↓
   Morning Cron      Afternoon Cron     Human Review   Midnight Cron   完成
   (研究關鍵字)       (撰寫文章)         (人工審核)     (發布&分析)
```

可能的分支：
```
pending_review → rejected (拒絕，不會發布)
```

### 資料庫 Schema

**articles 表**：
- `id`: 文章 ID
- `title`: 文章標題
- `keywords`: 關鍵字資料（JSON）
- `content`: 文章內容（Markdown）
- `status`: 當前狀態
- `performance_data`: 績效資料和 AI 分析（JSON）
- `created_at`, `reviewed_at`, `published_at`: 時間戳記

**execution_logs 表**：
- 記錄每次 cron 執行的日誌
- 方便追蹤問題和除錯

### 檔案結構

```
10-scheduled-workflow/
├── database.php           # 資料庫共用函數
├── cron-morning.php       # 早上 8:00 - 研究關鍵字
├── cron-afternoon.php     # 下午 2:00 - 撰寫文章
├── cron-midnight.php      # 凌晨 0:00 - 發布並分析績效
├── human-review.php       # 人工審核介面（隨時可執行）
├── demo.php               # 互動測試工具
└── database.sqlite        # SQLite 資料庫（自動生成）
```

## 使用方式

### 1. 互動測試（推薦新手）

執行 demo 工具，手動觸發各階段：

```bash
php demo.php
```

選單操作：
1. 執行 Morning Cron (研究關鍵字)
2. 執行 Afternoon Cron (撰寫文章)
3. 進入 Human Review 模式 (審核文章)
4. 執行 Midnight Cron (分析績效)
5. 顯示所有文章狀態
6. 顯示執行日誌

### 2. 手動執行 Cron

分別執行各個 cron 腳本：

```bash
# 研究關鍵字
php cron-morning.php

# 撰寫文章
php cron-afternoon.php

# 審核文章
php human-review.php

# 發布並分析
php cron-midnight.php
```

### 3. 設定系統 Cron（生產環境）

編輯 crontab：

```bash
crontab -e
```

加入以下設定：

```cron
# Pattern 10: Scheduled Workflow
# 路徑請替換為實際路徑

# 每天早上 8:00 研究關鍵字
0 8 * * * cd /path/to/ai-agent-pattern-notes && php patterns/10-scheduled-workflow/cron-morning.php >> /var/log/cron-morning.log 2>&1

# 每天下午 2:00 撰寫文章
0 14 * * * cd /path/to/ai-agent-pattern-notes && php patterns/10-scheduled-workflow/cron-afternoon.php >> /var/log/cron-afternoon.log 2>&1

# 每天凌晨 0:00 發布並分析績效
0 0 * * * cd /path/to/ai-agent-pattern-notes && php patterns/10-scheduled-workflow/cron-midnight.php >> /var/log/cron-midnight.log 2>&1
```

## 完整測試流程

### 方法 A：使用 Demo 工具（最簡單）

```bash
php demo.php
```

1. 選擇 `1` → 執行 Morning Cron（AI 研究關鍵字）
2. 選擇 `5` → 確認文章狀態變為 `pending_write`
3. 選擇 `2` → 執行 Afternoon Cron（AI 撰寫文章）
4. 選擇 `5` → 確認文章狀態變為 `pending_review`
5. 選擇 `3` → 進入審核模式
   - 輸入 `list` 查看待審文章
   - 輸入 `view 1` 查看完整內容
   - 輸入 `approve 1` 批准文章
   - 輸入 `exit` 離開審核模式
6. 選擇 `4` → 執行 Midnight Cron（發布並分析）
7. 選擇 `5` → 確認文章狀態變為 `published`，並有績效資料

### 方法 B：分別執行腳本

```bash
# Step 1: 研究關鍵字
php cron-morning.php
# 預期：建立文章，狀態 pending_write

# Step 2: 撰寫文章
php cron-afternoon.php
# 預期：生成標題和內容，狀態 pending_review

# Step 3: 審核文章
php human-review.php
# 輸入 list, view 1, approve 1, exit

# Step 4: 發布並分析
php cron-midnight.php
# 預期：Mock 績效資料，AI 分析，狀態 published
```

## 重要概念

### 1. 狀態持久化

每個 cron 是**獨立的 PHP process**，生命週期如下：

```
cron-morning.php 啟動
  ↓ 連接資料庫
  ↓ 讀取文章狀態
  ↓ 呼叫 OpenAI API
  ↓ 更新資料庫
  ↓ 結束 (process 終止)

[等待數小時]

cron-afternoon.php 啟動
  ↓ 連接資料庫（全新的 process）
  ↓ 讀取文章狀態（從上一個 cron 留下的狀態）
  ↓ ...
```

**關鍵**：狀態必須存在資料庫，PHP 記憶體中的變數無法跨 process。

### 2. Human-in-the-loop

AI 完成草稿後，不會立即發布，而是：

1. 狀態變為 `pending_review`
2. Afternoon Cron 結束
3. 人類在方便時執行 `human-review.php`
4. 審核通過後狀態變為 `approved`
5. Midnight Cron 才會發布

這確保了**人工控制品質**，避免 AI 自動發布不適當內容。

### 3. Idempotency（冪等性）

如果 cron 重複執行，不應該產生重複操作：

```php
// ✅ Good: 只處理第一筆 pending_write
$articles = getArticlesByStatus($db, 'pending_write');
if (empty($articles)) {
    exit(0);  // 沒有待處理文章，優雅結束
}
$article = $articles[0];  // 只處理一篇

// ❌ Bad: 每次都建立新文章
$articleId = createArticle($db);
```

### 4. 錯誤處理

所有 cron 都記錄執行日誌：

```php
try {
    // 執行任務
    logExecution($db, 'cron-morning', $articleId, 'success', '完成');
} catch (Exception $e) {
    logExecution($db, 'cron-morning', $articleId, 'error', $e->getMessage());
    exit(1);  // 非零 exit code 表示失敗
}
```

## 與其他 Patterns 的關係

| Pattern | 關聯 | 說明 |
|---------|------|------|
| **Pattern 04** | 借用 | 資料庫持久化的實作方式 |
| **Pattern 02** | 借用 | Human-in-the-loop 的審核機制 |
| **Pattern 09** | 對比 | Graph engine 適用於「流程是資料」，這裡「流程是程式碼」 |
| **Pattern 11** | 對比 | Pattern 11 用 LLM 決策，這裡用固定時間觸發 |

## 實際應用場景

### 1. 內容自動化
- 社群媒體定時發文
- 部落格文章生產流水線
- Email 電子報自動撰寫

### 2. 定期報告
- 每週業務報告生成
- 月度數據分析摘要
- 定期競品監控報告

### 3. 數據處理
- 每日數據清理和標註
- 定期模型訓練和評估
- 夜間批次處理任務

### 4. 監控和提醒
- 定期檢查系統健康度
- 異常檢測和警報
- 自動生成運維建議

## 生產環境注意事項

### 1. 資料庫升級

POC 使用 SQLite，生產環境應升級為：
- **MySQL** 或 **PostgreSQL**（支援併發）
- 加入 index 優化查詢速度
- 考慮使用 connection pool

### 2. 錯誤通知

Cron 失敗時應主動通知：
```php
try {
    // ...
} catch (Exception $e) {
    sendAlert('cron-morning 失敗: ' . $e->getMessage());
    logExecution($db, 'cron-morning', null, 'error', $e->getMessage());
}
```

### 3. 併發控制

如果 cron 可能重疊執行，需要加鎖：
```php
// 使用檔案鎖或資料庫鎖
$lock = flock($fp, LOCK_EX | LOCK_NB);
if (!$lock) {
    exit(0);  // 已有其他 process 在執行
}
```

### 4. 監控儀表板

建議建立 Web 介面顯示：
- 各文章的當前狀態
- 最近執行日誌
- 平均處理時間
- 失敗率統計

## POC 限制與擴充方向

### 當前 POC 限制

- ✅ 使用 Mock 績效資料（可替換為 WordPress API）
- ✅ 純 AI 關鍵字研究（可整合 SEO 工具 API）
- ✅ CLI 審核介面（可改為 Web UI）
- ✅ SQLite 資料庫（生產環境建議 MySQL/PostgreSQL）

### 實際上線：如何整合外部 API

**關鍵概念**：Pattern 10 的流程是**程式碼寫死**的，API 呼叫直接寫在 PHP 裡，不透過 Tool Functions。

#### 1. Morning Cron - 整合 SEO API（關鍵字研究）

**目前 POC**：純 AI 憑空想像關鍵字
```php
// cron-morning.php (目前)
$response = $client->chat()->create([
    'messages' => [
        ['role' => 'user', 'content' => '請研究熱門關鍵字...']
    ]
]);
```

**實際上線**：先呼叫 SEO API，再讓 AI 分析
```php
// cron-morning.php (實際上線)

// Step 1: 程式碼直接呼叫 SEO API 取得真實數據
$seoData = callGoogleTrendsAPI([
    'category' => 'Technology',
    'timeframe' => 'now 7-d',
    'geo' => 'US'
]);
// 或使用 Ahrefs API
$ahrefsData = callAhrefsAPI('trending-keywords', [
    'country' => 'us',
    'limit' => 10
]);

// Step 2: 將真實數據提供給 AI 分析
$response = $client->chat()->create([
    'model' => 'gpt-4o-mini',
    'messages' => [
        [
            'role' => 'system',
            'content' => '你是 SEO 專家，請分析真實的 SEO 數據並推薦最佳關鍵字。'
        ],
        [
            'role' => 'user',
            'content' => "Google Trends 數據：\n" . json_encode($seoData) . "\n\n" .
                        "Ahrefs 數據：\n" . json_encode($ahrefsData) . "\n\n" .
                        "請從這些真實數據中選出 3-5 個最值得撰寫的關鍵字，以 JSON 格式回覆..."
        ]
    ],
]);

// Google Trends API 範例
function callGoogleTrendsAPI(array $params): array {
    // 使用 Google Trends API 或第三方包裝套件
    // 例如：https://github.com/dotzero/py-googletrendsapi
    $url = 'https://trends.google.com/trends/api/explore?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_ENV['GOOGLE_API_KEY']
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Ahrefs API 範例
function callAhrefsAPI(string $endpoint, array $params): array {
    $url = "https://api.ahrefs.com/v3/{$endpoint}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_ENV['AHREFS_API_TOKEN'],
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}
```

#### 2. Midnight Cron - 整合 WordPress & Analytics API

**目前 POC**：Mock 假數據
```php
// cron-midnight.php (目前)
$performanceData = generateMockPerformance(); // 假數據
```

**實際上線**：真正發布並讀取績效
```php
// cron-midnight.php (實際上線)

// Step 1: 程式碼直接呼叫 WordPress API 發布文章
$postId = publishToWordPress($article['title'], $article['content']);

echo "✓ 已發布至 WordPress，文章 ID: {$postId}" . PHP_EOL;

// Step 2: 等待一段時間（或次日再分析）後抓取真實績效
sleep(3600); // 等 1 小時讓數據累積（實際可能是隔天執行）

// Step 3: 程式碼直接呼叫 Google Analytics API
$performanceData = getGoogleAnalytics($postId);

// Step 4: 讓 AI 分析真實績效
$response = $client->chat()->create([
    'messages' => [
        [
            'role' => 'user',
            'content' => "真實績效數據：\n" . json_encode($performanceData) . "\n\n請分析..."
        ]
    ]
]);

// WordPress REST API 發布
function publishToWordPress(string $title, string $content): int {
    $url = $_ENV['WORDPRESS_URL'] . '/wp-json/wp/v2/posts';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'title' => $title,
        'content' => $content,
        'status' => 'publish',  // 或 'draft' 再手動發布
        'categories' => [1],    // 分類 ID
        'tags' => [5, 8]        // 標籤 ID
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_ENV['WORDPRESS_TOKEN'],
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        throw new Exception("WordPress 發布失敗: {$response}");
    }

    $result = json_decode($response, true);
    return $result['id'];
}

// Google Analytics API v4 查詢
function getGoogleAnalytics(int $postId): array {
    // 使用 Google Analytics Data API v1
    $client = new Google\Client();
    $client->setAuthConfig($_ENV['GOOGLE_ANALYTICS_CREDENTIALS']);
    $client->addScope(Google\Service\AnalyticsData::ANALYTICS_READONLY);

    $analytics = new Google\Service\AnalyticsData($client);

    $response = $analytics->properties->runReport([
        'property' => 'properties/' . $_ENV['GA4_PROPERTY_ID'],
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'pagePath']],
        'metrics' => [
            ['name' => 'screenPageViews'],
            ['name' => 'userEngagementDuration'],
            ['name' => 'bounceRate']
        ],
        'dimensionFilter' => [
            'filter' => [
                'fieldName' => 'pagePath',
                'stringFilter' => [
                    'value' => "/post-{$postId}/",
                    'matchType' => 'CONTAINS'
                ]
            ]
        ]
    ]);

    // 解析回應
    return [
        'views' => $response->rows[0]->metricValues[0]->value ?? 0,
        'avg_time_on_page' => $response->rows[0]->metricValues[1]->value ?? 0,
        'bounce_rate' => $response->rows[0]->metricValues[2]->value ?? 0,
    ];
}
```

#### 3. 其他常見 API 整合

**Slack 通知**（審核提醒、錯誤警報）
```php
function sendSlackNotification(string $message): void {
    $ch = curl_init($_ENV['SLACK_WEBHOOK_URL']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'text' => $message,
        'username' => 'Blog Automation Bot',
        'icon_emoji' => ':robot_face:'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    curl_exec($ch);
    curl_close($ch);
}

// 在 cron-afternoon.php 完成後通知
updateArticleStatus($db, $articleId, 'pending_review');
sendSlackNotification("新文章待審核：{$article['title']}\n查看：php human-review.php");
```

**SEMrush API**（關鍵字難度、搜尋量）
```php
function getSEMrushKeywordData(string $keyword): array {
    $url = 'https://api.semrush.com/';
    $params = [
        'type' => 'phrase_this',
        'key' => $_ENV['SEMRUSH_API_KEY'],
        'phrase' => $keyword,
        'database' => 'us',
        'export_columns' => 'Ph,Nq,Cp,Co,Nr'
    ];

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    // 解析 CSV 回應
    $lines = explode("\n", trim($response));
    $data = str_getcsv($lines[1], ';');

    return [
        'keyword' => $data[0],
        'search_volume' => (int)$data[1],
        'cpc' => (float)$data[2],
        'competition' => (float)$data[3],
        'results' => (int)$data[4]
    ];
}
```

### 重要差異：Pattern 10 vs Pattern 11

| | Pattern 10 | Pattern 11 |
|---|---|---|
| **API 呼叫方式** | 程式碼直接寫 `callAPI()` | 透過 Tool Functions |
| **誰決定流程** | 程式碼寫死（cron 時間決定） | LLM 自主判斷 |
| **適用場景** | 固定時間、固定流程 | 需要判斷「現在該做什麼」 |
| **實作複雜度** | 簡單 | 需要 Dynamic Routing |

**Pattern 10 範例**：
```php
// 固定流程，程式碼決定一切
$seoData = callAPI();        // ← 一定會執行
$result = askAI($seoData);   // ← AI 只負責分析
saveResults($result);        // ← 一定會執行
```

**Pattern 11 範例**：
```php
// LLM 決定要不要呼叫 API
$result = askAI('現在該做什麼？', $tools); // ← AI 自己選
if ($result->tool_calls[0]->name === 'call_seo_api') {
    // AI 決定要呼叫
    $data = callAPI();
}
```

### 其他擴充方向

1. **Web 審核介面**（使用 Laravel、Symfony 等框架）
2. **通知系統**（Email、Slack、Discord webhook）
3. **A/B 測試**（讓 AI 生成多個版本，測試哪個表現更好）
4. **自動圖片生成**（整合 DALL-E、Midjourney API）
5. **多語言版本**（自動翻譯文章到其他語言）

## 總結

**Scheduled Workflow Pattern** 是用排程協調 AI 多階段任務的有效方式。

適用於：
- ✅ 需要在特定時間執行的任務
- ✅ 需要等待人工介入的流程
- ✅ 長時間運作的工作流程
- ✅ 多個獨立 process 共享狀態

不適用於：
- ❌ 即時對話式互動
- ❌ 需要立即回應的場景
- ❌ UI 驅動的工作流程（考慮 Pattern 09 graph engine）

與 **Pattern 11 (Autonomous Agent)** 的差異：
- **Pattern 10**：固定時間觸發，流程寫死在程式碼
- **Pattern 11**：LLM 自主決策「現在該做什麼」，更靈活但更複雜

兩者各有適用場景，Pattern 10 更簡單穩定，Pattern 11 更智能靈活。
