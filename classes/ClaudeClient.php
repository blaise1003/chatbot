<?php

namespace Chatbot;

class ClaudeClient implements AiClientInterface
{
    private $apiKey;
    private $model;
	private $trafficLimiter;

	public function __construct($apiKey, $model, TrafficLimiter $trafficLimiter = null) {
        $this->apiKey = $apiKey;
        $this->model = $model;
		$this->trafficLimiter = $trafficLimiter;
    }

    public function ask(array $historyContext) {
		// Claude vuole il system prompt separato dai messaggi user/assistant
		$system   = "";
		$messages = [];

		foreach ($historyContext as $msg) {
			if ($msg['role'] === 'system') {
				if (empty($messages)) {
					$system = $msg['content'];
				} else {
					$messages[] = ["role" => "user", "content" => "[Dati API ricevuti dal sistema - usa questi per rispondere]: " . $msg['content']];
				}
			} else {
				$messages[] = $msg;
			}
		}

		// Claude richiede che i ruoli alternino user/assistant e inizino con user.
		$clean_messages = [];
		foreach ($messages as $msg) {
			$last = count($clean_messages) - 1;
			if ($last >= 0 && $clean_messages[$last]['role'] === $msg['role']) {
				$clean_messages[$last]['content'] .= "\n\n" . $msg['content'];
			} else {
				$clean_messages[] = $msg;
			}
		}

		if (empty($clean_messages) || $clean_messages[0]['role'] !== 'user') {
			return ['_ok' => true, 'reply' => "Ciao! Sono l'assistente virtuale, come posso aiutarti? ??"];
		}

		$payload = [
			"model"      => $this->model,
			"max_tokens" => 2048,
			"system"     => $system,
			"messages"   => $clean_messages
		];

		// --- Chiamata principale con retry automatico ---
		$result = $this->requestMessagesApi($payload);

		// echo "--------------------------------------------\n";
		// echo "Payload inviato a Claude:\n";
		// var_dump($payload);
		// echo "Risposta ricevuta da Claude:\n";
		// var_dump($result);

		if (!$result['success']) {
			// Mappa gli errori HTTP a messaggi user-friendly
			$friendly_messages = [
				'auth_error'       => "C'è un problema di configurazione del servizio. Contatta l'assistenza.",
				'permission_error' => "C'è un problema di configurazione del servizio. Contatta l'assistenza.",
				'bad_request'      => "Scusa, c'è stato un errore tecnico. Prova a riformulare la richiesta.",
				'client_error'     => "Scusa, c'è stato un errore. Riprova tra poco! ??",
				'local_rate_limit' => "In questo momento ci sono molte richieste. Riprova tra qualche secondo! ??",
				'exhausted'        => "I server AI sono al momento sovraccarichi. Ho riprovato più volte ma senza successo. Riprova tra qualche minuto! ?",
			];
			$msg = isset($friendly_messages[$result['error']]) 
				? $friendly_messages[$result['error']] 
				: "Scusa, c'è stato un problema tecnico. Riprova tra poco! ??";

			return ['_ok' => false, 'reply' => $msg];
		}

		$res_json = $result['data'];

		// Estrae il testo dalla risposta Claude
		if (isset($res_json['content'][0]['text'])) {
			$text = trim($res_json['content'][0]['text']);

			// Rimuove eventuali blocchi di codice markdown
			$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
			$text = preg_replace('/\s*```$/', '', $text);
			$text = trim($text);

			$parsed = $this->parseClaudeResponse($text);


			// ---- RETRY AUTOMATICO (formato JSON) ----
			if ($parsed === null && !empty($text)) {
				$retry_messages   = $clean_messages;
				$retry_messages[] = ["role" => "user",      "content" => "Attenzione: devi rispondere ESCLUSIVAMENTE con un oggetto JSON valido."];
				$retry_messages[] = ["role" => "assistant", "content" => $text];
				$retry_messages[] = ["role" => "user",      "content" => "La tua risposta precedente non era JSON. Prova di nuovo rispondendo SOLO con il JSON."];

				$retry_payload             = $payload;
				$retry_payload['messages'] = $retry_messages;
				$retry_payload['max_tokens'] = 1024;

				$retry_result = $this->requestMessagesApi($retry_payload);

				if ($retry_result['success'] && isset($retry_result['data']['content'][0]['text'])) {
					$retry_text = trim($retry_result['data']['content'][0]['text']);
					$retry_text = preg_replace('/^```(?:json)?\s*/i', '', $retry_text);
					$retry_text = preg_replace('/\s*```$/', '', $retry_text);
					$retry_text = trim($retry_text);
					$parsed     = $this->parseClaudeResponse($retry_text);

				}
			}

			if ($parsed !== null) { // Parsing riuscito (sia al primo tentativo che dopo retry)
				$parsed['_ok'] = true;
				$result = $parsed;
			} else {
				// Risposta API non contiene JSON valido dopo retry
				Logger::logError("parseClaudeResponse", "[Claude] Risposta non JSON dopo retry: " . $text);
				$result = ['_ok' => false, 'reply' => "Scusa, non ho capito bene. Potresti riformulare la richiesta? ??"];
			}
		} else {
			// Risposta API non contiene content � errore generico
			Logger::logError("parseClaudeResponse", "[Claude] Risposta senza content: " . json_encode($res_json));
			$result = ['_ok' => false, 'reply' => "Scusa, i server dell'assistente non hanno risposto correttamente. Riprova tra poco! ??"];
		}

        return $result;
    }

    private function requestMessagesApi(array $payload) {
		if (
			$this->trafficLimiter !== null
			&& $this->trafficLimiter->isExceeded(
				'provider:claude',
				\CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS,
				\CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS
			)
		) {
			Logger::logError("requestMessagesApi", "[Claude] Rate limit locale superato: provider:claude");
			return ['success' => false, 'error' => 'local_rate_limit', 'message' => 'Local provider quota exceeded', 'http_code' => 429];
		}

		$timeout = 30; // Timeout di 30 secondi per la chiamata API
        $result = $this->claude_http_call($payload, $this->apiKey, $timeout);
        return $result;
    }

    private function parseClaudeResponse(string $text) {
        $parsed = json_decode($text, true);
        if ($parsed !== null) {
            return $parsed;
        }

        preg_match('/\{.*\}/s', $text, $matches);
        if (!empty($matches)) {
            return json_decode($matches[0], true);
        }

        return null;
    }

	// ---- FUNZIONE: Chiamata HTTP a Claude con gestione errori completa ----
	// Gestisce: 429 (rate limit), 529/503 (overloaded), 500 (server error), 4xx, 5xx
	// Implementa retry con backoff esponenziale per errori transitori
	private function claude_http_call($payload, $api_key, $timeout = 30) {
		$max_retries    = 3;
		$base_delay_ms  = 1000; // 1 secondo iniziale
		$last_error     = '';
		$last_http_code = 0;

		for ($attempt = 0; $attempt < $max_retries; $attempt++) {
			$ch = curl_init("https://api.anthropic.com/v1/messages");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"Content-Type: application/json",
				"x-api-key: " . $api_key,
				"anthropic-version: 2023-06-01"
			]);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

			$response  = curl_exec($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_err  = curl_errno($ch);
			curl_close($ch);

			// Errore di rete (timeout, DNS, connessione rifiutata)
			if ($curl_err) {
				$last_error = "Errore di rete (curl errno: $curl_err)";
				Logger::logError("claude_http_call", "[Claude] Tentativo " . ($attempt+1) . "/$max_retries - Errore CURL: $curl_err");
				// Ritenta dopo backoff
				usleep($base_delay_ms * pow(2, $attempt) * 1000);
				continue;
			}

			// === SUCCESSO (200) ===
			if ($http_code === 200) {
				return ['success' => true, 'data' => json_decode($response, true), 'http_code' => 200];
			}

			// === ERRORI RITENTABILI ===
			$res_body = json_decode($response, true);
			$err_type = isset($res_body['error']['type']) ? $res_body['error']['type'] : '';
			$err_msg  = isset($res_body['error']['message']) ? $res_body['error']['message'] : '';

			// 429 = Rate Limit Anthropic (troppe richieste alla chiave API)
			if ($http_code === 429) {
				$last_error = "Rate limit API Anthropic raggiunto";
				Logger::logError("claude_http_call", "[Claude] Tentativo " . ($attempt+1) . " - HTTP 429 Rate Limit. Attendo backoff...");
				// Cerca header Retry-After, altrimenti backoff esponenziale
				$delay = $base_delay_ms * pow(2, $attempt + 1); // 2s, 4s, 8s
				usleep($delay * 1000);
				continue;
			}

			// 529 o 503 = Server Sovraccarico (overloaded_error)
			if ($http_code === 529 || $http_code === 503 || $err_type === 'overloaded_error') {
				$last_error = "Server AI sovraccarico (HTTP $http_code)";
				Logger::logError("claude_http_call", "[Claude] Tentativo " . ($attempt+1) . " - Overloaded ($http_code). Riprovo...");
				$delay = $base_delay_ms * pow(2, $attempt + 1);
				usleep($delay * 1000);
				continue;
			}

			// 500 = Errore interno Anthropic
			if ($http_code === 500) {
				$last_error = "Errore interno server AI (HTTP 500)";
				Logger::logError("claude_http_call", "[Claude] Tentativo " . ($attempt+1) . " - HTTP 500 Internal Error");
				usleep($base_delay_ms * pow(2, $attempt) * 1000);
				continue;
			}

			// === ERRORI NON RITENTABILI ===

			// 401 = Chiave API invalida
			if ($http_code === 401) {
				Logger::logError("claude_http_call", "[Claude] CRITICO - HTTP 401 Chiave API non valida!");
				return ['success' => false, 'error' => 'auth_error', 'message' => 'Chiave API non valida', 'http_code' => 401];
			}

			// 403 = Permessi insufficienti
			if ($http_code === 403) {
				Logger::logError("claude_http_call", "[Claude] CRITICO - HTTP 403 Permesso negato");
				return ['success' => false, 'error' => 'permission_error', 'message' => 'Permesso negato dall\'API', 'http_code' => 403];
			}

			// 400 = Richiesta malformata
			if ($http_code === 400) {
				Logger::logError("claude_http_call", "[Claude] HTTP 400 - Richiesta non valida: $err_msg");
				return ['success' => false, 'error' => 'bad_request', 'message' => $err_msg, 'http_code' => 400];
			}

			// Qualsiasi altro 4xx
			if ($http_code >= 400 && $http_code < 500) {
				Logger::logError("claude_http_call", "[Claude] HTTP $http_code - Errore client: $err_msg");
				return ['success' => false, 'error' => 'client_error', 'message' => "Errore $http_code: $err_msg", 'http_code' => $http_code];
			}

			// Qualsiasi altro 5xx (ritentabile)
			if ($http_code >= 500) {
				$last_error = "Errore server (HTTP $http_code)";
				Logger::logError("claude_http_call", "[Claude] Tentativo " . ($attempt+1) . " - HTTP $http_code: $err_msg");
				usleep($base_delay_ms * pow(2, $attempt) * 1000);
				continue;
			}

			// Codice HTTP sconosciuto
			$last_error = "Risposta inaspettata (HTTP $http_code)";
			Logger::logError("claude_http_call", "[Claude] HTTP $http_code sconosciuto: $response");
			break;
		}

		// Tutti i tentativi esauriti
		Logger::logError("claude_http_call", "[Claude] ESAURITI $max_retries tentativi. Ultimo errore: $last_error");
		return ['success' => false, 'error' => 'exhausted', 'message' => $last_error, 'http_code' => $last_http_code];
	}

}
