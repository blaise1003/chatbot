<?php

namespace Chatbot;

class ApplicationBootstrap
{
    public static function build()
    {
        $requestGuard = new RequestGuard();

        $redisStorage = new RedisStorage(
            (string) self::constantValue('CHATBOT_REDIS_HOST', '127.0.0.1'),
            (int)    self::constantValue('CHATBOT_REDIS_PORT', 6379),
            (string) self::constantValue('CHATBOT_REDIS_PASSWORD', ''),
            (string) self::constantValue('CHATBOT_REDIS_PREFIX', 'getty:'),
            (int)    self::constantValue('CHATBOT_REDIS_TTL', 1800)
        );

        $mysqlStorage = new MySqlStorage(
            (string) self::constantValue('CHATBOT_MYSQL_DSN', ''),
            (string) self::constantValue('CHATBOT_MYSQL_USER', ''),
            (string) self::constantValue('CHATBOT_MYSQL_PASSWORD', ''),
            (string) self::constantValue('CHATBOT_MYSQL_TABLE', 'chatbot_conversations')
        );

        $limiterStorage = new FileStorage(
            (string) self::constantValue('CHATBOT_SESSION_DIR', '')
        );
        $trafficLimiter = new TrafficLimiter($limiterStorage, $redisStorage);

        $sessionManager = new SessionManager(
            (string) self::constantValue('CHATBOT_SESSION_DIR', ''),
            $requestGuard,
            $redisStorage,
            $mysqlStorage,
            $trafficLimiter
        );

        $handoffManager = new HandoffManager(
            (string) self::constantValue('CHATBOT_SESSION_DIR', ''),
            $redisStorage,
            $mysqlStorage
        );

        $productSearchService = new ProductSearchService(
            (string) self::constantValue('DOOFINDER_TOKEN', ''),
            (string) self::constantValue('DOOFINDER_SEARCH_URL', '')
        );
        $orderService = new OrderService(
            (string) self::constantValue('ORDER_API_URL', ''),
			(string) self::constantValue('ORDERS_API_URL', ''),
			(string) self::constantValue('CHECKSESSION_API_URL', ''),
            (string) self::constantValue('ORDER_API_TOKEN', '')
        );
        $aiClient = self::buildAiClient(
            (string) self::constantValue('CHATBOT_AI_PROVIDER', 'claude'),
            $trafficLimiter
        );
        $htmlSanitizer = new HtmlSanitizer();

        return new ChatbotApplication(
            $requestGuard,
            $sessionManager,
            $aiClient,
            $productSearchService,
            $orderService,
            $htmlSanitizer,
            $trafficLimiter,
            $handoffManager
        );
    }

    private static function buildAiClient($provider, TrafficLimiter $trafficLimiter)
    {
        $provider = strtolower(trim((string) $provider));

        switch ($provider) {
            case 'claude':
                return new ClaudeClient(
                    (string) self::constantValue('ANTHROPIC_API_KEY', ''),
                    (string) self::constantValue('CLAUDE_MODEL', ''),
                    $trafficLimiter
                );
			// case 'azure':
			// 	return new AzureOpenAIClient(
			// 		(string) self::constantValue('AZURE_OPENAI_ENDPOINT', ''),
			// 		(string) self::constantValue('AZURE_OPENAI_KEY', ''),
			// 		(string) self::constantValue('AZURE_OPENAI_DEPLOYMENT', '')
			// 	);
            default:
                Logger::logError("buildAiClient", '[Chatbot] Provider AI non supportato: ' . $provider . '. Fallback su claude.');
                return new ClaudeClient(
                    (string) self::constantValue('ANTHROPIC_API_KEY', ''),
                    (string) self::constantValue('CLAUDE_MODEL', ''),
                    $trafficLimiter
                );
        }
    }

    private static function constantValue($name, $fallback)
    {
        return defined($name) ? constant($name) : $fallback;
    }
}
