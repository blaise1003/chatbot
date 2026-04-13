<?php

define('CHATBOT_ALLOW_TEST_MODE', true);
define('CHATBOT_SESSION_DIR', dirname(__DIR__) . '/chatbot_sessions');
define('CHATBOT_LOGS_DIR', dirname(__DIR__) . '/chatbot_logs');

// 4. Password per la Dashboard admin (impostare prima del deploy)
define('DASHBOARD_PASSWORD', '');

// 5. Token per l'endpoint di distribuzione widget (widget.php?token=...)
// Generare un valore casuale sicuro, es.: openssl rand -hex 32
define('CHATBOT_WIDGET_TOKEN', '');
define('CHATBOT_WIDGET_DYNAMIC_SECRET', CHATBOT_WIDGET_TOKEN);
define('CHATBOT_WIDGET_TOKEN_PREFIX', 'pfw1');
define('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS', 86400);
define('CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK', true);
define('CHATBOT_INCLUDE_RESPONSE_HISTORY', true);

// ============================================================
// Layer 1 — Redis (opzionale, disabilitato se l'estensione manca)
// ============================================================
// Se il server non ha l'estensione ext-redis il sistema scala
// automaticamente al layer file senza errori.
define('CHATBOT_REDIS_HOST',     '127.0.0.1');
define('CHATBOT_REDIS_PORT',     6379);
define('CHATBOT_REDIS_PASSWORD', '');           // stringa vuota = nessuna auth
define('CHATBOT_REDIS_PREFIX',   'chatbot:');   // prefisso chiavi Redis
define('CHATBOT_REDIS_TTL',      1800);         // TTL sessione in secondi (30 minuti)

// ============================================================
// Layer 3 — MySQL persistente (opzionale, disabilitato se DSN vuoto)
// ============================================================
// DSN esempio: 'mysql:host=localhost;dbname=mio_db;charset=utf8mb4'
// La tabella chatbot_conversations viene creata automaticamente al primo avvio.
define('CHATBOT_MYSQL_DSN',      'mysql:host=localhost;dbname=chatbot_db;charset=utf8mb4');           // lasciare vuoto per disabilitare
define('CHATBOT_MYSQL_USER',     'chatbot_usr');
define('CHATBOT_MYSQL_PASSWORD', '');
define('CHATBOT_MYSQL_TABLE',    'chatbot_conversations');


require_once __DIR__ . '/basic_config.php';

?>