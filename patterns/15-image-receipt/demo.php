<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use OpenAI\Client;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ============================================
// 圖片輸入：支援本地檔案（base64）或遠端 URL
// ============================================

function loadImage(string $source): array
{
    if (filter_var($source, FILTER_VALIDATE_URL)) {
        // 遠端 URL：直接用
        return [
            'type' => 'image_url',
            'image_url' => ['url' => $source],
        ];
    } else {
        // 本地檔案：轉 base64
        $mimeType = mime_content_type($source);
        $base64   = base64_encode(file_get_contents($source));
        return [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"],
        ];
    }
}

// ============================================
// 主程式
// ============================================

$imagePath = __DIR__ . '/receipt.jpg';

echo "📷 載入圖片: {$imagePath}\n\n";

$imageContent = loadImage($imagePath);

// 送給 GPT-4o Vision，要求回傳結構化 JSON
$response = $client->chat()->create([
    'model' => 'gpt-5-mini',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                $imageContent,
                [
                    'type' => 'text',
                    'text' => <<<PROMPT
請仔細閱讀這張發票或收據，擷取以下資訊並以 JSON 格式回傳：

{
  "store_name": "店家名稱",
  "invoice_period": "發票期別，例如 115年01-02月（台灣電子發票專用，非日期）",
  "invoice_number": "發票號碼，例如 WH-83513141（英文字母開頭的8碼）",
  "date": "消費日期 (YYYY-MM-DD)，西元年",
  "items": [
    { "name": "品項名稱", "quantity": 數量, "price": 單價 }
  ],
  "subtotal": 小計,
  "tax": 稅額,
  "total": 總金額（純數字，單位為新台幣元）,
  "payment_method": "付款方式",
  "notes": "其他備註（如無則為 null）"
}

只回傳 JSON，不要其他說明文字。若某欄位看不清楚或不存在，填入 null。
PROMPT
                ],
            ],
        ],
    ],
]);

$raw = $response->choices[0]->message->content;

echo "🤖 LLM 回應:\n";
echo $raw . "\n\n";

// 解析 JSON
$data = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ JSON 解析失敗: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "✅ 結構化資料擷取成功！\n\n";
echo "店家: " . ($data['store_name'] ?? 'N/A') . "\n";
echo "發票期別: " . ($data['invoice_period'] ?? 'N/A') . "\n";
echo "發票號碼: " . ($data['invoice_number'] ?? 'N/A') . "\n";
echo "日期: " . ($data['date'] ?? 'N/A') . "\n";
echo "總金額: " . ($data['total'] ?? 'N/A') . "\n";

if (!empty($data['items'])) {
    echo "\n品項明細:\n";
    foreach ($data['items'] as $item) {
        $name = $item['name'] ?? '?';
        $qty  = $item['quantity'] ?? '?';
        $price = $item['price'] ?? '?';
        echo "  - {$name} x{$qty}  \${$price}\n";
    }
}
