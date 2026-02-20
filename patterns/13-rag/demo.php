<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use OpenAI\Client;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ============================================
// RAG 系統核心功能
// ============================================

/**
 * 步驟 1: 建立索引（讀取文檔 -> 切段 -> Embedding -> 存檔）
 */
function buildIndex($client, $knowledgeBaseDir, $embeddingsFile) {
    echo "🔨 開始建立知識庫索引...\n\n";

    $chunks = [];
    $files = glob($knowledgeBaseDir . '/*.txt');

    foreach ($files as $file) {
        $filename = basename($file);
        $content = file_get_contents($file);

        echo "📄 處理文檔: $filename\n";

        // 簡單切段策略：按段落分割
        $paragraphs = array_filter(array_map('trim', explode("\n\n", $content)));

        foreach ($paragraphs as $index => $text) {
            if (strlen($text) < 20) continue; // 過濾太短的段落

            echo "  - 段落 " . ($index + 1) . ": " . mb_substr($text, 0, 50) . "...\n";

            // 調用 OpenAI Embedding API
            $response = $client->embeddings()->create([
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);

            $embedding = $response->embeddings[0]->embedding;

            $chunks[] = [
                'id' => count($chunks) + 1,
                'source' => $filename,
                'text' => $text,
                'embedding' => $embedding,
            ];

            usleep(100000); // 0.1 秒延遲，避免 rate limit
        }

        echo "\n";
    }

    // 儲存到 JSON 檔案
    file_put_contents($embeddingsFile, json_encode($chunks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo "✅ 索引建立完成！共 " . count($chunks) . " 個文檔段落\n";
    echo "💾 已儲存至: $embeddingsFile\n\n";

    return $chunks;
}

/**
 * 步驟 2: 檢索相關文檔（查詢 -> Embedding -> 計算相似度 -> 返回 Top-K）
 */
function retrieve($client, $query, $chunks, $topK = 3) {
    echo "🔍 檢索相關文檔...\n";
    echo "查詢: $query\n\n";

    // 將查詢轉換成 embedding
    $response = $client->embeddings()->create([
        'model' => 'text-embedding-3-small',
        'input' => $query,
    ]);

    $queryEmbedding = $response->embeddings[0]->embedding;

    // 計算每個文檔段落與查詢的相似度
    $results = [];
    foreach ($chunks as $chunk) {
        $similarity = cosineSimilarity($queryEmbedding, $chunk['embedding']);
        $results[] = [
            'chunk' => $chunk,
            'similarity' => $similarity,
        ];
    }

    // 按相似度排序，取 Top-K
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    $topResults = array_slice($results, 0, $topK);

    echo "📊 相似度排名 (Top $topK):\n";
    foreach ($topResults as $i => $result) {
        echo sprintf(
            "  %d. [%.4f] %s - %s\n",
            $i + 1,
            $result['similarity'],
            $result['chunk']['source'],
            mb_substr($result['chunk']['text'], 0, 60) . '...'
        );
    }
    echo "\n";

    return $topResults;
}

/**
 * 輔助函數：計算 Cosine Similarity
 */
function cosineSimilarity($vec1, $vec2) {
    $dotProduct = 0;
    $magnitude1 = 0;
    $magnitude2 = 0;

    for ($i = 0; $i < count($vec1); $i++) {
        $dotProduct += $vec1[$i] * $vec2[$i];
        $magnitude1 += $vec1[$i] * $vec1[$i];
        $magnitude2 += $vec2[$i] * $vec2[$i];
    }

    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);

    if ($magnitude1 == 0 || $magnitude2 == 0) {
        return 0;
    }

    return $dotProduct / ($magnitude1 * $magnitude2);
}

/**
 * 步驟 3: RAG 問答（檢索 + 生成）
 */
function ragAnswer($client, $query, $chunks) {
    // 檢索相關文檔
    $topResults = retrieve($client, $query, $chunks, 3);

    // 組合 context
    $context = "以下是相關的知識庫內容：\n\n";
    foreach ($topResults as $i => $result) {
        $context .= "【參考資料 " . ($i + 1) . "】\n";
        $context .= $result['chunk']['text'] . "\n\n";
    }

    // 呼叫 LLM 生成答案
    echo "💬 使用 RAG 生成答案...\n\n";

    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一個電商客服助理。請根據提供的知識庫內容回答用戶問題。如果知識庫中沒有相關資訊，請明確告知用戶。回答要準確、簡潔、友善。'
            ],
            [
                'role' => 'user',
                'content' => $context . "用戶問題：" . $query
            ],
        ],
    ]);

    return $response->choices[0]->message->content;
}

/**
 * 對比：無 RAG 的答案（LLM 直接回答，沒有知識庫）
 */
function directAnswer($client, $query) {
    echo "💬 無 RAG（LLM 直接回答）...\n\n";

    $response = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是一個電商客服助理。請回答用戶問題。'
            ],
            [
                'role' => 'user',
                'content' => $query
            ],
        ],
    ]);

    return $response->choices[0]->message->content;
}

// ============================================
// 主程式
// ============================================

$knowledgeBaseDir = __DIR__ . '/knowledge-base';
$embeddingsFile = __DIR__ . '/embeddings.json';

echo "\n";
echo "========================================\n";
echo "  Pattern 13: RAG 檢索增強生成\n";
echo "========================================\n\n";

// 檢查是否已有索引
if (!file_exists($embeddingsFile)) {
    echo "⚠️  尚未建立索引，開始建立...\n\n";
    $chunks = buildIndex($client, $knowledgeBaseDir, $embeddingsFile);
} else {
    echo "✅ 載入現有索引...\n";
    $chunks = json_decode(file_get_contents($embeddingsFile), true);
    echo "📚 已載入 " . count($chunks) . " 個文檔段落\n\n";
}

echo "========================================\n";
echo "  示範 1: 退換貨政策查詢\n";
echo "========================================\n\n";

$query1 = "我買的客製化蛋糕可以退貨嗎？";

echo "【問題】$query1\n\n";
echo "--- 無 RAG 的回答 ---\n";
$answer1NoRag = directAnswer($client, $query1);
echo $answer1NoRag . "\n\n";

echo "--- 使用 RAG 的回答 ---\n";
$answer1WithRag = ragAnswer($client, $query1, $chunks);
echo "✨ 答案：\n" . $answer1WithRag . "\n\n";

echo "========================================\n";
echo "  示範 2: 運費查詢\n";
echo "========================================\n\n";

$query2 = "訂單滿多少可以免運費？";

echo "【問題】$query2\n\n";
echo "--- 無 RAG 的回答 ---\n";
$answer2NoRag = directAnswer($client, $query2);
echo $answer2NoRag . "\n\n";

echo "--- 使用 RAG 的回答 ---\n";
$answer2WithRag = ragAnswer($client, $query2, $chunks);
echo "✨ 答案：\n" . $answer2WithRag . "\n\n";

echo "========================================\n";
echo "  示範 3: 會員優惠查詢\n";
echo "========================================\n\n";

$query3 = "金卡會員有什麼優惠？";

echo "【問題】$query3\n\n";
echo "--- 無 RAG 的回答 ---\n";
$answer3NoRag = directAnswer($client, $query3);
echo $answer3NoRag . "\n\n";

echo "--- 使用 RAG 的回答 ---\n";
$answer3WithRag = ragAnswer($client, $query3, $chunks);
echo "✨ 答案：\n" . $answer3WithRag . "\n\n";

echo "========================================\n";
echo "  RAG 系統核心組件說明\n";
echo "========================================\n\n";

echo "✅ Embedding: 使用 OpenAI text-embedding-3-small\n";
echo "   - 將文本轉換成 1536 維向量\n";
echo "   - 捕捉語義相似性\n\n";

echo "✅ Vector Storage: 純 PHP + JSON 檔案\n";
echo "   - 儲存文檔段落及其 embedding\n";
echo "   - 適合小型知識庫（< 1000 文檔）\n\n";

echo "✅ Retrieval: Cosine Similarity 手寫實作\n";
echo "   - 計算查詢與每個段落的相似度\n";
echo "   - 返回 Top-K 最相關文檔\n\n";

echo "✅ Generation: 將檢索結果作為 context 給 LLM\n";
echo "   - LLM 根據知識庫內容生成準確答案\n";
echo "   - 避免幻覺（hallucination）\n\n";

echo "========================================\n";
echo "  對比總結\n";
echo "========================================\n\n";

echo "❌ 無 RAG:\n";
echo "   - LLM 憑記憶或猜測回答\n";
echo "   - 可能給出錯誤或過時的資訊\n";
echo "   - 無法回答專屬業務規則\n\n";

echo "✅ 有 RAG:\n";
echo "   - 基於實際知識庫內容回答\n";
echo "   - 準確、即時、可追溯來源\n";
echo "   - 知識庫更新後立即生效\n\n";

echo "💡 提示：刪除 embeddings.json 可重新建立索引\n\n";
