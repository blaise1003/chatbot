<?php
// ============================================================
// widget.php — Endpoint di distribuzione widget
// Accetta GET ?token=<CHATBOT_WIDGET_TOKEN> e restituisce
// il contenuto di widget/widget.html con gli header corretti.
// ============================================================

function load_chatbot_config()
{
    $configPath = dirname(__FILE__) . '/chatbot_config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
        return;
    }
    http_response_code(503);
    exit;
}

load_chatbot_config();

function get_widget_token_secret()
{
    if (defined('CHATBOT_WIDGET_DYNAMIC_SECRET') && trim((string) CHATBOT_WIDGET_DYNAMIC_SECRET) !== '') {
        return trim((string) CHATBOT_WIDGET_DYNAMIC_SECRET);
    }

    if (defined('CHATBOT_WIDGET_TOKEN') && trim((string) CHATBOT_WIDGET_TOKEN) !== '') {
        return trim((string) CHATBOT_WIDGET_TOKEN);
    }

    return '';
}

function is_valid_dynamic_widget_token($token, $secret)
{
    $token = trim((string) $token);
    $secret = trim((string) $secret);
    if ($token === '' || $secret === '') {
        return false;
    }

    $prefix = defined('CHATBOT_WIDGET_TOKEN_PREFIX') ? trim((string) CHATBOT_WIDGET_TOKEN_PREFIX) : 'pfw1';
    if ($prefix === '') {
        $prefix = 'pfw1';
    }

    $parts = explode('.', $token);
    if (count($parts) !== 4) {
        return false;
    }

    if ($parts[0] !== $prefix) {
        return false;
    }

    $expiresAt = $parts[1];
    $nonce = $parts[2];
    $signature = $parts[3];
    if (!ctype_digit($expiresAt)) {
        return false;
    }

    if (!preg_match('/^[a-f0-9]{16,128}$/', $nonce)) {
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $signature)) {
        return false;
    }

    $now = time();
    $exp = (int) $expiresAt;
    if ($exp < $now) {
        return false;
    }

    $maxFutureSkew = defined('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS')
        ? max(60, (int) CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS)
        : 86400;
    if ($exp > ($now + $maxFutureSkew)) {
        return false;
    }

    $payload = $expiresAt . '.' . $nonce;
    $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

    return hash_equals($expectedSignature, $signature);
}

function is_valid_widget_token($token)
{
    $token = trim((string) $token);
    if ($token === '') {
        return false;
    }

    $allowStaticFallback = defined('CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK')
        ? (bool) CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK
        : true;

    if ($allowStaticFallback && defined('CHATBOT_WIDGET_TOKEN')) {
        $staticToken = trim((string) CHATBOT_WIDGET_TOKEN);
        if ($staticToken !== '' && hash_equals($staticToken, $token)) {
            return true;
        }
    }

    $secret = get_widget_token_secret();
    return is_valid_dynamic_widget_token($token, $secret);
}

// Controllo Origin
$origin = $_SERVER['HTTP_REFERER'] ?? '';
$origin_scheme = parse_url($origin, PHP_URL_SCHEME) ?? '';
$origin_host = parse_url($origin, PHP_URL_HOST) ?? '';
$origin = $origin_scheme . '://' . $origin_host;
$origin = rtrim($origin, '/'); // Rimuove trailing slash per confronto più flessibile
$allowedOrigins = defined('CHATBOT_ALLOWED_ORIGINS') ? CHATBOT_ALLOWED_ORIGINS : [];
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
	echo "Origin non consentito: $origin\n";
    http_response_code(403);
    exit;
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

// Solo richieste GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Validazione token (hash_equals previene timing attacks)
$token = (string) ($_GET['token'] ?? '');
if (!is_valid_widget_token($token)) {
    http_response_code(403);
    exit;
}

$externalSessionId = $_GET['customerSessionId'] ?? '';
$customerId = $_GET['v3'] ?? '';

define('EXT_SESSION_ID', $externalSessionId);
define('CUSTOMER_ID', $customerId);

// Serve il file HTML
$htmlFile = __DIR__ . '/widget/widget.html';
if (!file_exists($htmlFile)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$htmlFile = str_replace('<EXT_SESSION_ID>', EXT_SESSION_ID, file_get_contents($htmlFile));
$htmlFile = str_replace('<CHATBOT_WIDGET_TOKEN>', $token, $htmlFile);
$htmlFile = str_replace('<CUSTOMER_ID>', CUSTOMER_ID, $htmlFile);
echo $htmlFile;
