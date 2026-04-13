<?php

namespace Chatbot;

/**
 * Layer 1 � Redis storage.
 *
 * Requires the PhpRedis extension (ext-redis).
 * If the extension is missing or Redis is unreachable the instance marks
 * itself as unavailable and SessionManager falls back to FileStorage.
 *
 * Rate limiting uses a sorted-set sliding window (ZADD/ZREMRANGEBYSCORE)
 * which is more accurate than a fixed-window INCR counter.
 */
class RedisStorage implements StorageInterface {
    /** @var \Redis|null */
    private $redis = null;

    /** @var bool */
    private $available = false;

    /** @var string */
    private $prefix;

    /** @var int session TTL in seconds */
    private $ttl;

    public function __construct(
        string $host,
        int    $port,
        string $password,
        string $prefix,
        int    $ttl
    ) {
        $this->prefix = $prefix;
        $this->ttl    = $ttl;

        if (!\extension_loaded('redis')) {
            return;
        }

        try {
            $r = new \Redis();
            if (!@$r->connect($host, $port, 2.0)) {
                return;
            }
            if ($password !== '' && !$r->auth($password)) {
                return;
            }
            $this->redis     = $r;
            $this->available = true;
        } catch (\Exception $e) {
            // Redis not reachable : silently unavailable
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function loadHistory(string $sessionId): array
    {
        try {
            $data = $this->redis->get($this->sessionKey($sessionId));
        } catch (\Exception $e) {
            $this->available = false;
            return [];
        }

        if ($data === false) {
            return [];
        }

        $history = \json_decode($data, true);
        return \is_array($history) ? $history : [];
    }

    public function saveHistory(string $sessionId, array $history): void
    {
        try {
            $this->redis->setex(
                $this->sessionKey($sessionId),
                $this->ttl,
                \json_encode($history, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Exception $e) {
            $this->available = false;
            throw $e; // re-throw so SessionManager can handle fallback
        }
    }

    /**
     * Combined three-layer rate limit. Returns true if any layer is exceeded.
     *
     * Layer 1 — Sliding window  (per windowSeconds, precise, blocks bursts)
     * Layer 2 — Fixed window    (hourly,  lightweight — bursts within a minute are caught above)
     * Layer 3 — Fixed window    (daily,   very lightweight — single INCR counter)
     *
     * Hourly and daily limits are derived proportionally from $maxRequests so that
     * a caller hitting exactly the per-window rate never trips the coarser limits.
     * All three limits are read first; writes happen only when every check passes.
     */
    public function checkRateLimit(string $ipKey, int $maxRequests, int $windowSeconds): bool
    {
        $keyMin  = $this->prefix . 'rl:'   . $ipKey;
        $keyH    = $this->prefix . 'rl_h:' . $ipKey;
        $keyD    = $this->prefix . 'rl_d:' . $ipKey;

        $now        = \microtime(true);
        $windowStart = $now - $windowSeconds;

        $safe    = max(1, $windowSeconds);
        $maxH    = $maxRequests * (int) \ceil(3600  / $safe);
        $maxD    = $maxRequests * (int) \ceil(86400 / $safe);

        try {
            // ── Layer 1: sliding window (minute) ─────────────────────────────
            $this->redis->zRemRangeByScore($keyMin, '-inf', (string) $windowStart);
            $countMin = (int) $this->redis->zCard($keyMin);
            if ($countMin >= $maxRequests) {
                return true;
            }

            // ── Layer 2: fixed window (hour) — read only ──────────────────────
            $countH = (int) ($this->redis->get($keyH) ?: 0);
            if ($countH >= $maxH) {
                return true;
            }

            // ── Layer 3: fixed window (day) — read only ───────────────────────
            $countD = (int) ($this->redis->get($keyD) ?: 0);
            if ($countD >= $maxD) {
                return true;
            }

            // ── All checks passed → record the request ────────────────────────
            $member = $now . '.' . \mt_rand();
            $this->redis->zAdd($keyMin, $now, $member);
            $this->redis->expire($keyMin, $windowSeconds + 1);

            $newH = (int) $this->redis->incr($keyH);
            if ($newH === 1) {
                $ttlH = 3600 - ((int) \time() % 3600) + 1;
                $this->redis->expire($keyH, $ttlH);
            }

            $newD = (int) $this->redis->incr($keyD);
            if ($newD === 1) {
                $ttlD = 86400 - ((int) \time() % 86400) + 1;
                $this->redis->expire($keyD, $ttlD);
            }
        } catch (\Exception $e) {
            $this->available = false;
            return false; // on Redis error don't block the user, fall back to file check
        }

        return false;
    }

    private function sessionKey(string $sessionId): string
    {
        return $this->prefix . 'sess:' . $sessionId;
    }
}
