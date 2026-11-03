<?php

declare(strict_types=1);

// GabayBatas AI Assistant. The app sends the question, the Legal Information
// context it found for it, and the earlier turns of the conversation; the
// Gemini key never leaves the server. The app saves the exchange itself
// through ai_message_save.php.

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

$length = static fn(string $text): int => function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

$payload = api_json_input();
$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    api_error('Message cannot be empty.', 422);
}
if ($length($message) > 2000) {
    api_error('Message is too long. Please keep it under 2,000 characters.', 422);
}

// Legal Information articles the app matched to the question (optional).
$context = trim((string)($payload['context'] ?? ''));
if ($length($context) > 20000) {
    $context = function_exists('mb_substr') ? mb_substr($context, 0, 20000) : substr($context, 0, 20000);
}

// Earlier turns of this conversation: [{"role": "user"|"model", "text": "..."}], oldest first.
$contents = [];
$historyInput = is_array($payload['history'] ?? null) ? array_slice($payload['history'], -20) : [];
foreach ($historyInput as $turn) {
    $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text === '') {
        continue;
    }
    if ($length($text) > 6000) {
        $text = function_exists('mb_substr') ? mb_substr($text, 0, 6000) : substr($text, 0, 6000);
    }
    $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
}

// The articles go with the current question only, so the history stays short.
$prompt = $context === ''
    ? $message
    : "APPROVED GABAYBATAS LEGAL INFORMATION (from the app)\n{$context}\nEND OF APPROVED INFORMATION\n\nResident's question:\n{$message}";
$contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

$requestBody = [
    'system_instruction' => ['parts' => [['text' => require __DIR__ . '/../../includes/ai_instruction.php']]],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 2200,
        // The facts come from the app's articles, so a low thinking level keeps replies fast.
        'thinkingConfig' => ['thinkingLevel' => 'low'],
    ],
];

@set_time_limit(120);
$callGemini = static function () use ($model, $apiKey, $requestBody): array {
    $ch = curl_init(
        'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent'
    );
    if ($ch === false) {
        return [false, 'curl_init failed', 0];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 50,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$raw, $error, $status];
};

// Gemini sometimes answers "busy" (429/503) for a moment; try once more before giving up.
[$rawResponse, $curlError, $httpStatus] = $callGemini();
if ($curlError === '' && in_array($httpStatus, [429, 500, 503], true)) {
    sleep(2);
    [$rawResponse, $curlError, $httpStatus] = $callGemini();
}

if ($rawResponse === false || $curlError !== '') {
    api_error('The AI assistant is temporarily unavailable. Please try again.', 502);
}

$response = json_decode((string)$rawResponse, true);
if (!is_array($response)) {
    api_error('The AI assistant returned an invalid response.', 502);
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    $providerMessage = trim((string)($response['error']['message'] ?? ''));
    error_log('GabayBatas AI: Gemini HTTP ' . $httpStatus . ': ' . $providerMessage);
    // The free tier allows a fixed number of replies per day.
    if ($httpStatus === 429 && stripos($providerMessage, 'per day') !== false) {
        api_error("The AI assistant has reached today's limit. Please try again tomorrow.", 429);
    }
    api_error(in_array($httpStatus, [429, 503], true)
        ? 'The AI assistant is busy right now. Please try again in a moment.'
        : 'The AI assistant could not answer right now. Please try again.', 502);
}

// Join the text parts (thinking parts are not returned as text).
$reply = '';
foreach ((array)($response['candidates'][0]['content']['parts'] ?? []) as $part) {
    if (empty($part['thought'])) {
        $reply .= (string)($part['text'] ?? '');
    }
}
$reply = trim($reply);
// Apps before 1.0.2 cannot show the Submit a Case button, so they get the reply without its tag.
if (empty($payload['submit_button'])) {
    $reply = trim((string)preg_replace('/\s*\[SUBMIT_CASE:?[^\]]*\]/u', '', $reply));
}
if ($reply === '') {
    api_error('The AI assistant returned an empty response.', 502);
}

api_success([
    'message' => 'AI response generated.',
    'data' => [
        'reply' => $reply,
        'model' => $model,
    ],
]);
