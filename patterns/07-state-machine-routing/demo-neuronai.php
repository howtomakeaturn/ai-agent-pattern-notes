<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use NeuronAI\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

/**
 * NeuronAI Dynamic Routing - 用 NeuronAI 框架實現動態路由
 *
 * 這個範例用 NeuronAI 框架重寫 demo 06 的動態路由模式。
 *
 * 核心概念：
 * 1. 繼承 NeuronAI\Agent，覆寫 tools() 方法根據狀態動態返回工具
 * 2. 覆寫 bootstrapTools() 讓每次 LLM 推論前重新載入工具（不使用快取）
 * 3. 工具的 callable 更新 Agent 內部狀態，驅動流程前進
 * 4. NeuronAI 自動管理 tool call loop、對話記憶、訊息格式
 *
 * 與 demo 06 的差異：
 * - 不需要手動管理 messages 陣列（NeuronAI 的 ChatHistory 自動處理）
 * - 不需要手動呼叫 OpenAI API（NeuronAI 的 Provider 自動處理）
 * - 不需要手動處理 tool call loop（NeuronAI 自動處理）
 * - 不需要手動建構 tool schema（NeuronAI 的 Tool/ToolProperty 自動產生）
 * - 代碼更簡潔、更 OOP、更易維護
 */

class CustomerServiceAgent extends Agent
{
    // 業務狀態 - 追蹤流程進度和客戶資料
    public array $state = [
        'phase' => 'initial',        // initial → recorded → classified → done/escalated
        'issue_description' => null,
        'issue_type' => null,         // login / billing / technical
        'resolved' => false,
        'escalated' => false,
        'login_data' => null,
        'billing_data' => null,
        'technical_data' => null,
        'ticket_id' => null,
    ];

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: $_ENV['OPENAI_API_KEY'],
            model: 'gpt-4o-mini',
        );
    }

    public function instructions(): string
    {
        return <<<PROMPT
你是客服助手，協助客戶解決問題。

基本流程：
1. 記錄問題
2. 判斷類型
3. 處理問題
4. 無法處理時升級人工

使用提供的工具完成任務。工具會告訴你下一步該做什麼。
保持友善、專業、有效率。
PROMPT;
    }

    /**
     * 覆寫 bootstrapTools()，讓每次 LLM 推論前都重新載入工具。
     * 這是動態路由的關鍵！NeuronAI 預設會快取工具，
     * 但我們需要根據 state 變化提供不同的工具。
     */
    public function bootstrapTools(): array
    {
        // 清除快取，強制重新評估 tools()
        $this->toolsBootstrapCache = [];
        return parent::bootstrapTools();
    }

    /**
     * 根據當前狀態動態返回可用工具。
     * 這是 dynamic routing 的核心！
     */
    protected function tools(): array
    {
        $tools = [];

        if ($this->state['phase'] === 'initial') {
            // 一開始只能記錄問題
            $tools[] = Tool::make(
                'record_issue',
                '記錄客戶的問題描述',
            )->addProperty(
                new ToolProperty(
                    name: 'description',
                    type: PropertyType::STRING,
                    description: '問題描述',
                    required: true,
                )
            )->setCallable(function (string $description) {
                $this->state['issue_description'] = $description;
                $this->state['phase'] = 'recorded';

                return json_encode([
                    'success' => true,
                    'message' => '問題已記錄',
                    'next_step' => '請判斷問題類型',
                ], JSON_UNESCAPED_UNICODE);
            });
        }

        elseif ($this->state['phase'] === 'recorded') {
            // 記錄後要分類
            $tools[] = Tool::make(
                'classify_issue',
                '判斷問題類型：login（登入）、billing（帳務）、technical（技術）',
            )->addProperty(
                new ToolProperty(
                    name: 'type',
                    type: PropertyType::STRING,
                    description: '問題類型，必須是 login、billing、technical 之一',
                    required: true,
                )
            )->setCallable(function (string $type) {
                $this->state['issue_type'] = $type;
                $this->state['phase'] = 'classified';

                return json_encode([
                    'success' => true,
                    'type' => $type,
                    'message' => "已分類為 {$type} 問題",
                    'next_step' => '請處理該類型問題',
                ], JSON_UNESCAPED_UNICODE);
            });
        }

        elseif ($this->state['phase'] === 'classified') {
            // 分類後根據問題類型提供對應工具
            if ($this->state['issue_type'] === 'login') {
                $tools[] = Tool::make(
                    'handle_login',
                    '處理登入問題。需要收集帳號和錯誤訊息。',
                )->addProperty(
                    new ToolProperty(
                        name: 'username',
                        type: PropertyType::STRING,
                        description: '帳號',
                        required: true,
                    )
                )->addProperty(
                    new ToolProperty(
                        name: 'error_message',
                        type: PropertyType::STRING,
                        description: '錯誤訊息',
                        required: true,
                    )
                )->setCallable(function (string $username, string $error_message) {
                    $this->state['login_data'] = [
                        'username' => $username,
                        'error_message' => $error_message,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];

                    // 模擬：密碼問題可解決，其他需升級
                    if (str_contains(strtolower($error_message), 'password') ||
                        str_contains(strtolower($error_message), '密碼')) {
                        $this->state['resolved'] = true;
                        $this->state['phase'] = 'done';

                        return json_encode([
                            'success' => true,
                            'resolved' => true,
                            'solution' => '已發送密碼重置連結至您的信箱',
                            'message' => '問題已解決',
                        ], JSON_UNESCAPED_UNICODE);
                    } else {
                        return json_encode([
                            'success' => true,
                            'resolved' => false,
                            'message' => '此問題需要人工處理',
                            'next_step' => '請使用 escalate 升級',
                        ], JSON_UNESCAPED_UNICODE);
                    }
                });

                $tools[] = Tool::make(
                    'escalate',
                    '當無法解決時，升級至人工客服',
                )->addProperty(
                    new ToolProperty(
                        name: 'reason',
                        type: PropertyType::STRING,
                        description: '升級原因',
                        required: true,
                    )
                )->setCallable(function (string $reason) {
                    $this->state['escalated'] = true;
                    $this->state['phase'] = 'escalated';

                    return json_encode([
                        'success' => true,
                        'message' => '準備升級至人工客服',
                        'reason' => $reason,
                        'next_step' => '請創建工單',
                    ], JSON_UNESCAPED_UNICODE);
                });

            } elseif ($this->state['issue_type'] === 'billing') {
                $tools[] = Tool::make(
                    'handle_billing',
                    '處理帳務問題（發票、退款等）',
                )->setCallable(function () {
                    $this->state['billing_data'] = [
                        'request_type' => 'invoice',
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];

                    $this->state['resolved'] = true;
                    $this->state['phase'] = 'done';

                    return json_encode([
                        'success' => true,
                        'resolved' => true,
                        'solution' => '已確認付款，發票將於 3 天內寄出',
                        'message' => '帳務問題已處理',
                    ], JSON_UNESCAPED_UNICODE);
                });

            } elseif ($this->state['issue_type'] === 'technical') {
                $tools[] = Tool::make(
                    'handle_technical',
                    '處理技術問題（系統錯誤、功能異常等）',
                )->setCallable(function () {
                    $this->state['technical_data'] = [
                        'issue' => $this->state['issue_description'],
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];

                    $this->state['resolved'] = true;
                    $this->state['phase'] = 'done';

                    return json_encode([
                        'success' => true,
                        'resolved' => true,
                        'solution' => '已清除快取並重啟服務',
                        'message' => '技術問題已處理',
                    ], JSON_UNESCAPED_UNICODE);
                });
            }
        }

        elseif ($this->state['phase'] === 'escalated') {
            // 升級後要創建工單
            $tools[] = Tool::make(
                'create_ticket',
                '創建人工客服工單',
            )->addProperty(
                new ToolProperty(
                    name: 'summary',
                    type: PropertyType::STRING,
                    description: '問題摘要',
                    required: true,
                )
            )->setCallable(function (string $summary) {
                $ticket_id = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999);

                $ticket_data = [
                    'ticket_id' => $ticket_id,
                    'summary' => $summary,
                    'issue_type' => $this->state['issue_type'],
                    'issue_description' => $this->state['issue_description'],
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                if ($this->state['login_data']) {
                    $ticket_data['login_data'] = $this->state['login_data'];
                }
                if ($this->state['billing_data']) {
                    $ticket_data['billing_data'] = $this->state['billing_data'];
                }
                if ($this->state['technical_data']) {
                    $ticket_data['technical_data'] = $this->state['technical_data'];
                }

                $this->state['ticket_id'] = $ticket_id;
                $this->state['phase'] = 'done';

                return json_encode([
                    'success' => true,
                    'ticket_id' => $ticket_id,
                    'message' => "工單 {$ticket_id} 已創建，客服將於 24 小時內回覆",
                    'ticket_details' => $ticket_data,
                ], JSON_UNESCAPED_UNICODE);
            });
        }
        // phase === 'done' 時不提供任何工具

        return $tools;
    }

    /**
     * 重置狀態（用於切換場景）
     */
    public function resetState(): void
    {
        $this->state = [
            'phase' => 'initial',
            'issue_description' => null,
            'issue_type' => null,
            'resolved' => false,
            'escalated' => false,
            'login_data' => null,
            'billing_data' => null,
            'technical_data' => null,
            'ticket_id' => null,
        ];
    }
}

// 對話函數 - 包裝 NeuronAI 的 chat()
function chat(CustomerServiceAgent $agent, string $userMessage): void
{
    echo "\n客戶：$userMessage\n";

    $response = $agent->chat(new UserMessage($userMessage));

    echo "助手：{$response->getContent()}\n";
    echo "  [狀態：phase={$agent->state['phase']}, type={$agent->state['issue_type']}, resolved=" . ($agent->state['resolved'] ? 'true' : 'false') . "]\n";
}

// 示範
echo "=== NeuronAI Dynamic Routing - 用 NeuronAI 實現動態路由 ===\n";
echo "特點：用 NeuronAI 框架的 Agent/Tool 系統，覆寫 bootstrapTools() 實現動態工具切換\n";

// 場景 1：登入問題 - 密碼錯誤
echo "\n\n【場景 1：登入問題 - 密碼錯誤】\n";
$agent = CustomerServiceAgent::make();
chat($agent, "你好，我無法登入");
chat($agent, "帳號是 john@example.com，說密碼錯誤");
chat($agent, "謝謝");

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($agent->state['login_data']) {
    echo "登入資料：" . json_encode($agent->state['login_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "登入資料：無\n";
}

// 場景 2：登入問題 - 需要升級
echo "\n\n【場景 2：登入問題 - 需要升級】\n";
$agent2 = CustomerServiceAgent::make();
chat($agent2, "我的帳號被鎖定了");
chat($agent2, "帳號是 mary@example.com，錯誤是 Account Locked");
chat($agent2, "好的");

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($agent2->state['login_data']) {
    echo "登入資料：" . json_encode($agent2->state['login_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if ($agent2->state['ticket_id']) {
    echo "工單號碼：{$agent2->state['ticket_id']}\n";
    echo "(工單中包含的登入資訊已傳遞給人工客服)\n";
}

// 場景 3：帳務問題
echo "\n\n【場景 3：帳務問題】\n";
$agent3 = CustomerServiceAgent::make();
chat($agent3, "我需要發票");
chat($agent3, "謝謝");

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($agent3->state['billing_data']) {
    echo "帳務資料：" . json_encode($agent3->state['billing_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n=== 完成 ===\n";
echo "\n與 demo 06 的比較：\n";
echo "✓ 不需要手動管理 \$messages 陣列 → NeuronAI ChatHistory 自動處理\n";
echo "✓ 不需要手動呼叫 OpenAI API → NeuronAI Provider 自動處理\n";
echo "✓ 不需要手動處理 tool call loop → NeuronAI Agent 自動處理\n";
echo "✓ 不需要手動建構 tool JSON schema → NeuronAI Tool/ToolProperty 自動產生\n";
echo "✓ 動態路由透過覆寫 bootstrapTools() + tools() 實現\n";
echo "✓ 更 OOP、更易測試、更易維護\n";
echo "\nNeuronAI 的優勢：\n";
echo "- Agent 類別封裝所有邏輯，可重用、可測試\n";
echo "- 內建對話記憶（ChatHistory）\n";
echo "- 支援多種 LLM Provider（OpenAI、Anthropic、Gemini 等）\n";
echo "- 工具系統（Tool/Toolkit）結構清晰\n";
echo "- 內建監控整合（Inspector）\n";
