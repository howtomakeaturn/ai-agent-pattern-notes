<?php

/**
 * Database functions for Autonomous Agent Pattern
 *
 * 與 Pattern 10 類似，但新增一些自主決策需要的功能
 */

/**
 * 初始化資料庫
 */
function initDatabase(): PDO {
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 文章表
    $db->exec("
        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            keywords TEXT,
            content TEXT,
            status TEXT NOT NULL DEFAULT 'pending_research',
            quality_score REAL,
            revision_count INTEGER DEFAULT 0,
            performance_data TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME,
            published_at DATETIME,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 執行日誌表
    $db->exec("
        CREATE TABLE IF NOT EXISTS execution_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            script_name TEXT NOT NULL,
            article_id INTEGER,
            action TEXT NOT NULL,
            decision_reason TEXT,
            result TEXT,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Agent 決策歷史
    $db->exec("
        CREATE TABLE IF NOT EXISTS agent_decisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            system_state TEXT NOT NULL,
            available_actions TEXT NOT NULL,
            chosen_action TEXT NOT NULL,
            reasoning TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    return $db;
}

/**
 * 根據狀態查詢文章
 */
function getArticlesByStatus(PDO $db, string $status): array {
    $stmt = $db->prepare("SELECT * FROM articles WHERE status = ? ORDER BY created_at DESC");
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 根據 ID 查詢文章
 */
function getArticleById(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * 查詢所有文章
 */
function getAllArticles(PDO $db): array {
    $stmt = $db->query("SELECT * FROM articles ORDER BY created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 取得系統當前狀態摘要（供 AI 決策用）
 */
function getSystemState(PDO $db): array {
    $counts = [];
    $statuses = ['pending_research', 'pending_write', 'pending_review', 'approved', 'published', 'rejected'];

    foreach ($statuses as $status) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM articles WHERE status = ?");
        $stmt->execute([$status]);
        $counts[$status] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    // 最近的文章
    $stmt = $db->query("SELECT * FROM articles ORDER BY updated_at DESC LIMIT 5");
    $recentArticles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 最近的決策
    $stmt = $db->query("SELECT * FROM agent_decisions ORDER BY created_at DESC LIMIT 3");
    $recentDecisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'counts' => $counts,
        'recent_articles' => $recentArticles,
        'recent_decisions' => $recentDecisions,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * 建立新文章
 */
function createArticle(PDO $db, string $status = 'pending_research'): int {
    $stmt = $db->prepare("INSERT INTO articles (status) VALUES (?)");
    $stmt->execute([$status]);
    return (int)$db->lastInsertId();
}

/**
 * 更新文章狀態
 */
function updateArticleStatus(PDO $db, int $id, string $status, ?string $timestampField = null): bool {
    $sql = "UPDATE articles SET status = ?, updated_at = CURRENT_TIMESTAMP";
    $params = [$status];

    if ($timestampField) {
        $sql .= ", {$timestampField} = CURRENT_TIMESTAMP";
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;

    $stmt = $db->prepare($sql);
    return $stmt->execute($params);
}

/**
 * 更新文章資料
 */
function updateArticle(PDO $db, int $id, array $data): bool {
    $fields = [];
    $values = [];

    foreach ($data as $key => $value) {
        $fields[] = "{$key} = ?";
        $values[] = $value;
    }

    $fields[] = "updated_at = CURRENT_TIMESTAMP";
    $values[] = $id;

    $sql = "UPDATE articles SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);

    return $stmt->execute($values);
}

/**
 * 增加文章修訂次數
 */
function incrementRevisionCount(PDO $db, int $id): void {
    $stmt = $db->prepare("UPDATE articles SET revision_count = revision_count + 1 WHERE id = ?");
    $stmt->execute([$id]);
}

/**
 * 記錄執行日誌
 */
function logExecution(PDO $db, string $scriptName, ?int $articleId, string $action, string $result, ?string $decisionReason = null): void {
    $stmt = $db->prepare("
        INSERT INTO execution_logs (script_name, article_id, action, decision_reason, result)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$scriptName, $articleId, $action, $decisionReason, $result]);
}

/**
 * 記錄 Agent 決策
 */
function logAgentDecision(PDO $db, array $systemState, array $availableActions, string $chosenAction, string $reasoning): void {
    $stmt = $db->prepare("
        INSERT INTO agent_decisions (system_state, available_actions, chosen_action, reasoning)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        json_encode($systemState, JSON_UNESCAPED_UNICODE),
        json_encode($availableActions, JSON_UNESCAPED_UNICODE),
        $chosenAction,
        $reasoning
    ]);
}

/**
 * 取得最近的執行日誌
 */
function getRecentLogs(PDO $db, int $limit = 20): array {
    $stmt = $db->prepare("
        SELECT * FROM execution_logs
        ORDER BY executed_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 重置資料庫（開發測試用）
 */
function resetDatabase(PDO $db): void {
    $db->exec("DROP TABLE IF EXISTS articles");
    $db->exec("DROP TABLE IF EXISTS execution_logs");
    $db->exec("DROP TABLE IF EXISTS agent_decisions");

    // 重新初始化
    $newDb = initDatabase();
}
