<?php

namespace Chatbot;

class RequestGuard
{
    public function isHttpsRequest()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    public function getRequestOrigin()
    {
        return isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
    }

    public function getAllowedOrigins()
    {
        $origins = [];

        if (is_array(\CHATBOT_ALLOWED_ORIGINS)) {
            $origins = \CHATBOT_ALLOWED_ORIGINS;
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = $this->isHttpsRequest() ? 'https' : 'http';
            $origins[] = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        $origins[] = 'http://localhost';
        $origins[] = 'http://127.0.0.1';
        $origins[] = 'https://localhost';
        $origins[] = 'https://127.0.0.1';

        return array_values(array_unique(array_filter($origins)));
    }

    public function applyCorsPolicy()
    {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        $origin = $this->getRequestOrigin();
        if ($origin === '') {
            return;
        }

        if (in_array($origin, $this->getAllowedOrigins(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            return;
        }

        \json_response([
            'reply' => 'Origine non autorizzata.',
            'options' => []
        ], 403);
    }

    public function enforceRequestMethod()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            \json_response([
                'reply' => 'Metodo non consentito.',
                'options' => []
            ], 405);
        }
    }

    public function applySecurityHeaders()
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    public function getClientIp()
    {
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return 'unknown';
    }

    public function isTestModeAllowed()
    {
        if (\CHATBOT_ALLOW_TEST_MODE) {
            return true;
        }

        return in_array($this->getClientIp(), ['127.0.0.1', '::1'], true);
    }

    public function enforceBearerToken()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return;
        }

        $authHeader = $this->getAuthorizationHeader();
        if (stripos($authHeader, 'Bearer ') !== 0) {
            \json_response([
                'reply' => 'Autenticazione mancante.' . json_encode($authHeader),
                'options' => []
            ], 401);
        }

        $providedToken = trim(substr($authHeader, 7));
        if (!$this->isValidWidgetAuthToken($providedToken)) {
            \json_response([
                'reply' => 'Token di autenticazione non valido.',
                'options' => []
            ], 402);
        }
    }

    private function getAuthorizationHeader()
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['HTTP_AUTHORIZATION']);
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        return '';
    }

    private function isValidWidgetAuthToken($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        $allowStaticFallback = true;
        if (\defined('CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK')) {
            $allowStaticFallback = (bool) \CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK;
        }

        $staticToken = '';
        if (\defined('CHATBOT_WIDGET_TOKEN') && \is_string(\CHATBOT_WIDGET_TOKEN)) {
            $staticToken = trim((string) \CHATBOT_WIDGET_TOKEN);
        }

        if ($allowStaticFallback && $staticToken !== '' && hash_equals($staticToken, $token)) {
            return true;
        }

        $secret = $this->getDynamicTokenSecret($staticToken);
        if ($secret === '') {
            return false;
        }

        return $this->isValidDynamicToken($token, $secret);
    }

    private function getDynamicTokenSecret($fallbackSecret)
    {
        if (\defined('CHATBOT_WIDGET_DYNAMIC_SECRET') && \is_string(\CHATBOT_WIDGET_DYNAMIC_SECRET)) {
            $dynamicSecret = trim((string) \CHATBOT_WIDGET_DYNAMIC_SECRET);
            if ($dynamicSecret !== '') {
                return $dynamicSecret;
            }
        }

        return trim((string) $fallbackSecret);
    }

    private function isValidDynamicToken($token, $secret)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4) {
            return false;
        }

        $prefix = \defined('CHATBOT_WIDGET_TOKEN_PREFIX') ? trim((string) \CHATBOT_WIDGET_TOKEN_PREFIX) : 'pfw1';
        if ($prefix === '') {
            $prefix = 'pfw1';
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

        $maxFutureSkew = \defined('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS')
            ? max(60, (int) \CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS)
            : 86400;
        if ($exp > ($now + $maxFutureSkew)) {
            return false;
        }

        $payload = $expiresAt . '.' . $nonce;
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

        return hash_equals($expectedSignature, $signature);
    }
}
