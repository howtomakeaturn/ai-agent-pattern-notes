<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

/**
 * Graph-Based Agent Engine - 圖驅動的 Agent 引擎
 *
 * 與 Demo 06 的根本差異：
 * - Demo 06：流程寫在 CODE 裡（if/else 決定可用工具）
 * - Demo 08：流程寫在 DATA 裡（graph 定義節點和邊），引擎是通用的
 *
 * 這就是 Vapi、Voiceflow、Bland AI 等 SaaS 平台的底層架構：
 * 使用者在 UI 上拖拉定義流程 → 存成 graph → 通用引擎執行
 *
 * 核心概念：
 * 1. 每個節點 = 一個步驟（有自己的指示）
 * 2. 每個節點有多個可能的「結果」（outcome），每個結果指向下一個節點
 * 3. LLM 在節點內與用戶對話，完成後用 select_outcome 選擇結果
 * 4. 引擎根據結果跳到下一個節點
 * 5. 引擎本身不知道業務邏輯，換一個 graph 就是完全不同的 agent
 */

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ===== 流程定義：純資料，不是程式碼 =====
//
// 這個 array 可以來自：資料庫、JSON 檔、前端 UI 拖拉產生
// 引擎不需要改一行程式就能執行任何流程
//
// 對比 Demo 06 的 get_available_tools()（程式碼控制流程），
// 這裡整個流程是「資料」：

$graph = [
    'start_node' => 'identify_issue',
    'nodes' => [
        'identify_issue' => [
            'name' => '識別問題',
            'instructions' => '親切地問候客戶，請他們描述遇到的問題。',
            'outcomes' => [
                'issue_identified' => [
                    'description' => '客戶已清楚描述問題',
                    'next' => 'determine_type',
                ],
                'need_more_info' => [
                    'description' => '需要客戶提供更多資訊',
                    'next' => 'identify_issue',  // 自迴圈：回到同一個節點
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
            'outcomes' => [
                'end' => [
                    'description' => '已完成轉接說明',
                    'next' => null,  // null = 對話結束
                ],
            ],
        ],
        'wrap_up' => [
            'name' => '結束對話',
            'instructions' => '確認問題已解決，詢問是否還有其他問題。如果沒有，禮貌地結束對話。',
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

// ===== 通用 Graph 引擎 =====
//
// 這個 class 不知道「客服」、「登入問題」、「帳務」...
// 它只知道：節點、邊、LLM、select_outcome
// 換一個 $graph 就是完全不同的 agent

class GraphEngine
{
    private string|null $currentNodeId;
    private array $graph;
    private array $messages = [];
    private $client;

    public function __construct(array $graph, $client)
    {
        $this->graph = $graph;
        $this->client = $client;
        $this->currentNodeId = $graph['start_node'];

        $this->messages[] = [
            'role' => 'system',
            'content' => "你是一個智能助手。依照每個步驟的指示與客戶互動。\n" .
                         "當你認為當前步驟已完成，使用 select_outcome 工具選擇結果進入下一步。\n" .
                         "重要：在選擇 outcome 之前，先回應客戶。",
        ];

        $this->enterNode($this->currentNodeId);
    }

    // 進入一個節點：加入該節點的指示
    private function enterNode(string $nodeId): void
    {
        $node = $this->graph['nodes'][$nodeId];
        echo "  [進入節點：{$nodeId}（{$node['name']}）]\n";

        $this->messages[] = [
            'role' => 'system',
            'content' => "【當前步驟：{$node['name']}】\n指示：{$node['instructions']}",
        ];
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

                    $this->messages[] = [
                        'role' => 'tool',
                        'content' => json_encode([
                            'outcome' => $outcome,
                            'transitioned_to' => $nextNodeId ?? 'END',
                        ]),
                        'tool_call_id' => $toolCall->id,
                    ];

                    // 轉到下一個節點
                    $this->currentNodeId = $nextNodeId;
                    if ($nextNodeId !== null) {
                        $this->enterNode($nextNodeId);
                    }
                }

                // 流程結束
                if ($this->currentNodeId === null) {
                    break;
                }

                // 新節點可能不需要用戶輸入就能行動
                // （例如 determine_type 根據上下文直接判斷）
                continue;
            } else {
                // 純文字回應，等待下一次用戶輸入
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

echo "=== Graph-Based Agent Engine - 圖驅動的 Agent 引擎 ===\n";
echo "特點：流程定義在 DATA（graph）裡，引擎是通用的，換 graph 就是不同 agent\n";

echo "\n\n【場景 1：登入問題 - 密碼錯誤】\n";
$engine1 = new GraphEngine($graph, $client);
$engine1->chat("你好，我無法登入");
$engine1->chat("帳號是 john@example.com，說密碼錯誤");
$engine1->chat("謝謝，沒有其他問題了");

echo "\n\n【場景 2：登入問題 - 帳號鎖定（需要升級）】\n";
$engine2 = new GraphEngine($graph, $client);
$engine2->chat("我的帳號被鎖定了，帳號是 mary@example.com");
$engine2->chat("好的，謝謝");

echo "\n\n【場景 3：帳務問題】\n";
$engine3 = new GraphEngine($graph, $client);
$engine3->chat("我需要發票");
$engine3->chat("謝謝");

echo "\n\n=== 完成 ===\n";
echo "\n核心差異：\n";
echo "──────────────────────────────────────────────────\n";
echo "  Demo 06（手寫 agent）       Demo 08（圖驅動引擎）\n";
echo "──────────────────────────────────────────────────\n";
echo "  流程寫在 CODE 裡             流程寫在 DATA 裡\n";
echo "  改流程 = 改程式碼             改流程 = 改 graph 資料\n";
echo "  每個 tool 要寫實作            節點只需寫指示文字\n";
echo "  一個 agent                   一個引擎跑任何 agent\n";
echo "  開發者使用                   非技術人員可用（配 UI）\n";
echo "──────────────────────────────────────────────────\n";
echo "\n這就是 Vapi、Voiceflow 等 SaaS 平台的底層原理：\n";
echo "  前端 UI 拖拉節點 → 產生 graph（如上面的 \$graph 陣列）→ 通用引擎執行\n";
echo "\n注意 GraphEngine class 裡面沒有任何「客服」相關的程式碼：\n";
echo "  它只知道 節點、邊、LLM、select_outcome\n";
echo "  換一個 \$graph 就是完全不同的 agent（銷售、預約、問卷...）\n";
