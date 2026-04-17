<?php

namespace Chatbot;

use PDO;
use Throwable;

class ConfigLoader
{
    private static $dbValues = null;

    public static function bootstrap(array $bootstrapDefaults, string $tableName = 'configuration'): void {
        if (is_array(self::$dbValues)) {
            $GLOBALS['chatbot_db_config_values'] = self::$dbValues;
            return;
        }

        if (isset($GLOBALS['chatbot_db_config_values']) && is_array($GLOBALS['chatbot_db_config_values'])) {
            self::$dbValues = $GLOBALS['chatbot_db_config_values'];
            return;
        }

        $dsn = isset($bootstrapDefaults['CHATBOT_MYSQL_DSN']) ? (string) $bootstrapDefaults['CHATBOT_MYSQL_DSN'] : '';
        $username = isset($bootstrapDefaults['CHATBOT_MYSQL_USER']) ? (string) $bootstrapDefaults['CHATBOT_MYSQL_USER'] : '';
        $password = isset($bootstrapDefaults['CHATBOT_MYSQL_PASSWORD']) ? (string) $bootstrapDefaults['CHATBOT_MYSQL_PASSWORD'] : '';

        self::$dbValues = self::loadDbValues($dsn, $username, $password, $tableName);
        $GLOBALS['chatbot_db_config_values'] = self::$dbValues;
    }

    public static function define(string $name, $defaultValue, string $type = 'string'): void {
        if (defined($name)) {
            return;
        }

        $rawValue = self::value($name, $defaultValue);
        define($name, self::castValue($rawValue, $type, $defaultValue));
    }

    public static function value(string $name, $defaultValue) {
        $values = self::values();
        if (array_key_exists($name, $values)) {
            return $values[$name];
        }

        return $defaultValue;
    }

    public static function values(): array {
        if (is_array(self::$dbValues)) {
            return self::$dbValues;
        }

        if (isset($GLOBALS['chatbot_db_config_values']) && is_array($GLOBALS['chatbot_db_config_values'])) {
            self::$dbValues = $GLOBALS['chatbot_db_config_values'];
            return self::$dbValues;
        }

        self::$dbValues = [];
        return self::$dbValues;
    }

    public static function castValue($rawValue, string $type, $defaultValue) {
        if (is_array($rawValue) && $type !== 'array') {
            return $defaultValue;
        }

        switch ($type) {
            case 'bool':
                if (is_bool($rawValue)) {
                    return $rawValue;
                }
                if (is_numeric($rawValue)) {
                    return ((int) $rawValue) !== 0;
                }
                if (is_string($rawValue)) {
                    $normalized = strtolower(trim($rawValue));
                    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                        return true;
                    }
                    if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                        return false;
                    }
                }
                return (bool) $defaultValue;

            case 'int':
                if (is_int($rawValue)) {
                    return $rawValue;
                }
                if (is_numeric($rawValue)) {
                    return (int) $rawValue;
                }
                return (int) $defaultValue;

            case 'array':
                if (is_array($rawValue)) {
                    return $rawValue;
                }
                if (!is_string($rawValue)) {
                    return is_array($defaultValue) ? $defaultValue : [];
                }

                $trimmed = trim($rawValue);
                if ($trimmed === '') {
                    return [];
                }

                if ($trimmed[0] === '[' || $trimmed[0] === '{') {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }

                $items = preg_split('/[\r\n,;]+/', $trimmed) ?: [];
                return array_values(array_filter(array_map('trim', $items), static function ($entry) {
                    return $entry !== '';
                }));

            case 'string':
            default:
                if ($rawValue === null) {
                    return (string) $defaultValue;
                }
                if (is_scalar($rawValue)) {
                    return (string) $rawValue;
                }
                return (string) $defaultValue;
        }
    }

    private static function loadDbValues(string $dsn, string $username, string $password, string $tableName): array {
        if ($dsn === '') {
            return [];
        }

        $safeTable = preg_match('/^[A-Za-z0-9_]+$/', $tableName) ? $tableName : 'configuration';

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2,
            ]);

            $sql = sprintf('SELECT configuration_key, configuration_value FROM %s', $safeTable);
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                return [];
            }

            $values = [];
            foreach ($stmt as $row) {
                $key = isset($row['configuration_key']) ? trim((string) $row['configuration_key']) : '';
                if ($key === '') {
                    continue;
                }
                $values[$key] = isset($row['configuration_value']) ? (string) $row['configuration_value'] : '';
            }

            return $values;
        } catch (Throwable $exception) {
            return [];
        }
    }
}