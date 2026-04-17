# Documentazione Unificata Chatbot chatbot

Documento consolidato dai file in docs.

---

## Fonte: INDEX.md

# Chatbot chatbot - Hub Documentazione

## Documentazione Centrale

Indice principale dei documenti.

- [ISTRUZIONI.md](ISTRUZIONI.md)
- [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md)
- [GUIDA_INTEGRAZIONE.md](GUIDA_INTEGRAZIONE.md)
- [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md)
- [SECURITY_REVIEW.md](SECURITY_REVIEW.md)
- [DOCUMENTAZIONE_CHATBOT.md](DOCUMENTAZIONE_CHATBOT.md)
- [DOCUMENTAZIONE_UNIFICATA.md](DOCUMENTAZIONE_UNIFICATA.md)

## Flusso Lettura Consigliato

1. [ISTRUZIONI.md](ISTRUZIONI.md)
2. [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md)
3. [SECURITY_REVIEW.md](SECURITY_REVIEW.md)
4. [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md)

---

## Fonte: ISTRUZIONI.md

# Documentazione Tecnica — Chatbot Prchatbot

**Versione:** 1.0  
**Ultimo aggiornamento:** 13-04-2026  
**Tecnologia:** PHP 8+ · Anthropic Claude · Redis · MySQL · JavaScript (IIFE)

---

## Indice

1. [Requisiti Tecnici](#1-requisiti-tecnici)
2. [Requisiti Funzionali](#2-requisiti-funzionali)
3. [Progettazione e Architettura](#3-progettazione-e-architettura)
4. [Strutturazione del Codice](#4-strutturazione-del-codice)
5. [Sicurezza delle Comunicazioni — Token Dinamici](#5-sicurezza-delle-comunicazioni--token-dinamici)
6. [Gestione dei Limiti — Rate Limiting](#6-gestione-dei-limiti--rate-limiting)

---

## 1. Requisiti Tecnici

### Server

| Requisito                        | Valore minimo | Note                                                 |
|----------------------------------|---------------|------------------------------------------------------|
| PHP                              | 8.0+          | Necessario per named arguments e union types         |
| Estensione `cURL`                | obbligatoria  | Chiamate a Claude, Doofinder, API ordini             |
| Estensione `json`                | obbligatoria  | Encoding/decoding di tutte le risposte               |
| Estensione `openssl`             | obbligatoria  | Generazione token CSRF e HMAC-SHA256                 |
| Estensione `redis` (`ext-redis`) | opzionale     | Se mancante il sistema scala su FileStorage          |
| Database MySQL                   | opzionale     | Se DSN vuoto la persistenza analitica è disabilitata |
| Scrittura su filesystem          | obbligatoria  | Directory `chatbot_sessions/` con permessi `0700`    |

### Dipendenze esterne (nessuna libreria da installare)

Il progetto è **zero-dependency**: non usa Composer né package manager. Tutte le integrazioni esterne sono chiamate HTTP via `cURL`.

| Servizio                     | Protocollo      | Obbligatorio                                      |
|------------------------------|-----------------|---------------------------------------------------|
| Anthropic Claude API         | HTTPS REST      | Sì                                                |
| Doofinder Search API         | HTTPS REST      | No (ricerca prodotti disabilitata se token vuoto) |
| chatbot.org API (Oct8ne) | HTTPS REST      | No (lookup ordini disabilitato se token vuoto)    |
| Redis                        | TCP (ext-redis) | No (fallback FileStorage)                         |
| MySQL                        | PDO             | No (fallback FileStorage)                         |

### Controllo sintassi

Prima di ogni deploy:

```bash
php -l chatbotchat/Chatbot/chat_api.php
php -l chatbotchat/Chatbot/chatbot_config.php
php -l chatbotchat/Chatbot/basic_config.php
```

### Server di sviluppo locale

```bash
php -S 127.0.0.1:8000 -t chatbotchat/Chatbot
```

---

## 2. Requisiti Funzionali

### RF-01 — Conversazione AI

Il sistema deve supportare conversazioni multi-turno con l'AI Claude. Ad ogni messaggio dell'utente il backend:

1. Carica la cronologia della sessione corrente
2. Aggiunge il messaggio utente alla cronologia
3. Invia la cronologia completa a Claude (con system prompt)
4. Riceve una risposta JSON strutturata da Claude
5. Esegue le azioni estratte dalla risposta (ricerca prodotti, lookup ordine)
6. Salva la cronologia aggiornata
7. Restituisce la risposta al widget

La cronologia è limitata a **40 messaggi** (il messaggio di sistema viene sempre preservato come primo elemento).

### RF-02 — Ricerca Prodotti (Doofinder)

Quando Claude include la chiave `"Keyword search"` nella risposta JSON, il backend esegue automaticamente una ricerca su Doofinder e inietta i risultati (max 3 prodotti) nella conversazione, poi richiama Claude per formulare la risposta finale arricchita con HTML dei prodotti.

### RF-03 — Lookup Ordini (API Prchatbot

Quando Claude include `"order id"` + `"order email"` nella risposta, il backend:

1. Valida che l'email sia presente (obbligatoria lato server)
2. Se il frontend ha passato `customerSessionId` (utente loggato via Oct8ne), valida prima l'identità tramite `checkSession`
3. Recupera il dettaglio ordine dall'API `getOrderDetails`
4. Sanitizza tutti i campi con `htmlspecialchars` contro XSS
5. Inietta i dati nella conversazione per la risposta finale di Claude

Nota: i dettagli ordine vengono mostrati solo se l'utente risulta autenticato e se i dati forniti sono coerenti (`order id` + `order email` validi e corrispondenti).

### RF-04 — Persistenza Sessioni

Le conversazioni devono sopravvivere a riavvii del server web. La sessione è identificata da un `session_id` generato lato client (widget JS) e trasmesso ad ogni richiesta. Il backend usa una strategia a tre layer (vedi §3).

### RF-05 — Widget Embeddabile

Il chatbot deve essere integrabile in qualsiasi pagina del sito tramite un singolo tag `<script>` senza dipendenze esterne. Il widget:

- È un'IIFE JavaScript autocontenuta (`pgettywidget.js`)
- Non richiede framework (Zero dipendenze — DOMPurify opzionale)
- Gestisce autenticazione, sessione e caching della cronologia internamente
- È ridimensionabile drag-to-resize dall'angolo in alto a sinistra
- Persiste le dimensioni in `localStorage`

### RF-06 — Pannello Admin

Il sistema deve fornire un pannello di amministrazione accessibile via browser per:

- Visualizzare e ricercare conversazioni salvate in MySQL
- Monitorare lo stato di Redis (connessione, chiavi, TTL)
- Consultare i log applicativi
- Verificare la configurazione runtime (health check)
- Visualizzare il system prompt attivo e le chiavi configurate (con mascheratura)

### RF-07 — Test Mode

In ambienti di sviluppo/staging il sistema deve supportare un `test_mode` che bypassa le chiamate reali alle API esterne sostituendole con fixture hardcoded. Il test mode è abilitabile solo se `CHATBOT_ALLOW_TEST_MODE=true` **e** la richiesta proviene da `localhost`/`127.0.0.1`.

---

## 3. Progettazione e Architettura

### Pattern architetturale

Il backend segue un pattern **Dependency Injection con factory centralizzata**: tutti gli oggetti vengono costruiti da `ApplicationBootstrap::build()` e iniettati nelle dipendenze. Non esiste un container DI esterno — la factory è manuale e leggibile.

```
chat_api.php
    │
    ├─ load_chatbot_config()          ← carica costanti da chatbot_config.php
    ├─ spl_autoload_register()        ← autoloader PSR-like su classes/ e classes/storage/
    │
    └─ ApplicationBootstrap::build()
           │
           ├─ RedisStorage            ← Layer 1 storage
           ├─ MySqlStorage            ← Layer 3 storage
           ├─ FileStorage             ← Layer 2 storage (sempre disponibile)
           ├─ TrafficLimiter          ← Rate limiter condiviso
           ├─ RequestGuard            ← CORS + auth + headers
           ├─ SessionManager          ← Orchestratore storage (1+2+3)
           ├─ ProductSearchService    ← Doofinder
           ├─ OrderService            ← Oct8ne / Prezzochatbot
           ├─ ClaudeClient            ← Anthropic API
           └─ ChatbotApplication      ← Handler principale
                    │
                    └─ run()          ← entry point del ciclo request/response
```

### Flusso di una richiesta

```
POST /chat_api.php
  │
  ├─[1] enforceBearerToken()     → 401 se token mancante/non valido
  ├─[2] applyCorsPolicy()        → 403 se origine non in CHATBOT_ALLOWED_ORIGINS
  ├─[3] enforceRequestMethod()   → 405 se non POST (204 se OPTIONS)
  ├─[4] applySecurityHeaders()   → X-Content-Type-Options, Cache-Control, ecc.
  ├─[5] bootStorage()
  │       └─ applyRateLimit()    → 429 per-IP o 429 globale se bucket saturo
  │
  ├─[6] Parsing JSON body        → 400 se malformato
  ├─[7] Validazione messaggio    → 400 se vuoto o > 2000 caratteri
  │
  ├─[8] loadHistory(sessionId)   → Redis → File fallback
  ├─[9] ClaudeClient::ask()
  │       ├─ checkRateLimit provider:claude  → errore locale 429
  │       ├─ HTTP POST a Anthropic (3 retry + backoff)
  │       └─ parseClaudeResponse() + retry JSON se malformato
  │
  ├─[10] processActions()
  │       ├─ "Keyword search" → TrafficLimiter provider:doofinder
  │       │                   → ProductSearchService::getProductsByKeyword()
  │       │                   → inietta prodotti → seconda chiamata Claude
  │       └─ "order id"       → OrderService::getOrderStatus()
  │                           → inietta dati ordine → seconda chiamata Claude
  │
  ├─[11] sanitizeParsedResponse()  → HtmlSanitizer su campo "reply"
  ├─[12] saveHistory()             → Redis + File + MySQL (flush condizionale)
  └─[13] json_encode() → HTTP 200
```

### Architettura storage a tre layer

```
┌──────────────────────────────────────────────┐
│              SessionManager                  │
│           (orchestratore DI)                 │
└──────┬──────────────┬───────────────┬────────┘
       │              │               │
  [LAYER 1]      [LAYER 2]       [LAYER 3]
   Redis           File            MySQL
  (primario)    (write-through   (analitica
                 + fallback)      asincrona)
```

- **Redis:** lettura/scrittura primaria. TTL sessione: 1800 s. Se `ext-redis` manca o la connessione fallisce, l'istanza si marca `available=false` e il sistema scala silenziosamente.
- **File:** sempre scritto come backup durevole quando Redis è attivo (write-through). Diventa layer primario se Redis non è disponibile. Race condition prevenute con `LOCK_EX`.
- **MySQL:** solo scritture event-driven (`INSERT ... ON DUPLICATE KEY UPDATE`). Non letto durante il ciclo normale. Trigger: `first_message`, `periodic` (ogni 5 scambi), `order_found`, `conversation_end`, `redis_failure`.

---

## 4. Strutturazione del Codice

### Mappa file

```
chatbotchat/Chatbot/
│
├── chat_api.php               Entry point HTTP. Carica config, registra autoloader,
│                              istanzia ApplicationBootstrap e chiama run().
│
├── chatbot_config.php         Costanti di configurazione (storage, auth, rate limit).
│                              ⚠ Non esporre in web root in produzione.
│
├── basic_config.php           Segreti API (Claude, Doofinder, ordini), system prompt AI,
│                              origini CORS consentite.
│                              ⚠ Non esporre in web root in produzione.
│
├── widget.php                 Endpoint di distribuzione widget. Valida il token,
│                              sostituisce i placeholder in widget.html e lo serve.
│
├── version.txt                Versione applicazione (letta dal pannello admin).
│
├── classes/
│   ├── ApplicationBootstrap.php   Factory/DI container. Costruisce tutti gli oggetti
│   │                              e li inietta nelle dipendenze.
│   │
│   ├── ChatbotApplication.php     Handler principale. Esegue il ciclo request/response:
│   │                              sicurezza → parsing → AI → azioni → storage → risposta.
│   │
│   ├── RequestGuard.php           CORS, Bearer token, metodo HTTP, security headers,
│   │                              validazione token dinamico (HMAC-SHA256).
│   │
│   ├── SessionManager.php         Orchestratore storage a tre layer. Applica rate limit
│   │                              all'ingresso. Gestisce i flush MySQL event-driven.
│   │
│   ├── TrafficLimiter.php         Rate limiter condiviso. Espone isExceeded(bucket, max, window).
│   │                              Usa Redis se disponibile, fallback su FileStorage.
│   │
│   ├── ClaudeClient.php           Client Anthropic. Gestisce retry con backoff, parsing JSON,
│   │                              retry per risposta non-JSON, rate limit locale.
│   │                              Implementa AiClientInterface.
│   │
│   ├── ProductSearchService.php   Client Doofinder. GET su endpoint search, max 3 risultati,
│   │                              mapping campi, timeout 8s.
│   │
│   ├── OrderService.php           Client API ordini Prezzofochatbot Gestisce
│   │                              getOrderDetails, getOrders, checkSession (validazione
│   │                              identità cliente loggato). Sanitizzazione XSS risposta.
│   │
│   ├── HtmlSanitizer.php          Sanitizzatore HTML basato su DOMDocument. Whitelist di tag
│   │                              e attributi consentiti. Applicato sulla chiave "reply" di
│   │                              ogni risposta Claude prima dell'invio al client.
│   │
│   ├── Logger.php                 Helper per scrittura log su file in CHATBOT_LOGS_DIR.
│   │                              Livelli: DEBUG, ERROR, INFO.
│   │
│   ├── AiClientInterface.php      Interfaccia: ask(array $historyContext): array
│   │
│   └── storage/
│       ├── StorageInterface.php   Interfaccia: isAvailable(), loadHistory(), saveHistory()
│       ├── RedisStorage.php       Layer 1. Sorted-set sliding window + INCR ora/giorno per
│       │                          rate limit. Sessioni via SETEX.
│       ├── FileStorage.php        Layer 2. JSON + LOCK_EX. Sempre disponibile.
│       │                          Rate limit: sliding window su array timestamp.
│       └── MySqlStorage.php       Layer 3. PDO. Upsert su chatbot_conversations.
│                                  Auto-create tabella al primo avvio.
│
├── widget/
│   ├── prezzy-wgettyjs       Widget IIFE autocontenuto. Gestisce: UI chat, sessione
│   │                          (sessionStorage), cache cronologia, autenticazione Bearer,
│   │                          sanitizzazione DOM, resize drag-and-drop (localStorage).
│   ├── prezzy-wgettycss      Stili scoped a #pf-chat-window e #pf-widget-fab.
│   └── widget.html            Template HTML del widget con placeholder sostituiti da widget.php.
│
└── admin/
    ├── index.php              Panoramica e health check runtime.
    ├── dashboard.php          Lista e ricerca conversazioni MySQL. Autenticazione con CSRF.
    ├── redis_admin.php        Diagnostica Redis live.
    ├── logs.php               Visualizzatore log applicativi (sola lettura).
    ├── prompt.php             Visualizzatore system prompt AI (sola lettura).
    ├── cors_origins.php       Visualizzatore origini CORS consentite e .htaccess.
    ├── keys.php               Visualizzatore configurazione con mascheratura segreti.
    ├── css/                   Fogli di stile admin modulari.
    └── views/
        ├── _admin_menu.php    Sidebar navigazione (gruppi: Operations, Platform, Governance).
        └── _admin_header.php  Header pagina con breadcrumb e azioni rapide.
```

### Namespace e autoloading

Tutte le classi PHP usano `namespace Chatbot`. L'autoloader in `chat_api.php` risolve `Chatbot\ClassName` cercando il file in:
1. `classes/ClassName.php`
2. `classes/storage/ClassName.php`

Nessun autoloader esterno (no Composer).

### Interfacce

- `AiClientInterface` — garantisce la sostituibilità del provider AI (attualmente solo `ClaudeClient`)
- `StorageInterface` — garantisce la sostituibilità dello storage (`RedisStorage`, `FileStorage`)

---

## 5. Sicurezza delle Comunicazioni — Token Dinamici

### Panoramica

Ogni richiesta a `chat_api.php` deve presentare un token di autenticazione nell'header `Authorization: Bearer {token}`. Il sistema supporta due forme di token, in ordine di priorità:

1. **Token dinamico firmato** (raccomandato in produzione)
2. **Token statico** (fallback, utile in sviluppo)

### Token dinamico — Formato

```
pfw1.{expires}.{nonce}.{signature}
```

| Componente | Tipo | Descrizione |
|---|---|---|
| `pfw1` | Stringa fissa | Prefisso versione (configurabile via `CHATBOT_WIDGET_TOKEN_PREFIX`) |
| `expires` | Unix timestamp (intero) | Scadenza del token |
| `nonce` | Hex 16–128 caratteri | Valore casuale anti-replay |
| `signature` | Base64url 20–128 caratteri | HMAC-SHA256 firmato con `CHATBOT_WIDGET_DYNAMIC_SECRET` |

### Generazione (lato `getty_widget.php`)

```php
$expires   = time() + getty_CHATBOT_TOKEN_TTL;   // es. 300 secondi
$nonce     = bin2hex(random_bytes(16));            // 32 caratteri hex
$payload   = "pfw1.{$expires}.{$nonce}";
$rawSig    = hash_hmac('sha256', $payload, getty_CHATBOT_DYNAMIC_SECRET, true);
$signature = rtrim(strtr(base64_encode($rawSig), '+/', '-_'), '='); // base64url
$token     = "{$payload}.{$signature}";
```

### Validazione (lato `RequestGuard::isValidDynamicToken()`)

Il backend esegue in sequenza i seguenti controlli, tutti obbligatori:

1. **Struttura:** il token contiene esattamente 4 parti separate da `.`
2. **Prefisso:** la prima parte è `pfw1` (o il valore di `CHATBOT_WIDGET_TOKEN_PREFIX`)
3. **Formato expires:** stringa numerica pura (`ctype_digit`)
4. **Formato nonce:** regex `/^[a-f0-9]{16,128}$/`
5. **Formato signature:** regex `/^[A-Za-z0-9_-]{20,128}$/`
6. **Scadenza:** `expires >= time()` (token non scaduto)
7. **Skew massimo:** `expires <= time() + CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS` (difesa da token con scadenza eccessivamente lontana)
8. **Firma HMAC:** ricalcola `HMAC-SHA256("pfw1.{expires}.{nonce}", secret)` e confronta con `hash_equals()` (timing-safe)

Se anche un solo controllo fallisce il token è rifiutato con HTTP 402.

### Fallback statico

Se `CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK=true` (utile in sviluppo), il backend accetta anche il token statico definito in `CHATBOT_WIDGET_TOKEN` tramite confronto `hash_equals()` timing-safe. In produzione questo fallback deve essere disabilitato.

### Costanti rilevanti

| Costante | File | Descrizione |
|---|---|---|
| `CHATBOT_WIDGET_TOKEN` | `chatbot_config.php` | Token statico (fallback) |
| `CHATBOT_WIDGET_DYNAMIC_SECRET` | `chatbot_config.php` | Segreto HMAC condiviso |
| `CHATBOT_WIDGET_TOKEN_PREFIX` | `chatbot_config.php` | Prefisso versione (default `pfw1`) |
| `CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS` | `chatbot_config.php` | Max skew temporale (default 86400 s) |
| `CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK` | `chatbot_config.php` | Abilita token statico (default `true`) |
| `getty_CHATBOT_DYNAMIC_SECRET` | lato `org/` | Segreto condiviso per la generazione del token |
| `getty_CHATBOT_TOKEN_TTL` | lato `org/` | Durata del token generato (secondi) |

### Flusso completo token dinamico

```
[getty_widget.php sul sito org]
  │
  ├─ Genera token: pfw1.{exp}.{nonce}.{hmac}
  └─ Inserisce come attributo data-* su <script src="widget.php?token=...">
                         │
                         ▼
              [widget.php — validazione token]
                         │ token valido
                         ▼
              [getty-widget.js caricato]
                         │ ogni POST a chat_api.php
                         ▼
              Authorization: Bearer pfw1.{exp}.{nonce}.{hmac}
                         │
                         ▼
              [RequestGuard::enforceBearerToken()]
                         │ token verificato
                         ▼
              [Risposta JSON al widget]
```

---

## 6. Gestione dei Limiti — Rate Limiting

### Architettura a quattro bucket

Il sistema applica rate limiting su quattro assi indipendenti, gestiti tutti da `TrafficLimiter`:

| Bucket | Chiave | Scopo | Costanti |
|---|---|---|---|
| Per-IP | `ip:{sha256(IP)}` | Blocca singoli utenti abusivi | `CHATBOT_RATE_LIMIT_MAX_REQUESTS` / `_WINDOW_SECONDS` |
| Globale | `global:all_requests` | Protegge contro picchi di traffico aggregato | `CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS` / `_WINDOW_SECONDS` |
| Claude | `provider:claude` | Protegge la quota API Anthropic | `CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS` / `_WINDOW_SECONDS` |
| Doofinder | `provider:doofinder` | Protegge la quota API Doofinder | `CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS` / `_WINDOW_SECONDS` |

### Valori di default (produzione)

| Bucket | Max richieste | Finestra | Soglia effettiva |
|---|---|---|---|
| Per-IP | 10 | 60 s | 10 msg/min per utente |
| Globale | 3000 | 60 s | 3000 msg/min totali |
| Claude | 1800 | 60 s | 1800 chiamate AI/min |
| Doofinder | 1200 | 60 s | 1200 ricerche/min |

### Dove viene applicato

```
Richiesta HTTP
  │
  ├─ SessionManager::applyRateLimit()
  │       ├─ TrafficLimiter::isExceeded('ip:{hash}', ...)      → 429 "Troppe richieste"
  │       └─ TrafficLimiter::isExceeded('global:all_requests') → 429 "Servizio sovraccarico"
  │
  ├─ ClaudeClient::requestMessagesApi()
  │       └─ TrafficLimiter::isExceeded('provider:claude', ...) → risposta locale senza chiamata API
  │
  └─ ChatbotApplication::processActions()
          └─ TrafficLimiter::isExceeded('provider:doofinder', ...) → skip ricerca, nota in contesto
```

### Classe TrafficLimiter

`TrafficLimiter::isExceeded(string $bucket, int $maxRequests, int $windowSeconds): bool`

La chiave effettiva passata allo storage è `sha256($bucket)` per normalizzare lunghezza e caratteri speciali.

Il limiter usa Redis se disponibile; se Redis si disconnette durante la chiamata, l'istanza si marca unavailable e il controllo ricade su `FileStorage::checkRateLimit()`.

### Strategia Redis — tre layer

Il metodo `RedisStorage::checkRateLimit()` applica una strategia combinata su ogni bucket:

```
┌─────────────────────────────────────────────────────────────┐
│  Layer 1 — Sliding Window (per finestra corta)              │
│  Chiave: prgettyl:{key}  (Sorted Set)                      │
│  Tecnica: ZADD/ZREMRANGEBYSCORE/ZCARD                       │
│  Precisione: millisecondi (microtime)                       │
│  Scopo: blocca i burst istantanei                           │
├─────────────────────────────────────────────────────────────┤
│  Layer 2 — Fixed Window (ora)                               │
│  Chiave: prgettyl_h:{key}  (INCR counter)                  │
│  TTL: allineato all'ora solare  (3600 - time()%3600 + 1)   │
│  Limite: maxRequests × ⌈3600 / windowSeconds⌉              │
│  Scopo: blocca accumuli nel corso dell'ora                  │
├─────────────────────────────────────────────────────────────┤
│  Layer 3 — Fixed Window (giorno)                            │
│  Chiave: prgettyl_d:{key}  (INCR counter)                  │
│  TTL: allineato alla mezzanotte UTC (86400 - time()%86400)  │
│  Limite: maxRequests × ⌈86400 / windowSeconds⌉             │
│  Scopo: blocca crawler sistematici nell'arco del giorno     │
└─────────────────────────────────────────────────────────────┘
```

**Pattern read-first / write-only-if-passed:** tutti e tre i contatori vengono letti prima di qualsiasi scrittura. Se anche un solo layer è saturato, la richiesta è rifiutata senza scrivere nulla (nessun consumo sprecato di quota).

### Strategia FileStorage — fallback

Quando Redis non è disponibile, `FileStorage::checkRateLimit()` usa:
- File JSON per bucket: `{CHATBOT_SESSION_DIR}/rate_limits/{sha256_key}.json`
- Struttura dati: array di timestamp Unix
- Algoritmo: sliding window (rimuove i timestamp fuori dalla finestra, conta i rimanenti)
- Concorrenza: `flock(LOCK_EX)` sulla scrittura

### Risposte HTTP al client

| Condizione | HTTP | Messaggio |
|---|---|---|
| Rate limit per-IP superato | 429 | "Stai inviando troppi messaggi. Attendi un momento." |
| Rate limit globale superato | 429 | "Il servizio è momentaneamente sovraccarico. Riprova tra poco." |
| Rate limit Claude superato | 200 | "In questo momento ci sono molte richieste. Riprova tra qualche secondo! 😊" |
| Rate limit Doofinder superato | 200 | Risposta Claude senza dati prodotto |

I bucket Claude e Doofinder restituiscono sempre HTTP 200 con un messaggio di cortesia per non interrompere il flusso conversazionale dal punto di vista dell'utente.

### Configurazione rate limit

Tutti i valori si trovano in `chatbot_config.php` (valori espliciti di produzione) con fallback di sicurezza definiti in `chat_api.php`:

```php
// Per-IP
CHATBOT_RATE_LIMIT_MAX_REQUESTS        = 10
CHATBOT_RATE_LIMIT_WINDOW_SECONDS      = 60

// Globale
CHATBOT_GLOBAL_RATE_LIMIT_MAX_REQUESTS        = 3000
CHATBOT_GLOBAL_RATE_LIMIT_WINDOW_SECONDS      = 60

// Claude
CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS        = 1800
CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS      = 60

// Doofinder
CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS     = 1200
CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS   = 60
```
6. Ruotare tutte le chiavi/token se provenienti da ambienti di sviluppo o repository.

## Verifiche post deploy consigliate

1. php -l Chatbot/chat_api.php
2. php -l Chatbot/widget.php
3. php -l Chatbot/chatbot_config.php
4. GET su chat_api.php deve rispondere 405.
5. POST senza Authorization deve rispondere 401.
6. POST con token invalido deve rispondere 402.
7. Richieste fuori origin autorizzate devono ricevere 403.
8. Superamento soglia rate limit deve rispondere 429.

## Note operative dashboard

- Accesso con password semplice via sessione PHP.
- Presente CSRF token sulle azioni POST.
- Supporta ricerca, edit e delete delle conversazioni.
- Non e presente lockout tentativi login nel codice corrente.

---

## 7. Modalità Test vs Modalità Reale

### Scopo

La modalità test permette di sviluppare e verificare il chatbot senza effettuare chiamate reali alle API esterne (Doofinder, API ordini Pchatbot. Tutte le risposte alle ricerche di prodotti e agli ordini vengono simulate con dati fissi predefiniti.

### Condizioni di attivazione

Il test mode si attiva **solo se entrambe le seguenti condizioni sono vere contemporaneamente**:

1. La costante PHP `CHATBOT_ALLOW_TEST_MODE=true` è impostata in `chatbot_config.php`
2. Il campo `test_mode: true` è presente nel corpo JSON della richiesta POST a `chat_api.php`

La seconda condizione è inviata dal widget JS: il valore letto da `localStorage.getItem('pf_test_mode')` viene trasmesso come campo `test_mode` nel payload di ogni messaggio.

La verifica lato server è:

```php
$testMode = !empty($data['test_mode']) && $this->requestGuard->isTestModeAllowed();
```

dove `isTestModeAllowed()` restituisce `true` solo se `CHATBOT_ALLOW_TEST_MODE=true` **oppure** se l'IP è `127.0.0.1`/`::1` (localhost sempre autorizzato).

### Attivazione dal widget (lato browser)

All'**primo caricamento** del widget su un browser il test mode è attivato di default (`localStorage` non ancora impostato → il JS lo imposta a `true`). Questo comportamento è intenzionale per ambienti di sviluppo.

Il toggle è controllabile dall'utente tramite il pulsante nella UI del widget (`#pf-test-toggle`):

- Click sul pulsante → inversione dello stato → salvataggio in `localStorage('pf_test_mode')` → messaggio informativo in chat
- Lo stato persiste tra ricaricamenti della pagina perché salvato in `localStorage`

Per **disabilitare definitivamente** il test mode in produzione è sufficiente impostare `CHATBOT_ALLOW_TEST_MODE=false` in `chatbot_config.php`: anche se il browser invia `test_mode: true`, il server lo ignora.

### Differenze comportamentali

| Comportamento | Modalità TEST | Modalità REALE |
|---|---|---|
| **Ricerca prodotti (Doofinder)** | Non implementata in test mode — Doofinder viene comunque chiamato | Chiamata reale all'API Doofinder |
| **Lookup ordine (`getOrderDetails`)** | Restituisce fixture hardcoded in `OrderService::getTestOrders()` | Chiamata HTTP reale a `chatbot.org/oct8ne/frame/getOrderDetails` |
| **Lista ordini (`getOrders`)** | Restituisce l'intero array di fixture hardcoded | Chiamata HTTP reale a `chatbot.org/oct8ne/frame/getOrders` |
| **Validazione identità (`checkSession`)** | Saltata (non viene chiamata) | Chiamata HTTP reale a `pchatbotorg/oct8ne/frame/checkSession` |
| **Validazione email ordine** | Obbligatoria ugualmente (controllo lato server) | Obbligatoria |
| **Numero ordine non trovato** | Messaggio: "usa i numeri 1000001–1000005" | Messaggio: "verifica il numero ordine" |
| **Log debug** | `testMode=ON` nei log di debug | `testMode=OFF` nei log di debug |
| **Badge UI widget** | Pulsante test attivo (classe CSS `active`) | Pulsante test inattivo |

### Ordini di test disponibili

| Numero ordine | Stato | Corriere | Note |
|---|---|---|---|
| `1000001` | In lavorazione | — | Ordine appena ricevuto |
| `1000002` | Spedito | BRT | Tracking attivo |
| `1000003` | Consegnato | GLS | Data consegna passata |
| `1000004` | Rimborso parziale | DHL | — |
| `1000005` | Annullato | — | — |

Tutti gli ordini fittizi rispondono a qualsiasi email: in test mode la validazione `checkSession` è bypassata, quindi non è necessario fornire un'email reale.

### Configurazione raccomandata per ambiente

| Ambiente | `CHATBOT_ALLOW_TEST_MODE` | `pf_test_mode` (localStorage) |
|---|---|---|
| Sviluppo locale | `true` | `true` (default automatico) |
| Staging | `true` | A discrezione del tester |
| Produzione | **`false`** | Ignorato dal server |

---

## Fonte: API_INTEGRAZIONI.md

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

---

## Fonte: GUIDA_INTEGRAZIONE.md

# Guida Integrazione Widget getty

Ultimo aggiornamento: 10-04-2026

## Flusso corrente consigliato

Il widget non va integrato con token statico hardcoded nel markup.
Il flusso attuale e:

1. Backend sito genera token firmato a scadenza breve.
2. Frontend sito chiama Chatbot/widget.php?token=...&customerSessionId=...&v3=...
3. widget.php valida token e origin, poi restituisce widget/widget.html.
4. getty-widget.js viene caricato con i parametri e usa Authorization: Bearer <token> verso chat_api.php.

## Stato integrazione su org

Nel progetto org il caricamento e centralizzato in org/includes/getty_widget.php, incluso da:

- org/includes/footer.php
- org/templates/global/footer.php

Costanti usate lato org:

- getty_CHATBOT_URL
- getty_CHATBOT_DYNAMIC_SECRET
- getty_CHATBOT_TOKEN_TTL

## Parametri attesi da widget.php

- token: token widget (dinamico firmato oppure statico se fallback attivo)
- customerSessionId: hash sessione esterna cliente
- v3: customer id codificato (base64 email nel flusso corrente)

## Esempio token dinamico (server-side PHP)

```php
$prefix = 'pfw1';
$ttl = 900;
$secret = getty_CHATBOT_DYNAMIC_SECRET;

$exp = time() + $ttl;
$nonce = hash('sha256', uniqid('', true) . microtime(true));
$payload = $exp . '.' . $nonce;
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');

$token = $prefix . '.' . $exp . '.' . $nonce . '.' . $sig;
```

## Config backend Chatbot da verificare

In Chatbot/chatbot_config.php:

- CHATBOT_ALLOWED_ORIGINS
- CHATBOT_WIDGET_DYNAMIC_SECRET
- CHATBOT_WIDGET_TOKEN_PREFIX
- CHATBOT_WIDGET_TOKEN_MAX_FUTURE_SECONDS
- CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK

Per hardening finale produzione, impostare CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK=false quando tutte le integrazioni dinamiche sono migrate.

## Personalizzazioni UI widget

1. Colori/stile: widget/getty-widget.css
2. Avatar: costante AVATAR_URL in widget/getty-widget.js
3. Endpoint chat: costante CHATBOT_ENGINE_URL in widget/getty-widget.js
4. Timeout inattivita: valore 600000 in widget/getty-widget.js

## Troubleshooting rapido

1. widget.php 403: token invalido/scaduto o origin non autorizzata.
2. chat_api.php 401: header Authorization mancante.
3. chat_api.php 402: Bearer token non valido.
4. chat_api.php 403: Origin non presente in CHATBOT_ALLOWED_ORIGINS.
5. chat_api.php 429: rate limit raggiunto.

---

## Fonte: DEPLOY_CHECKLIST.md

# Deploy Checklist Chatbot chatbot

Ultimo aggiornamento: 10-04-2026

## 1) Prima del deploy

1. Ruota e sostituisci tutte le chiavi/token presenti in chatbot_config.php.
2. Verifica i domini in CHATBOT_ALLOWED_ORIGINS (solo domini autorizzati).
3. Imposta CHATBOT_ALLOW_TEST_MODE=false in produzione.
4. Configura DASHBOARD_PASSWORD robusta.
5. Verifica CHATBOT_SESSION_DIR con permessi scrittura PHP.
6. Verifica CHATBOT_MYSQL_DSN, CHATBOT_MYSQL_USER, CHATBOT_MYSQL_PASSWORD.
7. Verifica impostazioni widget auth:
	- CHATBOT_WIDGET_DYNAMIC_SECRET
	- CHATBOT_WIDGET_TOKEN_PREFIX
	- CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK
8. Verifica PHP 8+ e mod_headers attivo per .htaccess.

## 2) Artefatti da pubblicare

1. Chatbot/chat_api.php
2. Chatbot/widget.php
3. Chatbot/dashboard.php
4. Chatbot/chatbot_config.php
5. Chatbot/.htaccess
6. Chatbot/classes/ (completa)
7. Chatbot/widget/ (completa)
8. chatbotchat/migration.sql (utile per bootstrap DB)

## 3) Verifiche tecniche immediate

1. php -l Chatbot/chat_api.php
2. php -l Chatbot/widget.php
3. php -l Chatbot/chatbot_config.php
4. php -l Chatbot/classes/RequestGuard.php
5. php -l Chatbot/classes/ChatbotApplication.php

## 4) Smoke test endpoint

1. GET su chat_api.php => 405.
2. POST su chat_api.php senza Authorization => 401.
3. POST su chat_api.php con Bearer invalido => 402.
4. POST da origin non autorizzato => 403.
5. Burst richieste oltre soglia => 429.
6. GET su widget.php senza token => 403.
7. GET su widget.php con token valido e origin autorizzata => 200 + HTML widget.

## 5) Verifiche dashboard

1. Login con DASHBOARD_PASSWORD.
2. Verifica visualizzazione conversazioni.
3. Verifica azioni update/delete con CSRF valido.
4. Verifica che la tabella chatbot_conversations esista e sia popolata.

Nota: nel codice attuale non e implementato lockout tentativi login dashboard.

## 6) Hardening produzione consigliato

1. Disabilita fallback token statico: CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK=false (dopo rollout).
2. Disabilita index.html in produzione o proteggilo.
3. Mantieni .htaccess attivo per negare accesso a .md/.json/.log/.env.
4. Mantieni CHATBOT_SESSION_DIR fuori percorso web pubblico quando possibile.
5. Allinea eventuale rate-limit reverse proxy con CHATBOT_RATE_LIMIT_*.

---

## Fonte: SECURITY_REVIEW.md

# Security Review Chatbot chatbot

Data analisi: 10-04-2026

## Sintesi

Rispetto alle revisioni precedenti, il progetto ha introdotto controlli importanti:

- autenticazione Bearer obbligatoria su chat_api.php
- validazione token dinamico firmato per widget.php e chat_api.php
- CORS server-side con allowlist
- rate limiting lato server (Redis con fallback file)
- sanitizzazione HTML lato widget e lato backend

Rimangono comunque criticita da gestire prima di considerare il sistema hardenizzato.

## Findings aperti

### 1. Critical - Segreti reali presenti nel repository

Riferimenti:

- [chatbot_config.php](./chatbot_config.php)
- [org/includes/configure.php](../../org/includes/configure.php)

Dettaglio:

- chiavi/token reali risultano presenti in file versionati
- questo include token AI, token servizi esterni e password dashboard

Impatto:

- abuso API
- data exposure
- impossibilita di considerare attendibile la confidenzialita dei segreti correnti

Azioni consigliate:

1. ruotare subito tutti i segreti
2. rimuovere i valori dal repository e usare secret manager/env vars
3. invalidare credenziali storiche gia committate

### 2. High - Persistenza sessioni con PII su file JSON

Riferimenti:

- [classes/storage/FileStorage.php](./classes/storage/FileStorage.php)
- [chatbot_config.php](./chatbot_config.php)

Dettaglio:

- storico conversazioni salvato in file JSON nel path CHATBOT_SESSION_DIR
- possibile presenza di email e dati ordine nei payload

Impatto:

- rischio privacy/GDPR se il percorso e esposto o non gestito con retention

Azioni consigliate:

1. mantenere CHATBOT_SESSION_DIR fuori percorso web
2. definire retention/cancellazione automatica
3. minimizzare i dati salvati

### 3. High - Incoerenza schema MySQL auto-create vs upsert

Riferimenti:

- [classes/storage/MySqlStorage.php](./classes/storage/MySqlStorage.php)
- [../migration.sql](../migration.sql)

Dettaglio:

- l'upsert scrive customer_email
- ensureTable in MySqlStorage non crea la colonna customer_email
- in DB nuovi, la scrittura puo fallire silenziosamente

Impatto:

- perdita persistenza conversazioni su MySQL in ambienti fresh
- dashboard incompleta o non aggiornata

Azioni consigliate:

1. allineare ensureTable allo schema migration.sql
2. aggiungere logging esplicito su errore PDO in upsert

### 4. Medium - Test mode abilitato in configurazione corrente

Riferimenti:

- [chatbot_config.php](./chatbot_config.php)
- [classes/RequestGuard.php](./classes/RequestGuard.php)

Dettaglio:

- CHATBOT_ALLOW_TEST_MODE e attualmente true
- il client puo inviare test_mode=true e ottenere ordini demo

Impatto:

- comportamento non production-grade
- possibile confusione operativa e disclosure di flussi interni

Azioni consigliate:

1. impostare CHATBOT_ALLOW_TEST_MODE=false in produzione
2. mantenere true solo in ambienti di sviluppo

### 5. Medium - Fallback token statico ancora attivo

Riferimenti:

- [chatbot_config.php](./chatbot_config.php)
- [widget.php](./widget.php)
- [classes/RequestGuard.php](./classes/RequestGuard.php)

Dettaglio:

- CHATBOT_WIDGET_ALLOW_STATIC_TOKEN_FALLBACK e true
- oltre al token firmato resta accettato il token statico

Impatto:

- riduce il beneficio complessivo del token short-lived

Azioni consigliate:

1. completare rollout token dinamico
2. portare fallback statico a false

### 6. Medium - Dashboard senza lockout tentativi login

Riferimenti:

- [dashboard.php](./dashboard.php)

Dettaglio:

- presente auth via password + sessione + CSRF
- non presente meccanismo di lockout/rate-limit specifico login

Impatto:

- rischio brute-force sulla password admin

Azioni consigliate:

1. introdurre throttling/lockout login
2. valutare allowlist IP o basic auth a monte

## Controlli gia implementati

1. CORS con allowlist in RequestGuard.
2. Bearer token obbligatorio in chat_api.
3. Token dinamico firmato (prefisso + expiry + nonce + HMAC) in widget flow.
4. Rate limiting server-side con fallback resiliente.
5. Header di sicurezza in .htaccess e in RequestGuard.
6. Sanitizzazione HTML in widget client + HtmlSanitizer backend.
7. Verifica email lato backend prima delle chiamate ordine.

## Priorita raccomandate

1. Rotazione segreti e rimozione dal repository.
2. Allineamento schema MySQL (customer_email).
3. Disattivazione test mode e fallback static token in produzione.
4. Lockout/rate-limit specifico per login dashboard.
5. Politica retention per sessioni JSON.
