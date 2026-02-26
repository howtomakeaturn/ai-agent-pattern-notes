<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);

// ============================================
// 圖片生成：DALL-E 3
// ============================================

$prompt = '一杯星巴克拿鐵咖啡放在木桌上，旁邊有一張台灣電子發票，極簡風格，自然光，俯拍';

echo "🎨 生成圖片中...\n";
echo "Prompt: {$prompt}\n\n";

$response = $client->images()->create([
    'model'           => 'dall-e-3',
    'prompt'          => $prompt,
    'size'            => '1024x1024',
    'quality'         => 'standard',
    'n'               => 1,
    'response_format' => 'b64_json',   // 直接拿 base64，不依賴暫時 URL
]);

$b64  = $response->data[0]->b64_json;
$revised = $response->data[0]->revisedPrompt;

echo "📝 實際使用的 Prompt（DALL-E 3 自動修訂）:\n";
echo $revised . "\n\n";

// 存成本地檔案
$outputPath = __DIR__ . '/output.png';
file_put_contents($outputPath, base64_decode($b64));

echo "✅ 圖片已儲存: {$outputPath}\n";
echo "檔案大小: " . round(filesize($outputPath) / 1024) . " KB\n";
