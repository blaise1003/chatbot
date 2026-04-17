<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


//declare(strict_types=1);

use Chatbot\Logger;

session_start();

header('Content-Type: application/json; charset=UTF-8');

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_handoff_session_id($value): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string) $value));
}

function require_handoff_session_id($value): string
{
    $sessionId = sanitize_handoff_session_id($value);
    if ($sessionId === '') {
        json_out(['ok' => false, 'error' => 'session_id mancante o non valido'], 400);
    }

    return $sessionId;
}

$configCandidates = [
    __DIR__ . '/chatbot_config.php',
    dirname(__DIR__) . '/chatbot_config.php',
    dirname(__DIR__, 2) . '/chatbot_config.php',
];
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require_once $configPath;
        break;
    }
}

spl_autoload_register(function ($className) {
    $prefix = 'Chatbot\\';
    if (strpos($className, $prefix) !== 0) {
        return;
    }

    $relativeName = substr($className, strlen($prefix));
    $classFile = dirname(__DIR__) . '/classes/' . str_replace('\\', '/', $relativeName) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
        return;
    }

    $storageFile = dirname(__DIR__) . '/classes/storage/' . str_replace('\\', '/', $relativeName) . '.php';
    if (file_exists($storageFile)) {
        require_once $storageFile;
    }
});

function load_mysql_storage_for_handoff(): ?Chatbot\MySqlStorage
{
    $dsn = defined('CHATBOT_MYSQL_DSN') ? (string) CHATBOT_MYSQL_DSN : '';
    $user = defined('CHATBOT_MYSQL_USER') ? (string) CHATBOT_MYSQL_USER : '';
    $pass = defined('CHATBOT_MYSQL_PASSWORD') ? (string) CHATBOT_MYSQL_PASSWORD : '';
    $table = defined('CHATBOT_MYSQL_TABLE') ? (string) CHATBOT_MYSQL_TABLE : 'chatbot_conversations';

    $mysql = new Chatbot\MySqlStorage($dsn, $user, $pass, $table);
    return $mysql->isAvailable() ? $mysql : null;
}

function load_history_for_handoff(string $sessionId): array
{
    $sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : dirname(__DIR__, 3) . '/chatbot_sessions';
    $fileStorage = new Chatbot\FileStorage($sessionDir);

    $redis = new Chatbot\RedisStorage(
        defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1',
        defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379,
        defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '',
        defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'getty:',
        defined('CHATBOT_REDIS_TTL') ? (int) CHATBOT_REDIS_TTL : 1800
    );

    if ($redis->isAvailable()) {
        $history = $redis->loadHistory($sessionId);
        if (!empty($history)) {
            return $history;
        }
    } else {
		Logger::logError('Redis non disponibile per caricamento conversazione handoff', ['session_id' => $sessionId]);
	}

    return $fileStorage->loadHistory($sessionId);
}

function save_history_for_handoff(string $sessionId, array $history): void
{
    $sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : dirname(__DIR__) . '/chatbot_sessions';
    $fileStorage = new Chatbot\FileStorage($sessionDir);

    $redis = new Chatbot\RedisStorage(
        defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1',
        defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379,
        defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '',
        defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'getty:',
        defined('CHATBOT_REDIS_TTL') ? (int) CHATBOT_REDIS_TTL : 1800
    );

    if ($redis->isAvailable()) {
        try {
            $redis->saveHistory($sessionId, $history);
        } catch (Throwable $e) {
            // fallback to file
        }
    } else {
		Logger::logError('Redis non disponibile per salvataggio conversazione handoff', ['session_id' => $sessionId]);
    	$fileStorage->saveHistory($sessionId, $history);
	}
}

function flush_handoff_history_snapshot(string $sessionId, string $reason): void
{
    $history = load_history_for_handoff($sessionId);
    $mysql = load_mysql_storage_for_handoff();
    if ($mysql !== null) {
        $mysql->upsertHistorySnapshot($sessionId, $history, $reason);
    }
}

function append_operator_reply_to_history(string $sessionId, string $operator, string $message): void
{
    $history = load_history_for_handoff($sessionId);
    if (!is_array($history)) {
        $history = [];
    }

    $payload = [
        'reply' => trim($message),
        'options' => [],
        'operator_handoff' => true,
        'operator_name' => trim($operator),
    ];
    $history[] = ['role' => 'assistant', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)];

    if (count($history) > 40) {
        $systemMessage = array_shift($history);
        $history = array_slice($history, -38);
        if (is_array($systemMessage)) {
            array_unshift($history, $systemMessage);
        }
    }

    save_history_for_handoff($sessionId, $history);
}

if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
	Logger::logError('Unauthorized access attempt to handoff_api.php', ['session' => $_SESSION]);
    json_out(['ok' => false, 'error' => 'Non autorizzato'], 401);
}

$csrf = isset($_REQUEST['csrf']) ? (string) $_REQUEST['csrf'] : '';
$sessionCsrf = isset($_SESSION['dashboard_csrf']) && is_string($_SESSION['dashboard_csrf'])
    ? $_SESSION['dashboard_csrf']
    : '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    json_out(['ok' => false, 'error' => 'CSRF token non valido'], 403);
}

$sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : dirname(__DIR__) . '/chatbot_sessions';
$handoff = new Chatbot\HandoffManager($sessionDir);

$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
$operator = isset($_SESSION['dashboard_operator']) ? trim((string) $_SESSION['dashboard_operator']) : '';
if ($operator === '') {
    $operator = 'operatore-admin';
}

if ($action === 'list') {
    json_out(['ok' => true, 'items' => $handoff->listForQueue()]);
}

if ($action === 'get') {
    $sessionId = require_handoff_session_id($_REQUEST['session_id'] ?? '');
    $state = $handoff->getQueueState($sessionId);
    if (!empty($state['_is_hidden_expired'])) {
        json_out(['ok' => false, 'error' => 'Sessione non disponibile'], 404);
    }
    json_out(['ok' => true, 'state' => $state]);
}

if ($action === 'claim') {
    $sessionId = require_handoff_session_id($_POST['session_id'] ?? '');
    $state = $handoff->claim($sessionId, $operator);
    if (!empty($state['_claim_ok'])) {
        flush_handoff_history_snapshot($sessionId, 'handoff_claimed');
        json_out(['ok' => true, 'state' => $state]);
    }

    json_out(['ok' => false, 'error' => $state['_claim_error'] ?? 'Claim non riuscito', 'state' => $state], 409);
}

if ($action === 'send') {
    $sessionId = require_handoff_session_id($_POST['session_id'] ?? '');
    $message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
    if ($message === '') {
        json_out(['ok' => false, 'error' => 'Messaggio vuoto'], 400);
    }
	// Validazione sicurezza: rilevamento injection pattern comuni
	$data = ['message' => $message, 'session_id' => $sessionId, 'operator' => $operator];
	if (!\Chatbot\JsonSecurityValidator::validateJsonSafety($data, ['operator', 'session_id', 'message'])) {
		Logger::logError("send_action", "Richiesta contiene pattern sospetti di injection.");
		\json_response([
			'reply' => 'Richiesta non valida.',
			'options' => []
		], 400);
	}

    $state = $handoff->getQueueState($sessionId);
    if (!empty($state['_is_hidden_expired'])) {
        json_out(['ok' => false, 'error' => 'Sessione non disponibile'], 404);
    }

    if (!$handoff->canOperatorReply($state)) {
        json_out(['ok' => false, 'error' => 'Sessione scaduta: non è più possibile rispondere'], 409);
    }

    if ((string) $state['status'] !== 'claimed') {
        json_out(['ok' => false, 'error' => 'Sessione non in carico'], 409);
    }

    if ((string) $state['claimed_by'] !== $operator) {
        json_out(['ok' => false, 'error' => 'Sessione in carico ad altro operatore'], 409);
    }

    $updated = $handoff->addOperatorMessage($sessionId, $operator, $message);
    append_operator_reply_to_history($sessionId, $operator, $message);

    $operatorMessageId = isset($updated['last_operator_message_id']) ? (int) $updated['last_operator_message_id'] : 0;
    if ($operatorMessageId > 0 && $operatorMessageId % 3 === 0) {
		Logger::logDebug("flush_handoff_history_snapshot", "Flushing handoff conversation snapshot for session {$sessionId} after operator message ID {$operatorMessageId}");
        flush_handoff_history_snapshot($sessionId, 'handoff_operator_reply_batch3');
    }

    json_out(['ok' => true, 'state' => $updated]);
}

if ($action === 'typing') {
    $sessionId = require_handoff_session_id($_POST['session_id'] ?? '');
    $isTypingRaw = isset($_POST['is_typing']) ? (string) $_POST['is_typing'] : '0';
    $isTyping = in_array(strtolower(trim($isTypingRaw)), ['1', 'true', 'yes', 'on'], true);

    $state = $handoff->getQueueState($sessionId);
    if (!empty($state['_is_hidden_expired'])) {
        json_out(['ok' => false, 'error' => 'Sessione non disponibile'], 404);
    }

    if ((string) $state['status'] !== 'claimed') {
        json_out(['ok' => false, 'error' => 'Sessione non in carico'], 409);
    }

    if ((string) $state['claimed_by'] !== $operator) {
        json_out(['ok' => false, 'error' => 'Sessione in carico ad altro operatore'], 409);
    }

    $updated = $handoff->setOperatorTyping($sessionId, $operator, $isTyping);
    json_out([
        'ok' => true,
        'typing' => [
            'is_typing' => !empty($updated['operator_typing']),
            'operator' => (string) ($updated['operator_typing_by'] ?? ''),
            'at' => (string) ($updated['operator_typing_at'] ?? ''),
        ],
    ]);
}

if ($action === 'close') {
    $sessionId = require_handoff_session_id($_POST['session_id'] ?? '');
    $note = isset($_POST['note']) ? (string) $_POST['note'] : '';
    $updated = $handoff->close($sessionId, $operator, $note);

	Logger::logDebug("flush_handoff_history_snapshot", "Flushing handoff conversation snapshot for closed session {$sessionId}");
    flush_handoff_history_snapshot($sessionId, 'handoff_closed');

    json_out(['ok' => true, 'state' => $updated]);
}

json_out(['ok' => false, 'error' => 'Azione non supportata'], 400);
