<?php

function json_response($payload, $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function load_chatbot_config(): void
{
    $configPath = __DIR__ . '/chatbot_config.php';
    if (is_file($configPath)) {
        require_once $configPath;
        return;
    }

    json_response(['ok' => false, 'error' => 'Config mancante'], 503);
}

load_chatbot_config();

spl_autoload_register(function ($className) {
    $prefix = 'Chatbot\\';
    if (strpos($className, $prefix) !== 0) {
        return;
    }

    $relativeName = substr($className, strlen($prefix));
    $classFile = __DIR__ . '/classes/' . str_replace('\\', '/', $relativeName) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
    $classStorageFile = __DIR__ . '/classes/storage/' . str_replace('\\', '/', $relativeName) . '.php';
    if (file_exists($classStorageFile)) {
        require_once $classStorageFile;
    }
});

$requestGuard = new Chatbot\RequestGuard();
$requestGuard->enforceBearerToken();
$requestGuard->applyCorsPolicy();
$requestGuard->applySecurityHeaders();

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST','OPTIONS'], true)) {
    json_response(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

$raw = json_decode(file_get_contents('php://input'), true);
if (!is_array($raw)) {
    json_response(['ok' => false, 'error' => 'Body non valido'], 400);
}

// Validazione sicurezza: rilevamento injection pattern comuni
if (!\Chatbot\JsonSecurityValidator::validateJsonSafety($raw, ['session_id', 'last_operator_message_id'])) {
    json_response(['ok' => false, 'error' => 'Richiesta contiene pattern sospetti'], 400);
}

$sessionId = isset($raw['session_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $raw['session_id']) : '';
$lastOperatorMessageId = isset($raw['last_operator_message_id']) ? (int) $raw['last_operator_message_id'] : 0;
if ($sessionId === '') {
    json_response(['ok' => false, 'error' => 'session_id mancante'], 400);
}

$sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : dirname(__DIR__, 2) . '/chatbot_sessions';
$redisStorage = new Chatbot\RedisStorage(
    defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1',
    defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379,
    defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '',
    defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'getty:',
    defined('CHATBOT_REDIS_TTL') ? (int) CHATBOT_REDIS_TTL : 1800
);
$trafficLimiter = new Chatbot\TrafficLimiter(
    new Chatbot\FileStorage($sessionDir),
    $redisStorage
);

$pollMaxRequests = defined('CHATBOT_HANDOFF_POLL_RATE_LIMIT_MAX_REQUESTS')
    ? (int) CHATBOT_HANDOFF_POLL_RATE_LIMIT_MAX_REQUESTS
    : 90;
$pollWindowSeconds = defined('CHATBOT_HANDOFF_POLL_RATE_LIMIT_WINDOW_SECONDS')
    ? (int) CHATBOT_HANDOFF_POLL_RATE_LIMIT_WINDOW_SECONDS
    : 60;

$clientIp = $requestGuard->getClientIp();
$pollBucket = 'handoff_poll:' . $clientIp . ':' . $sessionId;
if ($trafficLimiter->isExceeded($pollBucket, $pollMaxRequests, $pollWindowSeconds)) {
    header('Retry-After: ' . max(1, $pollWindowSeconds));
    json_response(['ok' => false, 'error' => 'Troppe richieste di polling'], 429);
	exit;
}

$handoff = new Chatbot\HandoffManager($sessionDir);
$state = $handoff->getState($sessionId);
$messages = $handoff->getOperatorMessagesSince($sessionId, $lastOperatorMessageId);

$respJson = [
    'ok' => true,
    'handoff' => [
        'status' => $state['status'],
        'claimed_by' => $state['claimed_by'],
        'operator_typing' => !empty($state['operator_typing']),
        'operator_typing_by' => isset($state['operator_typing_by']) ? (string) $state['operator_typing_by'] : '',
        'operator_typing_at' => isset($state['operator_typing_at']) ? (string) $state['operator_typing_at'] : '',
    ],
    'messages' => $messages,
    'last_operator_message_id' => (int) $state['last_operator_message_id'],
];

// Validazione sicurezza: rilevamento injection pattern comuni
if (!\Chatbot\JsonSecurityValidator::validateJsonSafety($respJson, ['ok', 'handoff', 'messages', 'last_operator_message_id'])) {
    json_response(['ok' => false, 'error' => 'Richiesta contiene pattern sospetti'], 400);
}

json_response($respJson);