<?php


function health_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function health_load_config(): void {
    $configPath = __DIR__ . '/chatbot_config.php';
    if (is_file($configPath)) {
        require_once $configPath;
        return;
    }

    health_json_response([
        'status' => 'down',
        'error' => 'Configurazione non disponibile'
    ], 503);
}

function health_is_local_request(): bool {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function health_is_authorized(): bool {
    $token = defined('CHATBOT_HEALTH_TOKEN') ? trim((string) CHATBOT_HEALTH_TOKEN) : '';
    if ($token === '') {
        return health_is_local_request();
    }

    $provided = '';
    if (isset($_SERVER['HTTP_X_HEALTH_TOKEN'])) {
        $provided = trim((string) $_SERVER['HTTP_X_HEALTH_TOKEN']);
    } elseif (isset($_GET['token'])) {
        $provided = trim((string) $_GET['token']);
    }

    return $provided !== '' && hash_equals($token, $provided);
}

function health_check_redis(): array {
    if (!extension_loaded('redis')) {
        return ['status' => 'degraded', 'detail' => 'ext-redis non installata'];
    }

    $host = defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1';
    $port = defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379;
    $password = defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '';

    try {
        $redis = new Redis();
        if (!$redis->connect($host, $port, 1.5)) {
            return ['status' => 'down', 'detail' => 'Connessione Redis fallita'];
        }

        if ($password !== '' && !$redis->auth($password)) {
            return ['status' => 'down', 'detail' => 'Auth Redis fallita'];
        }

        $pong = $redis->ping();
        $redis->close();

        return ['status' => ($pong ? 'up' : 'down'), 'detail' => $pong ? 'OK' : 'Ping fallito'];
    } catch (Throwable $e) {
        return ['status' => 'down', 'detail' => 'Errore Redis: ' . $e->getMessage()];
    }
}

function health_check_mysql(): array {
    $dsn = defined('CHATBOT_MYSQL_DSN') ? (string) CHATBOT_MYSQL_DSN : '';
    $user = defined('CHATBOT_MYSQL_USER') ? (string) CHATBOT_MYSQL_USER : '';
    $password = defined('CHATBOT_MYSQL_PASSWORD') ? (string) CHATBOT_MYSQL_PASSWORD : '';

    if ($dsn === '') {
        return ['status' => 'degraded', 'detail' => 'DSN MySQL non configurato'];
    }

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $stmt = $pdo->query('SELECT 1');
        $ok = $stmt !== false;
        return ['status' => $ok ? 'up' : 'down', 'detail' => $ok ? 'OK' : 'Query fallita'];
    } catch (Throwable $e) {
        return ['status' => 'down', 'detail' => 'Errore MySQL: ' . $e->getMessage()];
    }
}

function health_check_fs(): array {
    $sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : '';
    $logsDir = defined('CHATBOT_LOGS_DIR') ? (string) CHATBOT_LOGS_DIR : '';

    $sessionWritable = ($sessionDir !== '' && is_dir($sessionDir) && is_writable($sessionDir));
    $logsWritable = ($logsDir !== '' && is_dir($logsDir) && is_writable($logsDir));

    return [
        'status' => ($sessionWritable && $logsWritable) ? 'up' : 'degraded',
        'session_dir_writable' => $sessionWritable,
        'logs_dir_writable' => $logsWritable,
    ];
}

function health_load_metrics(): array {
    $logDir = defined('CHATBOT_LOGS_DIR') ? rtrim((string) CHATBOT_LOGS_DIR, '/\\') : (__DIR__ . '/../chatbot_logs');
    $metricsFile = $logDir . '/metrics-' . date('Y-m-d') . '.json';

    if (!is_file($metricsFile)) {
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

    $raw = @file_get_contents($metricsFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

function health_global_status(array $checks): string {
    $hasDown = false;
    $hasDegraded = false;

    foreach ($checks as $check) {
        $status = isset($check['status']) ? (string) $check['status'] : 'down';
        if ($status === 'down') {
            $hasDown = true;
        } elseif ($status === 'degraded') {
            $hasDegraded = true;
        }
    }

    if ($hasDown) {
        return 'down';
    }

    if ($hasDegraded) {
        return 'degraded';
    }

    return 'up';
}

function extract_config_constant_names(string $filePath): array {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    if (!is_string($content) || $content === '') {
        return [];
    }

    $names = [];

    if (preg_match_all("/ConfigLoader::define\\(\\s*'([A-Z0-9_]+)'/", $content, $matches) > 0) {
        $names = array_merge($names, $matches[1]);
    }

    if (preg_match_all("/define\\(\\s*'([A-Z0-9_]+)'/", $content, $matchesLegacy) > 0) {
        $names = array_merge($names, $matchesLegacy[1]);
    }

    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);
    return $names;
}

function format_constant_value($value): string {
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    if ($value === null) {
        return 'null';
    }

    if (is_string($value)) {
        return $value;
    }

    return '[tipo non gestito]';
}

function import_cli_args_into_get(): void {
    if (PHP_SAPI !== 'cli') {
        return;
    }

    $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
    if (count($argv) <= 1) {
        return;
    }

    foreach ($argv as $index => $arg) {
        if ($index === 0 || !is_string($arg) || $arg === '') {
            continue;
        }

        if (strpos($arg, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $arg, 2);
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        $_GET[$key] = $value;
    }
}

function health_check_constants_defined(bool $onlyMissing): array {
	$configFiles = [
		__DIR__ . '/chatbot_config.php',
		__DIR__ . '/basic_config.php',
	];

	$constantNames = [];
	foreach ($configFiles as $configFile) {
		$constantNames = array_merge($constantNames, extract_config_constant_names($configFile));
	}

	$constantNames = array_values(array_unique($constantNames));
	sort($constantNames, SORT_STRING);

	$results = [];
	foreach ($constantNames as $name) {
		$defined = defined($name);

		if ($onlyMissing && $defined) {
			continue;
		}

		$value = $defined ? constant($name) : null;
		$results[$name] = [
			'defined' => $defined,
			'value' => format_constant_value($value),
		];
	}
	return $results;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    health_json_response(['status' => 'error', 'message' => 'Metodo non consentito'], 405);
}

health_load_config();
import_cli_args_into_get();

if (!health_is_authorized()) {
    health_json_response(['status' => 'forbidden', 'message' => 'Accesso non autorizzato'], 403);
}

$onlyMissing = isset($_GET['only_missing'])
    && in_array(strtolower((string) $_GET['only_missing']), ['1', 'true', 'yes', 'on'], true);

$checks = [
    'php' => [
        'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'up' : 'down',
        'version' => PHP_VERSION,
    ],
    'redis' => health_check_redis(),
    'mysql' => health_check_mysql(),
    'filesystem' => health_check_fs(),
	'config_constants' => health_check_constants_defined($onlyMissing)
];

$status = health_global_status($checks);
$httpStatus = $status === 'up' ? 200 : ($status === 'degraded' ? 200 : 503);

health_json_response([
    'status' => $status,
    'timestamp' => date('c'),
    'dependencies' => $checks,
    'metrics' => health_load_metrics(),
], $httpStatus);
