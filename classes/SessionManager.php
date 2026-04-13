<?php

namespace Chatbot;

/**
 * Session orchestrator � three-layer storage strategy.
 *
 * Layer 1 (Redis)   � fast in-memory sessions; optional, graceful fallback.
 * Layer 2 (File)    � always-available disk storage; write-through backup
 *                     when Redis is active so data survives Redis restarts.
 * Layer 3 (MySQL)   � persistent analytics store; flushed asynchronously on
 *                     defined triggers (see flushToDatabase / maybeFlushToDatabase).
 */
class SessionManager
{
    const FLUSH_EVERY_N_EXCHANGES = 5;

    /** @var FileStorage */
    private $fileStorage;

    /** @var RedisStorage|null */
    private $redisStorage;

    /** @var MySqlStorage|null */
    private $mysqlStorage;

    /** @var RequestGuard */
    private $requestGuard;

    /** @var TrafficLimiter */
    private $trafficLimiter;

    public function __construct(
        string        $storageDir,
        RequestGuard  $requestGuard,
        RedisStorage  $redisStorage = null,
        MySqlStorage  $mysqlStorage = null,
        TrafficLimiter $trafficLimiter = null
    ) {
        $this->fileStorage  = new FileStorage($storageDir);
        $this->requestGuard = $requestGuard;
        $this->redisStorage = ($redisStorage !== null && $redisStorage->isAvailable()) ? $redisStorage : null;
        $this->mysqlStorage = ($mysqlStorage !== null && $mysqlStorage->isAvailable()) ? $mysqlStorage : null;
        $this->trafficLimiter = $trafficLimiter !== null
            ? $trafficLimiter
            : new TrafficLimiter($this->fileStorage, $this->redisStorage);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function bootStorage(): void
    {
        $this->fileStorage->ensureDirectory(
            $this->fileStorage->storageDir(),
            0700
        );
        $this->applyRateLimit();
    }

    /**
     * Load conversation history.
     * Redis ? (if empty) File fallback (covers Redis TTL expiry / restarts).
     */
    public function loadHistory(string $sessionId): array
    {
        if ($this->redisStorage !== null) {
            $history = $this->redisStorage->loadHistory($sessionId);
            if (!empty($history)) {
                return $history;
            }
            // Redis empty: TTL may have expired � try file
        }

        return $this->fileStorage->loadHistory($sessionId);
    }

    /**
     * Persist conversation history.
     * Always writes to File (durable backup).
     * Also writes to Redis when available.
     * On Redis failure: data is preserved in File and a MySQL flush is triggered.
     */
    public function saveHistory(string $sessionId, array $history, string $customerId): void
    {
        $redisFailed = false;

        if ($this->redisStorage !== null) { // Redis attivo
            try {
                $this->redisStorage->saveHistory($sessionId, $history);
            } catch (\Exception $e) {
                $redisFailed = true;
            }
        } else {
			$redisFailed = true; // Redis non attivo
			// Write to file fallback: acts as write-through backup
			$this->fileStorage->saveHistory($sessionId, $history);
		}

        if ($redisFailed) {
            $this->flushToDatabase($sessionId, $history, $customerId, 'redis_failure');
            return; // already flushed; skip periodic check
        }

        $this->maybeFlushToDatabase($sessionId, $history, $customerId);
    }

    /**
     * Unconditionally flush the current conversation to MySQL.
     * Called externally by ChatbotApplication for event-driven triggers
     * (order_found, conversation_end) and internally for periodic/first_message.
     */
    public function flushToDatabase(string $sessionId, array $history, string $customerId, string $reason): void
    {
        if ($this->mysqlStorage === null) {
            return;
        }

        $ipHash = \hash('sha256', $this->requestGuard->getClientIp());
        $this->mysqlStorage->upsert($sessionId, $ipHash, $history, $customerId, $reason);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Decide whether to flush to MySQL based on conversation progress.
     * Triggers: first assistant message, every N complete exchanges.
     */
    private function maybeFlushToDatabase(string $sessionId, array $history, string $customerId): void
    {
        if ($this->mysqlStorage === null) {
            return;
        }

        $assistantCount = \count(\array_filter($history, function ($m) {
            return isset($m['role']) && $m['role'] === 'assistant';
        }));

        if ($assistantCount === 1) {
            $this->flushToDatabase($sessionId, $history, $customerId, 'first_message');
            return;
        }

        if ($assistantCount > 1 && $assistantCount % self::FLUSH_EVERY_N_EXCHANGES === 0) {
            $this->flushToDatabase($sessionId, $history, $customerId, 'periodic');
        }
    }

    private function applyRateLimit(): void
    {
        $ip    = \preg_replace('/[^a-fA-F0-9:\.]/', '', $this->requestGuard->getClientIp());
        $ipKey = \hash('sha256', $ip);

        $ipExceeded = $this->trafficLimiter->isExceeded(
            'ip:' . $ipKey,
            \CHATBOT_RATE_LIMIT_MAX_REQUESTS,
            \CHATBOT_RATE_LIMIT_WINDOW_SECONDS
        );

        if ($ipExceeded) {
            \json_response([
                'reply'   => 'Troppe richieste ravvicinate. Riprova tra qualche minuto.',
                'options' => [],
            ], 429);
        }

        $globalExceeded = $this->trafficLimiter->isExceeded(
            'global:all_requests',
            \CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS,
            \CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS
        );

        if ($globalExceeded) {
            \json_response([
                'reply'   => 'Servizio temporaneamente molto richiesto. Riprova tra qualche secondo.',
                'options' => [],
            ], 429);
        }
    }
}
