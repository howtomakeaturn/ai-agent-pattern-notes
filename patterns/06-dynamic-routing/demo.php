<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * Dynamic Routing - 動態路由
 *
 * 這個範例展示純粹的 dynamic routing + dynamic tools 模式。
 *
 * 核心概念：
 * 1. 根據當前業務狀態動態提供可用的工具
 * 2. 工具函數自己決定下一步的狀態和可用工具
 * 3. AI 只能看到當前狀態下可用的工具（避免錯誤呼叫）
 * 4. System prompt 保持簡潔（降低 token 消耗）
 *
 * 優勢：
 * - 簡單直覺，容易理解和維護
 * - 靈活彈性，工具自己決定流程
 * - 嚴格控制，AI 不會呼叫錯誤工具
 * - Token 經濟，不需要長 prompt 解釋流程
 * - 適合大多數實際場景
 *
 * 何時使用：
 * - 需要根據狀態控制 AI 可用的功能
 * - 希望降低 token 消耗
 * - 想要嚴格控制 AI 行為
 * - 追求代碼簡潔性
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// 狀態 - 追蹤業務相關的資訊
// 包含宏觀狀態（phase）和各情境的具體資料
$state = [
    // 宏觀狀態
    'phase' => 'initial',        // initial → recorded → classified → done/escalated
    'issue_description' => null,
    'issue_type' => null,         // login / billing / technical
    'resolved' => false,
    'escalated' => false,

    // 各情境的具體資料
    'login_data' => null,         // ['username' => '...', 'error_message' => '...']
    'billing_data' => null,       // 帳務相關資料
    'technical_data' => null,     // 技術問題相關資料
    'ticket_id' => null,          // 工單號碼
];

// 所有工具的完整定義
$allToolsDefinitions = [
    'record_issue' => [
        'type' => 'function',
        'function' => [
            'name' => 'record_issue',
            'description' => '記錄客戶的問題描述',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'description' => [
                        'type' => 'string',
                        'description' => '問題描述',
                    ],
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
                    'type' => [
                        'type' => 'string',
                        'enum' => ['login', 'billing', 'technical'],
                        'description' => '問題類型',
                    ],
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

// 根據當前狀態動態獲取可用工具
// 這是 dynamic routing 的核心！
function get_available_tools() {
    global $state, $allToolsDefinitions;

    $tool_names = [];

    // 根據 phase 決定可用工具
    if ($state['phase'] === 'initial') {
        // 一開始只能記錄問題
        $tool_names = ['record_issue'];
    }
    elseif ($state['phase'] === 'recorded') {
        // 記錄後要分類
        $tool_names = ['classify_issue'];
    }
    elseif ($state['phase'] === 'classified') {
        // 分類後根據問題類型提供對應工具
        if ($state['issue_type'] === 'login') {
            $tool_names = ['handle_login', 'escalate'];
        } elseif ($state['issue_type'] === 'billing') {
            $tool_names = ['handle_billing'];
        } elseif ($state['issue_type'] === 'technical') {
            $tool_names = ['handle_technical'];
        }
    }
    elseif ($state['phase'] === 'escalated') {
        // 升級後要創建工單
        $tool_names = ['create_ticket'];
    }
    // phase === 'done' 時不提供任何工具

    // 轉換成完整定義
    $tools = [];
    foreach ($tool_names as $name) {
        if (isset($allToolsDefinitions[$name])) {
            $tools[] = $allToolsDefinitions[$name];
        }
    }

    return $tools;
}

// 工具實現 - 每個工具自己決定下一步的狀態
function record_issue($description) {
    global $state;

    $state['issue_description'] = $description;
    $state['phase'] = 'recorded';  // 自己決定下一個 phase

    return [
        'success' => true,
        'message' => '問題已記錄',
        'next_step' => '請判斷問題類型',
    ];
}

function classify_issue($type) {
    global $state;

    $state['issue_type'] = $type;
    $state['phase'] = 'classified';  // 自己決定下一個 phase

    return [
        'success' => true,
        'type' => $type,
        'message' => "已分類為 {$type} 問題",
        'next_step' => '請處理該類型問題',
    ];
}

function handle_login($username, $error_message) {
    global $state;

    // 記錄登入相關資料到 state
    $state['login_data'] = [
        'username' => $username,
        'error_message' => $error_message,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    // 模擬：密碼問題可解決，其他需升級
    if (str_contains(strtolower($error_message), 'password') ||
        str_contains(strtolower($error_message), '密碼')) {
        $state['resolved'] = true;
        $state['phase'] = 'done';

        return [
            'success' => true,
            'resolved' => true,
            'solution' => '已發送密碼重置連結至您的信箱',
            'message' => '問題已解決',
        ];
    } else {
        // 不改變 phase，讓 AI 可以選擇呼叫 escalate
        return [
            'success' => true,
            'resolved' => false,
            'message' => '此問題需要人工處理',
            'next_step' => '請使用 escalate 升級',
        ];
    }
}

function handle_billing() {
    global $state;

    // 記錄帳務相關資料
    $state['billing_data'] = [
        'request_type' => 'invoice',
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    $state['resolved'] = true;
    $state['phase'] = 'done';

    return [
        'success' => true,
        'resolved' => true,
        'solution' => '已確認付款，發票將於 3 天內寄出',
        'message' => '帳務問題已處理',
    ];
}

function handle_technical() {
    global $state;

    // 記錄技術問題相關資料
    $state['technical_data'] = [
        'issue' => $state['issue_description'],
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    $state['resolved'] = true;
    $state['phase'] = 'done';

    return [
        'success' => true,
        'resolved' => true,
        'solution' => '已清除快取並重啟服務',
        'message' => '技術問題已處理',
    ];
}

function escalate($reason) {
    global $state;

    $state['escalated'] = true;
    $state['phase'] = 'escalated';

    return [
        'success' => true,
        'message' => '準備升級至人工客服',
        'reason' => $reason,
        'next_step' => '請創建工單',
    ];
}

function create_ticket($summary) {
    global $state;

    $ticket_id = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999);

    // 工單中包含之前收集的所有資料
    $ticket_data = [
        'ticket_id' => $ticket_id,
        'summary' => $summary,
        'issue_type' => $state['issue_type'],
        'issue_description' => $state['issue_description'],
        'created_at' => date('Y-m-d H:i:s'),
    ];

    // 根據問題類型加入對應的詳細資料
    if ($state['login_data']) {
        $ticket_data['login_data'] = $state['login_data'];
    }
    if ($state['billing_data']) {
        $ticket_data['billing_data'] = $state['billing_data'];
    }
    if ($state['technical_data']) {
        $ticket_data['technical_data'] = $state['technical_data'];
    }

    $state['ticket_id'] = $ticket_id;
    $state['phase'] = 'done';

    // 這裡可以將 $ticket_data 保存到資料庫
    // saveToDatabase($ticket_data);

    return [
        'success' => true,
        'ticket_id' => $ticket_id,
        'message' => "工單 {$ticket_id} 已創建，客服將於 24 小時內回覆",
        'ticket_details' => $ticket_data,  // 返回完整工單資料供檢視
    ];
}

// 執行工具
function executeTool($functionName, $arguments) {
    switch ($functionName) {
        case 'record_issue':
            return record_issue($arguments['description']);
        case 'classify_issue':
            return classify_issue($arguments['type']);
        case 'handle_login':
            return handle_login($arguments['username'], $arguments['error_message']);
        case 'handle_billing':
            return handle_billing();
        case 'handle_technical':
            return handle_technical();
        case 'escalate':
            return escalate($arguments['reason']);
        case 'create_ticket':
            return create_ticket($arguments['summary']);
        default:
            return ['error' => "Unknown function: $functionName"];
    }
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

$messages = [['role' => 'system', 'content' => $systemPrompt]];

// 對話函數
function chat($userMessage) {
    global $client, $messages, $state;

    echo "\n客戶：$userMessage\n";
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    while (true) {
        // 動態獲取當前可用的工具
        $available_tools = get_available_tools();

        $request_params = [
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
        ];

        // 只有在有工具時才加入 tools 和 tool_choice
        if (!empty($available_tools)) {
            $request_params['tools'] = $available_tools;
            $request_params['tool_choice'] = 'auto';
        }

        $response = $client->chat()->create($request_params);

        $message = $response->choices[0]->message;

        $messageRecord = [
            'role' => 'assistant',
            'content' => $message->content,
        ];

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

                $result = executeTool($functionName, $arguments);

                $messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            // 執行完工具後顯示狀態（給開發者看內部狀態變化）
            echo "  [狀態：phase={$state['phase']}, type={$state['issue_type']}, resolved={$state['resolved']}]\n";
        } else {
            // 沒有工具呼叫，AI 已經做了文字回應，結束這輪對話
            break;
        }
    }
}

// 示範
echo "=== Dynamic Routing - 動態路由 ===";
echo "特點：根據當前狀態動態提供可用工具，AI 只看得到當前可用的功能\n";

echo "\n\n【場景 1：登入問題 - 密碼錯誤】\n";
chat("你好，我無法登入");
chat("帳號是 john@example.com，說密碼錯誤");
chat("謝謝");

// 先檢視記錄的狀態資料（重置之前）
echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($state['login_data']) {
    echo "登入資料：" . json_encode($state['login_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "登入資料：無\n";
}

// 重置
$state = [
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
$messages = [['role' => 'system', 'content' => $systemPrompt]];

echo "\n\n【場景 2：登入問題 - 需要升級】\n";
chat("我的帳號被鎖定了");
chat("帳號是 mary@example.com，錯誤是 Account Locked");
chat("好的");

// 先檢視記錄的狀態資料（重置之前）
echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($state['login_data']) {
    echo "登入資料：" . json_encode($state['login_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if ($state['ticket_id']) {
    echo "工單號碼：{$state['ticket_id']}\n";
    echo "(工單中包含的登入資訊已傳遞給人工客服)\n";
}

// 重置
$state = [
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
$messages = [['role' => 'system', 'content' => $systemPrompt]];

echo "\n\n【場景 3：帳務問題】\n";
chat("我需要發票");
chat("謝謝");

// 先檢視記錄的狀態資料（儲存在記憶體）
echo "\n--- 檢視記錄的狀態資料 ---\n";
if ($state['billing_data']) {
    echo "帳務資料：" . json_encode($state['billing_data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n=== 完成 ===\n";
echo "\n重點說明：\n";
echo "- 工具函數會將收集到的資料儲存在 \$state 中\n";
echo "- 後續工具（如 create_ticket）可以使用這些資料\n";
echo "- 這些資料可以保存到資料庫供後續分析\n";
echo "\nDynamic Routing 的優勢：\n";
echo "✓ System prompt 簡潔（降低 token 消耗）\n";
echo "✓ AI 不可能呼叫錯誤工具（只看得到可用的）\n";
echo "✓ 流程控制嚴格（在程式端控制）\n";
echo "✓ 代碼簡單直覺（容易理解維護）\n";
echo "✓ 靈活彈性（工具自己決定流程）\n";
echo "\n適用場景：\n";
echo "- 需要根據狀態限制 AI 可用功能\n";
echo "- 希望降低 API 成本（減少 token）\n";
echo "- 追求代碼簡潔性和可維護性\n";
echo "- 大多數實際的 LLM 應用場景\n";
