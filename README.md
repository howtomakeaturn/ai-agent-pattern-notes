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

## 使用方式

1. 複製 `.env.example` 為 `.env`，填入 OpenAI API Key
2. 安裝依賴：`composer install`
3. 執行範例：`php patterns/01-control-flow/demo.php`

## 適用場景

- 電商訂購系統
- 客服對話機器人
- 表單填寫助理
- 任務流程引導
