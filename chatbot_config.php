<?php

require_once __DIR__ . '/classes/ConfigLoader.php';

$chatbotConfigDefaults = [
	'CHATBOT_ALLOW_TEST_MODE' => false,
	'CHATBOT_SESSION_DIR' => dirname(__DIR__, 2) . '/chatbot_sessions',
	'CHATBOT_LOGS_DIR' => dirname(__DIR__, 2) . '/chatbot_logs',
	'DASHBOARD_PASSWORD' => '',
	'CHATBOT_WIDGET_TOKEN' => '',
	'CHATBOT_WIDGET_DYNAMIC_SECRET' => '',
	'CHATBOT_WIDGET_TOKEN_PREFIX' => '',
	'CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS' => 0,
	'CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK' => false,
	'CHATBOT_INCLUDE_RESPONSE_HISTORY' => false,
	'CHATBOT_HEALTH_TOKEN' => '',
	'CHATBOT_ALERT_EMAIL_ENABLED' => true,
	'CHATBOT_ALERT_EMAIL_TO' => '',
	'CHATBOT_ALERT_EMAIL_FROM' => '',
	'CHATBOT_ALERT_EMAIL_COOLDOWN_SECONDS' => 0,
	'CHATBOT_EMAIL_SEED_SECRET' => '',
	'CHATBOT_REDIS_HOST' => '',
	'CHATBOT_REDIS_PORT' => 0,
	'CHATBOT_REDIS_PASSWORD' => '',
	'CHATBOT_REDIS_PREFIX' => '',
	'CHATBOT_REDIS_TTL' => 0,
	'CHATBOT_MYSQL_DSN' => 'mysql:host=localhost;dbname=chatbot_db;charset=utf8mb4',
	'CHATBOT_MYSQL_USER' => 'chatbot_usr',
	'CHATBOT_MYSQL_PASSWORD' => 'h3~F[N3kU*f]7~laxbj',
	'CHATBOT_MYSQL_TABLE' => 'chatbot_conversations',
];

\Chatbot\ConfigLoader::bootstrap($chatbotConfigDefaults, 'configuration');

\Chatbot\ConfigLoader::define('CHATBOT_ALLOW_TEST_MODE', $chatbotConfigDefaults['CHATBOT_ALLOW_TEST_MODE'], 'bool');
\Chatbot\ConfigLoader::define('CHATBOT_SESSION_DIR', $chatbotConfigDefaults['CHATBOT_SESSION_DIR'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_LOGS_DIR', $chatbotConfigDefaults['CHATBOT_LOGS_DIR'], 'string');

\Chatbot\ConfigLoader::define('DASHBOARD_PASSWORD', $chatbotConfigDefaults['DASHBOARD_PASSWORD'], 'string');

\Chatbot\ConfigLoader::define('CHATBOT_WIDGET_TOKEN', $chatbotConfigDefaults['CHATBOT_WIDGET_TOKEN'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_WIDGET_DYNAMIC_SECRET', (string) $chatbotConfigDefaults['CHATBOT_WIDGET_DYNAMIC_SECRET'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_WIDGET_TOKEN_PREFIX', $chatbotConfigDefaults['CHATBOT_WIDGET_TOKEN_PREFIX'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS', $chatbotConfigDefaults['CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS'], 'int');
\Chatbot\ConfigLoader::define('CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK', $chatbotConfigDefaults['CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK'], 'bool');
\Chatbot\ConfigLoader::define('CHATBOT_INCLUDE_RESPONSE_HISTORY', $chatbotConfigDefaults['CHATBOT_INCLUDE_RESPONSE_HISTORY'], 'bool');

\Chatbot\ConfigLoader::define('CHATBOT_HEALTH_TOKEN', $chatbotConfigDefaults['CHATBOT_HEALTH_TOKEN'], 'string');

\Chatbot\ConfigLoader::define('CHATBOT_ALERT_EMAIL_ENABLED', $chatbotConfigDefaults['CHATBOT_ALERT_EMAIL_ENABLED'], 'bool');
\Chatbot\ConfigLoader::define('CHATBOT_ALERT_EMAIL_TO', $chatbotConfigDefaults['CHATBOT_ALERT_EMAIL_TO'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_ALERT_EMAIL_FROM', $chatbotConfigDefaults['CHATBOT_ALERT_EMAIL_FROM'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_ALERT_EMAIL_COOLDOWN_SECONDS', $chatbotConfigDefaults['CHATBOT_ALERT_EMAIL_COOLDOWN_SECONDS'], 'int');

\Chatbot\ConfigLoader::define('CHATBOT_EMAIL_SEED_SECRET', $chatbotConfigDefaults['CHATBOT_EMAIL_SEED_SECRET'], 'string');

\Chatbot\ConfigLoader::define('CHATBOT_REDIS_HOST', $chatbotConfigDefaults['CHATBOT_REDIS_HOST'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_REDIS_PORT', $chatbotConfigDefaults['CHATBOT_REDIS_PORT'], 'int');
\Chatbot\ConfigLoader::define('CHATBOT_REDIS_PASSWORD', $chatbotConfigDefaults['CHATBOT_REDIS_PASSWORD'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_REDIS_PREFIX', $chatbotConfigDefaults['CHATBOT_REDIS_PREFIX'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_REDIS_TTL', $chatbotConfigDefaults['CHATBOT_REDIS_TTL'], 'int');

\Chatbot\ConfigLoader::define('CHATBOT_MYSQL_DSN', $chatbotConfigDefaults['CHATBOT_MYSQL_DSN'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_MYSQL_USER', $chatbotConfigDefaults['CHATBOT_MYSQL_USER'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_MYSQL_PASSWORD', $chatbotConfigDefaults['CHATBOT_MYSQL_PASSWORD'], 'string');
\Chatbot\ConfigLoader::define('CHATBOT_MYSQL_TABLE', $chatbotConfigDefaults['CHATBOT_MYSQL_TABLE'], 'string');

require_once __DIR__ . '/basic_config.php';

?>