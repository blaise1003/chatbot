<?php

namespace Chatbot;

/**
 * Layer 2 : File-based storage (always available, used as fallback).
 *
 * Sessions are stored as JSON files in $storageDir.
 * Rate limiting uses a true sliding-window (timestamps array filtered
 * by window start) persisted to disk.
 */
class FileStorage implements StorageInterface
{
    /** @var string */
    private $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = \rtrim($storageDir, '/');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function storageDir(): string
    {
        return $this->storageDir;
    }

    public function loadHistory(string $sessionId): array
    {
        $file = $this->sessionFilePath($sessionId);
        if (!\file_exists($file)) {
            return [];
        }

        $data    = \file_get_contents($file);
        $history = \json_decode($data, true);
        return \is_array($history) ? $history : [];
    }

    public function saveHistory(string $sessionId, array $history): void
    {
        $this->writeJson($this->sessionFilePath($sessionId), $history);
    }

    /**
     * Sliding-window rate limit stored per-IP in a JSON file.
     * Returns true if the request limit is exceeded.
     */
    public function checkRateLimit(string $ipKey, int $maxRequests, int $windowSeconds): bool
    {
        $rateLimitDir = $this->storageDir . '/rate_limits';
        $this->ensureDirectory($rateLimitDir, 0700);

        $file        = $rateLimitDir . '/' . $ipKey . '.json';
        $now         = \time();
        $windowStart = $now - $windowSeconds;
        $requests    = [];

        if (\file_exists($file)) {
            $data     = \json_decode(\file_get_contents($file), true);
            $requests = \is_array($data) ? $data : [];
        }

        $requests = \array_values(\array_filter($requests, function ($timestamp) use ($windowStart) {
            return \is_int($timestamp) && $timestamp >= $windowStart;
        }));

        if (\count($requests) >= $maxRequests) {
            return true;
        }

        $requests[] = $now;
        $this->writeJson($file, $requests);
        return false;
    }

    public function ensureDirectory(string $path, int $mode): void
    {
        if (!\is_dir($path)) {
            \mkdir($path, $mode, true);
        }
    }

    public function writeJson(string $path, array $payload): void
    {
        \file_put_contents(
            $path,
            \json_encode($payload, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        if (\file_exists($path)) {
            @\chmod($path, 0600);
        }
    }

    private function sessionFilePath(string $sessionId): string
    {
        return $this->storageDir . '/' . $sessionId . '.json';
    }
}
