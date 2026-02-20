# AI Agent Pattern Notes

常見的 AI Agent 設計模式與實作範例

## Patterns

### 1. Control Flow (流程控制)
**問題**: 如何確保 AI agent 按照特定順序執行必要步驟？
**解決**: 使用狀態機追蹤流程進度，在系統 prompt 中明確定義必要步驟與順序依賴
**範例**: [patterns/01-control-flow/demo.php](patterns/01-control-flow/demo.php)

### 2. State Preview & Confirmation (預覽與確認)
**問題**: 涉及金錢、法律等重要操作時，如何確保用戶充分了解後才執行？
**解決**: 強制要求預覽步驟，在確認前必須先展示完整資訊，修改後重新預覽
**範例**: [patterns/02-state-preview-confirmation/demo.php](patterns/02-state-preview-confirmation/demo.php)

### 3. State Persistence (狀態持久化)
**問題**: 如何保存對話狀態，支援跨會話恢復？
**解決**: 將狀態機和對話歷史持久化到資料庫，載入時恢復完整上下文
**範例**: [patterns/03-state-persistence/demo.php](patterns/03-state-persistence/demo.php)

### 4. Tool Function + Persistence (工具函數與持久化)
**問題**: 如何可靠地控制狀態變更，避免字串匹配的不確定性？
**解決**: 使用 Tool Functions 讓 LLM 精確操作狀態，結合資料庫持久化
**範例**: [patterns/04-tool-function-persistence/demo.php](patterns/04-tool-function-persistence/demo.php)

### 5. Session Renewal (會話續用/訂單重置)
**問題**: LINE/SMS 等平台無法開新對話，同一聊天室如何處理多次獨立訂單？
**解決**: 提供 reset_order 工具函數重置訂單狀態，智能保留過敏等不變資訊，支援「跟上次一樣」
**範例**: [patterns/05-session-renewal/demo.php](patterns/05-session-renewal/demo.php)

### 6. Dynamic Routing (動態路由)
**問題**: 如何根據業務狀態動態控制 AI 可用的工具，避免錯誤呼叫？
**解決**: 根據當前 phase 動態提供可用工具，工具函數自己決定下一步狀態，AI 只看得到當前可用的功能
**範例**: [patterns/06-dynamic-routing/demo.php](patterns/06-dynamic-routing/demo.php)

### 7. State Machine Routing (狀態機路由)
**問題**: Demo 06 的手寫 if/elseif 路由邏輯難以維護，如何用更結構化的方式管理狀態轉換？
**解決**: 使用狀態機套件管理狀態轉換，宣告式定義流程，自動計算可用工具
**範例**: 同一模式的四種實作方式
- [demo-winzou.php](patterns/07-state-machine-routing/demo-winzou.php) — 使用 winzou/state-machine
- [demo-neuronai.php](patterns/07-state-machine-routing/demo-neuronai.php) — 使用 NeuronAI 框架
- [demo-finite.php](patterns/07-state-machine-routing/demo-finite.php) — 使用 yohang/finite（PHP Enum）
- [demo-pocketflow.php](patterns/07-state-machine-routing/demo-pocketflow.php) — 使用 PocketFlow（Graph-based）

### 8. Graph-Based Agent Engine (圖驅動的 Agent 引擎)
**問題**: Demo 06 的流程邏輯寫在程式碼裡，改流程需要改 code，無法讓非技術人員設計流程？
**解決**: 將流程定義抽離成資料結構（graph），引擎是通用的，換個 graph 就是不同的 agent
**特點**: 這是 Vapi、Voiceflow、Bland AI 等 SaaS 平台的底層架構 — UI 拖拉產生 graph，通用引擎執行
**範例**: [patterns/08-graph-based-agent-engine/demo.php](patterns/08-graph-based-agent-engine/demo.php)

### 9. Graph Engine + Actions (圖驅動引擎 + 外部動作)
**問題**: Demo 08 的 LLM 只是「說」它做了某事，實際沒有觸發任何外部動作（API、Email、DB）？
**解決**: 在節點上掛載 actions，進入節點或選擇結果時自動執行真實的外部操作
**動作類型**: API 呼叫、發送 Email、Webhook 通知、資料庫寫入、轉接真人客服
**範例**: [patterns/09-graph-engine-with-actions/demo.php](patterns/09-graph-engine-with-actions/demo.php)

### 10. Scheduled Workflow (排程工作流程)
**問題**: 如何用排程系統協調多階段 AI 任務？需要在特定時間執行、等待外部事件（人工審核），且跨越數小時甚至數天？
**解決**: 用 cron 在固定時間觸發不同階段的任務，透過資料庫持久化狀態，支援 Human-in-the-loop
**特點**: 時間驅動（非用戶輸入）、多 process 共享狀態、長時間運作
**範例**: [patterns/10-scheduled-workflow/](patterns/10-scheduled-workflow/) — 完整內容自動化系統（研究→撰寫→審核→發布）

### 11. Autonomous Agent (自主 Agent)
**問題**: Demo 10 的固定時間執行固定任務太僵化，如何讓 AI 自己看狀態決定現在該做什麼？
**解決**: 定時執行 agent，但讓 LLM 透過 Tool Functions 分析系統狀態並自主決策下一步動作
**特點**: AI 自己判斷優先順序、可立即修正錯誤、自我品質把關，更接近「AI employee」概念
**範例**: [patterns/11-autonomous-agent/](patterns/11-autonomous-agent/) — AI 自主管理內容生產流程

### 12. Rule-Based Agent (基於規則的 Agent)
**問題**: Demo 11 的 LLM 決策在固定工作流程中是過度設計，成本高、速度慢且有不確定性？
**解決**: 用程式碼中的固定優先級規則決定動作，只在執行動作時使用 LLM，不用 LLM 做決策
**特點**: 成本低（省 50%）、速度快、完全可預測、行為確定
**範例**: [patterns/12-rule-based-agent/](patterns/12-rule-based-agent/) — 用固定規則管理內容生產流程

### 13. RAG (Retrieval-Augmented Generation)
**問題**: LLM 無法回答專屬業務規則、容易產生幻覺、知識過時？
**解決**: 檢索增強生成 — 先從知識庫檢索相關文檔，再讓 LLM 基於檢索結果生成答案
**核心技術**: Embedding（語義向量化）+ Vector Search（相似度檢索）+ LLM Generation
**範例**: [patterns/13-rag/demo.php](patterns/13-rag/demo.php) — 電商客服知識庫問答系統

### 14. RAG + Similarity Threshold (RAG + 相似度門檻)
**問題**: Demo 13 的 RAG 永遠返回 Top-K 結果，即使相似度很低也會「硬凹」答案？
**解決**: 設定相似度門檻，過濾不相關文檔，沒有相關內容時明確拒絕回答
**關鍵機制**: 門檻過濾 + 空結果處理 + 嚴格 System Prompt
**範例**: [patterns/14-rag-with-threshold/demo.php](patterns/14-rag-with-threshold/demo.php) — 防止幻覺的生產級 RAG

## 使用方式

1. 複製 `.env.example` 為 `.env`，填入 OpenAI API Key
2. 安裝依賴：`composer install`
3. 執行範例：`php patterns/01-control-flow/demo.php`

## 適用場景

- 電商訂購系統
- 客服對話機器人
- 表單填寫助理
- 任務流程引導
