<?php

namespace Chatbot;

require_once __DIR__ . '/storage/RedisStorage.php';
require_once __DIR__ . '/storage/MySqlStorage.php';
require_once __DIR__ . '/storage/FileStorage.php';

class HandoffManager
{
    private $dir;
    private $expireAfterSeconds;
    private $hideExpiredAfterSeconds;
    private $operatorTypingTtlSeconds;
    private $redisStorage;
    private $mysqlStorage;

    public function __construct(string $sessionDir, ?RedisStorage $redisStorage = null, ?MySqlStorage $mysqlStorage = null)
    {
        $sessionDir = rtrim($sessionDir, '/\\');
        $this->dir = $sessionDir . '/handoffs';
        $this->expireAfterSeconds = $this->resolveExpireAfterSeconds();
        $this->hideExpiredAfterSeconds = 3600;
        $this->operatorTypingTtlSeconds = $this->resolveOperatorTypingTtlSeconds();
        $this->redisStorage = ($redisStorage !== null && $redisStorage->isAvailable()) ? $redisStorage : $this->buildRedisStorage();
        $this->mysqlStorage = ($mysqlStorage !== null && $mysqlStorage->isAvailable()) ? $mysqlStorage : $this->buildMySqlStorage();
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function getState(string $sessionId): array
    {
        $sessionId = $this->sanitizeSessionId($sessionId);
        if ($sessionId === '') {
            return $this->defaultState('');
        }

        if ($this->redisStorage !== null) {
            $redisState = $this->loadStateFromRedis($sessionId);
            if (!empty($redisState)) {
                return $redisState;
            }
        }

		Logger::logError('HandoffManager', 'State not found in Redis, falling back to file storage', ['session_id' => $sessionId]);
        $path = $this->filePath($sessionId);
        if (!is_file($path)) {
            return $this->defaultState($sessionId);
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $this->defaultState($sessionId);
        }

        return $this->normalizeState($decoded, $sessionId);
    }

    public function request(string $sessionId, string $source, string $reason): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($source, $reason): array {
            $status = (string) $state['status'];
            $newRequest = !in_array($status, ['requested', 'claimed'], true);

            $state['status'] = $status === 'claimed' ? 'claimed' : 'requested';
            if ($newRequest) {
                $state['requested_at'] = date('c');
            }
            $state['requested_by'] = $source;
            $state['reason'] = trim($reason);
            $state['updated_at'] = date('c');
            $state['version'] = (int) $state['version'] + 1;
            $state['_new_request'] = $newRequest;

            return $state;
        });
    }

    public function claim(string $sessionId, string $operator): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($operator): array {
            $operator = trim($operator);
            if ($operator === '') {
                $state['_claim_ok'] = false;
                $state['_claim_error'] = 'Operatore non valido.';
                return $state;
            }

            if ($state['status'] === 'claimed' && $state['claimed_by'] !== $operator) {
                $state['_claim_ok'] = false;
                $state['_claim_error'] = 'Sessione gi� presa in carico da un altro operatore.';
                return $state;
            }

            $state['status'] = 'claimed';
            $state['claimed_by'] = $operator;
            $state['operator_typing'] = false;
            $state['operator_typing_by'] = '';
            $state['operator_typing_at'] = '';
            if ((string) $state['claimed_at'] === '') {
                $state['claimed_at'] = date('c');
            }
            if ((string) $state['requested_at'] === '') {
                $state['requested_at'] = date('c');
            }
            $state['updated_at'] = date('c');
            $state['version'] = (int) $state['version'] + 1;
            $state['_claim_ok'] = true;
            return $state;
        });
    }

    public function close(string $sessionId, string $operator, string $note = ''): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($operator, $note): array {
            $state['status'] = 'closed';
            $state['operator_typing'] = false;
            $state['operator_typing_by'] = '';
            $state['operator_typing_at'] = '';
            $state['closed_at'] = date('c');
            $state['closed_by'] = trim($operator);
            $state['close_note'] = trim($note);
            $state['updated_at'] = date('c');
            $state['version'] = (int) $state['version'] + 1;
            return $state;
        });
    }

    public function setOperatorTyping(string $sessionId, string $operator, bool $isTyping): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($operator, $isTyping): array {
            $operator = trim($operator);
            if ($operator === '') {
                return $state;
            }

            if ((string) $state['status'] !== 'claimed') {
                $state['operator_typing'] = false;
                $state['operator_typing_by'] = '';
                $state['operator_typing_at'] = '';
                return $state;
            }

            if ((string) $state['claimed_by'] !== $operator) {
                return $state;
            }

            $state['operator_typing'] = $isTyping;
            $state['operator_typing_by'] = $isTyping ? $operator : '';
            $state['operator_typing_at'] = $isTyping ? date('c') : '';
            return $state;
        });
    }

    public function addWidgetMessage(string $sessionId, string $message): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($message): array {
            $text = trim($message);
            if ($text === '') {
                return $state;
            }

            $id = (int) $state['last_widget_message_id'] + 1;
            $state['last_widget_message_id'] = $id;
            $state['last_widget_message_at'] = date('c');
            $state['widget_unread_count'] = (int) $state['widget_unread_count'] + 1;
            $state['widget_messages'][] = [
                'id' => $id,
                'at' => date('c'),
                'text' => $text,
            ];
            $state['updated_at'] = date('c');
            $state['version'] = (int) $state['version'] + 1;
            return $state;
        });
    }

    public function addOperatorMessage(string $sessionId, string $operator, string $message): array
    {
        return $this->withStateLock($sessionId, function (array $state) use ($operator, $message): array {
            $text = trim($message);
            $operator = trim($operator);
            if ($text === '' || $operator === '') {
                return $state;
            }

            $id = (int) $state['last_operator_message_id'] + 1;
            $state['last_operator_message_id'] = $id;
            $state['last_operator_message_at'] = date('c');
            $state['operator_unread_count'] = (int) $state['operator_unread_count'] + 1;
            $state['operator_messages'][] = [
                'id' => $id,
                'at' => date('c'),
                'operator' => $operator,
                'text' => $text,
            ];
            $state['operator_typing'] = false;
            $state['operator_typing_by'] = '';
            $state['operator_typing_at'] = '';
            $state['updated_at'] = date('c');
            $state['version'] = (int) $state['version'] + 1;
            return $state;
        });
    }

    public function getOperatorMessagesSince(string $sessionId, int $lastId): array
    {
        $state = $this->getState($sessionId);
        $messages = isset($state['operator_messages']) && is_array($state['operator_messages'])
            ? $state['operator_messages']
            : [];

        $out = [];
        foreach ($messages as $message) {
            $id = isset($message['id']) ? (int) $message['id'] : 0;
            if ($id > $lastId) {
                $out[] = $message;
            }
        }

        return $out;
    }

    public function listActive(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $entries = @scandir($this->dir);
        if (!is_array($entries)) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.json') {
                continue;
            }
            $sessionId = substr($entry, 0, -5);
            $state = $this->getState($sessionId);
            if (in_array($state['status'], ['requested', 'claimed'], true)) {
                $result[] = $state;
            }
        }

        usort($result, function (array $a, array $b): int {
            return strcmp((string) $a['updated_at'], (string) $b['updated_at']) * -1;
        });

        return $result;
    }

    public function listForQueue(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $entries = @scandir($this->dir);
        if (!is_array($entries)) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.json') {
                continue;
            }

            $sessionId = substr($entry, 0, -5);
            $state = $this->getState($sessionId);
            if (!in_array($state['status'], ['requested', 'claimed'], true)) {
                continue;
            }

            $state = $this->withExpiryMetadata($state);
            if (!empty($state['_is_hidden_expired'])) {
                continue;
            }

            $result[] = $state;
        }

        usort($result, function (array $a, array $b): int {
            return strcmp((string) $a['updated_at'], (string) $b['updated_at']) * -1;
        });

        return $result;
    }

    public function getQueueState(string $sessionId): array
    {
        return $this->withExpiryMetadata($this->getState($sessionId));
    }

    public function canOperatorReply(array $state): bool
    {
        $state = $this->withExpiryMetadata($state);
        return empty($state['_is_expired']);
    }

    private function withStateLock(string $sessionId, callable $callback): array
    {
        $sessionId = $this->sanitizeSessionId($sessionId);
        if ($sessionId === '') {
            return $this->defaultState('');
        }

        $path = $this->filePath($sessionId);
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return $this->defaultState($sessionId);
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return $this->defaultState($sessionId);
            }

            $content = stream_get_contents($fh);
            $decoded = is_string($content) && trim($content) !== '' ? json_decode($content, true) : null;
            $state = is_array($decoded) ? $this->normalizeState($decoded, $sessionId) : $this->defaultState($sessionId);

            if ($this->redisStorage !== null) {
                $redisState = $this->loadStateFromRedis($sessionId);
                if (!empty($redisState) && (!isset($state['version']) || (int) $redisState['version'] >= (int) $state['version'])) {
                    $state = $redisState;
                }
            }

            $updated = $callback($state);
            $updated = $this->normalizeState(is_array($updated) ? $updated : $state, $sessionId);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fh);

            $this->saveStateToRedis($sessionId, $updated);
            $this->syncStateToMySql($sessionId, $updated);

            flock($fh, LOCK_UN);
            fclose($fh);

            return $updated;
        } catch (\Throwable $e) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return $this->defaultState($sessionId);
        }
    }

    private function filePath(string $sessionId): string
    {
        return $this->dir . '/' . $sessionId . '.json';
    }

    private function redisKey(string $sessionId): string
    {
        return '__handoff__' . $sessionId;
    }

    private function sanitizeSessionId(string $sessionId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', trim($sessionId));
    }

    private function resolveExpireAfterSeconds(): int
    {
        if (defined('CHATBOT_HANDOFF_EXPIRE_SECONDS')) {
            $value = (int) constant('CHATBOT_HANDOFF_EXPIRE_SECONDS');
            if ($value > 0) {
                return $value;
            }
        }

        if (defined('CHATBOT_REDIS_TTL')) {
            $value = (int) constant('CHATBOT_REDIS_TTL');
            if ($value > 0) {
                return $value;
            }
        }

        return 1800;
    }

    private function resolveOperatorTypingTtlSeconds(): int
    {
        if (defined('CHATBOT_HANDOFF_OPERATOR_TYPING_TTL_SECONDS')) {
            $value = (int) constant('CHATBOT_HANDOFF_OPERATOR_TYPING_TTL_SECONDS');
            if ($value > 0) {
                return max(3, min(60, $value));
            }
        }

        return 10;
    }

    private function buildRedisStorage(): ?RedisStorage
    {
        $redis = new RedisStorage(
            defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1',
            defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379,
            defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '',
            defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'getty:',
            defined('CHATBOT_REDIS_TTL') ? (int) CHATBOT_REDIS_TTL : 1800
        );

        return $redis->isAvailable() ? $redis : null;
    }

    private function buildMySqlStorage(): ?MySqlStorage
    {
        $mysql = new MySqlStorage(
            defined('CHATBOT_MYSQL_DSN') ? (string) CHATBOT_MYSQL_DSN : '',
            defined('CHATBOT_MYSQL_USER') ? (string) CHATBOT_MYSQL_USER : '',
            defined('CHATBOT_MYSQL_PASSWORD') ? (string) CHATBOT_MYSQL_PASSWORD : '',
            defined('CHATBOT_MYSQL_TABLE') ? (string) CHATBOT_MYSQL_TABLE : 'chatbot_conversations'
        );

        return $mysql->isAvailable() ? $mysql : null;
    }

    private function loadStateFromRedis(string $sessionId): array
    {
        if ($this->redisStorage === null) {
            return [];
        }

        try {
            $payload = $this->redisStorage->loadHistory($this->redisKey($sessionId));
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($payload) && !empty($payload)
            ? $this->normalizeState($payload, $sessionId)
            : [];
    }

    private function saveStateToRedis(string $sessionId, array $state): void
    {
        if ($this->redisStorage === null) {
            return;
        }

        try {
            $this->redisStorage->saveHistory($this->redisKey($sessionId), $state);
        } catch (\Throwable $e) {
            // File fallback is already persisted under the file lock.
        }
    }

    private function syncStateToMySql(string $sessionId, array $state): void
    {
        if ($this->mysqlStorage === null) {
            return;
        }

        $this->mysqlStorage->upsertHandoffState($sessionId, $state);
    }

    private function withExpiryMetadata(array $state): array
    {
        $lastActivityTs = $this->extractLastActivityTimestamp($state);
        if ($lastActivityTs <= 0) {
            $state['_is_expired'] = false;
            $state['_is_hidden_expired'] = false;
            $state['_expired_for_seconds'] = 0;
            $state['_expires_at'] = '';
            return $state;
        }

        $expiresAt = $lastActivityTs + $this->expireAfterSeconds;
        $now = time();
        $isExpired = $now >= $expiresAt;
        $expiredFor = $isExpired ? max(0, $now - $expiresAt) : 0;

        $state['_is_expired'] = $isExpired;
        $state['_is_hidden_expired'] = $isExpired && $expiredFor > $this->hideExpiredAfterSeconds;
        $state['_expired_for_seconds'] = $expiredFor;
        $state['_expires_at'] = date('c', $expiresAt);

        return $state;
    }

    private function extractLastActivityTimestamp(array $state): int
    {
        $candidates = [
            isset($state['updated_at']) ? (string) $state['updated_at'] : '',
            isset($state['last_widget_message_at']) ? (string) $state['last_widget_message_at'] : '',
            isset($state['last_operator_message_at']) ? (string) $state['last_operator_message_at'] : '',
            isset($state['claimed_at']) ? (string) $state['claimed_at'] : '',
            isset($state['requested_at']) ? (string) $state['requested_at'] : '',
        ];

        $maxTs = 0;
        foreach ($candidates as $value) {
            if ($value === '') {
                continue;
            }

            $parsed = strtotime($value);
            if ($parsed !== false && $parsed > $maxTs) {
                $maxTs = $parsed;
            }
        }

        return $maxTs;
    }

    private function defaultState(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'status' => 'none',
            'requested_at' => '',
            'requested_by' => '',
            'reason' => '',
            'claimed_by' => '',
            'claimed_at' => '',
            'closed_at' => '',
            'closed_by' => '',
            'close_note' => '',
            'last_widget_message_id' => 0,
            'last_operator_message_id' => 0,
            'last_widget_message_at' => '',
            'last_operator_message_at' => '',
            'widget_unread_count' => 0,
            'operator_unread_count' => 0,
            'widget_messages' => [],
            'operator_messages' => [],
            'operator_typing' => false,
            'operator_typing_by' => '',
            'operator_typing_at' => '',
            'updated_at' => date('c'),
            'version' => 1,
        ];
    }

    private function normalizeState(array $state, string $sessionId): array
    {
        $base = $this->defaultState($sessionId);
        foreach ($base as $key => $value) {
            if (!array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        $state['session_id'] = $sessionId;
        $status = (string) $state['status'];
        if (!in_array($status, ['none', 'requested', 'claimed', 'closed'], true)) {
            $state['status'] = 'none';
        }

        if (!is_array($state['widget_messages'])) {
            $state['widget_messages'] = [];
        }
        if (!is_array($state['operator_messages'])) {
            $state['operator_messages'] = [];
        }

        $state['operator_typing'] = (bool) $state['operator_typing'];
        $state['operator_typing_by'] = trim((string) $state['operator_typing_by']);
        $state['operator_typing_at'] = trim((string) $state['operator_typing_at']);

        // Avoid stale typing indicators if the operator closes or pauses too long.
        if ($state['operator_typing']) {
            $typingAtTs = $state['operator_typing_at'] !== '' ? strtotime($state['operator_typing_at']) : false;
            $typingExpired = ($typingAtTs === false) || ((time() - (int) $typingAtTs) > $this->operatorTypingTtlSeconds);
            if ($typingExpired || (string) $state['status'] !== 'claimed' || $state['operator_typing_by'] === '') {
                $state['operator_typing'] = false;
                $state['operator_typing_by'] = '';
                $state['operator_typing_at'] = '';
            }
        }

        return $state;
    }
}
