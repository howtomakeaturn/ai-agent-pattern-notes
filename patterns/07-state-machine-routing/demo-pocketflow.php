<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/lib/pocketflow/PocketFlow.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use PocketFlow\Node;
use PocketFlow\Flow;

/**
 * PocketFlow Routing - 使用 PocketFlow 實現動態路由
 *
 * 重寫 Demo 06 的動態路由，使用 PocketFlow 的 Graph + Node 架構。
 *
 * 與 Demo 06 的差異：
 * 1. 每個工具 = 一個 Node（prep/exec/post 三階段）
 * 2. Node.post() 返回 action，驅動 Flow 轉換（取代手動 $state['phase'] = '...'）
 * 3. Flow 用 on('action')->next($node) 定義路由圖（取代 get_available_tools()）
 * 4. shared (stdClass) 傳遞狀態（取代 global $state）
 *
 * 核心概念：
 * - Graph-based routing：預先定義所有可能的路徑
 * - Action-driven：每個 Node 根據結果返回 action，決定下一步
 * - Shared state：所有 Node 共享同一個 stdClass
 *
 * 流程：
 *   RecordIssue → ClassifyIssue → HandleLogin/HandleBilling/HandleTechnical → done
 *                                         ↓ (login + failed)
 *                                     Escalate → CreateTicket → done
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

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

// ===== 所有工具定義 =====

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

// ===== PocketFlow Nodes =====
//
// 每個 Node 對應一個工具，三階段執行：
// 1. prep()：準備資料、決定可用工具
// 2. exec()：呼叫 OpenAI API
// 3. post()：更新 shared state，返回 action

class RecordIssueNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        // 只提供 record_issue 工具
        return [$allToolsDefinitions['record_issue']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client, $systemPrompt;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        global $client;

        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        // 處理 tool calls
        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName]\n";

                // 執行業務邏輯
                if ($functionName === 'record_issue') {
                    $shared->issue_description = $arguments['description'];
                    $result = [
                        'success' => true,
                        'message' => '問題已記錄',
                        'next_step' => '請判斷問題類型',
                    ];
                }

                $shared->messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            echo "  [狀態：issue_description={$shared->issue_description}]\n";

            // 記錄完成，進入分類階段
            return 'classify';
        }

        return 'classify';
    }
}

class ClassifyIssueNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        return [$allToolsDefinitions['classify_issue']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName]\n";

                if ($functionName === 'classify_issue') {
                    $shared->issue_type = $arguments['type'];
                    $result = [
                        'success' => true,
                        'type' => $arguments['type'],
                        'message' => "已分類為 {$arguments['type']} 問題",
                        'next_step' => '請處理該類型問題',
                    ];
                }

                $shared->messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            echo "  [狀態：issue_type={$shared->issue_type}]\n";

            // 根據類型返回對應的 action
            return $shared->issue_type;
        }

        return $shared->issue_type ?? 'default';
    }
}

class HandleLoginNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        return [$allToolsDefinitions['handle_login'], $allToolsDefinitions['escalate']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName]\n";

                if ($functionName === 'handle_login') {
                    $shared->login_data = [
                        'username' => $arguments['username'],
                        'error_message' => $arguments['error_message'],
                        'timestamp' => date('Y-m-d H:i:s'),
                    ];

                    // 密碼問題可解決
                    if (str_contains(strtolower($arguments['error_message']), 'password') ||
                        str_contains(strtolower($arguments['error_message']), '密碼')) {
                        $shared->resolved = true;
                        $result = [
                            'success' => true,
                            'resolved' => true,
                            'solution' => '已發送密碼重置連結至您的信箱',
                            'message' => '問題已解決',
                        ];
                    } else {
                        $result = [
                            'success' => true,
                            'resolved' => false,
                            'message' => '此問題需要人工處理',
                            'next_step' => '請使用 escalate 升級',
                        ];
                    }

                    $shared->messages[] = [
                        'role' => 'tool',
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'tool_call_id' => $toolCall->id,
                    ];

                    echo "  [狀態：resolved=" . ($shared->resolved ? 'true' : 'false') . "]\n";

                    // 如果解決了就結束，否則繼續讓 AI 可以呼叫 escalate
                    if ($shared->resolved) {
                        return 'done';
                    }
                } elseif ($functionName === 'escalate') {
                    $shared->escalated = true;
                    $result = [
                        'success' => true,
                        'message' => '準備升級至人工客服',
                        'reason' => $arguments['reason'],
                        'next_step' => '請創建工單',
                    ];

                    $shared->messages[] = [
                        'role' => 'tool',
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'tool_call_id' => $toolCall->id,
                    ];

                    echo "  [狀態：escalated=true]\n";

                    return 'escalate';
                }
            }
        }

        return 'default';
    }
}

class HandleBillingNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        return [$allToolsDefinitions['handle_billing']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                echo "  [執行：{$toolCall->function->name}]\n";

                $shared->billing_data = [
                    'request_type' => 'invoice',
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                $shared->resolved = true;

                $result = [
                    'success' => true,
                    'resolved' => true,
                    'solution' => '已確認付款，發票將於 3 天內寄出',
                    'message' => '帳務問題已處理',
                ];

                $shared->messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];

                echo "  [狀態：resolved=true]\n";
            }
        }

        return 'done';
    }
}

class HandleTechnicalNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        return [$allToolsDefinitions['handle_technical']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                echo "  [執行：{$toolCall->function->name}]\n";

                $shared->technical_data = [
                    'issue' => $shared->issue_description,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
                $shared->resolved = true;

                $result = [
                    'success' => true,
                    'resolved' => true,
                    'solution' => '已清除快取並重啟服務',
                    'message' => '技術問題已處理',
                ];

                $shared->messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];

                echo "  [狀態：resolved=true]\n";
            }
        }

        return 'done';
    }
}

class CreateTicketNode extends Node
{
    public function prep(\stdClass $shared): mixed
    {
        global $allToolsDefinitions;
        return [$allToolsDefinitions['create_ticket']];
    }

    public function exec(mixed $tools): mixed
    {
        global $client;
        $shared = $this->params['shared'];

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $shared->messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        return $response;
    }

    public function post(\stdClass $shared, mixed $prepResult, mixed $response): ?string
    {
        $message = $response->choices[0]->message;

        $messageRecord = ['role' => 'assistant', 'content' => $message->content];
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }
        $shared->messages[] = $messageRecord;

        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $arguments = json_decode($toolCall->function->arguments, true);
                echo "  [執行：{$toolCall->function->name}]\n";

                $ticket_id = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999);
                $shared->ticket_id = $ticket_id;

                $ticket_data = [
                    'ticket_id' => $ticket_id,
                    'summary' => $arguments['summary'],
                    'issue_type' => $shared->issue_type,
                    'issue_description' => $shared->issue_description,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                if (isset($shared->login_data)) {
                    $ticket_data['login_data'] = $shared->login_data;
                }

                $result = [
                    'success' => true,
                    'ticket_id' => $ticket_id,
                    'message' => "工單 {$ticket_id} 已創建，客服將於 24 小時內回覆",
                    'ticket_details' => $ticket_data,
                ];

                $shared->messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];

                echo "  [狀態：ticket_id={$ticket_id}]\n";
            }
        }

        return 'done';
    }
}

// ===== 建立 Flow（定義整個路由圖）=====
//
// 這是 PocketFlow 的核心：用 on('action')->next($node) 定義所有可能的路徑
// （對比 Demo 06 的 get_available_tools() 或 Demo 07 的 state machine config）
//
// 重要：PocketFlow 要求所有可能返回的 action 都必須在圖中定義
// ->next(null) 表示 Flow 結束

function createCustomerServiceFlow(): Flow
{
    $flow = new Flow();

    // 創建所有節點
    $record = new RecordIssueNode();
    $classify = new ClassifyIssueNode();
    $handleLogin = new HandleLoginNode();
    $handleBilling = new HandleBillingNode();
    $handleTechnical = new HandleTechnicalNode();
    $createTicket = new CreateTicketNode();

    // 定義路由圖（Graph 結構）
    // 注意：->next() 返回目標節點，所以不能鏈式呼叫同一來源的多個 action
    // 且 ->next(null) 返回 null，後面不能再接任何方法
    $flow->start($record);

    $record->on('classify')->next($classify);
    $record->on('default')->next(null);  // AI 只回覆文字時，Flow 結束

    $classify->on('login')->next($handleLogin);
    $classify->on('billing')->next($handleBilling);
    $classify->on('technical')->next($handleTechnical);
    $classify->on('default')->next(null);

    $handleLogin->on('escalate')->next($createTicket);
    $handleLogin->on('done')->next(null);
    $handleLogin->on('default')->next(null);

    $handleBilling->on('done')->next(null);
    $handleBilling->on('default')->next(null);

    $handleTechnical->on('done')->next(null);
    $handleTechnical->on('default')->next(null);

    $createTicket->on('done')->next(null);
    $createTicket->on('default')->next(null);

    return $flow;
}

// ===== 對話函數 =====

function chat(string $userMessage, Flow $flow, \stdClass $shared): void
{
    echo "\n客戶：$userMessage\n";

    $shared->messages[] = ['role' => 'user', 'content' => $userMessage];

    // 將 shared 放入 params 供 Node.exec() 使用
    $flow->setParams(['shared' => $shared]);

    // 執行 Flow，自動走完整個流程
    $flow->run($shared);
}

// ===== 示範 =====

echo "=== PocketFlow Routing - Graph-based 動態路由 ===\n";
echo "特點：用 Flow + Node + on('action')->next() 定義路由圖，自動執行整個流程\n";

echo "\n\n【場景 1：登入問題 - 密碼錯誤】\n";
$shared1 = new \stdClass();
$shared1->messages = [['role' => 'system', 'content' => $systemPrompt]];
$shared1->issue_description = null;
$shared1->issue_type = null;
$shared1->resolved = false;
$shared1->escalated = false;
$shared1->login_data = null;

$flow1 = createCustomerServiceFlow();
chat("你好，我無法登入", $flow1, $shared1);
chat("帳號是 john@example.com，說密碼錯誤", $flow1, $shared1);
chat("謝謝", $flow1, $shared1);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if (isset($shared1->login_data)) {
    echo "登入資料：" . json_encode($shared1->login_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n【場景 2：登入問題 - 需要升級】\n";
$shared2 = new \stdClass();
$shared2->messages = [['role' => 'system', 'content' => $systemPrompt]];
$shared2->issue_description = null;
$shared2->issue_type = null;
$shared2->resolved = false;
$shared2->escalated = false;
$shared2->login_data = null;

$flow2 = createCustomerServiceFlow();
chat("我的帳號被鎖定了", $flow2, $shared2);
chat("帳號是 mary@example.com，錯誤是 Account Locked", $flow2, $shared2);
chat("好的", $flow2, $shared2);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if (isset($shared2->login_data)) {
    echo "登入資料：" . json_encode($shared2->login_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
if (isset($shared2->ticket_id)) {
    echo "工單號碼：{$shared2->ticket_id}\n";
}

echo "\n\n【場景 3：帳務問題】\n";
$shared3 = new \stdClass();
$shared3->messages = [['role' => 'system', 'content' => $systemPrompt]];
$shared3->issue_description = null;
$shared3->issue_type = null;
$shared3->resolved = false;
$shared3->billing_data = null;

$flow3 = createCustomerServiceFlow();
chat("我需要發票", $flow3, $shared3);
chat("謝謝", $flow3, $shared3);

echo "\n--- 檢視記錄的狀態資料 ---\n";
if (isset($shared3->billing_data)) {
    echo "帳務資料：" . json_encode($shared3->billing_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\n\n=== 完成 ===\n";
echo "\n與其他實作方式的比較：\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "           Demo 06              Demo 07                PocketFlow\n";
echo "           手寫 dynamic         State Machine          Graph + Node\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "路由方式    get_available_tools  getPossibleTransitions  on('action')->next()\n";
echo "狀態管理    global \$state        ServiceTicket object   shared (stdClass)\n";
echo "流程定義    if/elseif           config                 Flow graph\n";
echo "執行模式    手動 while loop      sm->apply()            flow->run()\n";
echo "工具執行    function            function               Node (prep/exec/post)\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "\nPocketFlow 的特點：\n";
echo "✓ Graph-based：預先定義所有節點和連接，視覺化清晰\n";
echo "✓ Action-driven：每個 Node 返回 action 決定下一步\n";
echo "✓ 三階段執行：prep（準備）/exec（執行）/post（後處理）\n";
echo "✓ 適合 LLM 編排：原本設計就是為了編排 LLM 呼叫流程\n";
echo "✓ 輕量級：單一檔案，無外部依賴（除了 ReactPHP 的 async 支援）\n";
echo "\n適用場景：\n";
echo "- 需要視覺化的流程圖\n";
echo "- 複雜的 LLM multi-agent 系統\n";
echo "- 需要 async/parallel 執行的場景\n";
echo "- 追求最小化依賴的專案\n";
