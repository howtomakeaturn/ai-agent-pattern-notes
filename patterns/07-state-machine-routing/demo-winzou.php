<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use SM\StateMachine\StateMachine;

/**
 * State Machine Routing - 狀態機路由
 *
 * 使用 winzou/state-machine 重構 Demo 06（Dynamic Routing）
 *
 * 與 Demo 06 的差異：
 * 1. 整個流程用 config 宣告（states + transitions + guards），一眼看完
 * 2. 工具函數不再手動 $state['phase'] = '...'（由 state machine 管理）
 * 3. getPossibleTransitions() 取代手寫的 get_available_tools()
 * 4. Guards 取代 if/elseif 路由邏輯
 *
 * 流程：
 *   initial → recorded → classified → done
 *                              ↘ escalated → done
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ===== ServiceTicket：state machine 操作的對象 =====

class ServiceTicket
{
    private string $phase = 'initial';

    public ?string $issue_description = null;
    public ?string $issue_type = null;       // login / billing / technical
    public bool $resolved = false;
    public bool $escalated = false;
    public ?array $login_data = null;
    public ?array $billing_data = null;
    public ?array $technical_data = null;
    public ?string $ticket_id = null;

    public function getPhase(): string { return $this->phase; }
    public function setPhase(string $phase): void { $this->phase = $phase; }
}

// ===== State Machine Config：宣告式定義整個客服流程 =====
//
// 對比 Demo 06 的 get_available_tools()（~30 行 if/elseif），
// 這裡用 config 就能看清所有狀態轉換和限制條件：

function createStateMachine(ServiceTicket $ticket): StateMachine
{
    return new StateMachine($ticket, [
        'graph'         => 'customer_service',
        'property_path' => 'phase',
        'states'        => ['initial', 'recorded', 'classified', 'escalated', 'done'],

        // 每個工具 = 一個 transition（from → to 一目了然）
        'transitions' => [
            'record_issue'     => ['from' => ['initial'],    'to' => 'recorded'],
            'classify_issue'   => ['from' => ['recorded'],   'to' => 'classified'],
            'handle_login'     => ['from' => ['classified'], 'to' => 'done'],
            'handle_billing'   => ['from' => ['classified'], 'to' => 'done'],
            'handle_technical' => ['from' => ['classified'], 'to' => 'done'],
            'escalate'         => ['from' => ['classified'], 'to' => 'escalated'],
            'create_ticket'    => ['from' => ['escalated'], 'to' => 'done'],
        ],

        // Guards：根據 issue_type 限制可用的 transition
        // （取代 Demo 06 get_available_tools 裡的 if ($state['issue_type'] === 'login') ...）
        'callbacks' => [
            'guard' => [
                'guard-login'     => ['on' => ['handle_login'],     'do' => fn() => $ticket->issue_type === 'login'],
                'guard-billing'   => ['on' => ['handle_billing'],   'do' => fn() => $ticket->issue_type === 'billing'],
                'guard-technical' => ['on' => ['handle_technical'], 'do' => fn() => $ticket->issue_type === 'technical'],
                'guard-escalate'  => ['on' => ['escalate'],         'do' => fn() => $ticket->issue_type === 'login'],
            ],
        ],
    ]);
}

// ===== 工具定義（同 Demo 06）=====

$allToolsDefinitions = [
    'record_issue' => [
        'type' => 'function',
        'function' => [
            'name' => 'record_issue',
            'description' => '記錄客戶的問題描述',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'description' => ['type' => 'string', 'description' => '問題描述'],
                ],
                'required' => ['description'],
            ],
        ],
    ],
    'classify_issue' => [
        'type' => 'function',
        'function' => [
            'name' => 'classify_issue',
            'description' => '判斷問題類型：login（登入）、billing（帳務）、technical（技術）',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['login', 'billing', 'technical'], 'description' => '問題類型'],
                ],
                'required' => ['type'],
            ],
        ],
    ],
    'handle_login' => [
        'type' => 'function',
        'function' => [
            'name' => 'handle_login',
            'description' => '處理登入問題。需要收集帳號和錯誤訊息。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'username' => ['type' => 'string', 'description' => '帳號'],
                    'error_message' => ['type' => 'string', 'description' => '錯誤訊息'],
                ],
                'required' => ['username', 'error_message'],
            ],
        ],
    ],
    'handle_billing' => [
        'type' => 'function',
        'function' => [
            'name' => 'handle_billing',
            'description' => '處理帳務問題（發票、退款等）',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    'handle_technical' => [
        'type' => 'function',
        'function' => [
            'name' => 'handle_technical',
            'description' => '處理技術問題（系統錯誤、功能異常等）',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    'escalate' => [
        'type' => 'function',
        'function' => [
            'name' => 'escalate',
            'description' => '當無法解決時，升級至人工客服',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => '升級原因'],
                ],
                'required' => ['reason'],
            ],
        ],
    ],
    'create_ticket' => [
        'type' => 'function',
        'function' => [
            'name' => 'create_ticket',
            'description' => '創建人工客服工單',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => '問題摘要'],
                ],
                'required' => ['summary'],
            ],
        ],
    ],
];

// ===== 工具實現（注意：不再手動設定 phase！由 state machine 管理）=====

function record_issue(ServiceTicket $ticket, $description) {
    $ticket->issue_description = $description;
    // Demo 06 這裡要寫 $state['phase'] = 'recorded'，現在不用了
    return ['success' => true, 'message' => '問題已記錄', 'next_step' => '請判斷問題類型'];
}

function classify_issue(ServiceTicket $ticket, $type) {
    $ticket->issue_type = $type;
    return ['success' => true, 'type' => $type, 'message' => "已分類為 {$type} 問題", 'next_step' => '請處理該類型問題'];
}

function handle_login(ServiceTicket $ticket, $username, $error_message) {
    $ticket->login_data = [
        'username' => $username,
        'error_message' => $error_message,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // 密碼問題可解決，其他需升級
    if (str_contains(strtolower($error_message), 'password') ||
        str_contains(strtolower($error_message), '密碼')) {
        $ticket->resolved = true;
        return ['success' => true, 'resolved' => true, 'solution' => '已發送密碼重置連結至您的信箱', 'message' => '問題已解決'];
    } else {
        return ['success' => true, 'resolved' => false, 'message' => '此問題需要人工處理', 'next_step' => '請使用 escalate 升級'];
    }
}

function handle_billing(ServiceTicket $ticket) {
    $ticket->billing_data = ['request_type' => 'invoice', 'timestamp' => date('Y-m-d H:i:s')];
    $ticket->resolved = true;
    return ['success' => true, 'resolved' => true, 'solution' => '已確認付款，發票將於 3 天內寄出', 'message' => '帳務問題已處理'];
}

function handle_technical(ServiceTicket $ticket) {
    $ticket->technical_data = ['issue' => $ticket->issue_description, 'timestamp' => date('Y-m-d H:i:s')];
    $ticket->resolved = true;
    return ['success' => true, 'resolved' => true, 'solution' => '已清除快取並重啟服務', 'message' => '技術問題已處理'];
}

function escalate(ServiceTicket $ticket, $reason) {
    $ticket->escalated = true;
    return ['success' => true, 'message' => '準備升級至人工客服', 'reason' => $reason, 'next_step' => '請創建工單'];
}

function create_ticket(ServiceTicket $ticket, $summary) {
    $ticket_id = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999);
    $ticket_data = [
        'ticket_id' => $ticket_id,
        'summary' => $summary,
        'issue_type' => $ticket->issue_type,
        'issue_description' => $ticket->issue_description,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if ($ticket->login_data) $ticket_data['login_data'] = $ticket->login_data;
    if ($ticket->billing_data) $ticket_data['billing_data'] = $ticket->billing_data;
    if ($ticket->technical_data) $ticket_data['technical_data'] = $ticket->technical_data;

    $ticket->ticket_id = $ticket_id;
    return ['success' => true, 'ticket_id' => $ticket_id, 'message' => "工單 {$ticket_id} 已創建，客服將於 24 小時內回覆", 'ticket_details' => $ticket_data];
}

// 執行工具
function executeTool(ServiceTicket $ticket, $functionName, $arguments) {
    return match ($functionName) {
        'record_issue'     => record_issue($ticket, $arguments['description']),
        'classify_issue'   => classify_issue($ticket, $arguments['type']),
        'handle_login'     => handle_login($ticket, $arguments['username'], $arguments['error_message']),
        'handle_billing'   => handle_billing($ticket),
        'handle_technical' => handle_technical($ticket),
        'escalate'         => escalate($ticket, $arguments['reason']),
        'create_ticket'    => create_ticket($ticket, $arguments['summary']),
        default            => ['error' => "Unknown function: $functionName"],
    };
}

// System prompt
$systemPrompt = <<<PROMPT
你是客服助手，協助客戶解決問題。

基本流程：
1. 記錄問題
2. 判斷類型
3. 處理問題
4. 無法處理時升級人工

使用提供的工具完成任務。工具會告訴你下一步該做什麼。
保持友善、專業、有效率。
PROMPT;

// ===== 對話函數 =====

function chat($userMessage, $client, ServiceTicket $ticket, StateMachine $sm, array &$messages, array $allToolsDefinitions) {
    echo "\n客戶：$userMessage\n";
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    while (true) {
        // 核心改進：用 getPossibleTransitions() 取代 Demo 06 的 get_available_tools()
        // state machine 根據「當前 phase + guards」自動算出可用工具
        $transitions = $sm->getPossibleTransitions();
        $available_tools = [];
        foreach ($transitions as $t) {
            if (isset($allToolsDefinitions[$t])) {
                $available_tools[] = $allToolsDefinitions[$t];
            }
        }

        $request_params = ['model' => 'gpt-4o-mini', 'messages' => $messages];
        if (!empty($available_tools)) {
            $request_params['tools'] = $available_tools;
            $request_params['tool_choice'] = 'auto';
        }

        $response = $client->chat()->create($request_params);
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName]\n";

                // 1. 執行工具業務邏輯
                $result = executeTool($ticket, $functionName, $arguments);

                // 2. 狀態轉換由 state machine 管理
                //    唯一例外：handle_login 未解決時不轉換（留在 classified 讓 AI 可以 escalate）
                $shouldTransition = true;
                if ($functionName === 'handle_login' && !($result['resolved'] ?? false)) {
                    $shouldTransition = false;
                }

                if ($shouldTransition && $sm->can($functionName)) {
                    $sm->apply($functionName);
                }

                $messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            echo "  [狀態：phase={$ticket->getPhase()}, type={$ticket->issue_type}, resolved=" . ($ticket->resolved ? 'true' : 'false') . "]\n";
        } else {
            break;
        }
    }
}

// ===== 示範 =====

echo "=== State Machine Routing - 狀態機路由 ===\n";
echo "特點：用 state machine config 宣告流程，getPossibleTransitions() 自動計算可用工具\n";

echo "\n\n【場景 1：登入問題 - 密碼錯誤】\n";
$ticket1 = new ServiceTicket();
$sm1 = createStateMachine($ticket1);
$messages1 = [['role' => 'system', 'content' => $systemPrompt]];
chat("你好，我無法登入", $client, $ticket1, $sm1, $messages1, $allToolsDefinitions);
chat("帳號是 john@example.com，說密碼錯誤", $client, $ticket1, $sm1, $messages1, $allToolsDefinitions);
chat("謝謝", $client, $ticket1, $sm1, $messages1, $allToolsDefinitions);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($ticket1->login_data) {
    echo "登入資料：" . json_encode($ticket1->login_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n【場景 2：登入問題 - 需要升級】\n";
$ticket2 = new ServiceTicket();
$sm2 = createStateMachine($ticket2);
$messages2 = [['role' => 'system', 'content' => $systemPrompt]];
chat("我的帳號被鎖定了", $client, $ticket2, $sm2, $messages2, $allToolsDefinitions);
chat("帳號是 mary@example.com，錯誤是 Account Locked", $client, $ticket2, $sm2, $messages2, $allToolsDefinitions);
chat("好的", $client, $ticket2, $sm2, $messages2, $allToolsDefinitions);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($ticket2->login_data) {
    echo "登入資料：" . json_encode($ticket2->login_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if ($ticket2->ticket_id) {
    echo "工單號碼：{$ticket2->ticket_id}\n";
    echo "(工單中包含的登入資訊已傳遞給人工客服)\n";
}

echo "\n\n【場景 3：帳務問題】\n";
$ticket3 = new ServiceTicket();
$sm3 = createStateMachine($ticket3);
$messages3 = [['role' => 'system', 'content' => $systemPrompt]];
chat("我需要發票", $client, $ticket3, $sm3, $messages3, $allToolsDefinitions);
chat("謝謝", $client, $ticket3, $sm3, $messages3, $allToolsDefinitions);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($ticket3->billing_data) {
    echo "帳務資料：" . json_encode($ticket3->billing_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n=== 完成 ===\n";
echo "\n與 Demo 06 的比較：\n";
echo "Demo 06：get_available_tools() 用 ~30 行 if/elseif 手動控制路由\n";
echo "Demo 07：state machine config 用 transitions + guards 宣告式定義\n";
echo "\n改進：\n";
echo "✓ 流程一目了然（看 config 就知道所有狀態和轉換）\n";
echo "✓ 工具函數更單純（不管 phase，只做業務邏輯）\n";
echo "✓ 路由邏輯集中（全在 createStateMachine 的 config 裡）\n";
echo "✓ 容易修改流程（改 config 不改程式碼）\n";
echo "✓ 狀態轉換有保障（state machine 確保只能走合法路徑）\n";
