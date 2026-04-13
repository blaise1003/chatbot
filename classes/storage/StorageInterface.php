<?php

namespace Chatbot;

interface StorageInterface
{
    public function isAvailable(): bool;
    public function loadHistory(string $sessionId): array;
    public function saveHistory(string $sessionId, array $history): void;
}
