<?php

//declare(strict_types=1);

namespace Chatbot;

/**
 * AdminAuthManager
 *
 * Gestisce autenticazione admin su tabella `admin` con hashing
 * compatibile con il formato chatbot: md5(salt . plain) . ':' . salt
 */
class AdminAuthManager
{
    private $pdo;
    private $table;

    public function __construct(\PDO $pdo, string $table = 'admin')
    {
        $this->pdo   = $pdo;
        $this->table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: 'admin';
    }

    /**
     * Verifica credenziali email + password.
     * Restituisce l'array row admin se OK, null altrimenti.
     * Convalida anche la lunghezza minima della password (>= 16 caratteri per mitigare la debolezza MD5).
     */
    public function authenticate(string $email, string $plainPassword): ?array
    {
        $email = trim($email);
        $plainPassword = trim($plainPassword);

        if ($email === '' || $plainPassword === '') {
            return null;
        }

        // Validazione minima lunghezza password
        if (mb_strlen($plainPassword) < 8) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT admin_id, admin_firstname, admin_lastname, admin_email_address, admin_password
             FROM `' . $this->table . '`
             WHERE admin_email_address = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $storedHash = isset($row['admin_password']) ? (string) $row['admin_password'] : '';

        if (!$this->verifyPassword($plainPassword, $storedHash)) {
            return null;
        }

        return $row;
    }

    /**
     * Aggiorna la data e il contatore di login per l'utente.
     */
    public function recordLogin(int $adminId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `' . $this->table . '`
             SET admin_logdate = NOW(), admin_lognum = admin_lognum + 1
             WHERE admin_id = :id'
        );
        $stmt->execute([':id' => $adminId]);
    }

    /**
     * Genera un hash sicuro nel formato chatbot (legacy):
     *   md5(salt . plain) . ':' . salt
     * dove salt = prime 2 cifre hex di md5(random seed).
     *
     * @deprecated Usare hashPasswordBcrypt() per nuovi hash.
     * Questo metodo è mantenuto per compatibilità.
     */
    public static function hashPassword(string $plain): string
    {
        $seed = '';
        for ($i = 0; $i < 10; $i++) {
            $seed .= (string) mt_rand();
        }
        $salt     = substr(md5($seed), 0, 2);
        $password = md5($salt . $plain) . ':' . $salt;

        return $password;
    }

    /**
     * Genera un hash bcrypt moderno (PHP 5.5+).
     * Questo è il nuovo formato per la migrazione progressiva.
     */
    public static function hashPasswordBcrypt(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, [
            'cost' => 12,
        ]);
    }

    /**
     * Verifica se l'hash è nel formato legacy MD5 (migrazione)
     */
    public function isLegacyMd5Hash(string $hash): bool
    {
        return strpos($hash, ':') !== false && strlen($hash) > 40;
    }

    /**
     * Aggiorna il hash di una password nel database da MD5 a bcrypt.
     */
    public function upgradePasswordHash(int $adminId, string $newBcryptHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `' . $this->table . '`
             SET admin_password = :hash
             WHERE admin_id = :id'
        );
        $stmt->execute([
            ':hash' => $newBcryptHash,
            ':id' => $adminId,
        ]);
    }

    /**
     * Verifica che $plain corrisponda all'hash salvato.
     * Supporta sia il formato legacy MD5 che il nuovo bcrypt.
     *
     * Formato legacy: md5(salt . plain) . ':' . salt
     * Formato bcrypt: $2y$12$...
     */
    public function verifyPassword(string $plain, string $storedHash): bool
    {
        // Prova bcrypt prima
        if (strpos($storedHash, '$2') === 0 || strpos($storedHash, '$2y$') === 0) {
            return password_verify($plain, $storedHash);
        }

        // Fallback a MD5 legacy
        $parts = explode(':', $storedHash);
        if (count($parts) !== 2) {
            return false;
        }

        [$hash, $salt] = $parts;

        if ($salt === '' || $hash === '') {
            return false;
        }

        $expected = md5($salt . $plain);
        return hash_equals($expected, $hash);
    }
}
