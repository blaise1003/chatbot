<?php

//declare(strict_types=1);

$baseDir = dirname(__DIR__);
$logsDir = $baseDir . '/chatbot_logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}

if (!defined('CHATBOT_ALLOWED_ORIGINS')) {
    define('CHATBOT_ALLOWED_ORIGINS', ['https://example.com']);
}
if (!defined('CHATBOT_WIDGET_TOKEN_PREFIX')) {
    define('CHATBOT_WIDGET_TOKEN_PREFIX', 'pfw1');
}
if (!defined('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS')) {
    define('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS', 86400);
}
if (!defined('CHATBOT_ALERT_EMAIL_ENABLED')) {
    define('CHATBOT_ALERT_EMAIL_ENABLED', false);
}
if (!defined('CHATBOT_LOGS_DIR')) {
    define('CHATBOT_LOGS_DIR', $logsDir);
}

require_once $baseDir . '/classes/Logger.php';
require_once $baseDir . '/classes/HtmlSanitizer.php';
require_once $baseDir . '/classes/RequestGuard.php';
require_once $baseDir . '/classes/HandoffManager.php';
