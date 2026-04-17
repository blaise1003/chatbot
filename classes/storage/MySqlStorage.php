<?php

namespace Chatbot;

/**
 * Layer 3 - MySQL persistent storage for analytics/dashboard.
 *
 * If the DSN is empty or the connection fails the instance marks itself
 * unavailable and SessionManager silently skips all MySQL operations.
 *
 * Table is auto-created on first connection (CREATE TABLE IF NOT EXISTS).
 *
 * Schema:
 *   chatbot_conversations
 *   ??? id               BIGINT UNSIGNED AUTO_INCREMENT PK
 *   ??? session_id       VARCHAR(64) UNIQUE  � chatbot session identifier
 *   ??? ip_hash          VARCHAR(64)         � SHA-256 of client IP (privacy)
 *   ??? started_at       DATETIME            � first flush timestamp
 *   ??? last_activity_at DATETIME            � most recent flush timestamp
 *   ??? message_count    SMALLINT UNSIGNED   � number of messages in history
 *   ??? last_flush_reason VARCHAR(64)        � why this flush was triggered
 *   ??? history          MEDIUMTEXT          � full JSON conversation history
 *
 * Flush reasons written by SessionManager / ChatbotApplication:
 *   first_message    -> First assistant reply (inserts the record)
 *   periodic         -> Every N complete exchanges
 *   order_found      -> Order info successfully fetched from the API
 *   conversation_end -> Claude returned an empty options array
 *   redis_failure    -> Redis save failed; data preserved before file fallback
 */
class MySqlStorage
{
    /** @var \PDO|null */
    private $pdo = null;

    /** @var bool */
    private $available = false;

    /** @var string */
    private $table;

    private const CUSTOMER_TOKEN_PREFIX = 'pfc1';

    public function __construct(
        string $dsn,
        string $user,
        string $password,
        string $table = 'chatbot_conversations'
    ) {
        $this->table = $table;

        if ($dsn === '') {
            return;
        }

        try {
            $this->pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 3,
            ]);
            $this->available = true;
        } catch (\Exception $e) {
            $this->pdo = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Insert or update the conversation record for $sessionId.
     * started_at is preserved on UPDATE (set only on INSERT).
     */
    public function upsert(
        string $sessionId,
        string $ipHash,
        array  $history,
		string $customerId,
        string $flushReason
    ): void {
        if (!$this->available) {
            return;
        }

        $now          = \date('Y-m-d H:i:s');
        $messageCount = \count($history);
        $historyJson  = \json_encode($history, JSON_UNESCAPED_UNICODE);
		$customerEmail = $this->decodeCustomerIdentifier($customerId);

        $sql = "INSERT INTO `{$this->table}`
                    (session_id, ip_hash, started_at, last_activity_at,
                     message_count, last_flush_reason, history, customer_email)
                VALUES
                    (:sid, :ip, :started, :now, :mc, :reason, :history, :customer_email)
                ON DUPLICATE KEY UPDATE
                    last_activity_at    = :now2,
                    message_count       = :mc2,
                    last_flush_reason   = :reason2,
                    history             = :history2,
                    customer_email      = :customer_email2";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':sid'      		=> $sessionId,
                ':ip'       		=> $ipHash,
                ':started'  		=> $now,
                ':now'      		=> $now,
                ':mc'       		=> $messageCount,
                ':reason'   		=> $flushReason,
                ':history'  		=> $historyJson,
				':customer_email' 	=> $customerEmail,
				':now2'     		=> $now,
                ':mc2'      		=> $messageCount,
                ':reason2'  		=> $flushReason,
                ':history2' 		=> $historyJson,
                ':customer_email2' 	=> $customerEmail,
            ]);
        } catch (\Exception $e) {
            // Non-fatal: MySQL write failure should not interrupt the chatbot
        }
    }

    public function upsertHandoffState(string $sessionId, array $handoffState): void
    {
        if (!$this->available) {
            return;
        }

        $now = \date('Y-m-d H:i:s');
        $status = isset($handoffState['status']) ? (string) $handoffState['status'] : 'none';
        $requestedAt = $this->toNullableDateTime(isset($handoffState['requested_at']) ? (string) $handoffState['requested_at'] : '');
        $requestedBy = isset($handoffState['requested_by']) ? (string) $handoffState['requested_by'] : '';
        $reason = isset($handoffState['reason']) ? (string) $handoffState['reason'] : '';
        $claimedBy = isset($handoffState['claimed_by']) ? (string) $handoffState['claimed_by'] : '';
        $claimedAt = $this->toNullableDateTime(isset($handoffState['claimed_at']) ? (string) $handoffState['claimed_at'] : '');
        $closedAt = $this->toNullableDateTime(isset($handoffState['closed_at']) ? (string) $handoffState['closed_at'] : '');
        $handoffPayload = \json_encode($handoffState, JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO `{$this->table}`
                    (session_id, ip_hash, started_at, last_activity_at, message_count, last_flush_reason, history,
                     handoff_status, handoff_requested_at, handoff_requested_by, handoff_reason,
                     handoff_claimed_by, handoff_claimed_at, handoff_closed_at, handoff_updated_at, handoff_payload)
                VALUES
                    (:sid, '', :now, :now, 0, 'handoff', '[]',
                     :status, :requested_at, :requested_by, :reason,
                     :claimed_by, :claimed_at, :closed_at, :updated_at, :handoff_payload)
                ON DUPLICATE KEY UPDATE
                    handoff_status       = :status2,
                    handoff_requested_at = :requested_at2,
                    handoff_requested_by = :requested_by2,
                    handoff_reason       = :reason2,
                    handoff_claimed_by   = :claimed_by2,
                    handoff_claimed_at   = :claimed_at2,
                    handoff_closed_at    = :closed_at2,
                    handoff_updated_at   = :updated_at2,
                    handoff_payload      = :handoff_payload2,
                    last_activity_at     = :now2";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':sid' => $sessionId,
                ':now' => $now,
                ':status' => $status,
                ':requested_at' => $requestedAt,
                ':requested_by' => $requestedBy,
                ':reason' => $reason,
                ':claimed_by' => $claimedBy,
                ':claimed_at' => $claimedAt,
                ':closed_at' => $closedAt,
                ':updated_at' => $now,
                ':handoff_payload' => $handoffPayload,
                ':status2' => $status,
                ':requested_at2' => $requestedAt,
                ':requested_by2' => $requestedBy,
                ':reason2' => $reason,
                ':claimed_by2' => $claimedBy,
                ':claimed_at2' => $claimedAt,
                ':closed_at2' => $closedAt,
                ':updated_at2' => $now,
                ':handoff_payload2' => $handoffPayload,
                ':now2' => $now,
            ]);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    public function upsertHistorySnapshot(string $sessionId, array $history, string $flushReason): void
    {
        if (!$this->available) {
            return;
        }

        $now = \date('Y-m-d H:i:s');
        $messageCount = \count($history);
        $historyJson = \json_encode($history, JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO `{$this->table}`
                    (session_id, ip_hash, started_at, last_activity_at,
                     message_count, last_flush_reason, history)
                VALUES
                    (:sid, '', :started, :now, :mc, :reason, :history)
                ON DUPLICATE KEY UPDATE
                    last_activity_at  = :now2,
                    message_count     = :mc2,
                    last_flush_reason = :reason2,
                    history           = :history2";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':sid' => $sessionId,
                ':started' => $now,
                ':now' => $now,
                ':mc' => $messageCount,
                ':reason' => $flushReason,
                ':history' => $historyJson,
                ':now2' => $now,
                ':mc2' => $messageCount,
                ':reason2' => $flushReason,
                ':history2' => $historyJson,
            ]);
			Logger::logDebug("MySqlStorage", "History snapshot upserted for session $sessionId with reason '$flushReason' and message count $messageCount."); // DEBUG LOG
        } catch (\Exception $e) {
            // Non-fatal
			Logger::logError("MySqlStorage", "Failed to upsert history snapshot for session $sessionId: " . $e->getMessage()); // DEBUG LOG			
		}
    }

    private function decodeCustomerIdentifier(string $customerId): ?string
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return null;
        }

        if (strpos($customerId, self::CUSTOMER_TOKEN_PREFIX . '.') === 0) {
            return $this->decryptCustomerToken($customerId);
        }

        $decoded = base64_decode($customerId, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $decoded = trim($decoded);
        return filter_var($decoded, FILTER_VALIDATE_EMAIL) ? $decoded : null;
    }

    private function decryptCustomerToken(string $token): ?string
    {
        $secret = '';
        if (defined('CHATBOT_EMAIL_SEED_SECRET')) {
            $secret = trim((string) CHATBOT_EMAIL_SEED_SECRET);
        }

        if ($secret === '' || !function_exists('openssl_decrypt')) {
            return null;
        }

		Logger::logDebug("MySqlStorage", "Decoding customer token: $token"); // DEBUG LOG

        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::CUSTOMER_TOKEN_PREFIX) {
            return null;
        }

        $iv = $this->base64urlDecode($parts[1]);
        $tag = $this->base64urlDecode($parts[2]);
        $ciphertext = $this->base64urlDecode($parts[3]);
        if ($iv === null || $tag === null || $ciphertext === null) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            hash('sha256', $secret, true),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

		Logger::logDebug("MySqlStorage", "Decrypted customer token to: " . ($plaintext ?? 'null')); // DEBUG LOG

        if (!is_string($plaintext) || $plaintext === '') {
            return null;
        }

        $plaintext = trim($plaintext);
        return filter_var($plaintext, FILTER_VALIDATE_EMAIL) ? $plaintext : null;
    }

    private function base64urlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function toNullableDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
