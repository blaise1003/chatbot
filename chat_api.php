<?php

// ============================================================
// chat_api.php — Cervello del Chatbot chatbot
// Motore AI: Anthropic Claude
// Versione: TEST/Staging su hosting condiviso cPanel
// ============================================================

function json_response($payload, $status = 200) {
    if (class_exists('\\Chatbot\\Logger')) {
        if ((int) $status === 429) {
            \Chatbot\Logger::trackMetric('http_429_total');
            \Chatbot\Logger::alert(
                'Chatbot alert: HTTP 429',
                'Rate limit response generated. Status: 429',
                'http_429'
            );
        }

        if ((int) $status >= 500) {
            \Chatbot\Logger::trackMetric('http_500_total');
            \Chatbot\Logger::alert(
                'Chatbot alert: HTTP 5xx',
                'Server error response generated. Status: ' . (int) $status,
                'http_5xx'
            );
        }
    }

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
