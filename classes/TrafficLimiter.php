<?php

namespace Chatbot;

class TrafficLimiter
{
    /** @var FileStorage */
    private $fileStorage;

    /** @var RedisStorage|null */
    private $redisStorage;

    public function __construct(FileStorage $fileStorage, RedisStorage $redisStorage = null)
    {
        $this->fileStorage = $fileStorage;
        $this->redisStorage = ($redisStorage !== null && $redisStorage->isAvailable()) ? $redisStorage : null;
    }

    public function isExceeded(string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        if ($maxRequests <= 0 || $windowSeconds <= 0) {
            return false;
        }

        $key = hash('sha256', trim($bucket));

        if ($this->redisStorage !== null) {
            $exceeded = $this->redisStorage->checkRateLimit($key, $maxRequests, $windowSeconds);

            if (!$this->redisStorage->isAvailable()) {
                return $this->fileStorage->checkRateLimit($key, $maxRequests, $windowSeconds);
            }

            return $exceeded;
        }

        return $this->fileStorage->checkRateLimit($key, $maxRequests, $windowSeconds);
    }
}
