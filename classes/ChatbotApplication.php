<?php

namespace Chatbot;

class ChatbotApplication
{
    private $requestGuard;
    private $sessionManager;
    private $aiClient;
    private $productSearchService;
    private $orderService;
    private $htmlSanitizer;
    private $trafficLimiter;

    /** @var bool Set to true when an order is successfully retrieved in this request */
    private $orderFoundInThisRequest = false;

    public function __construct(
        RequestGuard $requestGuard,
        SessionManager $sessionManager,
        AiClientInterface $aiClient,
        ProductSearchService $productSearchService,
        OrderService $orderService,
        HtmlSanitizer $htmlSanitizer,
        TrafficLimiter $trafficLimiter = null
    ) {
        $this->requestGuard = $requestGuard;
        $this->sessionManager = $sessionManager;
        $this->aiClient = $aiClient;
        $this->productSearchService = $productSearchService;
        $this->orderService = $orderService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->trafficLimiter = $trafficLimiter;
    }

    public function run()
    {
		// Fase 1: Validazione e sicurezza della richiesta
		$this->requestGuard->enforceBearerToken();
        $this->requestGuard->applyCorsPolicy();
        $this->requestGuard->enforceRequestMethod();
        $this->requestGuard->applySecurityHeaders();
        $this->sessionManager->bootStorage();

		// Fase 2: Recupero della richiesta
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            \json_response([
                'reply' => 'Richiesta non valida.',
                'options' => []
            ], 400);
        }

		// Sanitizzazione e validazione dei dati in ingresso
		$customerSessionId = isset($data['customerSessionId']) ? trim($data['customerSessionId']) : '';
		$customerId = isset($data['customerId']) ? trim($data['customerId']) : '';
        $sessionId = $this->sanitizeSessionId(isset($data['session_id']) ? $data['session_id'] : $this->defaultSessionId());
        $userMessage = isset($data['message']) ? trim((string) $data['message']) : '';
        $testMode = !empty($data['test_mode']) && $this->requestGuard->isTestModeAllowed();

        if ($userMessage === '' || mb_strlen($userMessage) > 2000) {
            \json_response([
                'reply' => 'Messaggio non valido o troppo lungo.',
                'options' => []
            ], 400);
        }

		// Fase 3: Caricamento cronologia, generazione risposta e gestione azioni
        $history = $this->sessionManager->loadHistory($sessionId);
		$prompt = $this->getSystemPrompt();
        if (empty($history)) {
            $history = [
                ['role' => 'system', 'content' => $prompt . "\n\nOggi è " . date('Y-m-d H:i:s')]
            ];
        }

        $history[] = ['role' => 'user', 'content' => $userMessage];
        $parsed = $this->aiClient->ask($history);

		// Se ask_claude ha ritornato un errore HTTP non recuperabile, rispondi subito
		if (isset($parsed['_ok']) && $parsed['_ok'] === false) {
			// Salva comunque la sessione per non perdere il messaggio utente
			echo json_encode([
				"reply"   => $parsed['reply'],
				"options" => []
			], JSON_UNESCAPED_UNICODE);
			exit;
		}

        $parsed = $this->fixMissingActionKeys($history, $parsed);
        $parsed = $this->processActions($history, $parsed, $customerSessionId, $testMode);
        $parsed = $this->sanitizeParsedResponse($parsed);

		// Risposta finale in memoria, ora salva la cronologia con la risposta e rispondi al client
        $history[] = ['role' => 'assistant', 'content' => json_encode($parsed, JSON_UNESCAPED_UNICODE)];

		// Limita cronologia a 40 messaggi
        if (count($history) > 40) {
            $systemMessage = array_shift($history);
            $history = array_slice($history, -38);
            array_unshift($history, $systemMessage);
        }

        $this->sessionManager->saveHistory($sessionId, $history, $customerId);

        // MySQL flush: event-driven triggers
        if ($this->orderFoundInThisRequest) {
            $this->sessionManager->flushToDatabase($sessionId, $history, $customerId, 'order_found');
        } elseif (isset($parsed['options']) && is_array($parsed['options']) && count($parsed['options']) === 0) {
            $this->sessionManager->flushToDatabase($sessionId, $history, $customerId, 'conversation_end');
        }
		
        echo json_encode([
            'reply' => isset($parsed['reply']) ? $parsed['reply'] : 'Ehm, non ho capito bene. Puoi ripetere? ??',
            'options' => $parsed,
			'history' => $history
        ], JSON_UNESCAPED_UNICODE);
    }

    private function sanitizeSessionId($sessionId)
    {
        $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $sessionId);
        return $sessionId === '' ? $this->defaultSessionId() : $sessionId;
    }

	private function defaultSessionId()
    {
        return uniqid('session_'.date('Ymd_His_'), true);
    }
    private function sanitizeParsedResponse(array $parsed)
    {
        if (isset($parsed['reply']) && is_string($parsed['reply'])) {
            $parsed['reply'] = $this->htmlSanitizer->sanitize($parsed['reply']);
        }

        return $parsed;
    }

    private function fixMissingActionKeys(array $history, array $parsed)
    {
        $replyPlain = strtolower(strip_tags(isset($parsed['reply']) ? $parsed['reply'] : ''));
        $hasSearchReply = strpos($replyPlain, 'cerco ') !== false
            || strpos($replyPlain, 'sto cercando') !== false
            || strpos($replyPlain, 'cerco subito') !== false
            || strpos($replyPlain, 'trovo subito') !== false;
        $hasOrderReply = strpos($replyPlain, 'un momento') !== false
            || strpos($replyPlain, 'sto recuperando') !== false
            || strpos($replyPlain, 'sto cercando l\'ordine') !== false;

        $missingSearchKey = $hasSearchReply && !isset($parsed['Keyword search']) && !isset($parsed['order id']);
        $missingOrderKey = $hasOrderReply && !isset($parsed['order id']);

        if (!$missingSearchKey && !$missingOrderKey) {
            return $parsed;
        }

        $fixHistory = $history;
        $fixHistory[] = ['role' => 'assistant', 'content' => json_encode($parsed, JSON_UNESCAPED_UNICODE)];

        if ($missingSearchKey) {
            $fixMessage = 'ERRORE: hai detto che stai cercando un prodotto ma non hai incluso il campo "Keyword search" nel JSON. Rispondi di nuovo con lo stesso intento ma includendo OBBLIGATORIAMENTE la chiave "Keyword search" con la parola chiave da cercare.';
        } else {
            $fixMessage = 'ERRORE: hai detto che stai recuperando l\'ordine ma non hai incluso il campo "order id" nel JSON. Rispondi di nuovo includendo OBBLIGATORIAMENTE la chiave "order id" con il numero ordine.';
        }

        $fixHistory[] = ['role' => 'user', 'content' => $fixMessage];
        $fixed = $this->aiClient->ask($fixHistory);

        if (isset($fixed['Keyword search']) || isset($fixed['order id'])) {
            return $fixed;
        }

        return $parsed;
    }

    private function processActions(array &$history, array $parsed, string $customerSession, $testMode) {
		Logger::logDebug("processActions", "Parsed response before processing: " . json_encode($parsed, JSON_UNESCAPED_UNICODE)); // DEBUG LOG

        $iterations = 0;

        while ((isset($parsed['Keyword search']) || isset($parsed['order id'])) && $iterations < 2) {
			Logger::logDebug("processActions", "Iterazione $iterations - Parsed response: " . json_encode($parsed, JSON_UNESCAPED_UNICODE)); // DEBUG LOG

            $iterations++;
            $systemUpdate = '';

            if (isset($parsed['Keyword search'])) {
                $keyword = trim($parsed['Keyword search']);
				Logger::logDebug("processActions", "Esegue ricerca per keyword: '$keyword' - parsed : " . json_encode($parsed, JSON_UNESCAPED_UNICODE)); // DEBUG LOG
                if ($keyword !== '') {
                    $doofinderExceeded = $this->trafficLimiter !== null
                        && $this->trafficLimiter->isExceeded(
                            'provider:doofinder',
                            \CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS,
                            \CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS
                        );

                    if ($doofinderExceeded) {
                        $systemUpdate = "Ricerca prodotti temporaneamente limitata per alto traffico. Chiedi all'utente di riprovare tra poco.";
                    } else {
                        $products = $this->productSearchService->getProductsByKeyword($keyword);
                        $systemUpdate = !empty($products)
                            ? "Risultati per '$keyword': " . json_encode($products, JSON_UNESCAPED_UNICODE)
                            : "Nessun prodotto trovato per '$keyword'. Chiedi all'utente di provare una ricerca diversa.";
                    }

					Logger::logDebug("processActions", "Eseguita ricerca prodotti per keyword: '$keyword'. Risultati: " . $systemUpdate); // DEBUG LOG
                }
                unset($parsed['Keyword search']);
            }

            if (isset($parsed['order id'])) {
                $orderId = preg_replace('/[^0-9]/', '', trim($parsed['order id']));
				$omail = isset($parsed['order email']) ? trim($parsed['order email']) : '';
                $osession = isset($customerSession) ? trim($customerSession) : '';
                if ($orderId !== '') {
                    $systemUpdate = $this->buildOrderSystemUpdate($orderId, $omail, $osession, $history, $testMode);
                }
                unset($parsed['order id']);
				unset($parsed['order email']);
            }

            if ($systemUpdate === '') {
                break;
            }

            $history[] = ['role' => 'assistant', 'content' => json_encode($parsed, JSON_UNESCAPED_UNICODE)];
            $history[] = ['role' => 'system', 'content' => $systemUpdate];
            $parsed = $this->aiClient->ask($history);

            $lastContent = isset($history[count($history) - 2]['content']) ? $history[count($history) - 2]['content'] : '';
            if (isset($parsed['Keyword search']) && strpos($lastContent, 'Keyword search') !== false) {
                unset($parsed['Keyword search']);
            }
			if (isset($parsed['order id']) && strpos($lastContent, 'order id') !== false) {
                unset($parsed['order id']);
            }
        }

        return $parsed;
    }

    private function buildOrderSystemUpdate(string $orderId, string $customerEmail = '', string $customerSession = '', array $history, $testMode) {
		if ($customerEmail === '' && $customerSession !== '') {
			$customerEmail = $this->orderService->findLatestEmailInHistory($history);
		}

        if (!$testMode && $customerEmail === null) {
			Logger::logDebug("buildOrderSystemUpdate", "Nessuna email valida trovata nella cronologia per sessione $customerSession. Impossibile procedere con recupero ordine $orderId."); // DEBUG LOG
            return 'Non eseguire il recupero ordine: manca un\'email valida nella conversazione. Chiedi all\'utente di fornire anche l\'email associata all\'ordine.';
        }

        $orderInfo = $this->orderService->getOrderStatus($orderId, $customerEmail, $customerSession, $testMode);

        if ($orderInfo && (!isset($orderInfo['success']) || $orderInfo['success'] !== false)) {
            $this->orderFoundInThisRequest = true;
			Logger::logDebug("buildOrderSystemUpdate", "Ordine $orderId trovato con email $customerEmail. Dati ordine: " . json_encode($orderInfo, JSON_UNESCAPED_UNICODE)); // DEBUG LOG
            return "Informazioni sull'ordine $orderId: " . json_encode($orderInfo, JSON_UNESCAPED_UNICODE);
        }

        if ($testMode) {
			Logger::logDebug("buildOrderSystemUpdate", "Modalità TEST attiva. Simulazione risposta ordine per orderId=$orderId, email=$customerEmail"); // DEBUG LOG
            return "Impossibile trovare l'ordine $orderId. Ricorda: in modalità TEST usa i numeri 1000001, 1000002, 1000003, 1000004 o 1000005.";
        }

		Logger::logDebug("buildOrderSystemUpdate", "Ordine $orderId non trovato per email $customerEmail."); // DEBUG LOG
        return "Impossibile trovare l'ordine $orderId. Chiedi all'utente di verificare il numero ordine.";
    }

    private function getSystemPrompt() {
        return AI_PROMPT;
    }
}
