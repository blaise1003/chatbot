<?php

namespace Chatbot;

class JsonSecurityValidator
{
    /**
     * Verifica che una stringa non contenga pattern di injection comuni
     */
    public static function containsMaliciousPattern(string $value): bool
    {
        // Patterns SQL injection
        $sqlPatterns = [
            '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|TRUNCATE|UNION|EXEC|EXECUTE)\b/i',
            '/(["\'])\s*(?:OR|AND)\s*(["\']?\w*["\']?)\s*=/i',
            '/--\s*$/m',  // Commenti SQL
            '/;\s*(?:DROP|DELETE|UPDATE|INSERT|SELECT)/i',
        ];

        // Patterns JavaScript injection
        $jsPatterns = [
            '/<script[^>]*>/i',
            '/javascript\s*:/i',
            '/on(load|error|click|change|focus|blur|submit|keydown|keyup)\s*=/i',
            '/{{\s*.*\s*}}/i',  // Template injection
            '/eval\s*\(/i',
        ];

        // Patterns PHP injection
        $phpPatterns = [
            '/<\?\s*(php)?/i',
            '/<\%/i',
        ];

        // Patterns shell command injection
        $shellPatterns = [
            '/;\s*(?:rm|cat|ls|mv|cp|chmod|chown|kill|curl|wget|nc|bash|sh)\b/i',
            '/\|\s*(?:cat|grep|sed|awk|sort)/i',
            '/`[^`]*`/i',  // Backtick command execution
            '/\$\(.*\)/i',  // Command substitution
        ];

        // Path traversal
        $pathPatterns = [
            '/\.\.[\\/]/i',
            '/%2e%2e/i',  // URL encoded
        ];

        $allPatterns = array_merge($sqlPatterns, $jsPatterns, $phpPatterns, $shellPatterns, $pathPatterns);

        foreach ($allPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valida ricorsivamente l'array JSON per rilevare contenuto malevolo
     */
    public static function validateJsonSafety(array $data, array $allowedKeys = []): bool
    {
        foreach ($data as $key => $value) {
            // Salta chiavi note e sicure se specificato un whitelist
            if (!empty($allowedKeys) && !in_array($key, $allowedKeys, true)) {
				Logger::logError("json_security", "Chiave JSON non consentita: " . $key);
                return false;  // Chiave inaspettata
            }

            // Verifica stringhe per pattern malevoli
            if (is_string($value)) {
                if (self::containsMaliciousPattern($value)) {
					Logger::logError("json_security", "Valore JSON sospetto rilevato in chiave: " . $key);
                    return false;
                }
            }
            // Verifica ricorsivamente array annidati
            elseif (is_array($value)) {
                if (!self::validateJsonSafety($value, [])) {
					Logger::logError("json_security", "Array JSON sospetto rilevato in chiave: " . $key);
                    return false;
                }
            }
            // Accetta solo tipi primitivi sicuri
            elseif (!is_int($value) && !is_bool($value) && !is_null($value)) {
                // Float o altri tipi: accetta ma non analizza
                if (!is_float($value)) {
					Logger::logError("json_security", "Tipo di dato JSON non consentito in chiave: " . $key);
                    return false;
                }
            }
        }

        return true;
    }
}
