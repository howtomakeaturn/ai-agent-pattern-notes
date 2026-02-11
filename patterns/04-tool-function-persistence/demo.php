<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// 載入 .env 檔案
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * Tool Function + Persistence Pattern - 工具函數與持久化
 *
 * 結合 Pattern 2 的 Tool Functions 和 Pattern 3 的資料庫持久化
 *
 * 核心特點：
 * 1. 使用 Tool Functions 讓 LLM 精確控制狀態（而非字串匹配）
 * 2. 完整的對話歷史持久化（包括 tool_calls）
 * 3. 每次狀態變更後即時持久化到資料庫
 * 4. 支援跨會話恢復對話（load session）
 * 5. 強制預覽確認機制（來自 Pattern 2）
 *
 * 與 Pattern 3 的差異：
 * - Pattern 3: 簡化版，用字串匹配展示持久化「概念」
 * - Pattern 4: 完整版，用 Tool Functions 實現可靠的狀態管理
 *
 * 這是生產環境建議的實作方式。
 */

// ============================================
// 資料庫初始化
// ============================================

function initDatabase(): PDO {
    $db = new PDO('sqlite:' . __DIR__ . '/chat_demo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("
        CREATE TABLE IF NOT EXISTS chat_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT,
            tool_calls TEXT,
            tool_call_id TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS agent_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            state TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id)
        )
    ");

    return $db;
}

// ============================================
// 資料庫操作
// ============================================

function createSession(PDO $db, string $userId): int {
    $stmt = $db->prepare("INSERT INTO chat_sessions (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    return (int)$db->lastInsertId();
}

function saveMessage(PDO $db, int $sessionId, array $message): void {
    $stmt = $db->prepare("
        INSERT INTO chat_messages (session_id, role, content, tool_calls, tool_call_id)
        VALUES (?, ?, ?, ?, ?)
    ");

    $toolCalls = isset($message['tool_calls']) ? json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE) : null;
    $toolCallId = $message['tool_call_id'] ?? null;

    $stmt->execute([
        $sessionId,
        $message['role'],
        $message['content'] ?? null,
        $toolCalls,
        $toolCallId
    ]);

    $stmt = $db->prepare("UPDATE chat_sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);
}

function loadMessages(PDO $db, int $sessionId): array {
    $stmt = $db->prepare("
        SELECT role, content, tool_calls, tool_call_id
        FROM chat_messages
        WHERE session_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 重建訊息格式
    $messages = [];
    foreach ($rows as $row) {
        $message = ['role' => $row['role']];

        if ($row['content']) {
            $message['content'] = $row['content'];
        }

        if ($row['tool_calls']) {
            $message['tool_calls'] = json_decode($row['tool_calls'], true);
        }

        if ($row['tool_call_id']) {
            $message['tool_call_id'] = $row['tool_call_id'];
        }

        $messages[] = $message;
    }

    return $messages;
}

function saveState(PDO $db, int $sessionId, array $state): void {
    $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $stmt = $db->prepare("SELECT id FROM agent_states WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare("
            UPDATE agent_states
            SET state = ?, updated_at = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");
        $stmt->execute([$stateJson, $sessionId]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO agent_states (session_id, state)
            VALUES (?, ?)
        ");
        $stmt->execute([$sessionId, $stateJson]);
    }
}

function loadState(PDO $db, int $sessionId): ?array {
    $stmt = $db->prepare("SELECT state FROM agent_states WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $result = $stmt->fetch();

    return $result ? json_decode($result['state'], true) : null;
}

// ============================================
// 狀態管理
// ============================================

function getInitialState(): array {
    return [
        'allergy_confirmed' => false,
        'allergies' => [],
        'current_items' => [],
        'order_previewed' => false,
        'order_confirmed' => false,
        'payment_provided' => false,
    ];
}

// ============================================
// Tool Functions
// ============================================

$menuPrices = [
    '牛肉麵' => 150,
    '滷肉飯' => 50,
    '珍珠奶茶' => 45,
    '炸雞' => 80,
    '薯條' => 40,
];

function confirm_allergies(array &$state, array $allergies): array {
    $state['allergy_confirmed'] = true;
    $state['allergies'] = $allergies;

    return [
        'success' => true,
        'allergies' => $allergies,
        'message' => empty($allergies)
            ? '已確認：客戶沒有食材過敏'
            : '已確認過敏資訊：' . implode(', ', $allergies),
    ];
}

function add_items_to_order(array &$state, array $items): array {
    if (!$state['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '請先確認過敏資訊',
        ];
    }

    foreach ($items as $item) {
        $found = false;
        foreach ($state['current_items'] as &$existingItem) {
            if ($existingItem['name'] === $item['name']) {
                $existingItem['quantity'] += $item['quantity'];
                $found = true;
                break;
            }
        }

        if (!$found) {
            $state['current_items'][] = $item;
        }
    }

    // 加入項目後需要重新預覽
    $state['order_previewed'] = false;
    $state['order_confirmed'] = false;

    return [
        'success' => true,
        'current_items' => $state['current_items'],
        'message' => '項目已加入訂單。請使用 preview_order 查看訂單詳情。',
    ];
}

function preview_order(array &$state): array {
    global $menuPrices;

    if (empty($state['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，請先加入項目',
        ];
    }

    $state['order_previewed'] = true;

    $summary = "訂單明細：\n";
    $summary .= str_repeat("=", 40) . "\n";

    $total = 0;
    foreach ($state['current_items'] as $item) {
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

    if (!empty($state['allergies'])) {
        $summary .= str_repeat("=", 40) . "\n";
        $summary .= "⚠️  過敏提醒：" . implode(', ', $state['allergies']) . "\n";
    }

    return [
        'success' => true,
        'items' => $state['current_items'],
        'total_amount' => $total,
        'summary' => $summary,
        'message' => '訂單預覽已生成',
    ];
}

function modify_order(array &$state, string $action, ?string $item_name = null, ?int $new_quantity = null, ?array $new_items = null): array {
    if (empty($state['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，無法修改',
        ];
    }

    if ($state['order_confirmed']) {
        return [
            'success' => false,
            'error' => '訂單已確認，無法再修改',
        ];
    }

    $modification_detail = '';

    switch ($action) {
        case 'update_quantity':
            $found = false;
            foreach ($state['current_items'] as &$item) {
                if ($item['name'] === $item_name) {
                    $old_qty = $item['quantity'];
                    $item['quantity'] = $new_quantity;
                    $found = true;
                    $modification_detail = "將 {$item_name} 數量從 {$old_qty} 改為 {$new_quantity}";
                    break;
                }
            }

            if (!$found) {
                return ['success' => false, 'error' => "找不到項目：{$item_name}"];
            }
            break;

        case 'remove_item':
            $initial_count = count($state['current_items']);
            $state['current_items'] = array_values(array_filter(
                $state['current_items'],
                fn($item) => $item['name'] !== $item_name
            ));

            if (count($state['current_items']) === $initial_count) {
                return ['success' => false, 'error' => "找不到項目：{$item_name}"];
            }

            $modification_detail = "移除 {$item_name}";
            break;

        case 'replace_items':
            $state['current_items'] = $new_items;
            $modification_detail = "完全更換訂單內容";
            break;

        default:
            return ['success' => false, 'error' => "未知的修改類型：{$action}"];
    }

    // 修改後需要重新預覽
    $state['order_previewed'] = false;
    $state['order_confirmed'] = false;

    return [
        'success' => true,
        'modification_detail' => $modification_detail,
        'current_items' => $state['current_items'],
        'message' => '訂單已修改。請使用 preview_order 查看更新後的訂單。',
    ];
}

function confirm_order(array &$state): array {
    global $menuPrices;

    if (!$state['allergy_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認過敏資訊',
        ];
    }

    if (empty($state['current_items'])) {
        return [
            'success' => false,
            'error' => '訂單是空的，無法確認',
        ];
    }

    // 必須先預覽才能確認
    if (!$state['order_previewed']) {
        return [
            'success' => false,
            'error' => '必須先預覽訂單才能確認。請使用 preview_order 查看訂單詳情。',
        ];
    }

    $state['order_confirmed'] = true;

    $total = 0;
    foreach ($state['current_items'] as $item) {
        $price = $menuPrices[$item['name']] ?? 0;
        $total += $price * $item['quantity'];
    }

    return [
        'success' => true,
        'items' => $state['current_items'],
        'total_amount' => $total,
        'message' => '訂單已正式確認！',
    ];
}

function provide_payment_link(array &$state): array {
    global $menuPrices;

    if (!$state['order_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認訂單',
        ];
    }

    $total = 0;
    foreach ($state['current_items'] as $item) {
        $price = $menuPrices[$item['name']] ?? 0;
        $total += $price * $item['quantity'];
    }

    $state['payment_provided'] = true;

    return [
        'success' => true,
        'payment_url' => "https://pay.example.com/order?amount={$total}",
        'amount' => $total,
        'message' => '付款連結已生成',
    ];
}

function executeTool(string $functionName, array $arguments, array &$state): array {
    switch ($functionName) {
        case 'confirm_allergies':
            return confirm_allergies($state, $arguments['allergies']);
        case 'add_items_to_order':
            return add_items_to_order($state, $arguments['items']);
        case 'preview_order':
            return preview_order($state);
        case 'modify_order':
            return modify_order(
                $state,
                $arguments['action'],
                $arguments['item_name'] ?? null,
                $arguments['new_quantity'] ?? null,
                $arguments['new_items'] ?? null
            );
        case 'confirm_order':
            return confirm_order($state);
        case 'provide_payment_link':
            return provide_payment_link($state);
        default:
            return ['error' => "Unknown function: $functionName"];
    }
}

// ============================================
// Tool Definitions
// ============================================

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
            'description' => '將項目加入到訂單中。',
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
                        'description' => '修改類型',
                    ],
                    'item_name' => [
                        'type' => 'string',
                        'description' => '要修改的項目名稱',
                    ],
                    'new_quantity' => [
                        'type' => 'integer',
                        'description' => '新的數量',
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
                        'description' => '新的訂單項目列表',
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
            'description' => '正式確認訂單。必須先執行 preview_order 才能確認。',
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

// ============================================
// 系統提示詞
// ============================================

$systemPrompt = <<<PROMPT
你是一個台灣餐廳訂餐助手。請用繁體中文對話。

可用的餐點和價格：
- 牛肉麵: $150
- 滷肉飯: $50
- 珍珠奶茶: $45
- 炸雞: $80
- 薯條: $40

流程規則：
1. 確認過敏資訊：首先詢問並確認客戶的食材過敏資訊
2. 建立訂單：使用 add_items_to_order 將客戶選擇的項目加入訂單
3. 預覽訂單（必須）：在確認訂單之前，你「必須」使用 preview_order 讓客戶看到完整的訂單明細
4. 確認或修改：如果客戶滿意則 confirm_order，想修改則 modify_order
5. 提供付款：使用 provide_payment_link 提供付款連結

關鍵原則：
- 預覽是確認訂單前的「必要步驟」
- 每次修改訂單後，都要重新預覽
- 自然地引導對話，不要死板
PROMPT;

// ============================================
// 主程式
// ============================================

function main() {
    global $client, $tools, $systemPrompt;

    $db = initDatabase();
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    echo "=== 台灣餐廳點餐系統 (Tool Functions + Persistence) ===\n";
    echo "輸入 'new' 開始新對話\n";
    echo "輸入 'load <session_id>' 載入舊對話\n";
    echo "輸入 'list' 查看所有對話\n";
    echo "輸入 'state' 查看當前狀態\n";
    echo "輸入 'quit' 結束\n\n";

    $sessionId = null;
    $orderState = null;
    $messages = [];

    while (true) {
        echo "> ";
        $userInput = trim(fgets(STDIN));

        if ($userInput === 'quit') {
            break;
        }

        if ($userInput === 'new') {
            $sessionId = createSession($db, 'demo_user_' . time());
            $orderState = getInitialState();
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt]
            ];
            saveState($db, $sessionId, $orderState);
            echo "✓ 創建新對話 #$sessionId\n\n";
            continue;
        }

        if (preg_match('/^load\s+(\d+)$/', $userInput, $matches)) {
            $loadSessionId = (int)$matches[1];
            $loadedState = loadState($db, $loadSessionId);
            $loadedMessages = loadMessages($db, $loadSessionId);

            if ($loadedState) {
                $sessionId = $loadSessionId;
                $orderState = $loadedState;
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ...$loadedMessages
                ];
                echo "✓ 載入對話 #$sessionId\n";
                echo "✓ 載入 " . count($loadedMessages) . " 則訊息\n\n";
            } else {
                echo "✗ 找不到對話 #$loadSessionId\n\n";
            }
            continue;
        }

        if ($userInput === 'list') {
            $stmt = $db->query("
                SELECT s.id, s.user_id, s.created_at, s.updated_at,
                       COUNT(m.id) as message_count
                FROM chat_sessions s
                LEFT JOIN chat_messages m ON s.id = m.session_id
                GROUP BY s.id
                ORDER BY s.updated_at DESC
                LIMIT 10
            ");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "\n最近的對話:\n";
            foreach ($sessions as $session) {
                echo sprintf(
                    "#%d | %s | %d 則訊息 | 更新: %s\n",
                    $session['id'],
                    $session['user_id'],
                    $session['message_count'],
                    $session['updated_at']
                );
            }
            echo "\n";
            continue;
        }

        if ($userInput === 'state') {
            if ($orderState) {
                echo "\n當前狀態:\n";
                echo json_encode($orderState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
            } else {
                echo "尚未開始對話\n\n";
            }
            continue;
        }

        if ($sessionId === null) {
            echo "請先輸入 'new' 開始新對話，或 'load <id>' 載入舊對話\n\n";
            continue;
        }

        // 處理用戶訊息
        $userMessage = ['role' => 'user', 'content' => $userInput];
        $messages[] = $userMessage;
        saveMessage($db, $sessionId, $userMessage);

        // AI 對話循環（處理 tool calls）
        while (true) {
            $response = $client->chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
            ]);

            $message = $response->choices[0]->message;

            // 建立訊息記錄
            $assistantMessage = [
                'role' => 'assistant',
                'content' => $message->content,
            ];

            if ($message->toolCalls) {
                $assistantMessage['tool_calls'] = $message->toolCalls;
            }

            $messages[] = $assistantMessage;
            saveMessage($db, $sessionId, $assistantMessage);

            // 顯示助手回應
            if ($message->content) {
                echo "\n🤖 {$message->content}\n";
            }

            // 執行工具
            if ($message->toolCalls) {
                foreach ($message->toolCalls as $toolCall) {
                    $functionName = $toolCall->function->name;
                    $arguments = json_decode($toolCall->function->arguments, true);

                    echo "  [執行：$functionName";
                    if (!empty($arguments)) {
                        echo " - " . json_encode($arguments, JSON_UNESCAPED_UNICODE);
                    }
                    echo "]\n";

                    $result = executeTool($functionName, $arguments, $orderState);

                    // 持久化狀態
                    saveState($db, $sessionId, $orderState);

                    if (isset($result['error'])) {
                        echo "  [錯誤：{$result['error']}]\n";
                    } else if (isset($result['summary'])) {
                        echo "\n" . $result['summary'] . "\n";
                    }

                    $toolMessage = [
                        'role' => 'tool',
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        'tool_call_id' => $toolCall->id,
                    ];

                    $messages[] = $toolMessage;
                    saveMessage($db, $sessionId, $toolMessage);
                }

                // 顯示當前狀態
                echo "  [狀態：";
                echo "過敏=" . ($orderState['allergy_confirmed'] ? '✓' : '✗') . ", ";
                echo "預覽=" . ($orderState['order_previewed'] ? '✓' : '✗') . ", ";
                echo "確認=" . ($orderState['order_confirmed'] ? '✓' : '✗') . "]\n";
            } else {
                // 沒有工具呼叫，結束這一輪
                break;
            }
        }

        echo "\n";
    }

    echo "\n再見！\n";
}

main();
