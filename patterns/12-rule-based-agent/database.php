<?php

/**
 * Pattern 12: Rule-Based Agent - Database Functions
 *
 * 複用 Pattern 11 的所有資料庫函數
 * 因為兩個 Pattern 使用相同的資料結構
 */

require_once __DIR__ . '/../11-autonomous-agent/database.php';

// Pattern 12 不需要額外的資料庫函數
// 所有需要的函數都已在 Pattern 11 中定義：
// - initDatabase()
// - getSystemState()
// - getArticleById()
// - getOldestArticleByStatus()
// - createArticle()
// - updateArticle()
// - updateArticleStatus()
// - logExecution()
// - logAgentDecision() (Pattern 12 可選用，用於記錄規則決策)
// - incrementRevisionCount()
