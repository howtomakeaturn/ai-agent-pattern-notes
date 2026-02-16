# Pattern 12: Rule-Based Agent（基於規則的 Agent）

## 概念

Pattern 12 展示了一個**基於固定規則**的 Agent 系統，與 Pattern 11 的 LLM 決策形成鮮明對比。

**核心設計**：使用程式碼中的固定優先級規則來決定每次執行的動作，而不是讓 LLM 分析狀態並做決策。

```php
// Pattern 12 的核心邏輯
if ($counts['approved'] > 0) {
    publishArticle();      // 優先級 1
} elseif ($counts['pending_review'] > 0) {
    reviewArticle();       // 優先級 2
} elseif ($counts['pending_write'] > 0) {
    writeArticle();        // 優先級 3
} elseif ($counts['pending_research'] < 2) {
    researchKeywords();    // 優先級 4
} else {
    wait();                // 無需執行
}
```

## 為什麼需要 Pattern 12？

### Pattern 11 的問題（在簡單場景下）

雖然 Pattern 11 的 LLM 決策很強大，但在**固定工作流程**中可能是過度設計：

1. **成本過高** 💰
   - 每次執行需要 2 次 API 調用（決策 + 執行動作）
   - Pattern 11：~$0.002/次
   - Pattern 12：~$0.001/次（省 50%）

2. **速度較慢** 🐌
   - LLM 決策需要額外 1-2 秒
   - 簡單規則判斷只需幾毫秒

3. **不確定性** 🎲
   - LLM 可能會做出意外決策
   - 生產環境需要可預測的行為

4. **過度複雜** 🤯
   - 如果優先級是固定的，為什麼要讓 AI 判斷？
   - 簡單的事情應該簡單做

### Pattern 12 的優勢

✅ **成本低** - 省下決策的 API 調用
✅ **速度快** - 沒有 LLM 延遲
✅ **可預測** - 行為完全確定
✅ **易理解** - 規則清晰明確
✅ **易維護** - 修改規則很直接

## 核心技術

### 1. 固定優先級規則

```php
function determineAction(array $counts): array {
    // 優先級 1：發布（最高優先級，快速產出）
    if ($counts['approved'] > 0) {
        return ['name' => 'publish', 'reason' => '...'];
    }

    // 優先級 2：審查（確保品質）
    if ($counts['pending_review'] > 0) {
        return ['name' => 'review', 'reason' => '...'];
    }

    // 優先級 3：撰寫（完成內容創作）
    if ($counts['pending_write'] > 0) {
        return ['name' => 'write', 'reason' => '...'];
    }

    // 優先級 4：研究（保持內容管道）
    if ($counts['pending_research'] < 2) {
        return ['name' => 'research', 'reason' => '...'];
    }

    // 無需執行
    return ['name' => 'wait', 'reason' => '...'];
}
```

**關鍵特點**：
- if-else 順序 = 優先級順序
- 完全確定性，無隨機或判斷
- 每次執行最多一個動作

### 2. 取得最舊的文章

Pattern 12 遵循 **FIFO (First In, First Out)** 原則：

```php
function getOldestArticleByStatus(PDO $db, string $status): ?array {
    $stmt = $db->prepare("
        SELECT * FROM articles
        WHERE status = ?
        ORDER BY updated_at ASC  -- 最舊的優先
        LIMIT 1
    ");
    $stmt->execute([$status]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

**為什麼用最舊的？**
- 防止某篇文章一直被跳過
- 確保公平處理所有文章
- 類似佇列 (Queue) 的概念

### 3. 執行流程

```
讀取系統狀態
    ↓
應用固定規則（if-else）
    ↓
取得目標文章（最舊的）
    ↓
執行動作（1 次 API 調用）
    ↓
記錄執行日誌
```

**對比 Pattern 11**：
```
讀取系統狀態
    ↓
Dynamic Routing（根據狀態提供工具）
    ↓
LLM 決策（1 次 API 調用）← 多了這步！
    ↓
執行動作（1 次 API 調用）
    ↓
記錄決策 + 執行日誌
```

## 架構說明

```
patterns/12-rule-based-agent/
├── database.php       # 複用 Pattern 11 的資料庫函數
├── agent.php          # 核心：固定規則決策引擎
├── human-review.php   # 複用 Pattern 11 的人工審核
├── demo.php           # 測試工具（含 P11 vs P12 比較）
└── README.md          # 本文件
```

### 與 Pattern 11 共享的資源

Pattern 12 **複用** Pattern 11 的：
- 資料庫結構（articles, execution_logs, agent_decisions）
- 所有資料庫函數（initDatabase, getSystemState, etc.）
- 人工審核介面（human-review.php）

**為什麼可以共享？**
- 資料結構完全相同
- 只有決策邏輯不同
- 減少程式碼重複

## 使用方式

### 1. 環境設定

確保已設定 `.env`：
```bash
OPENAI_API_KEY=sk-...
```

### 2. 執行 Demo

```bash
cd patterns/12-rule-based-agent
php demo.php
```

**互動選單**：
```
1. 執行 Agent（一次）         # 測試單次執行
2. 執行 Agent（多次）         # 模擬連續運作
3. 人工審核文章              # 批准/拒絕文章
4. 顯示所有文章              # 查看狀態
5. 顯示執行日誌              # 查看歷史
6. 重置資料庫                # 清空重來
7. 與 Pattern 11 比較        # 詳細對比表
0. 離開
```

### 3. 測試建議流程

**第一次測試**：
1. 選擇 `2`，輸入 **5**（執行 5 次）
2. 觀察固定優先級規則的執行順序
3. 選擇 `4` 查看文章狀態
4. 選擇 `3` 進行人工審核
5. 再執行 Agent 看發布流程

**比較測試**：
1. 在 Pattern 12 執行 5 次
2. 在 Pattern 11 執行 5 次
3. 選擇 `7` 查看詳細對比
4. 比較執行速度和決策過程

### 4. 生產環境部署

設定 Cron（每小時執行）：
```bash
0 * * * * cd /path/to/project && php patterns/12-rule-based-agent/agent.php >> /var/log/agent.log 2>&1
```

## Pattern 11 vs Pattern 12 完整對比

| 項目 | Pattern 11<br/>LLM 決策 | Pattern 12<br/>固定規則 | 推薦場景 |
|------|------------------------|------------------------|---------|
| **決策方式** | LLM 分析狀態並選擇動作 | if-else 固定優先級 | P12: 簡單工作流<br/>P11: 複雜判斷 |
| **API 調用次數** | 2+ 次/執行 | 1 次/執行 | P12: 成本敏感 |
| **成本估算** | ~$0.002/次 | ~$0.001/次（省 50%） | P12: 高頻執行 |
| **執行速度** | 2-3 秒 | 1-2 秒 | P12: 需要快速回應 |
| **可預測性** | 低（AI 可能變化） | 高（完全確定） | P12: 生產環境 |
| **靈活性** | 高（動態調整優先級） | 低（固定規則） | P11: 需要判斷權衡 |
| **決策透明性** | agent_decisions 表記錄 AI 推理 | 執行日誌（規則固定） | P11: 需要審計追蹤 |
| **錯誤風險** | 可能做出意外決策 | 按固定邏輯執行 | P12: 低容錯 |
| **擴展性** | 易於加入新動作/邏輯 | 需修改 if-else | P11: 頻繁變更需求 |
| **學習成本** | 需理解 Tool Functions, Dynamic Routing | 只需理解 if-else | P12: 團隊新手多 |
| **除錯難度** | AI 行為需分析決策記錄 | 程式碼邏輯直接可見 | P12: 快速定位問題 |
| **適合場景** | • 複雜工作流<br/>• 需要 AI 判斷<br/>• 優先級動態變化<br/>• 實驗性專案 | • 簡單固定流程<br/>• 優先級明確<br/>• 成本敏感<br/>• 生產環境 | 視需求而定 |

## 詳細決策邏輯

### 優先級設計理由

**為什麼是這個順序？**

1. **發布（approved）- 最高優先級**
   - 理由：快速產出，避免積壓已完成的文章
   - 情境：文章已通過審核，應立即發布產生價值
   - 耗時：1-2 秒（API 分析績效）

2. **審查（pending_review）**
   - 理由：確保品質，防止低品質文章送審
   - 情境：文章已寫完，需要 AI 自我審查
   - 耗時：2-3 秒（AI 評分）

3. **撰寫（pending_write）**
   - 理由：完成內容創作，推進流程
   - 情境：有關鍵字但沒內容的文章
   - 耗時：3-5 秒（生成 800-1200 字）

4. **研究（pending_research < 2）**
   - 理由：保持內容管道，但不要過度創建
   - 情境：待處理文章少於 2 篇時補充
   - 耗時：2-3 秒（研究關鍵字）

5. **等待** - 最低優先級
   - 理由：系統健康，無需執行
   - 情境：所有數量都在合理範圍內

### 為什麼不同步執行多個動作？

Pattern 12 每次只執行**一個動作**：

**理由**：
- ✅ 簡單可預測
- ✅ 避免 API rate limit
- ✅ 易於監控和除錯
- ✅ 每小時執行一次已足夠

**替代方案**（如果需要加速）：
- 改為每 30 分鐘執行一次
- 或在 actionWrite/actionReview 內批次處理
- 但這會增加複雜度

## 重要概念

### 1. 確定性 (Determinism)

給定相同的系統狀態，Pattern 12 **總是**會做出相同的決定：

```
狀態: 2 approved, 3 pending_review, 1 pending_write
決策: publish (100% 確定)

狀態: 0 approved, 3 pending_review, 1 pending_write
決策: review (100% 確定)
```

**Pattern 11** 則可能：
```
狀態: 2 approved, 3 pending_review, 1 pending_write
決策: 可能 publish，也可能先 review（AI 判斷）
```

### 2. FIFO 原則

所有狀態都遵循「先進先出」：

```php
// 總是處理最舊的文章
ORDER BY updated_at ASC
```

**好處**：
- 不會有文章被遺忘
- 公平對待所有文章
- 類似生產線流程

### 3. 單一職責

每次執行只做一件事：

```
執行 1: research（建立文章 #1）
執行 2: write（撰寫文章 #1）
執行 3: review（審查文章 #1）
執行 4: [等待人工批准]
執行 5: publish（發布文章 #1）
```

**好處**：
- 易於追蹤
- 錯誤容易定位
- 日誌清晰

## 實際應用場景

### ✅ 適合 Pattern 12 的場景

1. **固定的部落格自動化**
   - 每天固定產出文章
   - 流程簡單：研究 → 寫作 → 審查 → 發布
   - 不需要 AI 判斷優先級

2. **成本敏感的專案**
   - Startup 初期預算有限
   - 每月執行數千次
   - 省下 50% API 成本很重要

3. **需要穩定性的生產環境**
   - 金融、醫療等行業
   - 不能容忍 AI 的不確定性
   - 需要完全可預測的行為

4. **簡單的內容管道**
   - YouTube 影片標題生成
   - 社群媒體貼文排程
   - Email 行銷內容

### ❌ 不適合 Pattern 12 的場景

1. **複雜的決策需求**
   - 例：根據文章主題決定發布時機
   - 例：根據現有文章類型平衡新文章方向
   - → 使用 **Pattern 11**

2. **動態優先級**
   - 例：緊急熱門話題需要優先處理
   - 例：季節性內容有時效性
   - → 使用 **Pattern 11**

3. **多種工作流程**
   - 例：不同類型文章有不同流程
   - 例：A/B 測試需要不同策略
   - → 使用 **Pattern 11**

4. **需要 AI 學習改進**
   - 例：根據績效調整策略
   - 例：實驗性的內容優化
   - → 使用 **Pattern 11**

## 與 Pattern 10 的關係

**Pattern 10**: 固定時間執行固定動作
```
08:00 → research (cron-morning.php)
14:00 → write (cron-afternoon.php)
00:00 → publish (cron-midnight.php)
```

**Pattern 12**: 固定規則執行相應動作
```
每小時執行一次 agent.php
根據當前狀態應用規則決定動作
```

**差異**：
- Pattern 10：**時間驅動**（到時間就做固定事）
- Pattern 12：**狀態驅動**（看狀態決定做什麼）

**選擇**：
- 如果流程嚴格按時間 → Pattern 10
- 如果流程按狀態推進 → Pattern 12

## 生產環境考量

### 1. 監控

建議監控指標：
```php
// 每日統計
- 執行次數
- 各動作執行次數（publish, review, write, research, wait）
- API 成本
- 執行錯誤次數
```

### 2. 錯誤處理

Pattern 12 的錯誤更容易處理：
```php
// 規則固定，可預期錯誤
if ($counts['pending_write'] > 10) {
    alert("待撰寫文章積壓過多");
}

if ($counts['pending_review'] > 5) {
    alert("待審查文章過多，可能品質問題");
}
```

### 3. 規則調整

修改優先級很簡單：
```php
// 想先審查再發布？交換順序即可
if ($counts['pending_review'] > 0) {    // 改為優先級 1
    return ['name' => 'review', ...];
}
if ($counts['approved'] > 0) {          // 改為優先級 2
    return ['name' => 'publish', ...];
}
```

### 4. 成本優化

Pattern 12 已經很省成本，進一步優化：
```php
// 只在工作時間執行（省夜間成本）
$hour = (int) date('H');
if ($hour < 8 || $hour > 20) {
    return ['name' => 'wait', 'reason' => '非工作時間'];
}

// 週末降低頻率
if (date('N') >= 6) {
    // 週末使用 Pattern 10 的固定時間
}
```

## 程式碼對比

### Pattern 11（LLM 決策）

```php
// Step 1: 讀取狀態
$systemState = getSystemState($db);

// Step 2: Dynamic Routing
$availableTools = getAvailableTools($systemState);

// Step 3: LLM 決策 ← 多了這步！
$response = $client->chat()->create([
    'messages' => [
        ['role' => 'system', 'content' => getSystemPrompt()],
        ['role' => 'user', 'content' => buildDecisionPrompt($systemState)]
    ],
    'tools' => $availableTools,
    'tool_choice' => 'auto'
]);

// Step 4: 執行 LLM 選擇的動作
$toolCall = $response->choices[0]->message->toolCalls[0];
$result = executeToolFunction(..., $toolCall->function->name, ...);
```

### Pattern 12（固定規則）

```php
// Step 1: 讀取狀態
$systemState = getSystemState($db);
$counts = $systemState['counts'];

// Step 2: 固定規則決策（不需要 LLM）
$action = determineAction($counts);  // 純 PHP if-else

// Step 3: 執行決定的動作
$result = executeAction($db, $client, $action, $counts);
```

**關鍵差異**：省掉了 LLM 決策的 API 調用！

## 擴展可能性

雖然 Pattern 12 是固定規則，但仍有擴展空間：

### 1. 條件式規則

```php
// 根據時間調整優先級
$hour = (int) date('H');
if ($hour >= 8 && $hour < 12) {
    // 早上優先研究
    if ($counts['pending_research'] < 3) {
        return ['name' => 'research', ...];
    }
}
```

### 2. 配額限制

```php
// 每天最多發布 5 篇
$today = date('Y-m-d');
$publishedToday = getPublishedCount($db, $today);
if ($publishedToday >= 5) {
    // 跳過 publish，執行其他動作
}
```

### 3. 品質門檻

```php
// 只審查品質分數未達標的
if ($counts['pending_review'] > 0) {
    $article = getLowestQualityArticle($db);
    if ($article['quality_score'] < 5) {
        return ['name' => 'review', ...];
    }
}
```

### 4. 批次處理

```php
// 一次撰寫多篇（而不是一篇）
if ($counts['pending_write'] >= 3) {
    return [
        'name' => 'write_batch',
        'count' => min(3, $counts['pending_write'])
    ];
}
```

但注意：加太多邏輯會失去「簡單」的優勢！

## 總結

### Pattern 12 的核心價值

1. **簡單** 🎯
   - if-else 邏輯，任何人都能理解
   - 不需要理解 Tool Functions 或 Dynamic Routing

2. **經濟** 💰
   - 省 50% API 成本
   - 適合成本敏感的專案

3. **穩定** 🔒
   - 完全可預測的行為
   - 適合生產環境

4. **快速** ⚡
   - 沒有 LLM 決策延遲
   - 秒級回應

### 何時選擇 Pattern 12？

當你的回答是「是」時：

- [ ] 工作流程固定且簡單
- [ ] 優先級規則明確
- [ ] 成本是重要考量
- [ ] 需要完全可預測的行為
- [ ] 不需要 AI 判斷權衡
- [ ] 團隊不熟悉 LLM Agent 開發

### 何時選擇 Pattern 11？

當你的回答是「是」時：

- [ ] 需要 AI 判斷和權衡
- [ ] 優先級會動態變化
- [ ] 工作流程複雜多變
- [ ] 需要決策透明性追蹤
- [ ] 實驗性專案，可接受不確定性
- [ ] 成本不是主要考量

---

## 快速開始測試

```bash
# 1. 進入目錄
cd patterns/12-rule-based-agent

# 2. 啟動互動式 Demo
php demo.php

# 3. 選擇 2 → 輸入 5（執行 5 次）
# 4. 觀察固定規則的執行順序
# 5. 選擇 7 查看與 Pattern 11 的詳細對比
# 6. 選擇 3 進行人工審核
# 7. 繼續執行看發布流程
```

**期待看到**：
- 清晰的優先級執行順序
- 快速的執行速度
- 可預測的行為模式
- 與 Pattern 11 的成本差異

## 相關 Patterns

- **Pattern 10**: Scheduled Workflow（固定時間執行）
- **Pattern 11**: Autonomous Agent（LLM 自主決策）
- **Pattern 12**: Rule-Based Agent（固定規則決策）← 當前

**演進路徑**：
```
Pattern 10: 時間驅動，固定動作
    ↓
Pattern 12: 狀態驅動，固定規則
    ↓
Pattern 11: 狀態驅動，AI 決策
```

依複雜度和靈活性遞增，成本也遞增。
