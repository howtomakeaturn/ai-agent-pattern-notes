<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// 載入 .env 檔案
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * Session Renewal Pattern - 會話續用與訂單重置
 *
 * 這個範例展示如何在長期對話環境中支援多次獨立訂單：
 *
 * 核心問題：
 * - Chatbot UI：用戶可以點「新對話」按鈕開始新訂單
 * - LINE/SMS/WhatsApp：無法開新對話，同一聊天室會持續使用
 * - 用戶可能過幾天、幾週後回來再次下單
 *
 * 解決方案：
 * 1. 提供 reset_order 工具函數，讓 AI 能重置訂單狀態
 * 2. 檢測用戶意圖：「我要再點一次」「重新開始」「新訂單」
 * 3. 智能保留：過敏資訊通常不變，可選擇保留
 * 4. 歷史參考：可保存上次訂單，支援「跟上次一樣」
 *
 * 實際應用場景：
 * - LINE 官方帳號的訂餐機器人
 * - SMS 簡訊訂購服務
 * - WhatsApp Business 客服
 * - 任何無法手動開啟新對話的通訊平台
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
            session_id INTEGER NOT NULL UNIQUE,
            state TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id)
        )
    ");

    return $db;
}

function getOrCreateSession(PDO $db, string $userId): int {
    $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $session = $stmt->fetch();

    if ($session) {
        return $session['id'];
    }

    $stmt = $db->prepare("INSERT INTO chat_sessions (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    return $db->lastInsertId();
}

function saveMessage(PDO $db, int $sessionId, array $message): void {
    $stmt = $db->prepare("
        INSERT INTO chat_messages (session_id, role, content, tool_calls, tool_call_id)
        VALUES (?, ?, ?, ?, ?)
    ");

    // 只有當 tool_calls 不為空時才序列化存儲
    $toolCallsJson = null;
    if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
        $toolCallsJson = json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE);
    }

    $stmt->execute([
        $sessionId,
        $message['role'],
        $message['content'] ?? null,
        $toolCallsJson,
        $message['tool_call_id'] ?? null,
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

    $messages = [];
    foreach ($rows as $row) {
        $message = ['role' => $row['role']];

        if ($row['content']) {
            $message['content'] = $row['content'];
        }

        if ($row['tool_calls']) {
            $toolCalls = json_decode($row['tool_calls'], true);
            // 只有當 tool_calls 不為空時才加入（避免空陣列導致 API 錯誤）
            if (!empty($toolCalls)) {
                $message['tool_calls'] = $toolCalls;
            }
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
        // 訂單狀態
        'allergy_confirmed' => false,
        'allergies' => [],
        'current_items' => [],
        'order_previewed' => false,
        'order_confirmed' => false,
        'payment_provided' => false,

        // 歷史參考（可選）
        'last_order' => null,  // 保存上次訂單，支援「跟上次一樣」
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
    if (!$state['order_confirmed']) {
        return [
            'success' => false,
            'error' => '必須先確認訂單才能提供付款連結',
        ];
    }

    $state['payment_provided'] = true;

    // 保存此次訂單到歷史（供下次參考）
    $state['last_order'] = [
        'items' => $state['current_items'],
        'allergies' => $state['allergies'],
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    return [
        'success' => true,
        'payment_link' => 'https://payment.example.com/pay/ORDER123456',
        'message' => '付款連結已生成。訂單流程完成！',
    ];
}

/**
 * 重置訂單 - Pattern 5 的核心功能
 *
 * 使用時機：
 * - 客戶說「我要再點一次」「重新開始」「新訂單」
 * - 上一筆訂單已完成，客戶想下新訂單
 * - 客戶過幾天後回來再次下單
 *
 * 設計考量：
 * - keep_allergies: 通常過敏資訊不會變，預設保留
 * - keep_last_order: 保留上次訂單記錄，支援「跟上次一樣」
 */
function reset_order(array &$state, bool $keep_allergies = true): array {
    // 清空當前訂單狀態
    $state['current_items'] = [];
    $state['order_previewed'] = false;
    $state['order_confirmed'] = false;
    $state['payment_provided'] = false;

    $message = '訂單已重置，可以開始新的訂單了！';

    // 可選：重置過敏資訊
    if (!$keep_allergies) {
        $state['allergy_confirmed'] = false;
        $state['allergies'] = [];
        $message .= ' 過敏資訊也已清除。';
    } else {
        $message .= ' 過敏資訊已保留。';
    }

    // last_order 保持不變，作為歷史參考

    return [
        'success' => true,
        'message' => $message,
        'kept_allergies' => $keep_allergies,
        'has_last_order' => !empty($state['last_order']),
    ];
}

/**
 * 複製上次訂單
 *
 * 支援客戶說「跟上次一樣」「再來一份」
 */
function copy_last_order(array &$state): array {
    if (empty($state['last_order'])) {
        return [
            'success' => false,
            'error' => '沒有找到上次的訂單記錄',
        ];
    }

    if (!empty($state['current_items'])) {
        return [
            'success' => false,
            'error' => '當前訂單不是空的。請先使用 reset_order 清空訂單，或使用 add_items_to_order 加入項目。',
        ];
    }

    // 複製上次訂單的項目
    $state['current_items'] = $state['last_order']['items'];
    $state['order_previewed'] = false;
    $state['order_confirmed'] = false;

    return [
        'success' => true,
        'copied_items' => $state['current_items'],
        'message' => '已複製上次訂單的內容。請使用 preview_order 查看訂單詳情。',
    ];
}

// ============================================
// 定義 Tools
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
            'description' => '預覽當前訂單的完整內容，包含項目、價格和總金額。這是確認訂單前的必要步驟。',
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
            'description' => '修改訂單內容。可以更新數量、移除項目或完全替換訂單。修改後必須重新預覽。',
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
                        'description' => '要修改的項目名稱（用於 update_quantity 和 remove_item）',
                    ],
                    'new_quantity' => [
                        'type' => 'integer',
                        'description' => '新的數量（用於 update_quantity）',
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
                        'description' => '新的完整訂單（用於 replace_items）',
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
            'description' => '確認訂單。必須先預覽訂單才能確認。',
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
            'description' => '提供付款連結。必須先確認訂單才能提供付款連結。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'reset_order',
            'description' => '重置訂單狀態，開始新的訂單。用於：客戶說「我要再點一次」「重新開始」「新訂單」，或上一筆訂單已完成想下新訂單時。',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'keep_allergies' => [
                        'type' => 'boolean',
                        'description' => '是否保留過敏資訊（預設 true，因為通常不會變）',
                    ],
                ],
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'copy_last_order',
            'description' => '複製上次的訂單內容到當前訂單。用於客戶說「跟上次一樣」「再來一份相同的」時。',
            'parameters' => [
                'type' => 'object',
                'properties' => (object)[],
            ],
        ],
    ],
];

// ============================================
// 工具函數執行器
// ============================================

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

        case 'reset_order':
            return reset_order($state, $arguments['keep_allergies'] ?? true);

        case 'copy_last_order':
            return copy_last_order($state);

        default:
            return ['error' => "未知的函數: {$functionName}"];
    }
}

// ============================================
// 主程式
// ============================================

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// 為了確保 demo 每次都是乾淨的，先刪除舊資料庫
$dbPath = __DIR__ . '/chat_demo.db';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$db = initDatabase();

// 模擬 LINE 用戶 ID（在真實場景中，這會是 LINE User ID）
$userId = 'line_user_U1234567890abcdef';

// 載入或創建會話
$sessionId = getOrCreateSession($db, $userId);

// 載入歷史訊息和狀態
$messages = loadMessages($db, $sessionId);
$state = loadState($db, $sessionId) ?? getInitialState();

// 系統提示詞
$systemPrompt = <<<'PROMPT'
你是一個友善的餐廳訂餐助理，專門處理 LINE 訂餐服務。

【菜單】
- 牛肉麵: $150
- 滷肉飯: $50
- 珍珠奶茶: $45
- 炸雞: $80
- 薯條: $40

【訂單流程】
1. 確認過敏資訊（必須）
2. 記錄訂單項目
3. 預覽訂單（必須）
4. 確認訂單（必須看過預覽）
5. 提供付款連結

【重要：會話續用功能】
由於這是 LINE 對話，客戶無法開啟新對話。同一個聊天室會持續使用。

- 當客戶說「我要再點一次」「重新開始」「新訂單」時，使用 reset_order
- 當客戶說「跟上次一樣」「再來一份」時，使用 copy_last_order
- 完成訂單並提供付款連結後，告知客戶：「若之後還要訂餐，隨時告訴我！」

【對話風格】
- 簡潔親切
- 主動引導
- 確認重要資訊
PROMPT;

// 對話函數
function chat(string $userInput): void {
    global $client, $db, $sessionId, $messages, $state, $systemPrompt, $tools;

    echo "\n客戶：$userInput\n";

    $userMessage = [
        'role' => 'user',
        'content' => $userInput,
    ];

    $messages[] = $userMessage;
    saveMessage($db, $sessionId, $userMessage);

    $apiMessages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $messages
    );

    $maxIterations = 10;
    for ($i = 0; $i < $maxIterations; $i++) {
        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',  // 使用 mini 版本，rate limit 更高
            'messages' => $apiMessages,
            'tools' => $tools,
        ]);

        $message = $response->choices[0]->message;
        $finishReason = $response->choices[0]->finishReason;

        // 顯示助理回應
        if (isset($message->content) && $message->content) {
            echo "助理：{$message->content}\n";
        }

        // 準備訊息格式
        $messageArray = ['role' => 'assistant'];
        if (isset($message->content)) {
            $messageArray['content'] = $message->content;
        }
        // 只有當 toolCalls 存在且不為空時才添加
        if (isset($message->toolCalls) && !empty($message->toolCalls)) {
            $messageArray['tool_calls'] = array_map(
                fn($tc) => [
                    'id' => $tc->id,
                    'type' => $tc->type,
                    'function' => [
                        'name' => $tc->function->name,
                        'arguments' => $tc->function->arguments,
                    ],
                ],
                $message->toolCalls
            );
        }

        $messages[] = $messageArray;
        $apiMessages[] = $messageArray;
        saveMessage($db, $sessionId, $messageArray);

        // 處理工具呼叫
        if ($finishReason === 'tool_calls') {
            foreach ($message->toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $arguments = json_decode($toolCall->function->arguments, true);

                echo "  [執行：$functionName";
                if (!empty($arguments)) {
                    echo " - " . json_encode($arguments, JSON_UNESCAPED_UNICODE);
                }
                echo "]\n";

                $result = executeTool($functionName, $arguments, $state);

                // 持久化更新後的狀態
                saveState($db, $sessionId, $state);

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
                $apiMessages[] = $toolMessage;
                saveMessage($db, $sessionId, $toolMessage);
            }

            // 顯示當前狀態
            echo "  [狀態：";
            echo "過敏=" . ($state['allergy_confirmed'] ? '✓' : '✗') . ", ";
            echo "項目數=" . count($state['current_items']) . ", ";
            echo "已預覽=" . ($state['order_previewed'] ? '✓' : '✗') . ", ";
            echo "已確認=" . ($state['order_confirmed'] ? '✓' : '✗');
            if ($state['last_order']) {
                echo ", 有歷史訂單✓";
            }
            echo "]\n";
        } else {
            break;
        }
    }
}

// 開始對話示範
echo "=== LINE 訂餐 AI Agent - Session Renewal Pattern ===\n";
echo "這個範例展示如何在同一聊天室處理多次獨立訂單\n";

// 場景 1：第一次訂單（正常流程）
echo "\n\n【場景 1：客戶第一次訂餐】\n";
echo str_repeat("=", 60) . "\n";
chat("你好，我想訂餐");
chat("我沒有過敏");
chat("我要一碗牛肉麵和一杯珍珠奶茶");
chat("好的，請給我看訂單");
chat("確認訂單");
chat("付款");

// 場景 2：幾天後，客戶回來再次訂餐（使用 reset_order）
echo "\n\n【場景 2：過了幾天，客戶再次訂餐】\n";
echo str_repeat("=", 60) . "\n";
chat("我要再點一次");
chat("這次我要兩份滷肉飯和一份炸雞");
chat("讓我看看");
chat("確認");
chat("付款");

// 場景 3：客戶想點跟上次一樣的（使用 copy_last_order）
echo "\n\n【場景 3：客戶想點跟上次一樣的餐點】\n";
echo str_repeat("=", 60) . "\n";
chat("我想再訂一次");
chat("跟上次一樣就好");
chat("看一下訂單");
chat("確認");
chat("給我付款連結");

// 場景 4：重置並清除過敏資訊
echo "\n\n【場景 4：客戶是替朋友訂餐，需要清除過敏資訊】\n";
echo str_repeat("=", 60) . "\n";
chat("我要幫朋友訂餐，重新開始");
chat("他對花生過敏");
chat("他要一份炸雞和薯條");
chat("預覽訂單");
chat("確認");
chat("付款");

echo "\n\n=== 完成 ===\n";
echo "\n重點觀察：\n";
echo "1. 場景 1：第一次訂餐，建立初始狀態和歷史記錄\n";
echo "2. 場景 2：使用 reset_order 清空訂單，開始新訂單（保留過敏資訊）\n";
echo "3. 場景 3：使用 copy_last_order 快速複製上次訂單\n";
echo "4. 場景 4：可選擇清除過敏資訊（幫別人訂餐的情境）\n";
echo "\n關鍵工程概念：\n";
echo "- reset_order: 清空訂單狀態，預設保留過敏資訊\n";
echo "- copy_last_order: 複製 last_order 到 current_items\n";
echo "- last_order: 在提供付款連結時自動保存\n";
echo "- 同一個 session/聊天室可處理多次獨立訂單\n";
echo "\n這個模式特別適合 LINE、SMS、WhatsApp 等無法手動開新對話的平台。\n";
