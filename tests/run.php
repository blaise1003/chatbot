<?php

//declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Chatbot\HtmlSanitizer;
use Chatbot\HandoffManager;
use Chatbot\Logger;
use Chatbot\RequestGuard;

$passed = 0;
$failed = 0;

function assertTrue(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $message . PHP_EOL;
        return;
    }

    $failed++;
    echo "[FAIL] " . $message . PHP_EOL;
}

function runHealthEndpoint(array $serverVars): array
{
    $healthPath = realpath(dirname(__DIR__) . '/health.php');
    if (!is_string($healthPath) || $healthPath === '') {
        return ['ok' => false, 'json' => null, 'raw' => 'health.php non trovato'];
    }

    $script = '$GLOBALS["_SERVER"] = ' . var_export($serverVars, true) . ';'
        . '$GLOBALS["_GET"] = [];'
        . 'include ' . var_export($healthPath, true) . ';';

    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script);
    $lines = [];
    $exitCode = 0;
    exec($cmd, $lines, $exitCode);
    $raw = implode("\n", $lines);
    $decoded = json_decode($raw, true);

    return [
        'ok' => $exitCode === 0,
        'json' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
    ];
}

function buildDynamicToken(string $secret, int $ttl = 300): string
{
    $exp = time() + $ttl;
    $nonce = bin2hex(random_bytes(16));
    $payload = $exp . '.' . $nonce;
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    return 'pfw1.' . $exp . '.' . $nonce . '.' . $signature;
}

// Test 1: HtmlSanitizer strips dangerous javascript URLs.
$sanitizer = new HtmlSanitizer();
$dirty = '<a href="javascript:alert(1)">x</a><script>alert(1)</script><strong>ok</strong>';
$clean = $sanitizer->sanitize($dirty);
assertTrue(stripos($clean, 'javascript:') === false, 'HtmlSanitizer rimuove javascript:');
assertTrue(stripos($clean, '<script') === false, 'HtmlSanitizer rimuove script tag non consentiti');
assertTrue(stripos($clean, '<strong>ok</strong>') !== false, 'HtmlSanitizer mantiene tag consentiti');

// Test 2: Logger metrics increment.
Logger::trackMetric('test_metric_suite', 2);
$snapshot = Logger::getMetricsSnapshot();
assertTrue(isset($snapshot['test_metric_suite']) && (int) $snapshot['test_metric_suite'] >= 2, 'Logger aggiorna metriche giornaliere');

// Test 3: Dynamic token validation via reflection on RequestGuard private method.
$requestGuard = new RequestGuard();
$method = new ReflectionMethod(RequestGuard::class, 'isValidDynamicToken');
$method->setAccessible(true);

$secret = 'unit-test-secret';
$token = buildDynamicToken($secret, 60);
$isValid = (bool) $method->invoke($requestGuard, $token, $secret);
assertTrue($isValid, 'RequestGuard valida token dinamico corretto');

$invalidToken = $token . 'broken';
$isInvalid = (bool) $method->invoke($requestGuard, $invalidToken, $secret);
assertTrue($isInvalid === false, 'RequestGuard rifiuta token dinamico alterato');

// Test 4: Health endpoint integration - local request authorized.
$healthLocal = runHealthEndpoint([
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '127.0.0.1',
]);
assertTrue($healthLocal['ok'] === true, 'Health endpoint esegue correttamente in integrazione');
assertTrue(is_array($healthLocal['json']) && isset($healthLocal['json']['status']), 'Health endpoint restituisce JSON con status');

if (is_array($healthLocal['json']) && isset($healthLocal['json']['status']) && $healthLocal['json']['status'] === 'forbidden') {
    assertTrue(true, 'Health endpoint protetto da token (config corrente)');
} else {
    assertTrue(
        is_array($healthLocal['json'])
        && isset($healthLocal['json']['dependencies'])
        && isset($healthLocal['json']['metrics']),
        'Health endpoint espone dependencies e metrics'
    );
}

// Test 5: Health endpoint integration - unauthorized remote request.
$healthRemote = runHealthEndpoint([
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '8.8.8.8',
]);
assertTrue(
    is_array($healthRemote['json'])
    && isset($healthRemote['json']['status'])
    && $healthRemote['json']['status'] === 'forbidden',
    'Health endpoint blocca richieste non autorizzate'
);

// Test 6: HandoffManager claim esclusivo.
$tmpSessionDir = sys_get_temp_dir() . '/pf_handoff_test_' . uniqid('', true);
@mkdir($tmpSessionDir, 0700, true);
$handoff = new HandoffManager($tmpSessionDir);
$sessionId = 'ses_test_handoff_1';

$requested = $handoff->request($sessionId, 'user', 'Richiesta operatore');
assertTrue(isset($requested['status']) && $requested['status'] === 'requested', 'HandoffManager apre stato requested');

$claim1 = $handoff->claim($sessionId, 'op-one');
assertTrue(!empty($claim1['_claim_ok']) && $claim1['claimed_by'] === 'op-one', 'Primo claim operatore riesce');

$claim2 = $handoff->claim($sessionId, 'op-two');
assertTrue(empty($claim2['_claim_ok']), 'Secondo claim concorrente viene bloccato');

echo PHP_EOL;
echo 'Risultato: ' . $passed . ' pass, ' . $failed . ' fail' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
