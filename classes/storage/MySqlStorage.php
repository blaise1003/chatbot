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
            $this->ensureTable();
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
		$customerEmail = $customerId !== '' ? base64_decode($customerId) : null;

        $sql = "INSERT INTO `{$this->table}`
                    (session_id, ip_hash, started_at, last_activity_at,
                     message_count, last_flush_reason, history, customer_email)
                VALUES
                    (:sid, :ip, :started, :now, :mc, :reason, :history, :customer_email)
                ON DUPLICATE KEY UPDATE
                    last_activity_at    = :now2,
                    message_count       = :mc2,
                    last_flush_reason   = :reason2,
                    history             = :history2";

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
            ]);
        } catch (\Exception $e) {
            // Non-fatal: MySQL write failure should not interrupt the chatbot
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id        VARCHAR(64)          NOT NULL,
            ip_hash           VARCHAR(64)          NOT NULL DEFAULT '',
            started_at        DATETIME             NOT NULL,
            last_activity_at  DATETIME             NOT NULL,
            message_count     SMALLINT UNSIGNED    NOT NULL DEFAULT 0,
            last_flush_reason VARCHAR(64)          NOT NULL DEFAULT '',
            history           MEDIUMTEXT           NOT NULL,
            UNIQUE KEY uq_session     (session_id),
            KEY        idx_started    (started_at),
            KEY        idx_activity   (last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
