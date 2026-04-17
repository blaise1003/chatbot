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
    private $handoffManager;

    /** @var bool Set to true when an order is successfully retrieved in this request */
    private $orderFoundInThisRequest = false;

    public function __construct(
        RequestGuard $requestGuard,
        SessionManager $sessionManager,
        AiClientInterface $aiClient,
        ProductSearchService $productSearchService,
        OrderService $orderService,
        HtmlSanitizer $htmlSanitizer,
        ?TrafficLimiter $trafficLimiter = null,
        ?HandoffManager $handoffManager = null
    ) {
        $this->requestGuard = $requestGuard;
        $this->sessionManager = $sessionManager;
        $this->aiClient = $aiClient;
        $this->productSearchService = $productSearchService;
        $this->orderService = $orderService;
        $this->htmlSanitizer = $htmlSanitizer;
        $this->trafficLimiter = $trafficLimiter;
        $this->handoffManager = $handoffManager;
    }

    public function run() {
		// questo metodo viene invocato ad ogni messaggio dal widget

		// Fase 1: Validazione e sicurezza della richiesta
		$this->requestGuard->enforceBearerToken();
		$this->requestGuard->applyCorsPolicy();
		$this->requestGuard->enforceRequestMethod();
		$this->requestGuard->applySecurityHeaders();
		$this->sessionManager->bootStorage();

		// Fase 2: Recupero della richiesta
		$data = json_decode(file_get_contents('php://input'), true);
		if (!is_array($data)) {
			Logger::logError("ChatbotApplication", "Richiesta non valida: impossibile decodificare JSON in ingresso."); // DEBUG LOG
			\json_response([
				'reply' => 'Richiesta non valida.',
				'options' => []
			], 400);
		}

		// Validazione sicurezza: rilevamento injection pattern comuni
		if (!JsonSecurityValidator::validateJsonSafety($data, ['customerSessionId', 'customerId', 'session_id', 'message', 'test_mode'])) {
			Logger::logError("ChatbotApplication", "Richiesta contiene pattern sospetti di injection.");
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
            Logger::logError('ChatbotApplication', 'Messaggio utente non valido: ' . json_encode($userMessage, JSON_UNESCAPED_UNICODE));
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

		// Se il messaggio dell'utente contiene un comando per avere informazione sugli ordini o su un ordine specifico
		// bisogna controllare se il customerSessionId è settato (utente loggato) e in caso affermativo validarlo con l'API esterna prima di inviare la richiesta all'AI, altrimenti si rischia di esporre informazioni sensibili a utenti non autenticati
		// In caso di customerSessionId non valorizzato bisogna dire al cliente che deve effettuare il login per poter avere informazioni sugli ordini
		if ((strpos(strtolower($userMessage), 'ordine') !== false) || (strpos(strtolower($userMessage), 'ordini') !== false)) {
			Logger::logDebug("run", "Rilevato possibile richiesta informazioni ordine nel messaggio utente. customerSessionId: " . ($customerSessionId !== '' ? $customerSessionId : 'non fornito')); // DEBUG LOG
			if (!isset($customerSessionId) || trim($customerSessionId) === '') { // utente non loggato o sessione non fornita
				Logger::logDebug("run", "Nessun customerSessionId fornito, non è possibile procedere con la validazione della sessione cliente. Richiesta informazioni ordine da parte di utente non autenticato."); // DEBUG LOG
				$response = [
            		'reply' => 'Devi loggarti per poter ricevere informazioni sui tuoi ordini. Per favore, effettua il <a href="' . LOGIN_URL . '" target="_blank" rel="noopener noreferrer">login</a> e riprova.',
					'options' => []
				];
				if ($this->shouldIncludeHistoryInResponse()) {
					$response['history'] = $history;
				}

				$history[] = ['role' => 'assistant', 'content' => json_encode($response, JSON_UNESCAPED_UNICODE)];
				$this->sessionManager->saveHistory($sessionId, $history, $customerId);

				echo json_encode($response, JSON_UNESCAPED_UNICODE);
				exit;
			}
		}

		$history[] = ['role' => 'user', 'content' => $userMessage];

        // Human handoff: richiesta utente di intervento operatore
        if ($this->handoffManager !== null && $this->isHumanHandoffRequestedByUser($userMessage)) {
            $handoffState = $this->handoffManager->request($sessionId, 'user', 'Richiesta esplicita utente');
            $this->sessionManager->syncHandoffState($sessionId, $handoffState);
            if (!empty($handoffState['_new_request'])) {
                Logger::trackMetric('human_handoff_requested_total');
                Logger::alert(
                    'Chatbot alert: handoff richiesto',
                    'Sessione ' . $sessionId . ' ha richiesto operatore umano (utente).',
                    'handoff_requested'
                );
            }
        }

        if ($this->handoffManager !== null) {
            $currentHandoffState = $this->handoffManager->getState($sessionId);
            if (!in_array($currentHandoffState['status'], ['requested', 'claimed'], true)) {
                $autoReason = $this->detectAutoHandoffTrigger($userMessage, $history);
                if ($autoReason !== '') {
                    $autoHandoffState = $this->handoffManager->request($sessionId, 'auto', $autoReason);
                    $this->sessionManager->syncHandoffState($sessionId, $autoHandoffState);
                    if (!empty($autoHandoffState['_new_request'])) {
                        Logger::trackMetric('human_handoff_requested_total');
                        Logger::alert(
                            'Chatbot alert: handoff automatico',
                            'Sessione ' . $sessionId . ': ' . $autoReason,
                            'handoff_requested'
                        );
                    }
                }
            }
        }

        // Se handoff attivo, blocca AI e inoltra messaggio alla coda operatore
        if ($this->handoffManager !== null) {
            $handoffState = $this->handoffManager->getState($sessionId);
            if (in_array($handoffState['status'], ['requested', 'claimed'], true)) {
                $handoffState = $this->handoffManager->addWidgetMessage($sessionId, $userMessage);
                $this->sessionManager->syncHandoffState($sessionId, $handoffState);

                $handoffReply = $handoffState['status'] === 'claimed'
                    ? 'Un operatore umano sta seguendo la tua richiesta. Ti risponderà qui a breve.'
                    : 'Ho inoltrato la tua richiesta a un operatore umano. Attendi un attimo, ti risponderà qui in chat.';

                $assistantPayload = [
                    'reply' => $handoffReply,
                    'options' => [],
                    'handoff' => [
                        'status' => $handoffState['status'],
                    ],
                ];

                $history[] = ['role' => 'assistant', 'content' => json_encode($assistantPayload, JSON_UNESCAPED_UNICODE)];
                $this->sessionManager->saveHistory($sessionId, $history, $customerId);

                $response = [
                    'reply' => $handoffReply,
                    'options' => [],
                    'handoff' => [
                        'status' => $handoffState['status'],
                    ],
                ];
                if ($this->shouldIncludeHistoryInResponse()) {
                    $response['history'] = $history;
                }

                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

		$parsed = $this->aiClient->ask($history);

		// Se ask_claude ha ritornato un errore HTTP non recuperabile, rispondi subito
		if (isset($parsed['_ok']) && $parsed['_ok'] === false) {
            // Salva la sessione anche in errore AI per non perdere il messaggio utente
            $errorReply = isset($parsed['reply']) && is_string($parsed['reply'])
                ? $this->htmlSanitizer->sanitize($parsed['reply'])
                : 'Servizio temporaneamente non disponibile. Riprova tra poco.';
            $history[] = ['role' => 'assistant', 'content' => json_encode(['reply' => $errorReply], JSON_UNESCAPED_UNICODE)];
            $this->sessionManager->saveHistory($sessionId, $history, $customerId);

            $response = [
                'reply' => $errorReply,
                'options' => []
            ];
            if ($this->shouldIncludeHistoryInResponse()) {
                $response['history'] = $history;
            }

            echo json_encode($response, JSON_UNESCAPED_UNICODE);
			exit;
		}

		$parsed = $this->fixMissingActionKeys($history, $parsed);
		$parsed = $this->processActions($history, $parsed, $customerSessionId, $testMode);
        $parsed = $this->handleAiHumanHandoff($parsed, $sessionId);
		$parsed = $this->sanitizeParsedResponse($parsed);
        $conversationEnded = $this->isConversationEnded($parsed);

        $responseOptions = isset($parsed['options']) && is_array($parsed['options']) ? $parsed['options'] : [];

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
        } elseif ($conversationEnded) {
            $endSessionRaw = isset($parsed['end session']) ? json_encode($parsed['end session'], JSON_UNESCAPED_UNICODE) : 'null';            
			$this->sessionManager->flushToDatabase($sessionId, $history, $customerId, 'conversation_end');
		}
		
		$response = [
            'reply' => isset($parsed['reply']) ? $parsed['reply'] : 'Ehm, non ho capito bene. Puoi ripetere? ??',
			'options' => $responseOptions
		];
		if ($this->shouldIncludeHistoryInResponse()) {
			$response['history'] = $history;
		}

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
        $productsHtml = '';
        if (isset($parsed['products']) && is_array($parsed['products'])) {
            $productsHtml = $this->buildProductsHtml($parsed['products']);
            unset($parsed['products']);
        }

        if (isset($parsed['reply']) && is_string($parsed['reply'])) {
            $parsed['reply'] = $this->htmlSanitizer->sanitize($parsed['reply']);
        }

        if ($productsHtml !== '') {
            $reply = isset($parsed['reply']) && is_string($parsed['reply'])
                ? trim($parsed['reply'])
                : '';
            $parsed['reply'] = $reply === ''
                ? $productsHtml
                : $reply . '<br><br>' . $productsHtml;
        }

        return $parsed;
    }

    private function buildProductsHtml(array $products)
    {
        $cards = [];
        $count = 0;

        foreach ($products as $product) {
            if (!is_array($product) || $count >= 3) {
                continue;
            }

            $title = $this->safeProductText(isset($product['Title']) ? $product['Title'] : 'Prodotto');
            if ($title === '') {
                $title = 'Prodotto';
            }

            $url = $this->safeProductUrl(isset($product['Url']) ? $product['Url'] : '');
            $image = $this->safeProductUrl(isset($product['Image']) ? $product['Image'] : '');
            $price = $this->safeProductText(isset($product['Price']) ? $product['Price'] : '');
            $description = $this->safeShortDescription(isset($product['Description']) ? $product['Description'] : '');

            $imageHtml = $image !== ''
                ? '<img src="' . $image . '" alt="' . $title . '" style="max-width:100%; height:auto; border-radius:8px; margin-bottom:8px; aspect-ratio:1/1; object-fit:contain; background:#fff; padding:4px;">'
                : '';

            $titleHtml = $url !== ''
                ? '<strong><a href="' . $url . '" target="_blank">' . $title . '</a></strong>'
                : '<strong>' . $title . '</strong>';

            $cards[] =
                '<div class="pf-product-card">'
                . $imageHtml
                . ($imageHtml !== '' ? '<br>' : '')
                . $titleHtml
                . '<br>'
                . 'Prezzo: <strong>' . $price . '</strong>'
                . '<br>'
                . '<small>' . $description . '</small>'
                . '</div>';

            $count++;
        }

        return implode('<br>', $cards);
    }

    private function safeProductText($value)
    {
        $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return htmlspecialchars(trim($decoded), ENT_QUOTES, 'UTF-8');
    }

    private function safeProductUrl($value)
    {
        $decoded = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decoded === '' || preg_match('/^https?:\/\//i', $decoded) !== 1) {
            return '';
        }

        return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }

    private function safeShortDescription($value)
    {
        $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(strip_tags($decoded));
        $plain = preg_replace('/\s+/u', ' ', $plain);
        if (!is_string($plain) || $plain === '') {
            return '';
        }

        $parts = preg_split('/(?<=[\.!?])\s+/u', $plain);
        $sentence = isset($parts[0]) && is_string($parts[0]) ? $parts[0] : $plain;
        if (mb_strlen($sentence) > 180) {
            $sentence = mb_substr($sentence, 0, 177) . '...';
        }

        return htmlspecialchars($sentence, ENT_QUOTES, 'UTF-8');
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

        $iterations = 0;

        while ((isset($parsed['Keyword search']) || isset($parsed['order id'])) && $iterations < 2) {

            $iterations++;
            $systemUpdate = '';

            if (isset($parsed['Keyword search'])) {
                $keyword = trim($parsed['Keyword search']);
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

        $customerEmail = is_string($customerEmail) ? trim($customerEmail) : '';

        if (!$testMode && ($customerEmail === '' || filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false)) {
            return 'Non eseguire il recupero ordine: manca un\'email valida nella conversazione. Chiedi all\'utente di fornire anche l\'email associata all\'ordine.';
        }

        $orderInfo = $this->orderService->getOrderStatus($orderId, $customerEmail, $customerSession, $testMode);

        if ($orderInfo && (!isset($orderInfo['success']) || $orderInfo['success'] !== false)) {
            $this->orderFoundInThisRequest = true;
            return "Informazioni sull'ordine $orderId: " . json_encode($orderInfo, JSON_UNESCAPED_UNICODE);
        }

        if ($orderInfo && (!isset($orderInfo['success']) || $orderInfo['success'] === false)) {
            $this->orderFoundInThisRequest = false;
            return "Impossibile trovare l'ordine $orderId: " . $orderInfo['error'];
        }

        if ($testMode) {
            return "Impossibile trovare l'ordine $orderId. Ricorda: in modalità TEST usa i numeri 1000001, 1000002, 1000003, 1000004 o 1000005.";
        }

        return "Impossibile trovare l'ordine $orderId. Chiedi all'utente di verificare se è loggato, il numero ordine e l'email associata all'ordine.";
    }

    private function getSystemPrompt() {
        $overridePath = $this->getPromptOverridePath();
        if ($overridePath !== '' && is_readable($overridePath)) {
            $content = @file_get_contents($overridePath);
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
        }
        return AI_PROMPT;
    }

    private function getPromptOverridePath(): string
    {
        if (defined('CHATBOT_LOGS_DIR') && is_string(\CHATBOT_LOGS_DIR) && \CHATBOT_LOGS_DIR !== '') {
            return rtrim((string) \CHATBOT_LOGS_DIR, '/\\') . '/ai_prompt_override.txt';
        }
        return '';
    }

    private function shouldIncludeHistoryInResponse()
    {
        return defined('CHATBOT_INCLUDE_RESPONSE_HISTORY') && CHATBOT_INCLUDE_RESPONSE_HISTORY;
    }

    private function isHumanHandoffRequestedByUser(string $message): bool    {
        $text = strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        $keywords = [
            'operatore',
            'umano',
            'assistenza umana',
            'parlare con un operatore',
            'agente umano',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function detectAutoHandoffTrigger(string $userMessage, array $history): string
    {
        $text = strtolower(trim($userMessage));

        $frustrationKeywords = [
            'non funziona',
            'non capisce',
            'non capisco',
            'inutile',
            'pessimo',
            'fa schifo',
            'è uno schifo',
            'non risolve',
            'non mi aiuta',
            'che schifo',
            'vergogna',
            'reclamo',
            'rimborso immediato',
            'voglio parlare con',
            'fatemi parlare',
            'incompetente',
            'non funziona niente',
            'non serve a niente',
            'stufo',
            'deluso',
        ];

        foreach ($frustrationKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'Frustrazione utente rilevata: "' . $keyword . '"';
            }
        }

        $maxExchanges = defined('CHATBOT_AUTO_HANDOFF_AFTER_EXCHANGES')
            ? (int) \CHATBOT_AUTO_HANDOFF_AFTER_EXCHANGES
            : 0;

        if ($maxExchanges > 0) {
            $userMessageCount = 0;
            foreach ($history as $msg) {
                if (isset($msg['role']) && $msg['role'] === 'user') {
                    $userMessageCount++;
                }
            }

            if ($userMessageCount >= $maxExchanges) {
                return 'Soglia scambi raggiunta: ' . $userMessageCount . ' messaggi utente';
            }
        }

        return '';
    }

    private function handleAiHumanHandoff(array $parsed, string $sessionId): array
    {
        if ($this->handoffManager === null) {
            return $parsed;
        }

        if (!isset($parsed['need agent']) || !is_string($parsed['need agent'])) {
            return $parsed;
        }

        $reason = trim($parsed['need agent']);
        $handoffState = $this->handoffManager->request($sessionId, 'ai', $reason === '' ? 'Richiesta AI' : $reason);
        $this->sessionManager->syncHandoffState($sessionId, $handoffState);

        if (!empty($handoffState['_new_request'])) {
            Logger::trackMetric('human_handoff_requested_total');
            Logger::alert(
                'Chatbot alert: handoff richiesto',
                'Sessione ' . $sessionId . ' ha richiesto operatore umano (AI).',
                'handoff_requested'
            );
        }

        $reply = isset($parsed['reply']) && is_string($parsed['reply']) ? trim($parsed['reply']) : '';
        $handoffNotice = 'Ti metto in contatto con un operatore umano che continuerà la conversazione qui in chat.';

        $parsed['reply'] = $reply === ''
            ? $handoffNotice
            : $reply . '<br><br>' . $handoffNotice;
        $parsed['options'] = [];

        return $parsed;
    }

	/**
	 * Verifica se la conversazione è terminata
	 */
	private function isConversationEnded(array $parsed): bool
	{
		if (!isset($parsed['end session'])) {
			return false;
		}

		$value = $parsed['end session'];
		if (is_bool($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return (int) $value === 1;
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			return in_array($normalized, ['true', '1', 'yes', 'y', 'si'], true);
		}

		return false;
	}

}

