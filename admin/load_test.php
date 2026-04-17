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

if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
    header('Location: dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['dashboard_csrf']) || !is_string($_SESSION['dashboard_csrf'])) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['dashboard_csrf'] = bin2hex(openssl_random_pseudo_bytes(24));
        } else {
            $_SESSION['dashboard_csrf'] = hash('sha256', session_id() . ':' . (string) time());
        }
    }

    return $_SESSION['dashboard_csrf'];
}

function verify_csrf_or_die(): void
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $valid = isset($_SESSION['dashboard_csrf'])
        && is_string($_SESSION['dashboard_csrf'])
        && hash_equals($_SESSION['dashboard_csrf'], $token);

    if (!$valid) {
        http_response_code(403);
        echo 'CSRF token non valido.';
        exit;
    }
}

function default_chat_api_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/load_test.php'))), '/');
    $base = preg_replace('~/admin$~', '', $scriptDir);
    if (!is_string($base) || $base === '') {
        $base = '/';
    }

    return $scheme . '://' . $host . rtrim($base, '/') . '/chat_api.php';
}

function post_int(string $key, int $default, int $min, int $max): int
{
    $value = isset($_POST[$key]) ? (int) $_POST[$key] : $default;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function limit_value(string $name, int $fallback): int
{
    if (defined($name)) {
        return max(1, (int) constant($name));
    }

    return $fallback;
}

function build_presets(): array
{
    return [
        'smoke' => [
            'label' => 'Smoke',
            'requests' => 10,
            'concurrency' => 2,
            'timeoutMs' => 12000,
            'scenario' => 'ingress_only',
            'testMode' => true,
            'message' => 'Smoke test rapido.',
        ],
        'stress' => [
            'label' => 'Stress',
            'requests' => 120,
            'concurrency' => 25,
            'timeoutMs' => 20000,
            'scenario' => 'ingress_only',
            'testMode' => true,
            'message' => 'Stress test rate limit ingresso.',
        ],
        'spike' => [
            'label' => 'Spike',
            'requests' => 80,
            'concurrency' => 80,
            'timeoutMs' => 20000,
            'scenario' => 'ingress_only',
            'testMode' => true,
            'message' => 'Spike test simultaneo.',
        ],
        'soak' => [
            'label' => 'Soak breve',
            'requests' => 240,
            'concurrency' => 8,
            'timeoutMs' => 25000,
            'scenario' => 'full_chat',
            'testMode' => true,
            'message' => 'Soak test breve chatbot.',
        ],
        'custom' => [
            'label' => 'Custom',
            'requests' => 40,
            'concurrency' => 10,
            'timeoutMs' => 15000,
            'scenario' => 'full_chat',
            'testMode' => false,
            'message' => 'Ciao, questo e un test di carico.',
        ],
    ];
}

function expected_allowed_requests(float $elapsedSeconds, int $maxRequests, int $windowSeconds): int
{
    $windows = max(1, (int) ceil($elapsedSeconds / max(1, $windowSeconds)));
    return $windows * max(1, $maxRequests);
}

function evaluate_thresholds(array $result, array $cfg, array $limits): array
{
    $elapsed = (float) $result['elapsedSeconds'];
    $sent = (int) $cfg['requests'];
    $observed429 = ((int) $result['rateLimit429']) > 0;
    $checks = [];

    $allowedIp = expected_allowed_requests($elapsed, $limits['ipMax'], $limits['ipWindow']);
    $expectedIp429 = $sent > $allowedIp;
    $checks[] = [
        'name' => 'Per-IP ingress',
        'expected429' => $expectedIp429,
        'observed429' => $observed429,
        'status' => $expectedIp429 === $observed429 ? 'PASS' : ($expectedIp429 ? 'FAIL' : 'WARN'),
        'detail' => 'Inviate ' . $sent . ', budget stimato ' . $allowedIp . ' nella durata test.',
    ];

    $allowedGlobal = expected_allowed_requests($elapsed, $limits['globalMax'], $limits['globalWindow']);
    $expectedGlobal429 = $sent > $allowedGlobal;
    $checks[] = [
        'name' => 'Globale ingress',
        'expected429' => $expectedGlobal429,
        'observed429' => $observed429,
        'status' => $expectedGlobal429 === $observed429 ? 'PASS' : ($expectedGlobal429 ? 'FAIL' : 'WARN'),
        'detail' => 'Inviate ' . $sent . ', budget globale stimato ' . $allowedGlobal . '.',
    ];

    if ((string) $cfg['scenario'] === 'full_chat') {
        $allowedClaude = expected_allowed_requests($elapsed, $limits['claudeMax'], $limits['claudeWindow']);
        $expectedClaudePressure = $sent > $allowedClaude;
        $observedProviderHint = ((int) $result['providerLimitHints']) > 0;
        $checks[] = [
            'name' => 'Provider Claude (hint messaggio)',
            'expected429' => $expectedClaudePressure,
            'observed429' => $observedProviderHint,
            'status' => $expectedClaudePressure === $observedProviderHint ? 'PASS' : ($expectedClaudePressure ? 'WARN' : 'WARN'),
            'detail' => 'Nello scenario full_chat il limite provider puo emergere come messaggio user-friendly senza HTTP 429.',
        ];
    }

    return $checks;
}

function build_overall_assessment(array $result): array
{
    $checks = isset($result['thresholdChecks']) && is_array($result['thresholdChecks'])
        ? $result['thresholdChecks']
        : [];

    $hasFail = false;
    $hasWarn = false;
    $failCount = 0;
    $warnCount = 0;
    foreach ($checks as $check) {
        $status = strtoupper((string) ($check['status'] ?? ''));
        if ($status === 'FAIL') {
            $hasFail = true;
            $failCount++;
        } elseif ($status === 'WARN') {
            $hasWarn = true;
            $warnCount++;
        }
    }

    $networkErrors = (int) ($result['networkErrors'] ?? 0);
    $rateLimit429 = (int) ($result['rateLimit429'] ?? 0);

    $http5xx = 0;
    if (isset($result['statusCounts']) && is_array($result['statusCounts'])) {
        foreach ($result['statusCounts'] as $code => $count) {
            $httpCode = (int) $code;
            if ($httpCode >= 500) {
                $http5xx += (int) $count;
            }
        }
    }

    $score = 100;
    $score -= $failCount * 25;
    $score -= $warnCount * 12;
    $score -= min(35, $networkErrors * 5);
    $score -= min(40, $http5xx * 4);
    $score -= min(20, $rateLimit429 * 2);
    $score = max(0, min(100, $score));

    if ($networkErrors > 0 || $http5xx > 0 || $hasFail) {
        return [
            'status' => 'FAIL',
            'title' => 'Criticita rilevate',
            'detail' => 'Sono presenti errori di rete/5xx o mismatch sulle soglie. Verifica configurazione e limiti prima di procedere.',
            'score' => $score,
        ];
    }

    if ($hasWarn || $rateLimit429 > 0) {
        return [
            'status' => 'WARN',
            'title' => 'Risultato da attenzionare',
            'detail' => 'Il sistema risponde ma ci sono segnali da monitorare (WARN o 429). Valuta tuning di concorrenza, timeout e rate limit.',
            'score' => $score,
        ];
    }

    return [
        'status' => 'PASS',
        'title' => 'Test superato',
        'detail' => 'Nessuna anomalia critica rilevata. Latenza, errori e soglie risultano coerenti con le attese.',
        'score' => $score,
    ];
}

function random_session_id(int $index): string
{
    $seed = bin2hex(random_bytes(6));
    return 'lt_' . $seed . '_' . $index;
}

function run_load_test(array $cfg): array
{
    $targetUrl = $cfg['targetUrl'];
    $token = $cfg['token'];
    $message = $cfg['message'];
    $requests = $cfg['requests'];
    $concurrency = $cfg['concurrency'];
    $timeoutMs = $cfg['timeoutMs'];
    $scenario = $cfg['scenario'];
    $testMode = $cfg['testMode'];

    $statusCounts = [];
    $durationsMs = [];
    $failures = [];
    $rateLimit429 = 0;
    $providerLimitHints = 0;
    $networkErrors = 0;
    $startedAt = microtime(true);

    $requestIndex = 0;
    while ($requestIndex < $requests) {
        $mh = curl_multi_init();
        $handles = [];

        $batchSize = min($concurrency, $requests - $requestIndex);
        for ($i = 0; $i < $batchSize; $i++) {
            $idx = $requestIndex + $i;
            $payload = [
                'session_id' => random_session_id($idx),
                'message' => $message,
            ];

            if ($scenario === 'ingress_only') {
                $payload['message'] = str_repeat('A', 2101);
            }

            if ($testMode) {
                $payload['test_mode'] = true;
            }

            $ch = curl_init($targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'User-Agent: ChatbotAdminLoadTest/1.0',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);

            curl_multi_add_handle($mh, $ch);
            $handles[] = [
                'handle' => $ch,
                'idx' => $idx + 1,
            ];
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && $status === CURLM_OK) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $item) {
            /** @var resource $ch */
            $ch = $item['handle'];
            $idx = (int) $item['idx'];

            $body = (string) curl_multi_getcontent($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latency = ((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000;
            $error = (string) curl_error($ch);

            if (!isset($statusCounts[$http])) {
                $statusCounts[$http] = 0;
            }
            $statusCounts[$http]++;

            $durationsMs[] = $latency;

            if ($http === 429) { // Too Many Requests: rate limit exceeded	
                $rateLimit429++;
            }

            if (stripos($body, 'molte richieste') !== false || stripos($body, 'sovraccarico') !== false) {
                $providerLimitHints++;
            }

            if ($error !== '') {
                $networkErrors++;
            }

            if (($http >= 400 || $error !== '') && count($failures) < 12) {
                $snippet = trim(mb_substr($body, 0, 280));
                $failures[] = [
                    'request' => $idx,
                    'status' => $http,
                    'error' => $error,
                    'body' => $snippet,
                ];
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        $requestIndex += $batchSize;
    }

    sort($durationsMs);
    $endedAt = microtime(true);
    $elapsed = max(0.001, $endedAt - $startedAt);

    $count = count($durationsMs);
    $avg = $count > 0 ? (array_sum($durationsMs) / $count) : 0.0;
    $p95 = $count > 0 ? $durationsMs[(int) floor(($count - 1) * 0.95)] : 0.0;
    $min = $count > 0 ? $durationsMs[0] : 0.0;
    $max = $count > 0 ? $durationsMs[$count - 1] : 0.0;

    ksort($statusCounts);

    return [
        'elapsedSeconds' => $elapsed,
        'throughputRps' => $requests / $elapsed,
        'statusCounts' => $statusCounts,
        'rateLimit429' => $rateLimit429,
        'providerLimitHints' => $providerLimitHints,
        'networkErrors' => $networkErrors,
        'latencyMs' => [
            'min' => $min,
            'avg' => $avg,
            'p95' => $p95,
            'max' => $max,
        ],
        'failures' => $failures,
    ];
}

$presets = build_presets();
$presetId = isset($_REQUEST['preset']) ? trim((string) $_REQUEST['preset']) : 'custom';
if (!isset($presets[$presetId])) {
    $presetId = 'custom';
}
$activePreset = $presets[$presetId];

$targetUrl = isset($_POST['target_url']) ? trim((string) $_POST['target_url']) : default_chat_api_url();
$token = isset($_POST['auth_token']) ? trim((string) $_POST['auth_token']) : (defined('CHATBOT_WIDGET_TOKEN') ? (string) CHATBOT_WIDGET_TOKEN : '');
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : (string) $activePreset['message'];
$scenario = isset($_POST['scenario']) ? trim((string) $_POST['scenario']) : (string) $activePreset['scenario'];
if ($scenario !== 'full_chat' && $scenario !== 'ingress_only') {
    $scenario = 'full_chat';
}

$requests = post_int('requests', (int) $activePreset['requests'], 1, 500);
$concurrency = post_int('concurrency', (int) $activePreset['concurrency'], 1, 100);
$timeoutMs = post_int('timeout_ms', (int) $activePreset['timeoutMs'], 1000, 120000);
$testMode = isset($_POST['test_mode'])
    ? $_POST['test_mode'] === '1'
    : (bool) $activePreset['testMode'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_preset'])) {
    $requests = (int) $activePreset['requests'];
    $concurrency = (int) $activePreset['concurrency'];
    $timeoutMs = (int) $activePreset['timeoutMs'];
    $scenario = (string) $activePreset['scenario'];
    $testMode = (bool) $activePreset['testMode'];
    $message = (string) $activePreset['message'];
}

if ($concurrency > $requests) {
    $concurrency = $requests;
}

$result = null;
$errorMessage = '';
$limits = [
    'ipMax' => limit_value('CHATBOT_RATE_LIMIT_MAX_REQUESTS', 20),
    'ipWindow' => limit_value('CHATBOT_RATE_LIMIT_WINDOW_SECONDS', 300),
    'globalMax' => limit_value('CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS', 3000),
    'globalWindow' => limit_value('CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS', 60),
    'claudeMax' => limit_value('CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS', 1800),
    'claudeWindow' => limit_value('CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS', 60),
    'doofinderMax' => limit_value('CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS', 1200),
    'doofinderWindow' => limit_value('CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS', 60),
];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    $exportType = trim((string) $_GET['export']);
    $snapshot = $_SESSION['load_test_last'] ?? null;
    if (!is_array($snapshot) || !isset($snapshot['result'], $snapshot['config'])) {
        http_response_code(404);
        echo 'Nessun risultato disponibile per export.';
        exit;
    }

    $stamp = preg_replace('/[^0-9]/', '', (string) ($snapshot['timestamp'] ?? date('c')));
    if ($stamp === null || $stamp === '') {
        $stamp = date('YmdHis');
    }

    if ($exportType === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="load-test-' . $stamp . '.json"');
        echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="load-test-' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            echo 'Impossibile creare export CSV.';
            exit;
        }

        fputcsv($out, ['section', 'metric', 'value']);
        foreach (($snapshot['config'] ?? []) as $k => $v) {
            fputcsv($out, ['config', (string) $k, is_scalar($v) ? (string) $v : json_encode($v)]);
        }

        foreach (($snapshot['result'] ?? []) as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $subK => $subV) {
                    if (is_array($subV)) {
                        fputcsv($out, ['result_' . $k, (string) $subK, json_encode($subV, JSON_UNESCAPED_UNICODE)]);
                    } else {
                        fputcsv($out, ['result_' . $k, (string) $subK, (string) $subV]);
                    }
                }
            } else {
                fputcsv($out, ['result', (string) $k, (string) $v]);
            }
        }

        fclose($out);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['run_load_test']) || isset($_POST['execute_preset']))) {
    verify_csrf_or_die();

    if (!function_exists('curl_multi_init')) {
        $errorMessage = 'Estensione cURL multi non disponibile sul server.';
    } elseif ($targetUrl === '' || $token === '') {
        $errorMessage = 'URL API e token sono obbligatori.';
    } else {
        try {
            $result = run_load_test([
                'targetUrl' => $targetUrl,
                'token' => $token,
                'message' => $message,
                'requests' => $requests,
                'concurrency' => $concurrency,
                'timeoutMs' => $timeoutMs,
                'scenario' => $scenario,
                'testMode' => $testMode,
            ]);

            $cfg = [
                'targetUrl' => $targetUrl,
                'requests' => $requests,
                'concurrency' => $concurrency,
                'timeoutMs' => $timeoutMs,
                'scenario' => $scenario,
                'testMode' => $testMode,
                'preset' => $presetId,
            ];
            $result['thresholdChecks'] = evaluate_thresholds($result, $cfg, $limits);
            $result['assessment'] = build_overall_assessment($result);

            $_SESSION['load_test_last'] = [
                'timestamp' => date('c'),
                'config' => $cfg,
                'limits' => $limits,
                'result' => $result,
            ];
        } catch (Throwable $e) {
            $errorMessage = 'Errore durante il load test: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Load Test Chatbot</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/load-test.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('load-test') ?>

    <main class="admin-main">
        <div class="page">
            <?= admin_render_header(
                'load-test',
                'Load Test Chatbot',
                'Simula richieste concorrenti verso chat_api.php per verificare throughput, latenza e rate limit.',
                [
                    ['label' => 'Redis Admin', 'href' => 'redis_admin.php', 'class' => 'secondary'],
                    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
                ]
            ) ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="err"><?= h($errorMessage) ?></div>
            <?php endif; ?>

            <section class="panel">
                <details class="guide-details">
                    <summary>
                        <span class="summary-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false" role="img" aria-hidden="true">
                                <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 4.75a1.25 1.25 0 110 2.5 1.25 1.25 0 010-2.5zm1.5 10.5h-3v-1.5h.75v-4h-1v-1.5h2.5v5.5h.75v1.5z"/>
                            </svg>
                        </span>
                        Guida Test Disponibili, Limiti e Logica (clicca per espandere)
                    </summary>
                    <div class="guide-body">
                        <h3>Test disponibili</h3>
                        <ul>
                            <li><strong>Smoke:</strong> test rapido di verifica funzionale e tempi base.</li>
                            <li><strong>Stress:</strong> volume alto con concorrenza media per validare i rate limit ingresso.</li>
                            <li><strong>Spike:</strong> burst ad alta simultaneita per misurare la tenuta ai picchi improvvisi.</li>
                            <li><strong>Soak breve:</strong> test prolungato a concorrenza moderata per stabilita e degrado nel tempo.</li>
                            <li><strong>Custom:</strong> parametri liberi.</li>
                        </ul>

                        <h3>Limiti configurati correnti</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Bucket</th>
                                    <th>Max richieste</th>
                                    <th>Finestra (s)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Per-IP</td><td><?= (int) $limits['ipMax'] ?></td><td><?= (int) $limits['ipWindow'] ?></td></tr>
                                <tr><td>Globale</td><td><?= (int) $limits['globalMax'] ?></td><td><?= (int) $limits['globalWindow'] ?></td></tr>
                                <tr><td>Claude provider</td><td><?= (int) $limits['claudeMax'] ?></td><td><?= (int) $limits['claudeWindow'] ?></td></tr>
                                <tr><td>Doofinder provider</td><td><?= (int) $limits['doofinderMax'] ?></td><td><?= (int) $limits['doofinderWindow'] ?></td></tr>
                            </tbody>
                        </table>

                        <h3>Logica implementativa del test</h3>
                        <ul>
                            <li>Il modulo usa <code>curl_multi</code> per eseguire batch concorrenti lato server.</li>
                            <li>Ogni richiesta usa un <code>session_id</code> casuale, cosi si stressa il rate limit per IP e globale senza dipendere dalla cronologia chat.</li>
                            <li>Scenario <code>ingress_only</code>: invia messaggio volutamente invalido (>2000 chars) per fermarsi in validazione e misurare principalmente i filtri di ingresso/rate limit.</li>
                            <li>Scenario <code>full_chat</code>: passa dal flusso completo applicativo e puo coinvolgere provider esterni.</li>
                            <li>Il report calcola throughput, latenza (min/avg/p95/max), distribuzione status e campioni errore.</li>
                            <li>La sezione pass/fail confronta l'osservato con un budget stimato: <code>ceil(durata/finestra) * maxRequests</code>.</li>
                        </ul>
                    </div>
                </details>
            </section>

            <section class="panel">
                <h2>Configurazione Test</h2>
                <p class="muted">
                    Usa <strong>Scenario ingress_only</strong> per testare il rate limit di ingresso senza chiamare Claude.
                    Usa <strong>full_chat</strong> per una simulazione end-to-end (puo generare traffico reale verso provider esterni).
                </p>

                <form id="load-test-form" method="post" action="">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="preset" value="<?= h($presetId) ?>">

                    <div class="preset-row">
                        <?php foreach ($presets as $id => $preset): ?>
                            <a class="btn preset-btn <?= $presetId === $id ? 'secondary' : 'gray' ?>" href="?preset=<?= h($id) ?>">
                                <?= h((string) $preset['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-2">
                        <div>
                            <label class="label" for="target_url">URL chat_api.php</label>
                            <input id="target_url" name="target_url" type="text" value="<?= h($targetUrl) ?>" required>
                        </div>
                        <div>
                            <label class="label" for="auth_token">Bearer token</label>
                            <input id="auth_token" name="auth_token" type="text" value="<?= h($token) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-4">
                        <div>
                            <label class="label" for="requests">Numero richieste (# richieste totali)</label>
                            <input id="requests" name="requests" type="number" min="1" max="500" value="<?= (int) $requests ?>">
                        </div>
                        <div>
                            <label class="label" for="concurrency">Concorrenza (# richieste asincrone in parallelo)</label>
                            <input id="concurrency" name="concurrency" type="number" min="1" max="100" value="<?= (int) $concurrency ?>">
                        </div>
                        <div>
                            <label class="label" for="timeout_ms">Timeout per request (ms)</label>
                            <input id="timeout_ms" name="timeout_ms" type="number" min="1000" max="120000" value="<?= (int) $timeoutMs ?>">
                        </div>
                        <div>
                            <label class="label" for="scenario">Scenario</label>
                            <select id="scenario" name="scenario">
                                <option value="full_chat" <?= $scenario === 'full_chat' ? 'selected' : '' ?>>full chat</option>
                                <option value="ingress_only" <?= $scenario === 'ingress_only' ? 'selected' : '' ?>>ingress only</option>
                            </select>
                        </div>
                    </div>

                    <div class="row row-gap">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="test_mode" value="1" <?= $testMode ? 'checked' : '' ?>>
                            Invia test_mode=true nel payload
                        </label>
                    </div>

                    <div>
                        <label class="label" for="message">Messaggio (usato nello scenario "full chat")</label>
                        <textarea id="message" name="message" rows="3"><?= h($message) ?></textarea>
                    </div>

                    <div class="row">
                        <button class="btn" type="submit" name="run_load_test" value="1">Avvia Load Test</button>
                        <button class="btn secondary" type="submit" name="execute_preset" value="1">Applica preset</button>
                    </div>
                </form>
            </section>

            <?php if (is_array($result)): ?>
                <section class="grid metrics-grid">
                    <article class="card">
                        <h3>Durata totale</h3>
                        <div class="kpi"><?= h(number_format((float) $result['elapsedSeconds'], 2, ',', '.')) ?> s</div>
                    </article>
                    <article class="card">
                        <h3>Throughput</h3>
                        <div class="kpi"><?= h(number_format((float) $result['throughputRps'], 2, ',', '.')) ?> req/s</div>
                    </article>
                    <article class="card">
                        <h3>HTTP 429</h3>
                        <div class="kpi"><?= (int) $result['rateLimit429'] ?></div>
                    </article>
                    <article class="card">
                        <h3>Hint provider limit (claude limit exceeded)</h3>
                        <div class="kpi"><?= (int) $result['providerLimitHints'] ?></div>
                    </article>
                    <article class="card">
                        <h3>Errori rete</h3>
                        <div class="kpi"><?= (int) $result['networkErrors'] ?></div>
                    </article>
                </section>

                <section class="panel">
                    <h2>Export risultato</h2>
                    <div class="row">
                        <a class="btn secondary" href="?export=json">Scarica JSON</a>
                        <a class="btn gray" href="?export=csv">Scarica CSV</a>
                    </div>
                </section>

                <?php if (isset($result['assessment']) && is_array($result['assessment'])): ?>
                    <?php
                    $assessmentStatus = strtoupper((string) ($result['assessment']['status'] ?? 'WARN'));
                    $assessmentClass = 'status-warn';
                    if ($assessmentStatus === 'PASS') {
                        $assessmentClass = 'status-pass';
                    } elseif ($assessmentStatus === 'FAIL') {
                        $assessmentClass = 'status-fail';
                    }
                    ?>
                    <section class="panel">
                        <h2>Valutazione complessiva</h2>
                        <p>
                            <span class="status-pill <?= h($assessmentClass) ?>"><?= h($assessmentStatus) ?></span>
                            <strong><?= h((string) ($result['assessment']['title'] ?? 'Valutazione')) ?></strong>
                        </p>
                        <p><strong>Score:</strong> <?= (int) ($result['assessment']['score'] ?? 0) ?>/100</p>
                        <p class="muted"><?= h((string) ($result['assessment']['detail'] ?? '')) ?></p>
                    </section>

                    <section class="panel">
                        <h2>Legenda risultati</h2>
                        <table>
                            <thead>
                            <tr>
                                <th>Indicatore</th>
                                <th>Interpretazione</th>
                                <th>Esito</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td><span class="status-pill status-pass">PASS</span></td>
                                <td>Soglie rispettate e nessuna anomalia critica.</td>
                                <td>Positivo</td>
                            </tr>
                            <tr>
                                <td><span class="status-pill status-warn">WARN</span></td>
                                <td>Risultato utilizzabile ma con segnali da monitorare (es. 429 o mismatch non bloccanti).</td>
                                <td>Intermedio</td>
                            </tr>
                            <tr>
                                <td><span class="status-pill status-fail">FAIL</span></td>
                                <td>Errori critici (rete, HTTP 5xx o fallimento soglie principali).</td>
                                <td>Negativo</td>
                            </tr>
                            <tr>
                                <td><strong>Score 80-100</strong></td>
                                <td>Stato stabile, comportamento coerente con aspettative.</td>
                                <td>Positivo</td>
                            </tr>
                            <tr>
                                <td><strong>Score 50-79</strong></td>
                                <td>Da ottimizzare: possibile pressione su limiti o latenza in crescita.</td>
                                <td>Intermedio</td>
                            </tr>
                            <tr>
                                <td><strong>Score 0-49</strong></td>
                                <td>Rischio elevato: performance o affidabilita insufficienti.</td>
                                <td>Negativo</td>
                            </tr>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <?php if (!empty($result['thresholdChecks'])): ?>
                    <section class="panel">
                        <h2>Verifica Soglie (Pass/Fail)</h2>
                        <table>
                            <thead>
                            <tr>
                                <th>Controllo</th>
                                <th>Atteso</th>
                                <th>Osservato</th>
                                <th>Esito</th>
                                <th>Dettaglio</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($result['thresholdChecks'] as $check): ?>
                                <?php
                                $status = (string) $check['status'];
                                $badgeClass = 'status-warn';
                                if ($status === 'PASS') {
                                    $badgeClass = 'status-pass';
                                } elseif ($status === 'FAIL') {
                                    $badgeClass = 'status-fail';
                                }
                                ?>
                                <tr>
                                    <td><?= h((string) $check['name']) ?></td>
                                    <td><?= !empty($check['expected429']) ? '429 atteso' : '429 non atteso' ?></td>
                                    <td><?= !empty($check['observed429']) ? '429/hint rilevato' : 'nessun segnale' ?></td>
                                    <td><span class="status-pill <?= h($badgeClass) ?>"><?= h($status) ?></span></td>
                                    <td><?= h((string) $check['detail']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>

                <section class="panel">
                    <h2>Latenza (ms)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Min</th>
                                <th>Avg</th>
                                <th>P95</th>
                                <th>Max</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?= h(number_format((float) $result['latencyMs']['min'], 2, ',', '.')) ?></td>
                                <td><?= h(number_format((float) $result['latencyMs']['avg'], 2, ',', '.')) ?></td>
                                <td><?= h(number_format((float) $result['latencyMs']['p95'], 2, ',', '.')) ?></td>
                                <td><?= h(number_format((float) $result['latencyMs']['max'], 2, ',', '.')) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="panel">
                    <h2>Distribuzione status HTTP</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Conteggio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['statusCounts'] as $status => $count): ?>
                                <tr>
                                    <td><span class="pill"><?= (int) $status ?></span></td>
                                    <td><?= (int) $count ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <?php if (!empty($result['failures'])): ?>
                    <section class="panel">
                        <h2>Campioni errori (max 12)</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Status</th>
                                    <th>Errore cURL</th>
                                    <th>Snippet body</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($result['failures'] as $failure): ?>
                                    <tr>
                                        <td><?= (int) $failure['request'] ?></td>
                                        <td><?= (int) $failure['status'] ?></td>
                                        <td><?= h((string) $failure['error']) ?></td>
                                        <td><pre><?= h((string) $failure['body']) ?></pre></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<div id="action-loader" class="action-loader" aria-hidden="true">
    <div class="action-loader__box" role="status" aria-live="polite" aria-label="Operazione in corso">
        <span class="action-loader__spinner" aria-hidden="true"></span>
        <span class="action-loader__text">Operazione in corso...</span>
    </div>
</div>
<script>
(() => {
    const form = document.getElementById('load-test-form');
    const loader = document.getElementById('action-loader');
    if (!form || !loader) {
        return;
    }

    let lastActionButton = null;
    form.querySelectorAll('button[type="submit"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            lastActionButton = btn;
        });
    });

    form.addEventListener('submit', (event) => {
        const submitter = event.submitter || lastActionButton;
        if (!submitter) {
            return;
        }

        const actionName = submitter.getAttribute('name');
        if (actionName !== 'run_load_test' && actionName !== 'execute_preset') {
            return;
        }

        loader.classList.add('is-active');
        loader.setAttribute('aria-hidden', 'false');
    });
})();
</script>
</body>
</html>
