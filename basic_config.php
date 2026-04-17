<?php

require_once __DIR__ . '/classes/ConfigLoader.php';

$chatbotBasicBootstrapDefaults = [
  'CHATBOT_MYSQL_DSN' => defined('CHATBOT_MYSQL_DSN') ? (string) constant('CHATBOT_MYSQL_DSN') : 'mysql:host=localhost;dbname=chatbot_db;charset=utf8mb4',
  'CHATBOT_MYSQL_USER' => defined('CHATBOT_MYSQL_USER') ? (string) constant('CHATBOT_MYSQL_USER') : 'chatbot_usr',
  'CHATBOT_MYSQL_PASSWORD' => defined('CHATBOT_MYSQL_PASSWORD') ? (string) constant('CHATBOT_MYSQL_PASSWORD') : 'h3~F[N3kU*f]7~laxbj',
];

\Chatbot\ConfigLoader::bootstrap($chatbotBasicBootstrapDefaults, 'configuration');

// ============================================================
// chatbot_config.php — File di configurazione SEGRETO
// ⚠️  QUESTO FILE NON VA CARICATO in public_html!
//     Va caricato UN LIVELLO SOPRA, in /home/tuoutente/
//     In questo modo non è mai accessibile via browser.
// ============================================================

// La tua chiave API di Anthropic Claude
// Incolla qui la tua chiave che inizia con "sk-ant-..."
\Chatbot\ConfigLoader::define('ANTHROPIC_API_KEY', '', 'string');

// Modello Claude da usare (modelli attivi al 31/03/2026):
// "claude-haiku-4-5-20251001"    → Veloce ed economico (consigliato per chatbot) ✅
// "claude-sonnet-4-5-20250929"   → Bilanciato qualità/costo
// "claude-sonnet-4-6"            → Più potente, sempre aggiornato all'ultima versione
// "claude-opus-4-6"              → Il più potente (costa di più)
\Chatbot\ConfigLoader::define('CLAUDE_MODEL', '', 'string');

// Provider AI attivo (oggi supportato: "claude")
\Chatbot\ConfigLoader::define('CHATBOT_AI_PROVIDER', 'claude', 'string');

// Token servizi esterni
\Chatbot\ConfigLoader::define('DOOFINDER_TOKEN', '', 'string');
\Chatbot\ConfigLoader::define('ORDER_API_TOKEN', '', 'string');
\Chatbot\ConfigLoader::define('BASE_API_URL', '', 'string');
\Chatbot\ConfigLoader::define('ORDER_API_URL', '', 'string');
\Chatbot\ConfigLoader::define('ORDERS_API_URL', '', 'string');
\Chatbot\ConfigLoader::define('CHECKSESSION_API_URL', '', 'string');
\Chatbot\ConfigLoader::define('LOGIN_URL', '', 'string');

$aiPromptDefault = '';

\Chatbot\ConfigLoader::define('AI_PROMPT', $aiPromptDefault, 'string');

// Sicurezza runtime
\Chatbot\ConfigLoader::define('CHATBOT_ALLOWED_ORIGINS', [], 'array');

// Per-IP ingress based on analytics
\Chatbot\ConfigLoader::define('CHATBOT_RATE_LIMIT_MAX_REQUESTS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_RATE_LIMIT_WINDOW_SECONDS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_HANDOFF_POLL_RATE_LIMIT_MAX_REQUESTS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_HANDOFF_POLL_RATE_LIMIT_WINDOW_SECONDS', 0, 'int');
\Chatbot\ConfigLoader::define('CHATBOT_HANDOFF_OPERATOR_TYPING_TTL_SECONDS', 10, 'int');


?>