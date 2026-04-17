<?php

//declare(strict_types=1);

session_start();

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

require_once __DIR__ . '/views/_admin_menu.php';
require_once __DIR__ . '/views/_admin_header.php';

$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;
if (!$isAuthenticated) {
    header('Location: dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function check_redis_runtime(): array
{
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

function check_mysql_runtime(): array
{
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
        return ['status' => ($stmt !== false ? 'up' : 'down'), 'detail' => ($stmt !== false ? 'OK' : 'Query fallita')];
    } catch (Throwable $e) {
        return ['status' => 'down', 'detail' => 'Errore MySQL: ' . $e->getMessage()];
    }
}

function check_fs_runtime(): array
{
    $sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : '';
    $logsDir = defined('CHATBOT_LOGS_DIR') ? (string) CHATBOT_LOGS_DIR : '';

    $sessionWritable = ($sessionDir !== '' && is_dir($sessionDir) && is_writable($sessionDir));
    $logsWritable = ($logsDir !== '' && is_dir($logsDir) && is_writable($logsDir));

    return [
        'status' => ($sessionWritable && $logsWritable) ? 'up' : 'degraded',
        'detail' => 'session writable=' . ($sessionWritable ? 'yes' : 'no') . ', logs writable=' . ($logsWritable ? 'yes' : 'no'),
    ];
}

function load_runtime_metrics(): array
{
    $logDir = defined('CHATBOT_LOGS_DIR') ? rtrim((string) CHATBOT_LOGS_DIR, '/\\') : dirname(__DIR__) . '/chatbot_logs';
    $path = $logDir . '/metrics-' . date('Y-m-d') . '.json';

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

function global_runtime_status(array $checks): string
{
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

$checks = [
    'php' => [
        'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'up' : 'down',
        'detail' => 'Versione ' . PHP_VERSION,
    ],
    'redis' => check_redis_runtime(),
    'mysql' => check_mysql_runtime(),
    'filesystem' => check_fs_runtime(),
];

$metrics = load_runtime_metrics();
$globalStatus = global_runtime_status($checks);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Runtime Health</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('runtime-health') ?>

    <main class="admin-main">
        <?= admin_render_header(
            'runtime-health',
            'Runtime Health',
            'Stato dipendenze e metriche applicative in tempo reale.',
            [
                ['label' => 'Aggiorna', 'href' => 'runtime_health.php', 'class' => 'secondary'],
            ]
        ) ?>

        <section class="panel">
            <h3>Stato Globale: <?= h(strtoupper($globalStatus)) ?></h3>
            <p class="muted">Timestamp: <?= h(date('Y-m-d H:i:s')) ?></p>
        </section>

        <section class="panel">
            <h3>Dipendenze</h3>
            <table>
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th>Stato</th>
                        <th>Dettaglio</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $name => $check): ?>
                    <tr>
                        <td><?= h($name) ?></td>
                        <td><?= h(strtoupper((string) ($check['status'] ?? 'down'))) ?></td>
                        <td><?= h((string) ($check['detail'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h3>Metriche Giornaliere</h3>
            <table>
                <thead>
                    <tr>
                        <th>Metrica</th>
                        <th>Valore</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($metrics as $metric => $value): ?>
                    <tr>
                        <td><?= h((string) $metric) ?></td>
                        <td><?= h((string) $value) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>
</body>
</html>
