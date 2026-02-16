# Pattern 11: Autonomous Agent

## 概念說明

**Autonomous Agent** 是一種讓 AI 自主決策「現在該做什麼」的設計模式。

與 Pattern 10 (Scheduled Workflow) 的差異：
- **Pattern 10**：固定時間做固定事情（早上研究、下午撰寫、半夜發布）
- **Pattern 11**：定時執行，但讓 AI 自己看狀態決定要做什麼

## 為什麼需要這個 Pattern？

### Pattern 10 的限制

Pattern 10 的固定流程在某些情況下不夠靈活：

```
8:00  → 研究關鍵字 （即使已經有 5 篇待寫也會繼續研究）
14:00 → 寫文章     （如果文章品質不佳，明天才能修改）
20:00 → 人工審核   （必須準時審核）
00:00 → 發布分析   （固定時間）
```

**問題**：
- ❌ 無法根據實際狀況調整
- ❌ 如果某個步驟出錯，要等到下一個時段才能處理
- ❌ AI 無法自我評估品質並改進
- ❌ 不符合「AI employee」的自主性要求

### Pattern 11 的優勢

```
每小時執行一次 agent.php，但讓 AI 自己決定：

- 如果沒有待研究文章 → 研究新關鍵字
- 如果有待寫文章 → 撰寫文章
- 如果有待審文章 → 自我評估品質
  ├─ 品質好 → 提交人工審核
  └─ 品質差 → 退回重寫（不浪費人類時間）
- 如果有已批准文章 → 發布並分析
- 如果什麼都不需要做 → 等待
```

**好處**：
- ✅ AI 自己判斷優先順序
- ✅ 可以立即修正錯誤（不用等明天）
- ✅ 自我品質把關（降低人工審核負擔）
- ✅ 更接近「AI employee」概念

## 核心技術

### 1. Tool Functions

不是程式碼直接呼叫 API，而是定義「工具」讓 AI 選擇：

```php
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'research_keywords',
            'description' => '研究熱門關鍵字並建立新文章企劃',
            'parameters' => [...]
        ]
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'write_article',
            'description' => '為已有關鍵字的文章撰寫完整內容',
            'parameters' => [...]
        ]
    ],
    // ...
];

$response = $client->chat()->create([
    'messages' => [...],
    'tools' => $tools,  // ← AI 自己選擇要呼叫哪個
    'tool_choice' => 'auto'
]);

// AI 決定：「我要呼叫 write_article」
if ($response->toolCalls) {
    $functionName = $response->toolCalls[0]->function->name;
    executeToolFunction($functionName, $arguments);
}
```

### 2. Dynamic Routing

根據當前系統狀態，決定提供哪些工具（Pattern 06 的應用）：

```php
function getAvailableTools(array $systemState): array {
    $tools = [];
    $counts = $systemState['counts'];

    // 如果待研究文章少於 2 篇 → 提供「研究關鍵字」工具
    if ($counts['pending_research'] < 2) {
        $tools[] = getToolDefinition('research_keywords');
    }

    // 如果有待撰寫文章 → 提供「撰寫文章」工具
    if ($counts['pending_write'] > 0) {
        $tools[] = getToolDefinition('write_article');
    }

    // 根據狀態動態調整...

    return $tools;
}
```

**好處**：
- 減少 token 使用（不給 AI 無關的工具）
- 提高決策品質（選項越少越容易選對）
- 防止無效操作（不會在沒有文章時嘗試發布）

### 3. 自我品質評估

AI 完成文章後，會先自我審查品質：

```php
$response = $client->chat()->create([
    'messages' => [
        ['role' => 'system', 'content' => '你是嚴格的品質審查員...'],
        ['role' => 'user', 'content' => "評估這篇文章：\n{$content}"]
    ]
]);

$review = extractJson($response->content);

if ($review['score'] >= 7 && $review['verdict'] === 'approved') {
    // 品質通過 → 提交人工審核
    updateArticleStatus($db, $articleId, 'pending_review');
} else {
    // 品質不足 → 退回重寫
    updateArticleStatus($db, $articleId, 'pending_write');
    incrementRevisionCount($db, $articleId);
}
```

**好處**：
- 人類只需審核「AI 認為品質好」的文章
- 降低人工審核負擔
- AI 可以自我改進

## 架構設計

### 文章狀態流轉

```
pending_research → pending_write → pending_review → approved → published
       ↑               ↑                  ↓
       |               |            (品質不足)
       |               └──────────────────┘
       |
   (AI 決定需要更多文章)
```

特殊流程：
- **自我審查迴圈**：`pending_review` → (品質不足) → `pending_write` → (重寫) → `pending_review`
- **拒絕**：`pending_review` → (人工拒絕) → `rejected`

### 檔案結構

```
11-autonomous-agent/
├── database.php          # 資料庫共用函數
├── agent.php             # 核心：自主決策引擎
├── human-review.php      # 人工審核介面
├── demo.php              # 互動測試工具
└── database.sqlite       # SQLite 資料庫（自動生成）
```

### Agent 執行流程

```
1. 讀取系統當前狀態
   ├─ 各狀態文章數量
   ├─ 最近文章資訊
   └─ 最近決策歷史

2. Dynamic Routing - 決定可用工具
   └─ 根據狀態篩選合適的 Tool Functions

3. LLM 分析與決策
   ├─ 提供當前狀態
   ├─ 提供可用工具
   └─ LLM 選擇動作並說明理由

4. 執行選擇的動作
   ├─ research_keywords
   ├─ write_article
   ├─ self_review_quality
   ├─ publish_and_analyze
   └─ wait

5. 記錄決策與結果
   ├─ agent_decisions 表
   └─ execution_logs 表
```

## 使用方式

### 1. 互動測試（推薦）

執行 demo 工具：

```bash
cd patterns/11-autonomous-agent
php demo.php
```

選單操作：
1. **執行 Agent** - 讓 AI 自主決策一次
2. **多次執行 Agent** - 模擬連續運作（建議 3-5 次）
3. **Human Review** - 人工審核 AI 認為品質夠好的文章
4. **顯示所有文章狀態** - 查看當前進度
5. **顯示 Agent 決策歷史** - 了解 AI 的思考過程

### 2. 完整測試流程

**方法 A：多次執行 Agent（觀察自主行為）**

```bash
php demo.php
```

1. 選擇 `2` → 多次執行 Agent（輸入 5）
   - 觀察 AI 的決策過程
   - 第 1 次：可能研究關鍵字
   - 第 2 次：可能撰寫文章
   - 第 3 次：可能自我審查品質
   - 第 4-5 次：可能等待或繼續處理其他文章

2. 選擇 `5` → 查看 Agent 決策歷史
   - 看 AI 每次的思考理由
   - 理解為什麼選擇這個動作

3. 選擇 `4` → 查看文章狀態
   - 確認有文章進入 `pending_review`

4. 選擇 `3` → 進入人工審核
   - 輸入 `list` 查看待審文章
   - 輸入 `view 1` 查看內容
   - 輸入 `approve 1` 批准
   - 輸入 `exit` 離開

5. 再次選擇 `1` → 執行 Agent
   - AI 會自動發布並分析已批准的文章

**方法 B：手動執行 agent.php**

```bash
# 第一次執行 - AI 可能決定研究關鍵字
php agent.php

# 第二次執行 - AI 可能決定撰寫文章
php agent.php

# 第三次執行 - AI 可能決定自我審查
php agent.php

# 人工審核
php human-review.php
# 輸入 list, approve 1, exit

# 再次執行 - AI 發布文章
php agent.php
```

### 3. 設定系統 Cron（生產環境）

```bash
crontab -e
```

```cron
# Pattern 11: Autonomous Agent
# 每小時執行一次，讓 AI 自主決策

0 * * * * cd /path/to/ai-agent-pattern-notes && php patterns/11-autonomous-agent/agent.php >> /var/log/agent.log 2>&1
```

**注意**：
- Agent 每次只執行「一個」動作
- 頻繁執行（如每小時）可以讓 AI 更快完成整個流程
- 人工審核仍需手動執行 `human-review.php`

## 可用的 Tool Functions

### 1. research_keywords
研究熱門關鍵字並建立新文章企劃。

**觸發條件**：`pending_research < 2`（避免累積太多待處理文章）

**執行結果**：建立新文章，狀態 `pending_write`

### 2. write_article
撰寫文章完整內容。

**觸發條件**：有 `pending_write` 狀態的文章

**執行結果**：文章完成，狀態 `pending_review`

### 3. self_review_quality
自我審查文章品質。

**觸發條件**：有 `pending_review` 狀態的文章

**執行結果**：
- 品質好（≥7分）→ 保持 `pending_review`，等待人工批准
- 品質差（<7分）→ 退回 `pending_write`，下次重寫

### 4. publish_and_analyze
發布文章並分析績效。

**觸發條件**：有 `approved` 狀態的文章

**執行結果**：文章發布，狀態 `published`

### 5. get_article_details
查看文章詳細資訊（始終可用）。

### 6. wait
當前沒有需要執行的動作。

**觸發條件**：沒有其他可用工具時

## Pattern 10 vs Pattern 11 對比

| 特性 | Pattern 10<br/>Scheduled Workflow | Pattern 11<br/>Autonomous Agent |
|------|----------------------------------|--------------------------------|
| **流程控制** | 固定時間，固定動作 | AI 自主決策 |
| **API 呼叫** | 程式碼直接 `callAPI()` | Tool Functions |
| **品質控制** | 全部送人工審核 | AI 先自我審查 |
| **錯誤修正** | 等下一個時段 | 立即重試 |
| **工作負載** | 固定產出速度 | 根據需求調整 |
| **人工介入** | 固定時間審核 | 彈性審核 |
| **適用場景** | 固定流程、可預測 | 需要判斷、靈活應變 |
| **複雜度** | 簡單 | 中等 |
| **Token 使用** | 較少 | 較多（需決策） |

### 選擇指南

**使用 Pattern 10 當**：
- ✅ 流程固定且不需改變
- ✅ 每個步驟都必須執行
- ✅ 追求簡單和成本效益
- ✅ 時間點很重要（如定時發布）

**使用 Pattern 11 當**：
- ✅ 需要 AI 自主判斷優先順序
- ✅ 流程可能因狀況而異
- ✅ 希望 AI 自我品質把關
- ✅ 追求「AI employee」的自主性
- ✅ 願意接受較高的 API 成本

## 重要概念

### 1. 決策透明度

Pattern 11 會記錄每次 AI 的決策：

```php
logAgentDecision(
    $db,
    $systemState,          // 當時的系統狀態
    $availableActions,      // 可用的動作
    $chosenAction,          // 選擇的動作
    $reasoning              // 決策理由
);
```

查看決策歷史：
```bash
php demo.php
# 選擇 5 - 顯示 Agent 決策歷史
```

### 2. 自我改進能力

AI 可以從績效數據中學習：

```php
// agent.php 中 AI 的 system prompt
"重要原則：
1. 品質優先：寧可多花時間重寫，也不要送出品質不佳的文章
2. 避免過載：不要一次建立太多待處理文章
3. 學習改進：從已發布文章的績效中學習，調整未來的策略"
```

未來可以擴展：
- 分析哪些關鍵字表現好→優先選擇類似主題
- 追蹤哪種寫作風格互動率高→調整風格
- 記錄人工拒絕的原因→避免類似錯誤

### 3. Idempotency（冪等性）

Agent 多次執行相同狀態，不應產生重複操作：

```php
// ✅ Good: 只處理一篇文章
$articles = getArticlesByStatus($db, 'pending_write');
if (!empty($articles)) {
    $article = $articles[0];  // 只處理第一篇
    // ...
}

// Dynamic Routing 也確保不會提供無效工具
if ($counts['pending_write'] > 0) {
    $tools[] = getToolDefinition('write_article');
}
```

## 實際應用場景

### 1. 內容自動化
- **Pattern 11 優勢**：AI 根據當前內容庫自動調整產出速度
- 範例：庫存充足時減緩產出，不足時加速

### 2. AI 客服助理
- **Pattern 11 優勢**：根據當前工單狀態決定處理順序
- 範例：優先處理緊急工單，閒置時整理知識庫

### 3. 數據分析報告
- **Pattern 11 優勢**：AI 判斷哪些數據需要深入分析
- 範例：發現異常時自動生成專門報告

### 4. 社群媒體管理
- **Pattern 11 優勢**：根據互動率調整發文策略
- 範例：某類內容表現好時增加同類發文

## 生產環境注意事項

### 1. 成本控制

Pattern 11 的 Token 使用量較高：
- 每次執行都需要讓 LLM 決策
- 需要傳遞系統狀態資訊
- 自我審查需要額外 API 呼叫

**建議**：
- 使用較便宜的模型（如 `gpt-4o-mini`）
- 控制執行頻率（每小時而非每分鐘）
- 監控 API 使用量

### 2. 決策品質監控

定期檢查 AI 的決策是否合理：

```bash
php demo.php
# 選擇 5 - 顯示 Agent 決策歷史
```

如果發現不合理決策：
- 調整 System Prompt
- 調整 Dynamic Routing 規則
- 提供更多 context 資訊

### 3. 人工最終把關

雖然有自我審查，但仍需人工批准：

```php
// AI 自我審查通過後，狀態保持 pending_review
// 不會自動變為 approved
updateArticleStatus($db, $articleId, 'pending_review');  // 等待人工

// 人工批准才會變為 approved
// php human-review.php → approve [id]
```

### 4. 錯誤處理

Agent 失敗時應有通知機制：

```php
try {
    // Agent 執行
} catch (Exception $e) {
    logExecution($db, 'agent', null, 'error', $e->getMessage());
    sendAlert("Agent 執行失敗: " . $e->getMessage());  // Email/Slack
    exit(1);
}
```

## 擴充方向

### 1. 多 Agent 協作
不同 Agent 負責不同任務：
- Research Agent：專門研究關鍵字
- Writing Agent：專門撰寫文章
- QA Agent：專門品質審查

### 2. 學習迴圈
從績效數據中學習並調整策略：
```php
$performanceHistory = getRecentPublishedArticles($db, 10);
$insights = analyzePerformanceTrends($performanceHistory);
// 將 insights 加入 System Prompt
```

### 3. 優先級系統
讓 AI 根據重要性排序任務：
```php
$urgentArticles = getArticlesByPriority($db, 'high');
$regularArticles = getArticlesByPriority($db, 'normal');
// AI 優先處理 urgent 文章
```

### 4. 人類反饋整合
收集人工審核的反饋並學習：
```php
// 人工拒絕時記錄原因
$feedback = ['reason' => '語氣太正式', 'suggestions' => '使用更口語化的表達'];
// 下次撰寫時參考這些反饋
```

## 總結

**Pattern 11: Autonomous Agent** 是讓 AI 自主決策的強大模式。

核心優勢：
- 🤖 **自主性**：AI 自己判斷該做什麼
- 🔧 **靈活性**：根據狀況調整策略
- 📊 **品質控制**：自我審查降低人工負擔
- 🔄 **自我改進**：從數據中學習

適用場景：
- ✅ 需要判斷和決策的工作流程
- ✅ 希望 AI 像「員工」一樣自主工作
- ✅ 流程複雜且經常變化
- ✅ 願意投資更多 API 成本

與 Pattern 10 互補：
- **Pattern 10**：簡單、固定、成本低
- **Pattern 11**：智能、靈活、成本較高

選擇哪個取決於你的需求和預算！
