<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use Finite\State;
use Finite\StateMachine;
use Finite\Transition\Transition;
use Finite\Event\CanTransitionEvent;

/**
 * Finite State Machine Routing - 使用 yohang/finite 的狀態機路由
 *
 * 使用 yohang/finite 2.0 重構 Demo 06（Dynamic Routing）
 *
 * 與 Demo 07（winzou/state-machine）的差異：
 * 1. 用 PHP Enum 定義狀態（type-safe，IDE 自動完成）
 * 2. Transitions 定義在 Enum 本身（狀態和轉換耦合在一起，更直覺）
 * 3. 用 PSR-14 Events 做 guard（標準介面，不是自訂 callback config）
 * 4. Enum 可以加 method（如 isDone()），state 本身就有行為
 *
 * 與 Demo 07 相同的核心概念：
 * - 宣告式定義流程（看 Enum 就知道所有狀態轉換）
 * - getReachablesTransitions() 自動計算可用工具
 * - 工具函數不管 phase，只做業務邏輯
 *
 * 流程：
 *   initial → recorded → classified → done
 *                              ↘ escalated → done
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ===== Step 1：用 PHP Enum 定義狀態 =====
//
// 對比 Demo 07 的字串陣列 'states' => ['initial', 'recorded', 'classified', 'escalated', 'done']，
// Finite 2.0 用 Enum 定義，type-safe 且可以加 method：

enum Phase: string implements State
{
    case INITIAL    = 'initial';
    case RECORDED   = 'recorded';
    case CLASSIFIED = 'classified';
    case ESCALATED  = 'escalated';
    case DONE       = 'done';

    // 對比 Demo 07 config 裡的 'transitions' 陣列，
    // 這裡直接在 Enum 中宣告所有轉換：
    public static function getTransitions(): array
    {
        return [
            new Transition('record_issue',     [self::INITIAL],    self::RECORDED),
            new Transition('classify_issue',   [self::RECORDED],   self::CLASSIFIED),
            new Transition('handle_login',     [self::CLASSIFIED], self::DONE),
            new Transition('handle_billing',   [self::CLASSIFIED], self::DONE),
            new Transition('handle_technical', [self::CLASSIFIED], self::DONE),
            new Transition('escalate',         [self::CLASSIFIED], self::ESCALATED),
            new Transition('create_ticket',    [self::ESCALATED],  self::DONE),
        ];
    }

    // Enum 可以有 method！這是 winzou config 做不到的
    public function isDone(): bool
    {
        return $this === self::DONE;
    }

    public function label(): string
    {
        return match ($this) {
            self::INITIAL    => '等待記錄問題',
            self::RECORDED   => '等待分類問題',
            self::CLASSIFIED => '等待處理問題',
            self::ESCALATED  => '等待建立工單',
            self::DONE       => '已完成',
        };
    }
}

// ===== Step 2：Stateful 對象 =====
//
// 注意：phase 的型別是 Phase enum，不是 string

class ServiceTicket
{
    private Phase $phase = Phase::INITIAL;

    public ?string $issue_description = null;
    public ?string $issue_type = null;       // login / billing / technical
    public bool $resolved = false;
    public bool $escalated = false;
    public ?array $login_data = null;
    public ?array $billing_data = null;
    public ?array $technical_data = null;
    public ?string $ticket_id = null;

    public function getPhase(): Phase { return $this->phase; }
    public function setPhase(Phase $phase): void { $this->phase = $phase; }
}

// ===== Step 3：建立 State Machine + Guards =====
//
// 對比 Demo 07 把 guards 寫在 config 的 callbacks 裡，
// Finite 2.0 用 PSR-14 Event Listener：

function createStateMachine(ServiceTicket $ticket): StateMachine
{
    $sm = new StateMachine();

    // Guards：用 CanTransitionEvent 動態阻擋不合法的 transition
    // （對比 Demo 07 的 'guard' => ['guard-login' => ['on' => ..., 'do' => fn() => ...]]）
    $sm->getDispatcher()->addEventListener(CanTransitionEvent::class, function (CanTransitionEvent $event) use ($ticket) {
        $transitionName = $event->getTransition()->getName();

        // 根據 issue_type 限制可用的 handler
        match ($transitionName) {
            'handle_login'     => $ticket->issue_type !== 'login'     ? $event->blockTransition() : null,
            'handle_billing'   => $ticket->issue_type !== 'billing'   ? $event->blockTransition() : null,
            'handle_technical' => $ticket->issue_type !== 'technical' ? $event->blockTransition() : null,
            'escalate'         => $ticket->issue_type !== 'login'     ? $event->blockTransition() : null,
            default            => null,
        };
    });

    return $sm;
}

// ===== 工具定義（同 Demo 06、07）=====

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

// ===== 工具實現（同 Demo 07：不管 phase，只做業務邏輯）=====

function record_issue(ServiceTicket $ticket, $description) {
    $ticket->issue_description = $description;
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
        // 核心：用 getReachablesTransitions() 取得可用工具
        // Finite 2.0 回傳的是 Transition 物件，需要取 getName()
        $transitions = $sm->getReachablesTransitions($ticket);
        $available_tools = [];
        foreach ($transitions as $t) {
            $name = $t->getName();
            if (isset($allToolsDefinitions[$name])) {
                $available_tools[] = $allToolsDefinitions[$name];
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

                if ($shouldTransition && $sm->can($ticket, $functionName)) {
                    $sm->apply($ticket, $functionName);
                }

                $messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            // 用 Enum 的 value 和 label() 顯示狀態
            $phase = $ticket->getPhase();
            echo "  [狀態：phase={$phase->value}（{$phase->label()}）, type={$ticket->issue_type}, resolved=" . ($ticket->resolved ? 'true' : 'false') . "]\n";
        } else {
            break;
        }
    }
}

// ===== 示範 =====

echo "=== Finite State Machine Routing - yohang/finite 狀態機路由 ===\n";
echo "特點：用 PHP Enum 定義狀態和轉換，PSR-14 Event 做 guard\n";

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
echo "\n三種實作方式比較：\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "           Demo 06              Demo 07                Demo 09\n";
echo "           手寫 if/else         winzou/state-machine   yohang/finite\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "狀態定義    字串 + 陣列          config 字串陣列         PHP Enum ✓\n";
echo "轉換定義    get_available_tools  config transitions     Enum::getTransitions()\n";
echo "Guard      if/elseif           config callbacks        PSR-14 Event\n";
echo "取可用工具  手寫 ~30 行          getPossibleTransitions  getReachablesTransitions\n";
echo "型別安全    ✗ 字串比對           ✗ 字串比對              ✓ Enum type-safe\n";
echo "狀態有行為  ✗                   ✗                      ✓ Enum methods\n";
echo "─────────────────────────────────────────────────────────────────────\n";
echo "\nFinite 2.0 的優勢：\n";
echo "✓ PHP Enum 定義狀態（type-safe，IDE 自動完成，拼錯直接報錯）\n";
echo "✓ 狀態可以有 method（如 isDone()、label()），state 本身就是物件\n";
echo "✓ Transitions 定義在 Enum 內（狀態和轉換放在一起，更符合 DDD）\n";
echo "✓ PSR-14 標準事件介面（可以和其他 PSR-14 相容的套件整合）\n";
echo "✓ 現代 PHP 風格（readonly、Enum、named arguments）\n";
