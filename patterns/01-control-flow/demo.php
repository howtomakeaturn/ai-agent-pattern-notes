<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// 載入 .env 檔案
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * Control Flow Pattern - 餐廳訂餐系統
 *
 * 這個範例展示如何在 AI agent 中實現控制流程：
 *
 * 可選步驟：
 * - 顯示菜單 PDF 連結
 *
 * 必要步驟（有順序要求）：
 * 1. 詢問並確認食材過敏資訊
 * 2. 確認訂單內容
 * 3. 提供付款連結
 *
 * 關鍵概念：
 * - 某些動作是可選的（optional）
 * - 某些動作是必須的（required）
 * - 必須的動作之間有順序依賴（sequential dependencies）
 */

// 初始化 OpenAI 客戶端
$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// 追蹤訂餐流程的狀態
$orderState = [
    'menu_shown' => false,           // 可選：是否已顯示菜單
    'allergy_confirmed' => false,    // 必須：是否已確認過敏資訊
    'order_confirmed' => false,      // 必須：是否已確認訂單
    'payment_provided' => false,     // 必須：是否已提供付款連結
    'allergies' => [],               // 儲存過敏資訊
    'order_items' => [],             // 儲存訂單項目
];

// 定義可用的工具（functions）
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'show_menu',
            'description' => '顯示餐廳菜單 PDF 連結給客戶。這是可選的動作，可以在對話的任何時候執行。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'confirm_allergies',
            'description' => '確認客戶的食材過敏資訊。這是必須的第一步，在確認訂單之前必須完成。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'allergies' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => '客戶過敏的食材列表，如果沒有過敏則為空陣列',
                    ],
                ],
                'required' => ['allergies'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'confirm_order',
            'description' => '確認客戶的訂單內容。必須在確認過敏資訊之後才能執行。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => '餐點名稱'],
                                'quantity' => ['type' => 'integer', 'description' => '數量'],
                            ],
                        ],
                        'description' => '訂單項目列表',
                    ],
                ],
                'required' => ['items'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'provide_payment_link',
            'description' => '提供付款連結給客戶。必須在確認訂單之後才能執行。這是完成訂餐流程的最後一步。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'amount' => [
                        'type' => 'number',
                        'description' => '付款金額',
                    ],
                ],
                'required' => ['amount'],
            ],
        ],
    ],
];

// 實作工具函數
function show_menu() {
    global $orderState;
    $orderState['menu_shown'] = true;
    return [
        'success' => true,
        'menu_url' => 'https://example.com/restaurant-menu.pdf',
        'message' => '菜單連結已提供',
    ];
}

function confirm_allergies($allergies) {
    global $orderState;

    $orderState['allergy_confirmed'] = true;
    $orderState['allergies'] = $allergies;

    return [
        'success' => true,
        'allergies' => $allergies,
        'message' => empty($allergies)
            ? '已確認：客戶沒有食材過敏'
            : '已確認過敏資訊：' . implode(', ', $allergies),
    ];
}

function confirm_order($items) {
    global $orderState;

    // 檢查前置條件：必須先確認過敏資訊
    if (!$orderState['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認過敏資訊才能確認訂單',
            'required_step' => 'confirm_allergies',
        ];
    }

    $orderState['order_confirmed'] = true;
    $orderState['order_items'] = $items;

    return [
        'success' => true,
        'items' => $items,
        'message' => '訂單已確認',
    ];
}

function provide_payment_link($amount) {
    global $orderState;

    // 檢查前置條件：必須先確認過敏和訂單
    if (!$orderState['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認過敏資訊',
            'required_step' => 'confirm_allergies',
        ];
    }

    if (!$orderState['order_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認訂單',
            'required_step' => 'confirm_order',
        ];
    }

    $orderState['payment_provided'] = true;

    return [
        'success' => true,
        'payment_url' => "https://pay.example.com/order?amount={$amount}",
        'amount' => $amount,
        'message' => '付款連結已生成',
    ];
}

// 執行工具函數
function executeTool($functionName, $arguments) {
    switch ($functionName) {
        case 'show_menu':
            return show_menu();
        case 'confirm_allergies':
            return confirm_allergies($arguments['allergies']);
        case 'confirm_order':
            return confirm_order($arguments['items']);
        case 'provide_payment_link':
            return provide_payment_link($arguments['amount']);
        default:
            return ['error' => "Unknown function: $functionName"];
    }
}

// 系統提示詞
$systemPrompt = <<<PROMPT
你是一個餐廳訂餐助手。你的任務是協助客戶完成訂餐流程。

重要的流程控制規則：

可選步驟：
- 顯示菜單：你可以在對話的任何時候提供菜單連結，這不是必須的

必須步驟（按順序）：
1. 確認過敏資訊：詢問客戶是否對任何食材過敏，並使用 confirm_allergies 函數記錄
2. 確認訂單：在客戶決定要點什麼之後，使用 confirm_order 函數確認訂單
3. 提供付款連結：最後使用 provide_payment_link 函數提供付款連結

注意：
- 你必須按照順序完成這三個必須步驟
- 不能跳過任何必須步驟
- 如果客戶想要直接付款但還沒確認過敏或訂單，你需要先完成前面的步驟
- 要自然地引導對話，不要讓客戶感覺太死板
PROMPT;

// 對話歷史
$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

// 模擬對話
function chat($userMessage) {
    global $client, $messages, $tools, $orderState;

    echo "\n客戶：$userMessage\n";
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    while (true) {
        $response = $client->chat()->create([
            'model' => 'gpt-4.1-nano',
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        $message = $response->choices[0]->message;

        // 建立訊息記錄
        $messageRecord = [
            'role' => 'assistant',
            'content' => $message->content,
        ];

        // 只有在有 tool_calls 時才加入
        if ($message->toolCalls) {
            $messageRecord['tool_calls'] = $message->toolCalls;
        }

        $messages[] = $messageRecord;

        // 如果有文字回應，顯示給用戶
        if ($message->content) {
            echo "助手：{$message->content}\n";
        }

        // 如果需要執行工具
        if ($message->toolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName]\n";

                $result = executeTool($functionName, $arguments);

                if (isset($result['error'])) {
                    echo "  [錯誤：{$result['error']}]\n";
                }

                $messages[] = [
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'tool_call_id' => $toolCall->id,
                ];
            }

            // 顯示當前狀態
            echo "  [狀態：";
            echo "過敏確認=" . ($orderState['allergy_confirmed'] ? '✓' : '✗') . ", ";
            echo "訂單確認=" . ($orderState['order_confirmed'] ? '✓' : '✗') . ", ";
            echo "付款提供=" . ($orderState['payment_provided'] ? '✓' : '✗') . "]\n";
        } else {
            // 沒有工具呼叫，結束這一輪對話
            break;
        }
    }
}

// 開始對話示範
echo "=== 餐廳訂餐 AI Agent - Control Flow Pattern ===\n";
echo "這個範例展示如何處理有順序要求的必須步驟\n";

// 場景 1：正常流程
echo "\n\n【場景 1：客戶按照正常流程訂餐】\n";
chat("你好，我想訂餐");
chat("我對花生過敏");
chat("我想要一份義大利麵和一杯果汁");
chat("好的，我要付款");

// 重置狀態
$orderState = [
    'menu_shown' => false,
    'allergy_confirmed' => false,
    'order_confirmed' => false,
    'payment_provided' => false,
    'allergies' => [],
    'order_items' => [],
];

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

echo "\n\n【場景 2：客戶嘗試跳過步驟】\n";
chat("你好，我想要一份披薩，直接給我付款連結");

echo "\n\n=== 完成 ===\n";
echo "\n重點觀察：\n";
echo "1. 場景 1 中，AI 引導客戶完成所有必須步驟\n";
echo "2. 場景 2 中，即使客戶想跳過步驟，AI 仍會確保按順序完成\n";
echo "3. 系統透過 orderState 追蹤進度，並在 function 中檢查前置條件\n";
echo "4. 這確保了關鍵業務流程的完整性和正確性\n";
