<?php

// ============================================================
// chat_api.php — Cervello del Chatbot example
// Motore AI: Anthropic Claude
// Versione: TEST/Staging su hosting condiviso cPanel
// ============================================================

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('error_log', __DIR__ . '/../chatbot_logs/php_errors.log');	

function json_response($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function load_chatbot_config() {
    $configPathUp = dirname(__FILE__) . '/chatbot_config.php';
    if (file_exists($configPathUp)) {
        require_once $configPathUp;
		return;
    }

    json_response([
        'reply' => 'Configurazione chatbot non disponibile.',
        'options' => []
    ], 500);
}

load_chatbot_config();

if (!defined('DOOFINDER_TOKEN')) define('DOOFINDER_TOKEN', '');
if (!defined('DOOFINDER_SEARCH_URL')) define('DOOFINDER_SEARCH_URL', 'https://eu1-search.doofinder.com/6/xxxxxxxxxxxxx/_search');
if (!defined('ORDER_API_TOKEN')) define('ORDER_API_TOKEN', '');
if (!defined('BASE_API_URL')) define('BASE_API_URL', 'https://www.example.org/');
if (!defined('ORDER_API_URL')) define('ORDER_API_URL', BASE_API_URL . 'oct8ne/frame/getOrderDetails');
if (!defined('ORDERS_API_URL')) define('ORDERS_API_URL', BASE_API_URL . 'oct8ne/frame/getOrders');
if (!defined('CHATBOT_ALLOWED_ORIGINS')) define('CHATBOT_ALLOWED_ORIGINS', []);
if (!defined('CHATBOT_ALLOW_TEST_MODE')) define('CHATBOT_ALLOW_TEST_MODE', false);
if (!defined('CHATBOT_AI_PROVIDER')) define('CHATBOT_AI_PROVIDER', 'claude');
if (!defined('CHATBOT_SESSION_DIR')) define('CHATBOT_SESSION_DIR', dirname(__DIR__) . '/chatbot_sessions');
if (!defined('CHATBOT_RATE_LIMIT_MAX_REQUESTS')) define('CHATBOT_RATE_LIMIT_MAX_REQUESTS', 20);
if (!defined('CHATBOT_RATE_LIMIT_WINDOW_SECONDS')) define('CHATBOT_RATE_LIMIT_WINDOW_SECONDS', 300);
if (!defined('CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS')) define('CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS', 3000);
if (!defined('CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS')) define('CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS', 60);
if (!defined('CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS')) define('CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS', 1800);
if (!defined('CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS')) define('CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS', 60);
if (!defined('CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS')) define('CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS', 1200);
if (!defined('CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS')) define('CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS', 60);

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

$application = Chatbot\ApplicationBootstrap::build();
$application->run();
