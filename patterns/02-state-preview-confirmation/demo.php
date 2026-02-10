<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// 載入 .env 檔案
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * State Preview & Confirmation Pattern - 訂單預覽與確認
 *
 * 這個範例展示如何在 AI agent 中實現狀態預覽與確認機制：
 *
 * 核心概念：
 * 1. 在執行關鍵操作前，必須先展示預覽（Preview）
 * 2. 用戶可以在預覽後選擇確認或修改（Confirm or Modify）
 * 3. 強制要求預覽步驟，防止直接跳到最終確認（Enforcement）
 * 4. 每次修改後，必須重新預覽（Re-preview after modification）
 *
 * 為什麼需要這個 Pattern：
 * - 涉及金錢、法律或重要決策的操作，用戶必須看到完整資訊
 * - 防止誤操作或誤解導致的損失
 * - 在 AI agent 中，不能假設用戶已經"看過"或"記得"細節
 *
 * 實際應用場景：
 * - 電商購物車結帳前的訂單預覽
 * - 銀行轉帳前的交易確認
 * - 合約簽署前的內容審閱
 * - API 重要操作前的參數確認
 */

// 初始化 OpenAI 客戶端
$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// 菜單價格表（模擬餐廳菜單）
$menuPrices = [
    '義大利麵' => 280,
    '披薩' => 350,
    '沙拉' => 120,
    '果汁' => 80,
    '咖啡' => 90,
    '提拉米蘇' => 150,
];

// 追蹤訂餐流程的狀態
$orderState = [
    // 基本流程狀態
    'allergy_confirmed' => false,
    'allergies' => [],

    // 訂單狀態
    'current_items' => [],           // 當前訂單項目
    'order_previewed' => false,      // 是否已預覽過（核心狀態）
    'order_confirmed' => false,      // 是否已確認訂單

    // 付款狀態
    'payment_provided' => false,
];

// 定義可用的工具（functions）
$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'confirm_allergies',
            'description' => '確認客戶的食材過敏資訊。',
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
            'name' => 'add_items_to_order',
            'description' => '將項目加入到訂單中。這個操作只是將項目加入暫存，不會立即確認訂單。',
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
                        'description' => '要加入的訂單項目',
                    ],
                ],
                'required' => ['items'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'preview_order',
            'description' => '顯示當前訂單的詳細預覽，包含品項、數量、單價、小計和總金額。這是確認訂單前的必要步驟。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'modify_order',
            'description' => '修改訂單內容。可以調整數量、移除項目或更換品項。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['update_quantity', 'remove_item', 'replace_items'],
                        'description' => '修改類型：update_quantity=調整數量, remove_item=移除項目, replace_items=完全更換訂單',
                    ],
                    'item_name' => [
                        'type' => 'string',
                        'description' => '要修改的項目名稱（update_quantity 和 remove_item 需要）',
                    ],
                    'new_quantity' => [
                        'type' => 'integer',
                        'description' => '新的數量（update_quantity 需要）',
                    ],
                    'new_items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                            ],
                        ],
                        'description' => '新的訂單項目列表（replace_items 需要）',
                    ],
                ],
                'required' => ['action'],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'confirm_order',
            'description' => '正式確認訂單。必須先執行 preview_order 才能確認。確認後訂單將無法再修改。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'provide_payment_link',
            'description' => '提供付款連結。必須在確認訂單後才能執行。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
];

// 輔助函數：計算訂單總金額
function calculate_order_total($items) {
    global $menuPrices;
    $total = 0;
    foreach ($items as $item) {
        $price = $menuPrices[$item['name']] ?? 0;
        $total += $price * $item['quantity'];
    }
    return $total;
}

// 輔助函數：格式化訂單摘要
function format_order_summary($items) {
    global $menuPrices, $orderState;

    if (empty($items)) {
        return "訂單目前是空的。";
    }

    $summary = "訂單明細：\n";
    $summary .= str_repeat("=", 40) . "\n";

    $total = 0;
    foreach ($items as $item) {
        $price = $menuPrices[$item['name']] ?? 0;
        $subtotal = $price * $item['quantity'];
        $total += $subtotal;

        $summary .= sprintf(
            "%-15s x %d = $%d\n",
            $item['name'],
            $item['quantity'],
            $subtotal
        );
    }

    $summary .= str_repeat("-", 40) . "\n";
    $summary .= sprintf("總計：$%d\n", $total);

    // 如果有過敏資訊，顯示提醒
    if (!empty($orderState['allergies'])) {
        $summary .= str_repeat("=", 40) . "\n";
        $summary .= "⚠️  過敏提醒：" . implode(', ', $orderState['allergies']) . "\n";
    }

    return $summary;
}

// 實作工具函數
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

function add_items_to_order($items) {
    global $orderState;

    if (!$orderState['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '請先確認過敏資訊',
            'required_step' => 'confirm_allergies',
        ];
    }

    // 將新項目加入到現有訂單
    foreach ($items as $item) {
        // 檢查是否已存在相同項目
        $found = false;
        foreach ($orderState['current_items'] as &$existingItem) {
            if ($existingItem['name'] === $item['name']) {
                $existingItem['quantity'] += $item['quantity'];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $orderState['current_items'][] = $item;
        }
    }

    // 加入項目後，需要重新預覽
    $orderState['order_previewed'] = false;
    $orderState['order_confirmed'] = false;

    return [
        'success' => true,
        'current_items' => $orderState['current_items'],
        'message' => '項目已加入訂單。請使用 preview_order 查看訂單詳情。',
    ];
}

function preview_order() {
    global $orderState;

    if (empty($orderState['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，請先加入項目',
        ];
    }

    $orderState['order_previewed'] = true;

    $summary = format_order_summary($orderState['current_items']);
    $total = calculate_order_total($orderState['current_items']);

    return [
        'success' => true,
        'items' => $orderState['current_items'],
        'total_amount' => $total,
        'summary' => $summary,
        'message' => '訂單預覽已生成',
    ];
}

function modify_order($action, $item_name = null, $new_quantity = null, $new_items = null) {
    global $orderState;

    if (empty($orderState['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，無法修改',
        ];
    }

    if ($orderState['order_confirmed']) {
        return [
            'success' => false,
            'error' => '訂單已確認，無法再修改',
        ];
    }

    switch ($action) {
        case 'update_quantity':
            $found = false;
            foreach ($orderState['current_items'] as &$item) {
                if ($item['name'] === $item_name) {
                    $old_qty = $item['quantity'];
                    $item['quantity'] = $new_quantity;
                    $found = true;
                    $modification_detail = "將 {$item_name} 數量從 {$old_qty} 改為 {$new_quantity}";
                    break;
                }
            }

            if (!$found) {
                return [
                    'success' => false,
                    'error' => "找不到項目：{$item_name}",
                ];
            }
            break;

        case 'remove_item':
            $initial_count = count($orderState['current_items']);
            $orderState['current_items'] = array_values(array_filter(
                $orderState['current_items'],
                fn($item) => $item['name'] !== $item_name
            ));

            if (count($orderState['current_items']) === $initial_count) {
                return [
                    'success' => false,
                    'error' => "找不到項目：{$item_name}",
                ];
            }

            $modification_detail = "移除 {$item_name}";
            break;

        case 'replace_items':
            $orderState['current_items'] = $new_items;
            $modification_detail = "完全更換訂單內容";
            break;

        default:
            return [
                'success' => false,
                'error' => "未知的修改類型：{$action}",
            ];
    }

    // 修改後需要重新預覽（核心機制）
    $orderState['order_previewed'] = false;
    $orderState['order_confirmed'] = false;

    return [
        'success' => true,
        'modification_detail' => $modification_detail,
        'current_items' => $orderState['current_items'],
        'message' => '訂單已修改。請使用 preview_order 查看更新後的訂單。',
    ];
}

function confirm_order() {
    global $orderState;

    // 檢查前置條件
    if (!$orderState['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認過敏資訊',
            'required_step' => 'confirm_allergies',
        ];
    }

    if (empty($orderState['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，無法確認',
        ];
    }

    // 關鍵檢查：必須先預覽才能確認
    if (!$orderState['order_previewed']) {
        return [
            'success' => false,
            'error' => '必須先預覽訂單才能確認。請使用 preview_order 查看訂單詳情。',
            'required_step' => 'preview_order',
        ];
    }

    $orderState['order_confirmed'] = true;

    $summary = format_order_summary($orderState['current_items']);
    $total = calculate_order_total($orderState['current_items']);

    return [
        'success' => true,
        'items' => $orderState['current_items'],
        'total_amount' => $total,
        'summary' => $summary,
        'message' => '訂單已正式確認！',
    ];
}

function provide_payment_link() {
    global $orderState;

    if (!$orderState['order_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認訂單',
            'required_step' => 'confirm_order',
        ];
    }

    $total = calculate_order_total($orderState['current_items']);
    $orderState['payment_provided'] = true;

    return [
        'success' => true,
        'payment_url' => "https://pay.example.com/order?amount={$total}",
        'amount' => $total,
        'message' => '付款連結已生成',
    ];
}

// 執行工具函數
function executeTool($functionName, $arguments) {
    switch ($functionName) {
        case 'confirm_allergies':
            return confirm_allergies($arguments['allergies']);
        case 'add_items_to_order':
            return add_items_to_order($arguments['items']);
        case 'preview_order':
            return preview_order();
        case 'modify_order':
            return modify_order(
                $arguments['action'],
                $arguments['item_name'] ?? null,
                $arguments['new_quantity'] ?? null,
                $arguments['new_items'] ?? null
            );
        case 'confirm_order':
            return confirm_order();
        case 'provide_payment_link':
            return provide_payment_link();
        default:
            return ['error' => "Unknown function: $functionName"];
    }
}

// 系統提示詞
$systemPrompt = <<<PROMPT
你是一個餐廳訂餐助手。你的任務是協助客戶完成訂餐流程。

可用的餐點和價格：
- 義大利麵: $280
- 披薩: $350
- 沙拉: $120
- 果汁: $80
- 咖啡: $90
- 提拉米蘇: $150

重要的流程規則：

1. 確認過敏資訊：首先詢問並確認客戶的食材過敏資訊

2. 建立訂單：使用 add_items_to_order 將客戶選擇的項目加入訂單

3. 預覽訂單（必須）：在確認訂單之前，你「必須」使用 preview_order 讓客戶看到完整的訂單明細
   - 這一步是強制性的，不能跳過
   - 預覽會顯示每個品項的單價、數量、小計和總金額

4. 確認或修改：
   - 如果客戶對預覽內容滿意，使用 confirm_order 正式確認
   - 如果客戶想修改，使用 modify_order 進行修改，然後再次預覽

5. 提供付款：最後使用 provide_payment_link 提供付款連結

關鍵原則：
- 預覽是確認訂單前的「必要步驟」，即使客戶說「直接確認」也要先預覽
- 每次修改訂單後，都要重新預覽
- 要讓客戶清楚看到他們要付多少錢、買了什麼
- 自然地引導對話，讓預覽步驟感覺是為客戶著想，而不是死板的規則
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
            'model' => 'gpt-4o-mini',
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

                echo "  [執行：$functionName";
                if (!empty($arguments)) {
                    echo " - " . json_encode($arguments, JSON_UNESCAPED_UNICODE);
                }
                echo "]\n";

                $result = executeTool($functionName, $arguments);

                if (isset($result['error'])) {
                    echo "  [錯誤：{$result['error']}]\n";
                } else if (isset($result['summary'])) {
                    echo "\n" . $result['summary'] . "\n";
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
            echo "已預覽=" . ($orderState['order_previewed'] ? '✓' : '✗') . ", ";
            echo "已確認=" . ($orderState['order_confirmed'] ? '✓' : '✗') . "]\n";
        } else {
            // 沒有工具呼叫，結束這一輪對話
            break;
        }
    }
}

// 開始對話示範
echo "=== 餐廳訂餐 AI Agent - State Preview & Confirmation Pattern ===\n";
echo "這個範例展示如何在關鍵操作前強制要求預覽確認\n";

// 場景 1：正常流程（預覽→確認）
echo "\n\n【場景 1：客戶正常訂餐，先預覽再確認】\n";
chat("你好，我想訂餐");
chat("我沒有過敏");
chat("我要一份披薩和一杯咖啡");
chat("好的，確認訂單");
chat("付款");

// 重置狀態
$orderState = [
    'allergy_confirmed' => false,
    'allergies' => [],
    'current_items' => [],
    'order_previewed' => false,
    'order_confirmed' => false,
    'payment_provided' => false,
];

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

echo "\n\n【場景 2：客戶預覽後修改訂單】\n";
chat("我想點餐");
chat("沒有過敏");
chat("我要兩份義大利麵和三杯果汁");
chat("讓我看看訂單");
chat("果汁太多了，改成一杯就好");
chat("再讓我看一次");
chat("好的，確認");
chat("付款");

// 重置狀態
$orderState = [
    'allergy_confirmed' => false,
    'allergies' => [],
    'current_items' => [],
    'order_previewed' => false,
    'order_confirmed' => false,
    'payment_provided' => false,
];

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
];

echo "\n\n【場景 3：客戶嘗試跳過預覽直接確認】\n";
chat("你好，我要一份披薩，沒有過敏，直接確認訂單給我付款連結");

echo "\n\n=== 完成 ===\n";
echo "\n重點觀察：\n";
echo "1. 場景 1：展示正常的預覽→確認流程\n";
echo "2. 場景 2：展示預覽→修改→再預覽→確認的循環\n";
echo "3. 場景 3：即使客戶想跳過預覽，系統仍強制要求預覽步驟\n";
echo "\n關鍵工程概念：\n";
echo "- 在 confirm_order() 中檢查 order_previewed 狀態\n";
echo "- 每次修改後重置 order_previewed 為 false\n";
echo "- 確保用戶在確認前一定看過完整資訊\n";
echo "\n這個模式特別適合涉及金錢、法律或重要決策的操作。\n";
