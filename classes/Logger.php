<?php

namespace Chatbot;

class Logger {
    public static function logRequest($method, $params) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Params: " . json_encode($params) . "\n";
        error_log($logData, 3, __DIR__ . '/../../chatbot_logs/requests.log');
    }

    public static function logError($method, $errorMessage) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Error: $errorMessage\n";
        error_log($logData, 3, __DIR__ . '/../../chatbot_logs/errors.log');
    }
    
    public static function logDebug($method, $message) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Debug: $message\n";
        error_log($logData, 3, __DIR__ . '/../../chatbot_logs/debug.log');
    }
}