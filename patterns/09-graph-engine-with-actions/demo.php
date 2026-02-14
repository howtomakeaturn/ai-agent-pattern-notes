<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Graph Engine + Actions - 圖驅動引擎 + 外部動作
 *
 * 基於 Demo 08 擴充，在節點上掛 actions，讓 agent 真正「做事」
 *
 * Demo 08 的限制：LLM 只是「說」它做了，實際沒有觸發任何外部動作
 * Demo 09 的改進：節點可以掛 actions，在進入節點或選擇結果時自動執行
 *
 * Action 類型（模擬）：
 * - api_call：呼叫外部 API
 * - email：發送 Email
 * - webhook：通知外部系統
 * - db_write：寫入資料庫
 * - transfer：轉接真人客服
 *
 * 時機：
 * - on_enter：進入節點時立即執行
 * - on_outcome：選擇特定結果時執行
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ===== Action Handlers：模擬外部動作 =====
//
// 正式產品裡這些會是真正的 API call / DB write / Email send
// 這裡用 echo 模擬，展示觸發時機和資料流

$actionHandlers = [
    'api_call' => function (array $config, array $context) {
        $method = $config['method'] ?? 'GET';
        $url = $config['url'] ?? '';
        // 模擬 API 回應
        $mockResponses = [
            '/user/lookup' => ['found' => true, 'username' => $context['user_input'] ?? 'unknown', 'status' => 'active'],
            '/password/reset' => ['success' => true, 'reset_link_sent' => true],
            '/billing/status' => ['paid' => true, 'last_invoice' => '2026-01-15'],
            '/system/health' => ['status' => 'ok', 'cache_cleared' => true],
        ];
        // 用 URL 的最後一段來匹配 mock
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $response = $mockResponses[$path] ?? ['status' => 'ok'];
        echo "    → [API] {$method} {$url} => " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n";
        return $response;
    },

    'email' => function (array $config, array $context) {
        $template = $config['template'] ?? 'generic';
        $to = $config['to'] ?? $context['user_email'] ?? 'customer@example.com';
        echo "    → [Email] 寄送 \"{$template}\" 模板至 {$to}\n";
        return ['sent' => true, 'template' => $template, 'to' => $to];
    },

    'webhook' => function (array $config, array $context) {
        $url = $config['url'] ?? '';
        $payload = array_merge($config['payload'] ?? [], ['context' => $context]);
        echo "    → [Webhook] POST {$url}\n";
        return ['delivered' => true, 'url' => $url];
    },

    'db_write' => function (array $config, array $context) {
        $table = $config['table'] ?? 'logs';
        $data = $config['data'] ?? [];
        // 把 context 變數代入 data
        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, '$')) {
                $contextKey = substr($value, 1);
                $data[$key] = $context[$contextKey] ?? $value;
            }
        }
        $data['timestamp'] = date('Y-m-d H:i:s');
        echo "    → [DB] 寫入 {$table}: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
        return ['written' => true, 'table' => $table, 'id' => rand(1000, 9999)];
    },

    'transfer' => function (array $config, array $context) {
        $department = $config['department'] ?? 'general';
        $priority = $config['priority'] ?? 'normal';
        $ticketId = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999);
        echo "    → [Transfer] 轉接至 {$department}（優先級：{$priority}），工單號：{$ticketId}\n";
        return ['transferred' => true, 'department' => $department, 'ticket_id' => $ticketId];
    },
];

// ===== 流程定義：資料 + Actions =====
//
// 對比 Demo 08，每個節點多了 actions 欄位
// on_enter: 進入節點時觸發
// on_outcome: 選擇特定結果時觸發

$graph = [
    'start_node' => 'identify_issue',
    'nodes' => [
        'identify_issue' => [
            'name' => '識別問題',
            'instructions' => '親切地問候客戶，請他們描述遇到的問題。',
            'actions' => [
                'on_enter' => [
                    ['type' => 'db_write', 'table' => 'conversations', 'data' => ['event' => 'session_start']],
                ],
            ],
            'outcomes' => [
                'issue_identified' => [
                    'description' => '客戶已清楚描述問題',
                    'next' => 'determine_type',
                ],
                'need_more_info' => [
                    'description' => '需要客戶提供更多資訊',
                    'next' => 'identify_issue',
                ],
            ],
        ],
        'determine_type' => [
            'name' => '判斷問題類型',
            'instructions' => '根據前面的對話內容，判斷問題類型。不需要再問客戶，直接判斷。',
            'outcomes' => [
                'login_problem' => [
                    'description' => '登入或帳號相關問題',
                    'next' => 'resolve_login',
                ],
                'billing_question' => [
                    'description' => '帳務、發票、付款問題',
                    'next' => 'resolve_billing',
                ],
                'technical_issue' => [
                    'description' => '功能異常、技術問題',
                    'next' => 'resolve_technical',
                ],
            ],
        ],
        'resolve_login' => [
            'name' => '處理登入問題',
            'instructions' => '協助客戶解決登入問題。如果是密碼問題，告知已發送重置連結。如果是帳號鎖定等無法直接解決的問題，需要升級。',
            'actions' => [
                'on_enter' => [
                    ['type' => 'api_call', 'url' => 'https://api.example.com/user/lookup', 'method' => 'GET'],
                ],
                'on_outcome' => [
                    'resolved' => [
                        ['type' => 'api_call', 'url' => 'https://api.example.com/password/reset', 'method' => 'POST'],
                        ['type' => 'email', 'template' => 'password_reset'],
                    ],
                ],
            ],
            'outcomes' => [
                'resolved' => [
                    'description' => '問題已解決（如密碼重置）',
                    'next' => 'wrap_up',
                ],
                'needs_escalation' => [
                    'description' => '無法解決，需要人工介入',
                    'next' => 'handoff',
                ],
            ],
        ],
        'resolve_billing' => [
            'name' => '處理帳務問題',
            'instructions' => '協助客戶處理帳務問題。確認付款狀態，處理發票請求。告知發票將於 3 天內寄出。',
            'actions' => [
                'on_enter' => [
                    ['type' => 'api_call', 'url' => 'https://api.example.com/billing/status', 'method' => 'GET'],
                ],
                'on_outcome' => [
                    'resolved' => [
                        ['type' => 'email', 'template' => 'invoice_request_confirmed'],
                    ],
                ],
            ],
            'outcomes' => [
                'resolved' => [
                    'description' => '帳務問題已處理',
                    'next' => 'wrap_up',
                ],
                'needs_escalation' => [
                    'description' => '無法解決，需要人工介入',
                    'next' => 'handoff',
                ],
            ],
        ],
        'resolve_technical' => [
            'name' => '處理技術問題',
            'instructions' => '協助客戶排除技術問題。建議清除快取、重啟等操作。',
            'actions' => [
                'on_enter' => [
                    ['type' => 'api_call', 'url' => 'https://api.example.com/system/health', 'method' => 'GET'],
                ],
            ],
            'outcomes' => [
                'resolved' => [
                    'description' => '技術問題已解決',
                    'next' => 'wrap_up',
                ],
                'needs_escalation' => [
                    'description' => '無法解決，需要人工介入',
                    'next' => 'handoff',
                ],
            ],
        ],
        'handoff' => [
            'name' => '轉接人工',
            'instructions' => '告知客戶將轉接至人工客服。簡要總結問題，讓客戶安心。',
            'actions' => [
                'on_enter' => [
                    ['type' => 'transfer', 'department' => 'support-l2', 'priority' => 'high'],
                    ['type' => 'webhook', 'url' => 'https://your-backend.com/escalation', 'payload' => ['severity' => 'high']],
                ],
            ],
            'outcomes' => [
                'end' => [
                    'description' => '已完成轉接說明',
                    'next' => null,
                ],
            ],
        ],
        'wrap_up' => [
            'name' => '結束對話',
            'instructions' => '確認問題已解決，詢問是否還有其他問題。如果沒有，禮貌地結束對話。',
            'actions' => [
                'on_outcome' => [
                    'end' => [
                        ['type' => 'db_write', 'table' => 'conversations', 'data' => ['event' => 'session_end', 'status' => 'resolved']],
                    ],
                ],
            ],
            'outcomes' => [
                'end' => [
                    'description' => '客戶滿意，結束對話',
                    'next' => null,
                ],
                'new_issue' => [
                    'description' => '客戶有新的問題',
                    'next' => 'identify_issue',
                ],
            ],
        ],
    ],
];

// ===== 通用 Graph 引擎（擴充 actions 支援）=====

class GraphEngine
{
    private string|null $currentNodeId;
    private array $graph;
    private array $messages = [];
    private array $actionHandlers;
    private array $context = [];     // 累積 action 結果，供後續使用
    private $client;

    public function __construct(array $graph, $client, array $actionHandlers)
    {
        $this->graph = $graph;
        $this->client = $client;
        $this->actionHandlers = $actionHandlers;
        $this->currentNodeId = $graph['start_node'];

        $this->messages[] = [
            'role' => 'system',
            'content' => "你是一個智能助手。依照每個步驟的指示與客戶互動。\n" .
                         "當你認為當前步驟已完成，使用 select_outcome 工具選擇結果進入下一步。\n" .
                         "重要：在選擇 outcome 之前，先回應客戶。",
        ];

        $this->enterNode($this->currentNodeId);
    }

    // 執行一組 actions
    private function executeActions(array $actions): array
    {
        $results = [];
        foreach ($actions as $action) {
            $type = $action['type'] ?? null;
            if ($type && isset($this->actionHandlers[$type])) {
                $result = ($this->actionHandlers[$type])($action, $this->context);
                $results[] = ['type' => $type, 'result' => $result];
                // 把 action 結果存入 context，供後續 action 或 LLM 使用
                $this->context["last_{$type}"] = $result;
            }
        }
        return $results;
    }

    // 進入一個節點
    private function enterNode(string $nodeId): void
    {
        $node = $this->graph['nodes'][$nodeId];
        echo "  [進入節點：{$nodeId}（{$node['name']}）]\n";

        // 執行 on_enter actions
        $actionResults = [];
        if (!empty($node['actions']['on_enter'])) {
            $actionResults = $this->executeActions($node['actions']['on_enter']);
        }

        // 把節點指示 + action 結果一起告訴 LLM
        $systemContent = "【當前步驟：{$node['name']}】\n指示：{$node['instructions']}";
        if (!empty($actionResults)) {
            $systemContent .= "\n\n以下是系統查詢到的資料，可以參考：\n" .
                json_encode($actionResults, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $this->messages[] = ['role' => 'system', 'content' => $systemContent];
    }

    // 根據當前節點的 outcomes 動態建立 select_outcome 工具
    private function buildOutcomeTool(): array
    {
        $node = $this->graph['nodes'][$this->currentNodeId];
        $outcomeKeys = array_keys($node['outcomes']);

        $descriptions = [];
        foreach ($node['outcomes'] as $key => $outcome) {
            $descriptions[] = "- {$key}: {$outcome['description']}";
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => 'select_outcome',
                'description' => "選擇當前步驟的結果：\n" . implode("\n", $descriptions),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'outcome' => [
                            'type' => 'string',
                            'enum' => $outcomeKeys,
                            'description' => '選擇的結果',
                        ],
                    ],
                    'required' => ['outcome'],
                ],
            ],
        ];
    }

    // 處理一輪用戶對話
    public function chat(string $userMessage): void
    {
        if ($this->currentNodeId === null) {
            echo "  [對話已結束]\n";
            return;
        }

        echo "\n客戶：{$userMessage}\n";
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];

        // 記錄用戶輸入到 context
        $this->context['user_input'] = $userMessage;

        while ($this->currentNodeId !== null) {
            $tool = $this->buildOutcomeTool();

            $response = $this->client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => $this->messages,
                'tools' => [$tool],
                'tool_choice' => 'auto',
            ]);

            $message = $response->choices[0]->message;

            $messageRecord = ['role' => 'assistant', 'content' => $message->content];
            if ($message->toolCalls) {
                $messageRecord['tool_calls'] = $message->toolCalls;
            }
            $this->messages[] = $messageRecord;

            if ($message->content) {
                echo "助手：{$message->content}\n";
            }

            if ($message->toolCalls) {
                foreach ($message->toolCalls as $toolCall) {
                    $args = json_decode($toolCall->function->arguments, true);
                    $outcome = $args['outcome'];

                    $node = $this->graph['nodes'][$this->currentNodeId];
                    $nextNodeId = $node['outcomes'][$outcome]['next'] ?? null;

                    echo "  [結果：{$outcome} → 下一步：" . ($nextNodeId ?? '結束') . "]\n";

                    // 執行 on_outcome actions
                    if (!empty($node['actions']['on_outcome'][$outcome])) {
                        $this->executeActions($node['actions']['on_outcome'][$outcome]);
                    }

                    $this->messages[] = [
                        'role' => 'tool',
                        'content' => json_encode([
                            'outcome' => $outcome,
                            'transitioned_to' => $nextNodeId ?? 'END',
                        ]),
                        'tool_call_id' => $toolCall->id,
                    ];

                    $this->currentNodeId = $nextNodeId;
                    if ($nextNodeId !== null) {
                        $this->enterNode($nextNodeId);
                    }
                }

                if ($this->currentNodeId === null) {
                    break;
                }

                continue;
            } else {
                break;
            }
        }
    }

    public function isFinished(): bool
    {
        return $this->currentNodeId === null;
    }
}

// ===== 示範 =====

echo "=== Graph Engine + Actions - 圖驅動引擎 + 外部動作 ===\n";
echo "特點：節點可以掛 actions，進入節點或選擇結果時自動觸發外部動作\n";

echo "\n\n【場景 1：登入問題 - 密碼錯誤（會觸發：查用戶 → 重置密碼 → 寄信）】\n";
$engine1 = new GraphEngine($graph, $client, $actionHandlers);
$engine1->chat("你好，我無法登入");
$engine1->chat("帳號是 john@example.com，說密碼錯誤");
$engine1->chat("謝謝，沒有其他問題了");

echo "\n\n【場景 2：登入問題 - 帳號鎖定（會觸發：查用戶 → 轉接 → webhook）】\n";
$engine2 = new GraphEngine($graph, $client, $actionHandlers);
$engine2->chat("我的帳號被鎖定了，帳號是 mary@example.com");
$engine2->chat("好的，謝謝");

echo "\n\n【場景 3：帳務問題（會觸發：查帳務 → 寄確認信）】\n";
$engine3 = new GraphEngine($graph, $client, $actionHandlers);
$engine3->chat("我需要發票");
$engine3->chat("謝謝");

echo "\n\n=== 完成 ===\n";
echo "\n與 Demo 08 的差異：\n";
echo "──────────────────────────────────────────────────────\n";
echo "  Demo 08（純對話）             Demo 09（對話 + Actions）\n";
echo "──────────────────────────────────────────────────────\n";
echo "  LLM「說」它做了               真正觸發外部動作 ✓\n";
echo "  節點只有 instructions          節點有 instructions + actions\n";
echo "  無副作用                       打 API、寄信、寫 DB、轉接\n";
echo "  適合純對話腳本                 適合正式產品\n";
echo "──────────────────────────────────────────────────────\n";
echo "\nAction 觸發時機：\n";
echo "  on_enter：進入節點時立即執行（如查用戶資料）\n";
echo "  on_outcome：選擇特定結果時執行（如密碼重置 → 寄信）\n";
echo "\nAction 結果會注入 LLM 的 context：\n";
echo "  例如查到用戶狀態是 active，LLM 就能據此回應客戶\n";
echo "  引擎本身仍然是通用的 — 不知道什麼是客服\n";
