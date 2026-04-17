<?php

namespace Chatbot;

class Logger {
    public static function logRequest($method, $params) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Params: " . json_encode($params) . "\n";
        error_log($logData, 3, self::getLogFilePath('requests'));
        self::trackMetric('requests_total');
    }

    public static function logError($method, $errorMessage) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Error: $errorMessage\n";
        error_log($logData, 3, self::getLogFilePath('errors'));

        self::trackMetric('errors_total');

        $normalized = strtolower((string) $errorMessage);
        if (strpos($normalized, 'rate limit') !== false || strpos($normalized, '429') !== false) {
            self::trackMetric('rate_limit_exceeded_total');
            self::alert('Chatbot alert: rate limit exceeded', "Method: $method\nError: $errorMessage", 'rate_limit_exceeded');
        }

        if (strpos($normalized, '500') !== false || strpos($normalized, 'server error') !== false) {
            self::trackMetric('http_500_total');
            self::alert('Chatbot alert: server error', "Method: $method\nError: $errorMessage", 'http_500');
        }
    }
    
    public static function logDebug($method, $message) {
        $logData = date('Y-m-d H:i:s') . " - Method: $method - Debug: $message\n";
        error_log($logData, 3, self::getLogFilePath('debug'));
    }

    public static function trackMetric($metricName, $increment = 1) {
        $name = preg_replace('/[^a-z0-9_\-]/i', '', (string) $metricName);
        $amount = (int) $increment;
        if ($name === '' || $amount <= 0) {
            return;
        }

        $metrics = self::readDailyMetrics();
        if (!isset($metrics[$name]) || !is_int($metrics[$name])) {
            $metrics[$name] = 0;
        }
        $metrics[$name] += $amount;
        $metrics['updated_at'] = date('c');

        self::writeJsonFile(self::getMetricsFilePath(), $metrics);
    }

    public static function getMetricsSnapshot() {
        return self::readDailyMetrics();
    }

	/**
	 * Sends an alert email if enabled and conditions are met.
	 * @param mixed $subject The subject of the alert email.
	 * @param mixed $message The body of the alert email.
	 * @param mixed $eventKey A key to identify the type of alert.
	 * @return bool True if the alert was sent successfully, false otherwise.
	 */
	public static function alert($subject, $message, $eventKey = 'generic') {
        $enabled = defined('CHATBOT_ALERT_EMAIL_ENABLED') ? (bool) CHATBOT_ALERT_EMAIL_ENABLED : false;
        if (!$enabled) {
            return false;
        }

        $to = defined('CHATBOT_ALERT_EMAIL_TO') ? trim((string) CHATBOT_ALERT_EMAIL_TO) : '';
        if ($to === '' || strpos($to, '@') === false) {
            return false;
        }

        $cooldown = defined('CHATBOT_ALERT_EMAIL_COOLDOWN_SECONDS')
            ? max(60, (int) CHATBOT_ALERT_EMAIL_COOLDOWN_SECONDS)
            : 600;

        if (!self::canSendAlert($eventKey, $cooldown)) {
            return false;
        }

        $from = defined('CHATBOT_ALERT_EMAIL_FROM') ? trim((string) CHATBOT_ALERT_EMAIL_FROM) : '';
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($from !== '' && strpos($from, '@') !== false) {
            $headers .= "From: " . $from . "\r\n";
        }

        $ok = @mail($to, (string) $subject, (string) $message, $headers);
        if ($ok) {
            self::markAlertSent($eventKey);
            self::trackMetric('alerts_sent_total');
            return true;
        }

        self::trackMetric('alerts_failed_total');
        return false;
    }

    private static function getLogFilePath($channel) {
		$logDir = dirname(__DIR__, 3) . '/chatbot_logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        return $logDir . '/' . $channel . '-' . date('Y-m-d') . '.log';
    }

    private static function getMetricsFilePath() {
        return self::getBaseDir() . '/metrics-' . date('Y-m-d') . '.json';
    }

    private static function getAlertStateFilePath() {
        return self::getBaseDir() . '/alerts-state.json';
    }

    private static function getBaseDir() {
		$logDir = dirname(__DIR__, 3) . '/chatbot_logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        return $logDir;
    }

    private static function readDailyMetrics() {
        $path = self::getMetricsFilePath();
        if (!is_file($path)) {
            return [
                'requests_total' => 0,
                'errors_total' => 0,
                'rate_limit_exceeded_total' => 0,
                'http_500_total' => 0,
                'alerts_sent_total' => 0,
                'alerts_failed_total' => 0,
                'updated_at' => date('c'),
            ];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function canSendAlert($eventKey, $cooldownSeconds) {
        $state = self::readAlertState();
        $key = preg_replace('/[^a-z0-9_\-]/i', '', (string) $eventKey);
        if ($key === '') {
            $key = 'generic';
        }

        $now = time();
        $last = isset($state[$key]) ? (int) $state[$key] : 0;
        return ($now - $last) >= $cooldownSeconds;
    }

    private static function markAlertSent($eventKey) {
        $state = self::readAlertState();
        $key = preg_replace('/[^a-z0-9_\-]/i', '', (string) $eventKey);
        if ($key === '') {
            $key = 'generic';
        }

        $state[$key] = time();
        self::writeJsonFile(self::getAlertStateFilePath(), $state);
    }

    private static function readAlertState() {
        $path = self::getAlertStateFilePath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function writeJsonFile($path, array $payload) {
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}