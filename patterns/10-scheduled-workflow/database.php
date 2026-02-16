<?php

/**
 * Database functions for Scheduled Workflow Pattern
 *
 * 提供跨獨立 PHP process 的狀態持久化
 * 所有 cron 腳本和工具共用這些函數
 */

/**
 * 初始化資料庫，建立所需的 tables
 */
function initDatabase(): PDO {
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 文章表：儲存文章內容和狀態
    $db->exec("
        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            keywords TEXT,
            content TEXT,
            status TEXT NOT NULL DEFAULT 'pending_research',
            performance_data TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME,
            published_at DATETIME,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 執行日誌表：記錄每次 cron 執行
    $db->exec("
        CREATE TABLE IF NOT EXISTS execution_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            script_name TEXT NOT NULL,
            article_id INTEGER,
            action TEXT NOT NULL,
            result TEXT,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
 * 記錄執行日誌
 */
function logExecution(PDO $db, string $scriptName, ?int $articleId, string $action, string $result): void {
    $stmt = $db->prepare("
        INSERT INTO execution_logs (script_name, article_id, action, result)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$scriptName, $articleId, $action, $result]);
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

    // 重新建立 tables
    $db->exec("
        CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            keywords TEXT,
            content TEXT,
            status TEXT NOT NULL DEFAULT 'pending_research',
            performance_data TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME,
            published_at DATETIME,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE execution_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            script_name TEXT NOT NULL,
            article_id INTEGER,
            action TEXT NOT NULL,
            result TEXT,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
}
