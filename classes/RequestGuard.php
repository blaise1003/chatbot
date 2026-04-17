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

        // $origins[] = 'http://localhost';
        // $origins[] = 'http://127.0.0.1';
        // $origins[] = 'https://localhost';
        // $origins[] = 'https://127.0.0.1';

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
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
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
			//Logger::LogDebug("enforceBearerToken", "Richiesta OPTIONS ricevuta, bypass controllo token."); // DEBUG LOG
            return;
        }

        $authHeader = $this->getAuthorizationHeader();
        if (stripos($authHeader, 'Bearer ') !== 0) {
			Logger::logError("enforceBearerToken", "Header Authorization mancante o non in formato Bearer"); // DEBUG LOG
            \json_response([
                'reply' => 'Autenticazione mancante.',
                'options' => []
            ], 401);
        }

        $providedToken = trim(substr($authHeader, 7));
        if (!$this->isValidWidgetAuthToken($providedToken)) {
			Logger::logDebug("enforceBearerToken", "Token di autenticazione non valido"); // DEBUG LOG
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
			Logger::logError("isValidWidgetAuthToken", "Token di autenticazione mancante."); // DEBUG LOG
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
			Logger::logError("isValidWidgetAuthToken", "Token di autenticazione valido (fallback statico)."); // DEBUG LOG
            return true;
        }

        $secret = $this->getDynamicTokenSecret($staticToken);
        if ($secret === '') {
			Logger::logError("isValidWidgetAuthToken", "Secret per token dinamico non configurato. Impossibile validare token dinamico."); // DEBUG LOG
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

		Logger::logError("getDynamicTokenSecret", "Secret per token dinamico non trovato, utilizzo fallback: " . json_encode($fallbackSecret)); // DEBUG LOG
        return trim((string) $fallbackSecret);
    }

    private function isValidDynamicToken($token, $secret)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 5) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico malformato, attese 4 parti separate da '.', trovate: " . count($parts)); // DEBUG LOG
            return false;
        }

        $prefix = \defined('CHATBOT_WIDGET_TOKEN_PREFIX') ? trim((string) \CHATBOT_WIDGET_TOKEN_PREFIX) : 'pfw1';
        if ($prefix === '') {
            $prefix = 'pfw1';
        }

        if ($parts[0] !== $prefix) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con prefisso non valido. Atteso: " . $prefix . ", trovato: " . $parts[0]); // DEBUG LOG
            return false;
        }

        $expiresAt = $parts[1];
        $nonce = $parts[2];
		$tokenDomain = $parts[3];
        $signature = $parts[4];

        if (!ctype_digit($expiresAt)) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con campo expiresAt non valido, atteso timestamp numerico, trovato: " . json_encode($expiresAt)); // DEBUG LOG
            return false;
        }
        
		if (!preg_match('/^[a-f0-9]{16,128}$/', $nonce)) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con campo nonce non valido, atteso stringa esadecimale 16-128 caratteri, trovato: " . json_encode($nonce)); // DEBUG LOG
            return false;
        }

		$allowedOrigins = defined('CHATBOT_ALLOWED_ORIGINS') ? CHATBOT_ALLOWED_ORIGINS : [];
		$tokenDomainValid = false;
		foreach ($allowedOrigins as $origin) {
			if (hash_equals(hash('sha256', (string) $origin), $tokenDomain)) {
				$tokenDomainValid = true;
				break;
			}
		}
		if (!$tokenDomainValid) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con campo domain non valido o non autorizzato. Trovato: " . json_encode($tokenDomain) . ", allowed: " . json_encode($allowedOrigins)); // DEBUG LOG
            return false;
		}

        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $signature)) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con campo signature non valido, atteso stringa base64url 20-128 caratteri, trovato: " . json_encode($signature)); // DEBUG LOG
            return false;
        }

        $now = time();
        $exp = (int) $expiresAt;
        if ($exp < $now) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico scaduto. Exp: " . $exp . ", now: " . $now); // DEBUG LOG
            return false;
        }

        $maxFutureSkew = \defined('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS')
            ? max(60, (int) \CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS)
            : 86400;
        if ($exp > ($now + $maxFutureSkew)) {
			Logger::logDebug("isValidDynamicToken", "Token dinamico con exp troppo lontano nel futuro. Exp: " . $exp . ", now: " . $now . ", max allowed: " . ($now + $maxFutureSkew)); // DEBUG LOG
            return false;
        }

        $payload = $expiresAt . '.' . $nonce;
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

        Logger::logDebug("isValidDynamicToken", "Token dinamico con signature attesa: " . json_encode($expectedSignature) . ", trovata: " . json_encode($signature)); // DEBUG LOG

        return hash_equals($expectedSignature, $signature);
    }
}
