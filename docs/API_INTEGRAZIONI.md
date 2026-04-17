# Integrazione API Esterne - Chatbot chatbot

Documento tecnico su configurazione, flussi e gestione errori per le API esterne.

---

## Indice

1. Claude (Anthropic) - AI Engine
2. Doofinder - Ricerca Prodotti
3. chatbot.org / Oct8ne - API Ordini
4. Rate Limiting Provider
5. Costanti Configurazione
6. Storage Sessioni (Redis, File, MySQL)
7. Moduli Admin

---

## 1. Claude (Anthropic) - AI Engine

### Scopo
Claude e il motore conversazionale del chatbot. Riceve la cronologia e restituisce JSON strutturato.

### Credenziali
- `ANTHROPIC_API_KEY` in `basic_config.php`
- `CLAUDE_MODEL` in `basic_config.php`
- `CHATBOT_AI_PROVIDER=claude`

### Endpoint
`POST https://api.anthropic.com/v1/messages`

### Headers
- `Content-Type: application/json`
- `x-api-key: {ANTHROPIC_API_KEY}`
- `anthropic-version: 2023-06-01`

### Retry
`ClaudeClient::claude_http_call()` esegue 3 tentativi con backoff esponenziale:
- tentativo 1: 1 s x 2^0 = 1 s
- tentativo 2: 1 s x 2^1 = 2 s
- tentativo 3: fallimento definitivo

Codici con retry: `429`, `503`, `529`, `500`.

### Errori user-friendly
- `auth_error`: problema configurazione servizio
- `permission_error`: problema configurazione servizio
- `bad_request`: errore tecnico, riformulare richiesta
- `local_rate_limit`: troppe richieste, riprovare tra pochi secondi
- `exhausted`: server AI sovraccarichi, riprovare piu tardi

Classe: `classes/ClaudeClient.php`

---

## 2. Doofinder - Ricerca Prodotti

### Scopo
Se Claude restituisce `"Keyword search"`, il backend chiama Doofinder e inietta i risultati in conversazione.

### Configurazione
- `DOOFINDER_TOKEN` in `basic_config.php`
- `DOOFINDER_SEARCH_URL` in config/default `chat_api.php`

Endpoint default:
`https://eu1-search.doofinder.com/6/1df6a9e0beefea8b4faa2986d514b197/_search`

### Chiamata
`GET {DOOFINDER_SEARCH_URL}?query={keyword_urlencoded}`

Header:
`Authorization: Token {DOOFINDER_TOKEN}`

Timeout: 8 secondi.

### Mapping risultato
Max 3 prodotti, campi normalizzati:
- `title -> Title`
- `best_price -> Price`
- `link -> Url`
- `id -> Reference`
- `description -> Description`
- immagini: primo tra `image_link`, `image_url`, `link_image`, `image`

Classe: `classes/ProductSearchService.php`

---

## 3. chatbot.org / Oct8ne - API Ordini

### Scopo
Se Claude restituisce `"order id"`, il backend recupera dati ordine via API Oct8ne.

### Configurazione
- `ORDER_API_TOKEN`
- `BASE_API_URL`
- `ORDER_API_URL`
- `ORDERS_API_URL`
- `CHECKSESSION_API_URL`

### Endpoint
- Dettaglio ordine:
  `GET {ORDER_API_URL}?reference={orderId}&apiToken={ORDER_API_TOKEN}&locale=it-IT&currency=EUR`
- Lista ordini:
  `GET {ORDERS_API_URL}?customerEmail={email}&apiToken={ORDER_API_TOKEN}&locale=it-IT&currency=EUR`
- Validazione sessione:
  `GET {CHECKSESSION_API_URL}?customer_email={email}&customer_session_id={session_md5}&order_id={orderId}`

### Flusso sicurezza ordini
1. Validazione email lato backend
2. Se presente `customer_session`, chiamata `checkSession`
3. Chiamata `getOrderDetails`
4. Sanitizzazione campi (`htmlspecialchars`)
5. Iniezione dati in conversazione

I dettagli dell'ordine sono restituiti solo quando l'utente e autenticato e la coppia `order id`/`order email` e coerente.

### Test mode ordini
Con `CHATBOT_ALLOW_TEST_MODE=true` e `test_mode=true` nel payload:
- `getOrderDetails` e `getOrders` usano fixture locali (`getTestOrders()`)
- non viene chiamata API reale

Classe: `classes/OrderService.php`

---

## 4. Rate Limiting Provider

`TrafficLimiter` applica bucket separati:
- `provider:claude`
- `provider:doofinder`
- `ip:{sha256}`
- `global:all_requests`

Limiti configurabili:
- `CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS`
- `CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS`
- `CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS`
- `CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS`

Comportamento:
- Claude oltre limite: risposta locale user-friendly, senza chiamata API
- Doofinder oltre limite: ricerca saltata, risposta senza blocco HTTP lato utente

---

## 5. Costanti Configurazione

Costanti principali:
- Claude: `ANTHROPIC_API_KEY`, `CLAUDE_MODEL`, `CHATBOT_AI_PROVIDER`
- Doofinder: `DOOFINDER_TOKEN`, `DOOFINDER_SEARCH_URL`
- Ordini: `ORDER_API_TOKEN`, `BASE_API_URL`, `ORDER_API_URL`, `ORDERS_API_URL`, `CHECKSESSION_API_URL`
- Rate limit: costanti `CHATBOT_*_RATE_LIMIT_*`

Nota sicurezza:
`basic_config.php` e `chatbot_config.php` non devono essere esposti in web root.

---

## 6. Storage Sessioni (Redis, File, MySQL)

### Panoramica
`SessionManager` orchestra 3 layer:
1. Redis (primario)
2. FileStorage (fallback e backup write-through)
3. MySQL (persistenza analitica)

### Redis
- sessioni su `getty:sess:{sessionId}`
- rate limit su `getty:rl:*`, `getty:rl_h:*`, `getty:rl_d:*`

### File
Directory:
```text
{CHATBOT_SESSION_DIR}/
|-- {sessionId}.json
`-- rate_limits/
    `-- {sha256_ip}.json
```
Permessi consigliati: directory `0700`, file `0600`.

### MySQL
Tabella: `chatbot_conversations`

Flush event-driven:
- `first_message`
- `periodic`
- `order_found`
- `conversation_end`
- `redis_failure`

---

## 7. Moduli Admin

Gruppi menu:
- Operations
- Platform
- Governance

Moduli principali:
- `index.php`: overview + health check
- `dashboard.php`: ricerca conversazioni MySQL
- `redis_admin.php`: diagnostica Redis
- `load_test.php`: test di carico, preset, export, soglie pass/fail
- `logs.php`: viewer log
- `prompt.php`: visualizzazione prompt AI
- `cors_origins.php`: origini CORS
- `keys.php`: configurazione chiavi con masking
