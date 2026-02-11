<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// 載入 .env 檔案
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

use OpenAI\Client;

/**
 * State Persistence Pattern - 狀態持久化
 *
 * 展示如何將內存狀態機持久化到資料庫的基本概念
 *
 * 資料庫設計：
 * - chat_sessions: 對話會話
 * - chat_messages: 對話訊息歷史
 * - agent_states: 狀態機（JSON 格式）
 *
 * 核心概念：
 * 1. 在資料庫中保存對話狀態
 * 2. 支援多個獨立的對話會話
 * 3. 可以隨時載入舊對話繼續
 * 4. 每次狀態變更後持久化
 *
 * 注意：此版本使用字串匹配更新狀態（簡化版）
 * 生產環境建議使用 Pattern 4 的 Tool Functions 方式
 */

// ============================================
// 資料庫初始化
// ============================================

function initDatabase(): PDO {
    $db = new PDO('sqlite:' . __DIR__ . '/chat_demo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 創建表格
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
            content TEXT NOT NULL,
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
// 資料庫操作函數
// ============================================

function createSession(PDO $db, string $userId): int {
    $stmt = $db->prepare("INSERT INTO chat_sessions (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    return (int)$db->lastInsertId();
}

function saveMessage(PDO $db, int $sessionId, string $role, string $content): void {
    $stmt = $db->prepare("
        INSERT INTO chat_messages (session_id, role, content)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$sessionId, $role, $content]);

    // 更新 session 的 updated_at
    $stmt = $db->prepare("UPDATE chat_sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);
}

function loadMessages(PDO $db, int $sessionId): array {
    $stmt = $db->prepare("
        SELECT role, content
        FROM chat_messages
        WHERE session_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveState(PDO $db, int $sessionId, array $state): void {
    $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // 檢查是否已有狀態記錄
    $stmt = $db->prepare("SELECT id FROM agent_states WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // 更新現有記錄
        $stmt = $db->prepare("
            UPDATE agent_states
            SET state = ?, updated_at = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");
        $stmt->execute([$stateJson, $sessionId]);
    } else {
        // 插入新記錄
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

    if ($result) {
        return json_decode($result['state'], true);
    }

    return null;
}

// ============================================
// 狀態機管理
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

function updateStateFromResponse(array &$state, string $response): void {
    $lowerResponse = strtolower($response);

    // 過敏確認
    if (!$state['allergy_confirmed']) {
        if (strpos($lowerResponse, '沒有過敏') !== false ||
            strpos($lowerResponse, '没有过敏') !== false ||
            strpos($lowerResponse, 'no allergi') !== false) {
            $state['allergy_confirmed'] = true;
            $state['allergies'] = [];
        } elseif (strpos($lowerResponse, '過敏') !== false ||
                  strpos($lowerResponse, '过敏') !== false ||
                  strpos($lowerResponse, 'allergi') !== false) {
            $state['allergy_confirmed'] = true;
            // 這裡可以加入更複雜的過敏原解析邏輯
            $state['allergies'][] = $response;
        }
    }

    // 訂單項目（簡化版本）
    if (preg_match('/(\d+)\s*份/u', $response, $matches) ||
        preg_match('/(\d+)\s*個/u', $response, $matches)) {
        $quantity = (int)$matches[1];

        if (strpos($lowerResponse, '牛肉麵') !== false) {
            $state['current_items'][] = ['item' => '牛肉麵', 'quantity' => $quantity];
        } elseif (strpos($lowerResponse, '滷肉飯') !== false) {
            $state['current_items'][] = ['item' => '滷肉飯', 'quantity' => $quantity];
        } elseif (strpos($lowerResponse, '珍奶') !== false || strpos($lowerResponse, '珍珠奶茶') !== false) {
            $state['current_items'][] = ['item' => '珍珠奶茶', 'quantity' => $quantity];
        }
    }

    // 預覽觸發
    if (strpos($lowerResponse, '就這樣') !== false ||
        strpos($lowerResponse, '就这样') !== false ||
        strpos($lowerResponse, '確認訂單') !== false ||
        strpos($lowerResponse, '确认订单') !== false) {
        $state['order_previewed'] = true;
    }

    // 確認訂單
    if ($state['order_previewed'] &&
        (strpos($lowerResponse, '確認') !== false ||
         strpos($lowerResponse, '确认') !== false ||
         strpos($lowerResponse, 'confirm') !== false ||
         strpos($lowerResponse, '對') !== false ||
         strpos($lowerResponse, '对') !== false)) {
        $state['order_confirmed'] = true;
    }

    // 取消訂單重置
    if ($state['order_previewed'] &&
        (strpos($lowerResponse, '修改') !== false ||
         strpos($lowerResponse, '取消') !== false ||
         strpos($lowerResponse, '重新') !== false)) {
        $state['order_previewed'] = false;
        $state['current_items'] = [];
    }
}

function buildSystemPrompt(array $state): string {
    $prompt = "你是一個台灣餐廳的點餐助手。請用繁體中文對話。\n\n";
    $prompt .= "=== 當前狀態 ===\n";
    $prompt .= "過敏確認: " . ($state['allergy_confirmed'] ? '已確認' : '未確認') . "\n";

    if (!empty($state['allergies'])) {
        $prompt .= "過敏原: " . implode(', ', $state['allergies']) . "\n";
    }

    $prompt .= "訂單項目: " . (count($state['current_items']) > 0 ? json_encode($state['current_items'], JSON_UNESCAPED_UNICODE) : '無') . "\n";
    $prompt .= "是否已預覽: " . ($state['order_previewed'] ? '是' : '否') . "\n";
    $prompt .= "是否已確認: " . ($state['order_confirmed'] ? '是' : '否') . "\n";
    $prompt .= "付款資訊: " . ($state['payment_provided'] ? '已提供' : '未提供') . "\n";

    $prompt .= "\n=== 流程規則 ===\n";

    if (!$state['allergy_confirmed']) {
        $prompt .= "1. 先詢問客人是否有過敏\n";
    } elseif (empty($state['current_items'])) {
        $prompt .= "2. 介紹菜單並接受點餐（牛肉麵 $150、滷肉飯 $50、珍珠奶茶 $45）\n";
    } elseif (!$state['order_previewed']) {
        $prompt .= "3. 繼續接受點餐，或等待客人說「就這樣」來預覽訂單\n";
    } elseif (!$state['order_confirmed']) {
        $prompt .= "4. **重要**: 顯示完整訂單摘要，並明確詢問：「請確認訂單是否正確？」\n";
        $prompt .= "   等待客人明確說「確認」或「對」才能進入付款流程\n";
    } elseif (!$state['payment_provided']) {
        $prompt .= "5. 詢問付款方式（現金/信用卡/行動支付）\n";
    } else {
        $prompt .= "6. 完成訂單，感謝客人\n";
    }

    return $prompt;
}

// ============================================
// 主程式
// ============================================

function main() {
    // 初始化資料庫
    $db = initDatabase();

    // 初始化 OpenAI 客戶端
    $client = OpenAI::client($_ENV['OPENAI_API_KEY']);

    echo "=== 台灣餐廳點餐系統 (State Persistence) ===\n";
    echo "輸入 'new' 開始新對話\n";
    echo "輸入 'load <session_id>' 載入舊對話\n";
    echo "輸入 'list' 查看所有對話\n";
    echo "輸入 'state' 查看當前狀態\n";
    echo "輸入 'quit' 結束\n\n";

    $sessionId = null;
    $orderState = null;
    $conversationHistory = [];

    while (true) {
        echo "> ";
        $userInput = trim(fgets(STDIN));

        if ($userInput === 'quit') {
            break;
        }

        if ($userInput === 'new') {
            // 創建新對話
            $sessionId = createSession($db, 'demo_user_' . time());
            $orderState = getInitialState();
            $conversationHistory = [];
            saveState($db, $sessionId, $orderState);
            echo "✓ 創建新對話 #$sessionId\n\n";
            continue;
        }

        if (preg_match('/^load\s+(\d+)$/', $userInput, $matches)) {
            // 載入舊對話
            $loadSessionId = (int)$matches[1];
            $loadedState = loadState($db, $loadSessionId);
            $loadedMessages = loadMessages($db, $loadSessionId);

            if ($loadedState) {
                $sessionId = $loadSessionId;
                $orderState = $loadedState;
                $conversationHistory = $loadedMessages;
                echo "✓ 載入對話 #$sessionId\n";
                echo "✓ 載入 " . count($conversationHistory) . " 則訊息\n\n";
            } else {
                echo "✗ 找不到對話 #$loadSessionId\n\n";
            }
            continue;
        }

        if ($userInput === 'list') {
            // 列出所有對話
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
        saveMessage($db, $sessionId, 'user', $userInput);
        updateStateFromResponse($orderState, $userInput);
        saveState($db, $sessionId, $orderState);

        // 構建提示詞
        $systemPrompt = buildSystemPrompt($orderState);

        // 準備對話歷史
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($conversationHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $userInput
        ];

        // 呼叫 OpenAI API
        try {
            $response = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => $messages,
                'max_tokens' => 300,
                'temperature' => 0.7,
            ]);

            $assistantMessage = $response->choices[0]->message->content;

            // 儲存助手回應
            saveMessage($db, $sessionId, 'assistant', $assistantMessage);

            // 更新對話歷史
            $conversationHistory[] = ['role' => 'user', 'content' => $userInput];
            $conversationHistory[] = ['role' => 'assistant', 'content' => $assistantMessage];

            echo "\n🤖 $assistantMessage\n\n";

        } catch (Exception $e) {
            echo "錯誤: " . $e->getMessage() . "\n\n";
        }
    }

    echo "\n再見！\n";
}

main();
