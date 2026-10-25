<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');

$user = api_current_user($conn);
if (!$user) {
    api_error('Unauthorized.', 401);
}

$configPath = __DIR__ . '/../../config/gemini.local.php';
if (!is_file($configPath)) {
    api_error('AI service is not configured.', 503);
}

$config = require $configPath;
$apiKey = trim((string)($config['api_key'] ?? ''));
$model = trim((string)($config['model'] ?? 'gemini-3.6-flash'));
if ($apiKey === '') {
    api_error('AI service is not configured.', 503);
}

$payload = api_json_input();
$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    api_error('Message cannot be empty.', 422);
}

if (function_exists('mb_strlen') ? mb_strlen($message) > 2000 : strlen($message) > 2000) {
    api_error('Message is too long. Please keep it under 2,000 characters.', 422);
}

$systemInstruction = <<<'TEXT'
You are GabayBatas, a careful legal information assistant for residents of Barangay Muñoz in the Philippines.
Give general legal information and practical next steps, not legal representation or guaranteed outcomes.
Use plain, respectful language. Do not invent laws, deadlines, fees, offices, or case facts.
Ask a concise clarifying question when essential details are missing.
For urgent danger, abuse, or emergencies, advise the resident to contact the appropriate emergency service or barangay authority immediately.
When a matter requires official action, recommend contacting Barangay Muñoz staff or a qualified Philippine lawyer.
Do not request passwords, API keys, or unnecessary sensitive personal information.
TEXT;

$userId = (string)($user['user_id'] ?? '');
$history = [];
$historyStmt = $conn->prepare('SELECT role, content FROM api_ai_messages WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
if ($historyStmt) {
    $historyStmt->bind_param('s', $userId);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    if ($historyResult) {
        while ($row = $historyResult->fetch_assoc()) {
            $history[] = [
                'role' => (string)($row['role'] ?? ''),
                'parts' => [['text' => (string)($row['content'] ?? '')]],
            ];
        }
    }
    $historyStmt->close();
}
$history = array_reverse($history);
$history[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$requestBody = [
    'system_instruction' => [
        'parts' => [
            ['text' => $systemInstruction],
        ],
    ],
    'contents' => $history,
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 700,
    ],
];

$ch = curl_init(
    'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model)
    . ':generateContent?key='
    . rawurlencode($apiKey)
);
if ($ch === false) {
    api_error('Unable to initialize AI service.', 502);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_SLASHES),
]);

$rawResponse = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($rawResponse === false || $curlError !== '') {
    api_error('AI service is temporarily unavailable: ' . ($curlError !== '' ? $curlError : 'no response received') . '.', 502);
}

$response = json_decode((string)$rawResponse, true);
if (!is_array($response)) {
    api_error('AI service returned an invalid response.', 502);
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    $providerMessage = trim((string)($response['error']['message'] ?? ''));
    if ($providerMessage === '') {
        $providerMessage = 'The provider returned an unexpected error.';
    }
    api_error('AI service could not process the request: ' . $providerMessage, 502);
}

$reply = trim((string)($response['candidates'][0]['content']['parts'][0]['text'] ?? ''));
if ($reply === '') {
    api_error('AI service returned an empty response.', 502);
}

$saveStmt = $conn->prepare('INSERT INTO api_ai_messages (user_id, role, content, created_at) VALUES (?, ?, ?, NOW())');
if (!$saveStmt) {
    api_error('Unable to save AI conversation.', 500);
}

$userRole = 'user';
$saveStmt->bind_param('sss', $userId, $userRole, $message);
if (!$saveStmt->execute()) {
    $saveStmt->close();
    api_error('Unable to save AI conversation.', 500);
}
$saveStmt->close();

$modelRole = 'model';
$saveStmt = $conn->prepare('INSERT INTO api_ai_messages (user_id, role, content, created_at) VALUES (?, ?, ?, NOW())');
if (!$saveStmt) {
    api_error('Unable to save AI response.', 500);
}
$saveStmt->bind_param('sss', $userId, $modelRole, $reply);
if (!$saveStmt->execute()) {
    $saveStmt->close();
    api_error('Unable to save AI response.', 500);
}
$saveStmt->close();

api_success([
    'message' => 'AI response generated.',
    'data' => [
        'reply' => $reply,
        'model' => $model,
    ],
]);
