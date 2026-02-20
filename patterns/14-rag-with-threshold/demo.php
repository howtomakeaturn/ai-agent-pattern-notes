<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use OpenAI\Client;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ============================================
// RAG 系統（加入相似度門檻）
// ============================================

/**
 * 檢索相關文檔（帶相似度門檻過濾）
 *
 * @param float $minSimilarity 最低相似度門檻（0-1），低於此值視為不相關
 */
function retrieve($client, $query, $chunks, $topK = 3, $minSimilarity = 0.5) {
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

    // 按相似度排序
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    // 取 Top-K 並過濾低相似度結果
    $topResults = array_slice($results, 0, $topK);
    $relevantResults = array_filter($topResults, fn($r) => $r['similarity'] >= $minSimilarity);

    echo "📊 相似度排名 (Top $topK, 門檻: $minSimilarity):\n";
    foreach ($topResults as $i => $result) {
        $isRelevant = $result['similarity'] >= $minSimilarity ? '✅' : '❌';
        echo sprintf(
            "  %d. [%.4f] %s %s - %s\n",
            $i + 1,
            $result['similarity'],
            $isRelevant,
            $result['chunk']['source'],
            mb_substr($result['chunk']['text'], 0, 50) . '...'
        );
    }

    if (empty($relevantResults)) {
        echo "\n⚠️  警告：沒有找到相似度超過 $minSimilarity 的相關文檔！\n";
    }
    echo "\n";

    return $relevantResults;
}

/**
 * 計算 Cosine Similarity
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
 * RAG 問答（帶門檻機制）
 */
function ragAnswerWithThreshold($client, $query, $chunks, $minSimilarity = 0.5) {
    // 檢索相關文檔（帶門檻過濾）
    $relevantResults = retrieve($client, $query, $chunks, 3, $minSimilarity);

    // 呼叫 LLM 生成答案
    echo "💬 使用 RAG 生成答案...\n\n";

    // 如果沒有相關文檔，明確告知 LLM
    if (empty($relevantResults)) {
        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '你是一個電商客服助理。'
                ],
                [
                    'role' => 'user',
                    'content' => "知識庫中沒有找到與以下問題相關的資訊。請禮貌地告知用戶這超出了知識庫範圍，建議他們聯絡客服人員。\n\n用戶問題：" . $query
                ],
            ],
        ]);
    } else {
        // 組合 context
        $context = "以下是相關的知識庫內容：\n\n";
        foreach ($relevantResults as $i => $result) {
            $context .= "【參考資料 " . ($i + 1) . "】（相似度: " . round($result['similarity'], 3) . "）\n";
            $context .= $result['chunk']['text'] . "\n\n";
        }

        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '你是一個電商客服助理。請嚴格根據提供的知識庫內容回答用戶問題。如果知識庫內容無法回答問題，請明確告知用戶。不要編造或推測知識庫以外的資訊。回答要準確、簡潔、友善。'
                ],
                [
                    'role' => 'user',
                    'content' => $context . "用戶問題：" . $query
                ],
            ],
        ]);
    }

    return $response->choices[0]->message->content;
}

// ============================================
// 主程式
// ============================================

echo "\n";
echo "========================================\n";
echo "  Pattern 14: RAG + 相似度門檻\n";
echo "========================================\n\n";

echo "💡 重點：防止 RAG 系統「亂回答」\n\n";

// 重複使用 Pattern 13 的索引
$embeddingsFile = __DIR__ . '/../13-rag/embeddings.json';

if (!file_exists($embeddingsFile)) {
    echo "❌ 錯誤：請先執行 Pattern 13 建立索引\n";
    echo "   php patterns/13-rag/demo.php\n\n";
    exit(1);
}

echo "✅ 載入 Pattern 13 的索引...\n";
$chunks = json_decode(file_get_contents($embeddingsFile), true);
echo "📚 已載入 " . count($chunks) . " 個文檔段落\n\n";

echo "========================================\n";
echo "  測試 1: 相關問題（有答案）\n";
echo "========================================\n\n";

$query1 = "金卡會員有什麼優惠？";
echo "【問題】$query1\n\n";

$answer1 = ragAnswerWithThreshold($client, $query1, $chunks, 0.5);
echo "✨ 答案：\n" . $answer1 . "\n\n";

echo "========================================\n";
echo "  測試 2: 不相關問題（沒答案）\n";
echo "========================================\n\n";

$query2 = "你們有賣 iPhone 嗎？";
echo "【問題】$query2\n";
echo "（這問題和蛋糕電商完全無關）\n\n";

$answer2 = ragAnswerWithThreshold($client, $query2, $chunks, 0.5);
echo "✨ 答案：\n" . $answer2 . "\n\n";

echo "========================================\n";
echo "  測試 3: 邊緣問題（弱相關）\n";
echo "========================================\n\n";

$query3 = "你們賣什麼產品？";
echo "【問題】$query3\n";
echo "（知識庫沒有「產品目錄」，但可能會匹配到一些段落）\n\n";

$answer3 = ragAnswerWithThreshold($client, $query3, $chunks, 0.5);
echo "✨ 答案：\n" . $answer3 . "\n\n";

echo "========================================\n";
echo "  相似度門檻機制說明\n";
echo "========================================\n\n";

echo "🔑 核心改進：\n\n";

echo "1️⃣ 設定相似度門檻（預設 0.5）\n";
echo "   - 超過門檻：視為相關，提供給 LLM\n";
echo "   - 低於門檻：視為不相關，過濾掉\n\n";

echo "2️⃣ 沒有相關文檔時的處理\n";
echo "   - 明確告知 LLM「找不到相關資訊」\n";
echo "   - LLM 會禮貌地拒絕回答\n";
echo "   - 建議用戶聯絡客服人員\n\n";

echo "3️⃣ 更嚴格的 System Prompt\n";
echo "   - 要求「嚴格根據知識庫」\n";
echo "   - 禁止「編造或推測」\n";
echo "   - 強調「無法回答請明確告知」\n\n";

echo "========================================\n";
echo "  門檻值選擇建議\n";
echo "========================================\n\n";

echo "📏 常見門檻值：\n\n";
echo "0.3 - 寬鬆（可能包含弱相關內容）\n";
echo "0.5 - 平衡（推薦，本 demo 使用）\n";
echo "0.7 - 嚴格（只返回高度相關內容）\n";
echo "0.8 - 極嚴格（幾乎要完全匹配）\n\n";

echo "💡 建議：\n";
echo "- 先從 0.5 開始測試\n";
echo "- 觀察實際查詢的相似度分佈\n";
echo "- 根據業務需求調整\n\n";

echo "========================================\n";
echo "  Pattern 13 vs Pattern 14 對比\n";
echo "========================================\n\n";

echo "Pattern 13（基礎版）：\n";
echo "❌ 永遠返回 Top-K 結果\n";
echo "❌ 不管相似度多低都會給 LLM\n";
echo "❌ 可能導致「亂回答」\n\n";

echo "Pattern 14（門檻版）：\n";
echo "✅ 過濾低相似度結果\n";
echo "✅ 沒有相關內容時明確拒絕\n";
echo "✅ 避免幻覺（hallucination）\n";
echo "✅ 生產級 RAG 必備機制\n\n";

echo "💡 提示：實際應用中，門檻值可根據業務需求調整\n\n";
