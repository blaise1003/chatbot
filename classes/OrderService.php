<?php

namespace Chatbot;

class OrderService
{
    private $orderApiUrl;

	private $ordersApiUrl;
    private $checkSessionApiUrl;
    private $orderApiToken;

    public function __construct($orderApiUrl, $ordersApiUrl, $checkSessionApiUrl, $orderApiToken) {
        $this->orderApiUrl = $orderApiUrl;
        $this->ordersApiUrl = $ordersApiUrl;
        $this->checkSessionApiUrl = $checkSessionApiUrl;
        $this->orderApiToken = $orderApiToken;
    }

    public function findLatestEmailInHistory(array $history) {
        $email = null;

        foreach ($history as $entry) {
            if (!isset($entry['role'], $entry['content']) || $entry['role'] !== 'user') {
                continue;
            }

            if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $entry['content'], $matches)) {
                foreach ($matches[0] as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                        $email = strtolower($candidate);
                    }
                }
            }
        }

        return $email;
    }

    public function getOrderStatus($orderId, $order_email = '', $customer_session = '', $testMode = false) {

		// Sicurezza 2: Validazione email lato Backend
		if (empty($order_email)) {
			return ["success" => false, "error" => "Email non fornita. Richiedi all'utente l'indirizzo email prima di cercare."];
		}

        if ($this->orderApiToken === '') {
            return null;
        }

		Logger::logDebug("getOrderStatus", "Richiesta getOrderStatus per orderId=$orderId, email=$order_email, testMode=" . ($testMode ? "ON" : "OFF")); // DEBUG LOG
        if ($testMode) {
            $orders = $this->getTestOrders();
			$order = isset($orders[$orderId]) ? $orders[$orderId] : null;
            return $order;
        }

        // STEP 1: Validazione sessione/identità cliente (flusso Oct8ne)
        // Se il frontend ha passato il customer_session (= md5 hash del cliente loggato),
        // validiamo tramite checkSession che:
        //   - L'email corrisponda al cliente nel DB
        //   - Il sessionId sia autentico
        //   - L'ordine appartenga a quel cliente
        if (!empty($customer_session)) {
            $validation = $this->validateCustomerSession($order_email, $customer_session, $orderId);

			Logger::logDebug("getOrderStatus", "Risultato validazione sessione esterna con ordine=$orderId, email=$order_email: " . json_encode($validation)); // DEBUG LOG

            if (!$validation['valid']) {
                // Mappatura errori Oct8ne a messaggi user-friendly
                $err = $validation['error'];
                if (strpos($err, 'Customer not found') !== false) {
                    return ["success" => false, "error" => "L'email fornita non corrisponde a nessun account. Verifica di aver scritto l'email corretta."];
                }
                if (strpos($err, 'Invalid session') !== false) {
                    return ["success" => false, "error" => "La sessione non è valida. Assicurati di essere loggato su Pexampleit e riprova."];
                }
                if (strpos($err, 'Invalid Order') !== false) {
                    return ["success" => false, "error" => "L'ordine $orderId non corrisponde all'email fornita. Verifica i dati inseriti."];
                }
                return ["success" => false, "error" => "Validazione fallita: $err"];
            }

            Logger::logError("getOrderStatus", "[PF-Bot] checkSession OK per email=$order_email, ordine=$orderId");
        }

		$urlParams = http_build_query([
			'reference' 	=> $orderId,
			'apiToken'     	=> $this->orderApiToken,
			'locale' 		=> 'it-IT',
			'currency' 		=> 'EUR'
		]);


        $url = $this->orderApiUrl . '?' . $urlParams;

		Logger::logDebug("getOrderStatus", "Chiamata API getOrderStatus per orderId=$orderId, email=$order_email tramite URL: $url"); // DEBUG LOG
        
		$ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        $raw_data = $response ? json_decode($response, true) : null;

		Logger::logDebug("getOrderStatus", "Risposta API getOrderStatus per orderId=$orderId, email=$order_email: " . json_encode($raw_data, JSON_UNESCAPED_UNICODE)); // DEBUG LOG

		// SICUREZZA 4: SANITIZZAZIONE DATI ORDINE CONTRO XSS
		if ($raw_data && (!isset($raw_data['success']) || $raw_data['success'] !== false)) {
			if (isset($raw_data['reference'])) $raw_data['reference'] = htmlspecialchars((string)$raw_data['reference'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['date'])) $raw_data['date'] = htmlspecialchars($raw_data['date'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['total'])) $raw_data['total'] = htmlspecialchars($raw_data['total'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['labelState'])) $raw_data['labelState'] = htmlspecialchars($raw_data['labelState'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['carrier'])) $raw_data['carrier'] = htmlspecialchars($raw_data['carrier'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['trackingUrl'])) $raw_data['trackingUrl'] = htmlspecialchars($raw_data['trackingUrl'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['trackingNumber'])) $raw_data['trackingNumber'] = htmlspecialchars($raw_data['trackingNumber'], ENT_QUOTES, 'UTF-8');
			if (isset($raw_data['deliveryDate'])) $raw_data['deliveryDate'] = htmlspecialchars($raw_data['deliveryDate'], ENT_QUOTES, 'UTF-8');
			
			// Sanitizza prodotti
			if (isset($raw_data['products']) && is_array($raw_data['products'])) {
				foreach ($raw_data['products'] as &$product) {
					if (isset($product['name'])) $product['name'] = htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
					if (isset($product['quantity'])) $product['quantity'] = (int)$product['quantity'];
				}
			}

			// Sanitizza commenti
			if (isset($raw_data['comments']) && is_array($raw_data['comments'])) {
				foreach ($raw_data['comments'] as &$comment) {
					if (isset($comment['message'])) {
						$comment['message'] = htmlspecialchars($comment['message'], ENT_QUOTES, 'UTF-8');
					}
				}
			}
		}

		Logger::logDebug("getOrderStatus", "Dati finali ordine sanitizzati per orderId=$orderId, email=$order_email: " . json_encode($raw_data, JSON_UNESCAPED_UNICODE)); // DEBUG LOG
		return $raw_data;
    }

    public function getOrdersList($order_email = '', $testMode = false) {

		// Sicurezza 2: Validazione email lato Backend
		if (empty($order_email)) {
			return ["success" => false, "error" => "Email non fornita. Richiedi all'utente l'indirizzo email prima di cercare."];
		}

        if ($this->orderApiToken === '') {
            return null;
        }

        if ($testMode) {
            $orders = $this->getTestOrders();
            return $orders;
        }

		$urlParams = http_build_query([
			'customerEmail' => $order_email,
			'apiToken'     	=> $this->orderApiToken,
			'locale' 		=> 'it-IT',
			'currency' 		=> 'EUR'
		]);

        $url = $this->ordersApiUrl . '?' . $urlParams;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        $raw_data = $response ? json_decode($response, true) : null;

		$res_orders = [];
		// SICUREZZA 4: SANITIZZAZIONE DATI ORDINE CONTRO XSS
		if ($raw_data && (!isset($raw_data['success']) || $raw_data['success'] !== false)) {
			foreach ($raw_data['orders'] as $order) {
				$res_orders[] = [
					"date" => isset($order['date']) ? htmlspecialchars((string)$order['date'], ENT_QUOTES, 'UTF-8') : '',
					"reference" => isset($order['reference']) ? htmlspecialchars((string)$order['reference'], ENT_QUOTES, 'UTF-8') : '',
					"total" => isset($order['total']) ? htmlspecialchars((string)$order['total'], ENT_QUOTES, 'UTF-8') : '',
					"currency" => isset($order['currency']) ? htmlspecialchars((string)$order['currency'], ENT_QUOTES, 'UTF-8') : '',
					"labelState" => isset($order['labelState']) ? htmlspecialchars((string)$order['labelState'], ENT_QUOTES, 'UTF-8') : '',
					"deliveryDate" => isset($order['deliveryDate']) ? htmlspecialchars((string)$order['deliveryDate'], ENT_QUOTES, 'UTF-8') : ''
				];
			}
		}

		return $res_orders;
    }

    private function getTestOrders() {
        return [
            '1000001' => [
                'reference' => 1000001,
                'date' => '2026-03-28 09:15:00',
                'total' => '€ 299,00',
                'currency' => 'EUR',
                'labelState' => 'In lavorazione',
                'deliveryDate' => '',
                'carrier' => 'In attesa di affidamento',
                'trackingNumber' => '',
                'trackingUrl' => '',
                'products' => [
                    ['quantity' => 1, 'name' => 'Smart TV Samsung 55" 4K QLED']
                ],
                'comments' => [
                    ['message' => 'Il tuo ordine � stato ricevuto e sar� processato a breve. Evasione prevista entro le 15:00 del giorno lavorativo successivo.']
                ]
            ],
            '1000002' => [
                'reference' => 1000002,
                'date' => '2026-03-25 14:22:00',
                'total' => '€ 649,00',
                'currency' => 'EUR',
                'labelState' => 'Spedito',
                'deliveryDate' => '01 - 03 Aprile',
                'carrier' => 'BRT',
                'trackingNumber' => '062010999888',
                'trackingUrl' => 'http://as777.brt.it/vas/sped_det_show.hsm?Nspediz=062010999888',
                'products' => [
                    ['quantity' => 1, 'name' => 'Lavatrice Bosch Serie 6 WAT28400IT 8kg'],
                    ['quantity' => 1, 'name' => 'Kit staffa ancoraggio lavatrice']
                ],
                'comments' => [
                    ['message' => 'Ordine consegnato al corriere BRT il 30-03-2026. Consegna stimata 01-03 Aprile.'],
                    ['message' => 'Ordine in preparazione presso il magazzino.']
                ]
            ],
            '1000003' => [
                'reference' => 1000003,
                'date' => '2026-03-10 11:05:00',
                'total' => '€ 1.249,00',
                'currency' => 'EUR',
                'labelState' => 'Consegnato',
                'deliveryDate' => '15 Marzo 2026',
                'carrier' => 'GLS',
                'trackingNumber' => 'GLS88776655',
                'trackingUrl' => 'https://gls-group.eu/IT/it/ricerca-spedizioni?match=GLS88776655',
                'products' => [
                    ['quantity' => 1, 'name' => 'Frigorifero Combinato LG GBB62PZFGN 384L Classe D']
                ],
                'comments' => [
                    ['message' => 'Ordine consegnato con successo il 15-03-2026. Grazie per aver scelto example!']
                ]
            ],
            '1000004' => [
                'reference' => 1000004,
                'date' => '2026-02-20 08:50:00',
                'total' => '€ 489,00',
                'currency' => 'EUR',
                'labelState' => 'Rimborso parziale',
                'deliveryDate' => '25 Febbraio 2026',
                'carrier' => 'DHL',
                'trackingNumber' => 'DHL1234567890',
                'trackingUrl' => 'https://www.dhl.com/it-it/home/tracking.html?tracking-id=DHL1234567890',
                'products' => [
                    ['quantity' => 1, 'name' => 'Forno da incasso Whirlpool AKZ96220IX'],
                    ['quantity' => 1, 'name' => 'Kit installazione forno']
                ],
                'comments' => [
                    ['message' => 'Rimborso parziale di � 49,00 per accessorio mancante processato il 05-03-2026. Il rimborso apparir� entro 5 giorni lavorativi.'],
                    ['message' => 'Cliente ha segnalato mancanza del kit di installazione. Aperto ticket #45821.']
                ]
            ],
            '1000005' => [
                'reference' => 1000005,
                'date' => '2026-03-29 16:30:00',
                'total' => '€ 0,00',
                'currency' => 'EUR',
                'labelState' => 'Annullato',
                'deliveryDate' => '',
                'carrier' => '',
                'trackingNumber' => '',
                'trackingUrl' => '',
                'products' => [
                    ['quantity' => 2, 'name' => 'Aspirapolvere Dyson V15 Detect Absolute']
                ],
                'comments' => [
                    ['message' => 'Ordine annullato su richiesta del cliente il 29-03-2026. Il rimborso completo sar� visibile entro 3-5 giorni lavorativi.']
                ]
            ]
        ];
    }

    private function getTestOrder() {
        return [
				'reference' => 1000001,
                'date' => '2026-03-28 09:15:00',
                'total' => '€ 299,00',
                'currency' => 'EUR',
                'labelState' => 'In lavorazione',
                'deliveryDate' => '',
                'carrier' => 'In attesa di affidamento',
                'trackingNumber' => '',
                'trackingUrl' => '',
                'products' => [
                    ['quantity' => 1, 'name' => 'Smart TV Samsung 55" 4K QLED']
                ],
                'comments' => [
                    ['message' => 'Il tuo ordine � stato ricevuto e sar� processato a breve. Evasione prevista entro le 15:00 del giorno lavorativo successivo.']
                ]
            ];
    }

	private function validateCustomerSession($customer_email, $customer_session_id, $order_id = null) {

		// Costruisce la query string esattamente come l'originale Oct8ne
		$params = http_build_query([
			'customerEmail' => $customer_email,
			'sessionId'     => $customer_session_id,
			'order_id'      => $order_id ?? '',
			'apiToken'      => $this->orderApiToken
		]);

        $url = $this->checkSessionApiUrl . '?' . $params;

		Logger::logDebug("validateCustomerSession", "Validazione sessione per email=$customer_email, order_id=$order_id tramite URL: $url"); // DEBUG LOG

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json"
		]);

		$response  = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_err  = curl_errno($ch);
		curl_close($ch);

		if ($curl_err || !$response) {
			Logger::logError("validateCustomerSession", "[PF-Bot] Errore di rete (curl: $curl_err)");
			return ['valid' => false, 'error' => 'Errore di comunicazione con il server. Riprova tra poco.'];
		}

		$result = json_decode($response, true);

		if (!$result) {
			Logger::logError("validateCustomerSession", "[PF-Bot] Risposta non valida: " . substr($response, 0, 500));
			return ['valid' => false, 'error' => 'Risposta non valida dal server di validazione.'];
		}

		// Il SessionController originale ritorna {"success": true, "message": "user enabled"} se tutto ok
		// Oppure {"success": false, "error": "Invalid session: user not enabled"} o "Invalid Order for customer email"
		if (isset($result['success']) && $result['success'] === true) {
			return ['valid' => true];
		}

		$err_msg = isset($result['error']) ? $result['error'] : 'Validazione sessione fallita';
		Logger::logError("validateCustomerSession", "[PF-Bot] Validazione fallita per $customer_email / ordine $order_id: $err_msg");

		return ['valid' => false, 'error' => $err_msg];
	}
}
