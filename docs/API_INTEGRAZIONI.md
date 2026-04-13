# Integrazione API Esterne — Chatbot example

Documento tecnico che descrive configurazione, flusso e gestione degli errori per ciascuna API esterna utilizzata dal chatbot.

---

## Indice

1. [Claude (Anthropic) — AI Engine](#1-claude-anthropic--ai-engine)
2. [Doofinder — Ricerca Prodotti](#2-doofinder--ricerca-prodotti)
3. [example.org / Oct8ne — API Ordini](#3-exampleorg--oct8ne--api-ordini)
4. [Rate Limiting per Provider](#4-rate-limiting-per-provider)
5. [Costanti di Configurazione](#5-costanti-di-configurazione)
6. [Architettura di Storage — Sessioni e Conversazioni](#6-architettura-di-storage--sessioni-e-conversazioni)
7. [Pannello Admin](#7-pannello-admin)

---

## 1. Claude (Anthropic) — AI Engine

### Scopo

Claude è il motore conversazionale del chatbot. Riceve la cronologia della conversazione (inclusi eventuali dati di prodotti e ordini iniettati dal backend) e restituisce sempre un oggetto JSON strutturato con la risposta da mostrare all'utente.

### Credenziali e configurazione

| Costante | Dove definita | Descrizione |
|---|---|---|
| `ANTHROPIC_API_KEY` | `basic_config.php` | Chiave API `sk-ant-...` |
| `CLAUDE_MODEL` | `basic_config.php` | Modello da usare (default: `claude-haiku-4-5-20251001`) |
| `CHATBOT_AI_PROVIDER` | `basic_config.php` | Provider AI attivo (`claude`) |

**Modelli disponibili (al 13/04/2026):**
- `claude-haiku-4-5-20251001` — Veloce ed economico, consigliato per chatbot
- `claude-sonnet-4-5-20250929` — Bilanciato qualità/costo
- `claude-sonnet-4-6` — Più potente, versione corrente
- `claude-opus-4-6` — Il più potente, costo maggiore

Per cambiare modello modificare `CLAUDE_MODEL` in `basic_config.php`.

### Endpoint

```
POST https://api.anthropic.com/v1/messages
```

### Headers richiesti

```
Content-Type: application/json
x-api-key: {ANTHROPIC_API_KEY}
anthropic-version: 2023-06-01
```

### Payload inviato

```json
{
  "model": "claude-haiku-4-5-20251001",
  "max_tokens": 2048,
  "system": "<system prompt da AI_PROMPT>",
  "messages": [
    { "role": "user", "content": "..." },
    { "role": "assistant", "content": "..." }
  ]
}
```

**Note sul formato messaggi:**
- Il `system` prompt viene estratto dal primo elemento con `role: system` della cronologia e separato dall'array `messages`.
- I messaggi successivi con `role: system` (dati API iniettati dal backend — prodotti, ordini) vengono convertiti in messaggi `user` con il prefisso `[Dati API ricevuti dal sistema - usa questi per rispondere]:`.
- Il backend garantisce che i ruoli alternino `user`/`assistant` e che il primo messaggio sia sempre `user`. Messaggi consecutivi con lo stesso ruolo vengono fusi con `\n\n`.

### Formato risposta atteso

Claude deve rispondere **esclusivamente** con un oggetto JSON (nessun testo libero):

```json
{
  "reply": "<HTML della risposta>",
  "Keyword search": "parola chiave (opzionale)",
  "ref": "EAN1,EAN2 (opzionale)",
  "order id": "12345 (opzionale)",
  "order email": "cliente@email.it (opzionale)",
  "need agent": "descrizione problema (opzionale)",
  "end session": "True (opzionale)",
  "form": "True (opzionale)"
}
```

Il backend legge queste chiavi e triggera le azioni corrispondenti (ricerca prodotti, lookup ordine, ecc.).

### Retry automatico

La chiamata HTTP è gestita da `ClaudeClient::claude_http_call()` con **3 tentativi** e backoff esponenziale:

| Tentativo | Attesa prima del retry |
|---|---|
| 1 | 1 s × 2? = 1 s |
| 2 | 1 s × 2¹ = 2 s |
| 3 | (fallimento definitivo) |

Codici HTTP che triggerano il retry: `429`, `503`, `529`, `500`.  
Codici che restituiscono errore immediato: `401` (auth), `403` (permission), `400` (bad request), altri `4xx`.

**Retry per risposta non-JSON:** se Claude restituisce testo non parsabile come JSON, il backend inietta nella conversazione un messaggio di correzione e richiama l'API un'ulteriore volta (`max_tokens: 1024`). Se anche questo tentativo fallisce viene restituito un messaggio di errore generico all'utente.

### Messaggi di errore utente

| Codice interno | Testo mostrato al cliente |
|---|---|
| `auth_error` | "C'è un problema di configurazione del servizio. Contatta l'assistenza." |
| `permission_error` | "C'è un problema di configurazione del servizio. Contatta l'assistenza." |
| `bad_request` | "Scusa, c'è stato un errore tecnico. Prova a riformulare la richiesta." |
| `local_rate_limit` | "In questo momento ci sono molte richieste. Riprova tra qualche secondo! ??" |
| `exhausted` | "I server AI sono al momento sovraccarichi. Ho riprovato più volte ma senza successo. Riprova tra qualche minuto! ??" |

### Classe responsabile

`examplechat/Chatbot/classes/ClaudeClient.php` — implementa `AiClientInterface`.

---

## 2. Doofinder — Ricerca Prodotti

### Scopo

Quando Claude include la chiave `"Keyword search"` nella risposta JSON, il backend esegue una ricerca prodotti su Doofinder e inietta i risultati nella conversazione prima della risposta finale all'utente.

### Credenziali e configurazione

| Costante | Dove definita | Descrizione |
|---|---|---|
| `DOOFINDER_TOKEN` | `basic_config.php` | Token Bearer per l'API Doofinder |
| `DOOFINDER_SEARCH_URL` | `chat_api.php` (default) | Endpoint Doofinder con hash indice |

**Endpoint di default:**
```
https://eu1-search.doofinder.com/6/1df6a9e0beefea8b4faa2986d514b197/_search
```

Il percorso contiene: `{zona}-search.doofinder.com/{versione}/{hash_indice}/_search`.  
Per cambiare indice (es. cambio account) è sufficiente aggiornare `DOOFINDER_SEARCH_URL` in `basic_config.php`.

### Chiamata HTTP

```
GET {DOOFINDER_SEARCH_URL}?query={keyword_urlencoded}
Authorization: Token {DOOFINDER_TOKEN}
```

- Timeout: **8 secondi** (hardcoded in `ProductSearchService`)
- Libreria: PHP `curl`

### Risposta e mapping campi

Doofinder restituisce un JSON con il campo `results` (array). Il backend estrae a **massimo 3 prodotti** e mappa i campi come segue:

| Campo Doofinder | Campo interno | Note |
|---|---|---|
| `title` | `Title` | htmlspecialchars |
| `best_price` | `Price` | Aggiunge " €" |
| `link` | `Url` | htmlspecialchars |
| `id` | `Reference` | Cast a stringa |
| `description` | `Description` | htmlspecialchars |
| `image_link` / `image_url` / `link_image` / `image` | `Image` | Primo campo non vuoto trovato |

I prodotti vengono serializzati come JSON e iniettati nella conversazione come messaggio `system`, che il backend converte in un messaggio `user` verso Claude (vedi §1).

### Fallback

Se il token è vuoto (`''`), la ricerca non viene eseguita e viene restituito array vuoto. Se `curl_exec` fallisce (timeout, errore di rete) viene restituito array vuoto senza eccezioni bloccanti.

### Classe responsabile

`examplechat/Chatbot/classes/ProductSearchService.php`

---

## 3. example.org / Oct8ne — API Ordini

### Scopo

Quando Claude include la chiave `"order id"` nella risposta JSON, il backend interroga le API REST di `example.org` (basate su Oct8ne) per recuperare i dettagli dell'ordine e li inietta nella conversazione.

### Credenziali e configurazione

| Costante | Dove definita | Descrizione |
|---|---|---|
| `ORDER_API_TOKEN` | `basic_config.php` | Token di autenticazione API |
| `BASE_API_URL` | `basic_config.php` | Base URL: `https://www.example.org/` |
| `ORDER_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/getOrderDetails` |
| `ORDERS_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/getOrders` |
| `CHECKSESSION_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/checkSession` |

### Endpoint disponibili

#### 3.1 `getOrderDetails` — Dettaglio singolo ordine

```
GET {ORDER_API_URL}?reference={orderId}&apiToken={ORDER_API_TOKEN}&locale=it-IT&currency=EUR
```

- Timeout: **5 secondi**
- Richiede: `reference` (numero ordine), `order_email` (validato lato backend prima della chiamata)

**Campi restituiti e utilizzo:**

| Campo | Tipo | Descrizione |
|---|---|---|
| `reference` | string/int | Numero ordine |
| `date` | string | Data ordine (es. `2026-03-28 09:15:00`) |
| `total` | string | Totale (es. `"€ 299,00"`) |
| `labelState` | string | Stato leggibile (es. `"In lavorazione"`, `"Spedito"`, `"Consegnato"`) |
| `carrier` | string | Nome corriere (es. `"BRT"`, `"GLS"`, `"DHL"`) |
| `trackingUrl` | string | URL tracciamento spedizione |
| `trackingNumber` | string | Numero tracking |
| `deliveryDate` | string | Data consegna stimata o effettiva |
| `products` | array | Lista prodotti: `{quantity, name}` |
| `comments` | array | Messaggi di stato: `{message}` — usato solo `comments[0].message` |

#### 3.2 `getOrders` — Lista ordini di un cliente

```
GET {ORDERS_API_URL}?customerEmail={email}&apiToken={ORDER_API_TOKEN}&locale=it-IT&currency=EUR
```

- Timeout: **5 secondi**
- Richiede solo `customerEmail`
- Restituisce array di ordini con: `date`, `reference`, `total`, `currency`, `labelState`, `deliveryDate`

#### 3.3 `checkSession` — Validazione identità cliente (flusso Oct8ne)

```
GET {CHECKSESSION_API_URL}?customer_email={email}&customer_session_id={session_md5}&order_id={orderId}
```

Questo endpoint viene chiamato **solo se** il frontend ha passato un `customer_session` (hash MD5 del cliente loggato su example.it via Oct8ne widget).

**Scopo:** Verificare che:
1. L'email corrisponda al cliente nel database Oct8ne
2. Il session ID sia autentico
3. L'ordine richiesto appartenga a quel cliente

**Mapping errori Oct8ne ? messaggi utente:**

| Errore Oct8ne | Messaggio mostrato |
|---|---|
| `Customer not found` | "L'email fornita non corrisponde a nessun account. Verifica di aver scritto l'email corretta." |
| `Invalid session` | "La sessione non è valida. Assicurati di essere loggato su example.it e riprova." |
| `Invalid Order` | "L'ordine {N} non corrisponde all'email fornita. Verifica i dati inseriti." |

Se `customer_session` non è presente (utente non loggato), il `checkSession` viene saltato e si procede direttamente a `getOrderDetails` con la sola email fornita dall'utente in chat.

### Flusso di sicurezza ordini

```
[Chat utente fornisce orderId + email]
           ?
           ?
  Backend valida: email presente?
   NO ? errore "Email non fornita"
   SÌ ?
           ?
  customer_session presente?
   NO ? vai a getOrderDetails
   SÌ ?
           ?
  checkSession(email, session_id, orderId)
   KO ? errore user-friendly mappato
   OK ?
           ?
  getOrderDetails(orderId, email)
           ?
           ?
  Sanitizzazione XSS dati risposta
           ?
           ?
  Iniezione dati in conversazione ? Claude formatta risposta HTML
```

### Sanitizzazione dati ordine (sicurezza XSS)

Tutti i campi stringa della risposta vengono sanitizzati con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` prima di essere iniettati nella conversazione. I valori numerici (`quantity`) vengono castati a `int`.

### Test mode

Se `CHATBOT_ALLOW_TEST_MODE=true` e la sessione ha il flag test attivo, `getOrderDetails` e `getOrders` non chiamano l'API reale ma restituiscono fixture hardcoded in `getTestOrders()` (ordini di esempio con numeri `1000001`–`1000005`).

### Classe responsabile

`examplechat/Chatbot/classes/OrderService.php`

---

## 4. Rate Limiting per Provider

Ogni provider esterno ha un bucket di rate limit indipendente gestito da `TrafficLimiter`. Se il limite è superato la chiamata all'API esterna viene saltata e all'utente viene mostrato un messaggio di cortesia (senza errori HTTP bloccanti).

### Configurazione limiti

| Costante | Default | Descrizione |
|---|---|---|
| `CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS` | `1800` | Max chiamate Claude per finestra |
| `CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS` | `60` | Finestra Claude (secondi) |
| `CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS` | `1200` | Max ricerche Doofinder per finestra |
| `CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS` | `60` | Finestra Doofinder (secondi) |

I valori di default sono definiti in `chat_api.php`; i valori di produzione espliciti in `chatbot_config.php`.

### Bucket Redis utilizzati

| Bucket | Destinazione |
|---|---|
| `provider:claude` | Chiamate a `api.anthropic.com/v1/messages` |
| `provider:doofinder` | Chiamate all'endpoint Doofinder |
| `ip:{sha256}` | Richieste per singolo IP cliente |
| `global:all_requests` | Ingresso globale (tutti gli utenti) |

Il `TrafficLimiter` usa Redis (sorted-set sliding window per la finestra corta + INCR counter orario e giornaliero) con fallback automatico su FileStorage se Redis non è disponibile.

### Comportamento al superamento del limite

- **Claude:** `ClaudeClient::requestMessagesApi()` restituisce `['success'=>false, 'error'=>'local_rate_limit']` ? l'utente vede "In questo momento ci sono molte richieste. Riprova tra qualche secondo! ??"
- **Doofinder:** `ChatbotApplication::processActions()` salta la chiamata e inietta nel contesto il messaggio `"[Rate limit Doofinder superato - non cercare prodotti ora]"` ? Claude risponde senza dati di prodotto

---

## 5. Costanti di Configurazione

Riepilogo completo di tutte le costanti legate alle API esterne, con il file in cui devono essere definite per la produzione.

| Costante | File | Esempio |
|---|---|---|
| `ANTHROPIC_API_KEY` | `basic_config.php` | `<YOUR_ANTHROPIC_API_KEY>` |
| `CLAUDE_MODEL` | `basic_config.php` | `claude-haiku-4-5-20251001` |
| `CHATBOT_AI_PROVIDER` | `basic_config.php` | `claude` |
| `DOOFINDER_TOKEN` | `basic_config.php` | `eu1-5a50040f...` |
| `DOOFINDER_SEARCH_URL` | `basic_config.php` o `chat_api.php` | `https://eu1-search.doofinder.com/6/{hash}/_search` |
| `ORDER_API_TOKEN` | `basic_config.php` | `4E0977CF...` |
| `BASE_API_URL` | `basic_config.php` | `https://www.example.org/` |
| `ORDER_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/getOrderDetails` |
| `ORDERS_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/getOrders` |
| `CHECKSESSION_API_URL` | `basic_config.php` | `{BASE_API_URL}oct8ne/frame/checkSession` |
| `CHATBOT_CLAUDE_RATE_LIMIT_MAX_REQUESTS` | `chatbot_config.php` | `1800` |
| `CHATBOT_CLAUDE_RATE_LIMIT_WINDOW_SECONDS` | `chatbot_config.php` | `60` |
| `CHATBOT_DOOFINDER_RATE_LIMIT_MAX_REQUESTS` | `chatbot_config.php` | `1200` |
| `CHATBOT_DOOFINDER_RATE_LIMIT_WINDOW_SECONDS` | `chatbot_config.php` | `60` |

> **Sicurezza:** `basic_config.php` e `chatbot_config.php` contengono credenziali reali. In produzione vanno posizionati **un livello sopra** la web root (`/home/utente/`) e non devono mai essere accessibili via browser.

---

## 6. Architettura di Storage — Sessioni e Conversazioni

### Panoramica

La gestione delle sessioni (cronologia dei messaggi) è affidata a `SessionManager`, che orchestra tre layer di storage indipendenti con degradazione graceful: se un layer superiore non è disponibile il sistema scala al successivo senza errori visibili all'utente.

```
???????????????????????????????????????????????????????????????
?                        RICHIESTA HTTP                       ?
???????????????????????????????????????????????????????????????
                           ?
                           ?
              ??????????????????????????
              ?     SessionManager     ?
              ?  (orchestratore DI)    ?
              ??????????????????????????
                  ?        ?
        ??????????????  ???????????????
        ?  LAYER 1   ?  ?  LAYER 3    ?
        ?   Redis    ?  ?   MySQL     ?
        ? (primario) ?  ?(analitica)  ?
        ??????????????  ???????????????
                  ?        ? flush asincrono
        ??????????????????????????????
        ?         LAYER 2            ?
        ?      FileStorage           ?
        ?  (sempre disponibile)      ?
        ??????????????????????????????
```

### Layer 1 — Redis (primario, in-memory)

**Quando è attivo:** estensione PHP `ext-redis` caricata e Redis raggiungibile all'avvio.

**Struttura chiavi:**

| Chiave Redis | Tipo | Contenuto |
|---|---|---|
| `chatbot:sess:{sessionId}` | String | JSON della cronologia (array di `{role, content}`) |
| `chatbot:rl:{ipKey}` | Sorted Set | Sliding window rate limit (timestamp come score) |
| `chatbot:rl_h:{ipKey}` | String (INCR) | Contatore richieste corrente ora |
| `chatbot:rl_d:{ipKey}` | String (INCR) | Contatore richieste corrente giorno |

**TTL sessioni:** configurabile via `CHATBOT_REDIS_TTL` (default 1800 s = 30 minuti). La sessione scade se l'utente è inattivo oltre il TTL.

**Operazioni principali:**
- `loadHistory`: `GET chatbot:sess:{id}` ? decode JSON
- `saveHistory`: `SETEX chatbot:sess:{id} {ttl} {json}`
- Errore di connessione ? `$available = false`; ri-throw dell'eccezione su `saveHistory` per permettere al `SessionManager` di gestire il fallback

### Layer 2 — FileStorage (fallback, sempre disponibile)

**Quando è usato:**
1. Redis non disponibile (nessuna estensione o connessione fallita) ? unico layer di lettura/scrittura
2. Redis disponibile ma risponde con lista vuota al `loadHistory` (TTL scaduto o riavvio Redis) ? lettura di backup dal file
3. `saveHistory` — scritto **sempre** come backup durevole quando Redis è attivo (write-through)
4. Redis fallisce durante una scrittura ? `SessionManager` cattura l'eccezione, scrive su File e triggera un flush MySQL con `reason = 'redis_failure'`

**Struttura directory:**

```
{CHATBOT_SESSION_DIR}/
??? {sessionId}.json          ? cronologia sessione
??? rate_limits/
    ??? {sha256_ip}.json      ? sliding window rate limit per IP
```

**Formato file sessione:**
```json
[
  { "role": "system",    "content": "..." },
  { "role": "user",      "content": "..." },
  { "role": "assistant", "content": "{\"reply\":\"...\",\"Keyword search\":\"...\"}" }
]
```

**Sicurezza file:**
- Directory: `chmod 0700` (solo il processo PHP ha accesso)
- File sessione/rate-limit: `chmod 0600`
- Scritture con `LOCK_EX` per evitare race condition su richieste concorrenti sulla stessa sessione

### Layer 3 — MySQL (persistenza analitica)

**Quando è usato:** solo per scritture event-driven (flush); non viene mai letto durante il ciclo request/response normale.

**Tabella:** `chatbot_conversations`

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT PK | Auto-increment |
| `session_id` | VARCHAR(64) UNIQUE | ID di sessione chatbot |
| `ip_hash` | VARCHAR(64) | SHA-256 dell'IP cliente |
| `customer_email` | VARCHAR(255) | Email decodificata dal `customer_id` (base64) |
| `started_at` | DATETIME | Prima scrittura — preservata su UPDATE |
| `last_activity_at` | DATETIME | Timestamp dell'ultimo flush |
| `message_count` | SMALLINT | Numero di messaggi nella cronologia |
| `last_flush_reason` | VARCHAR(64) | Motivo del flush (vedi sotto) |
| `history` | MEDIUMTEXT | Cronologia completa in JSON |

La tabella è creata automaticamente da `MySqlStorage::ensureTable()` al primo avvio se non esiste (`CREATE TABLE IF NOT EXISTS`). La scrittura usa `INSERT ... ON DUPLICATE KEY UPDATE` per garantire l'idempotenza.

**Trigger di flush (reason):**

| Reason | Quando |
|---|---|
| `first_message` | Prima risposta dell'assistente nella sessione |
| `periodic` | Ogni 5 scambi completi (user+assistant) |
| `order_found` | Ordine recuperato con successo dall'API |
| `conversation_end` | Claude restituisce array `options` vuoto (fine flusso) |
| `redis_failure` | Salvataggio Redis fallito; dati preservati su File |

### Flusso completo di una richiesta

```
POST /chat_api.php
       ?
       ?
SessionManager::bootStorage()
  ?? applyRateLimit() ? TrafficLimiter (IP + global bucket)
       ?
       ?
SessionManager::loadHistory(sessionId)
  ?? Redis disponibile?
  ?    YES ? GET chatbot:sess:{id}
  ?           ?? non vuoto ? usa Redis ?
  ?           ?? vuoto (TTL scaduto) ? leggi da File
  ?? NO ? leggi da File
       ?
       ?
ClaudeClient::ask(history + system_prompt)
  ?? eventuale iniezione dati (Doofinder / OrderService)
       ?
       ?
SessionManager::saveHistory(sessionId, history)
  ?? Redis disponibile?
  ?    YES ? SETEX ?? eccezione? ??? scrivi File + flush MySQL (redis_failure)
  ?                                  ?? return
  ?    YES (ok) ? scrivi File (write-through backup)
  ?? NO ? scrivi solo File
       ?
       ?
maybeFlushToDatabase()
  ?? first_message? periodic? order_found? end? ? upsert MySQL
```

### Configurazione storage

| Costante | Default | Layer |
|---|---|---|
| `CHATBOT_SESSION_DIR` | *(obbligatorio)* | File |
| `CHATBOT_REDIS_HOST` | `127.0.0.1` | Redis |
| `CHATBOT_REDIS_PORT` | `6379` | Redis |
| `CHATBOT_REDIS_PASSWORD` | `''` | Redis |
| `CHATBOT_REDIS_PREFIX` | `chatbot:` | Redis |
| `CHATBOT_REDIS_TTL` | `1800` | Redis |
| `CHATBOT_MYSQL_DSN` | `''` (disabilitato) | MySQL |
| `CHATBOT_MYSQL_USER` | `''` | MySQL |
| `CHATBOT_MYSQL_PASSWORD` | `''` | MySQL |
| `CHATBOT_MYSQL_TABLE` | `chatbot_conversations` | MySQL |

---

## 7. Pannello Admin

### Accesso e autenticazione

Il pannello admin è accessibile all'URL `examplechat/Chatbot/admin/dashboard.php`. L'autenticazione è gestita tramite password configurata in `DASHBOARD_PASSWORD` (`chatbot_config.php`). Le sessioni admin usano `$_SESSION['dashboard_auth']` e tutti i form POST sono protetti da token CSRF generato con `openssl_random_pseudo_bytes(24)`.

**URL di accesso:** `https://{dominio}/examplechat/Chatbot/admin/dashboard.php`

Il menu laterale è organizzato in tre gruppi: **Operations**, **Platform**, **Governance**.

---

### Operations

#### Panoramica (`index.php?module=overview`)

Pagina iniziale dell'admin. Mostra:
- Versione applicazione (da `version.txt`)
- Conteggio moduli attivi e pianificati
- **Health check** runtime immediato: PHP ? 8.0, estensioni `cURL`, `JSON`, `OpenSSL`, `Redis`, caricamento del file di configurazione, costanti critiche definite
- Stato di scrittura di `CHATBOT_SESSION_DIR` e `CHATBOT_LOGS_DIR`

#### Dashboard Conversazioni (`dashboard.php`)

Il modulo più ricco. Richiede autenticazione (login con `DASHBOARD_PASSWORD`).

Funzionalità:
- **Lista sessioni:** elenca tutte le sessioni dalla tabella MySQL `chatbot_conversations` con paginazione
- **Ricerca full-text:** filtra per `session_id`, `ip_hash`, `customer_email`, o contenuto della cronologia
- **Dettaglio conversazione:** visualizza la cronologia completa di una sessione in formato leggibile
- **Statistiche aggregate:** conteggio conversazioni, media messaggi per sessione, utenti unici per IP hash
- **Azioni:** il modulo supporta operazioni di edit/cancellazione sessione con verifica CSRF

#### Redis Admin (`redis_admin.php`)

Strumento di diagnostica live per Redis. Non richiede login separato (condivide la sessione admin).

Funzionalità:
- **Test connessione:** verifica `ext-redis`, connect + `PING` al server configurato
- **Demo operazioni:** esegue un ciclo completo `saveHistory` / `loadHistory` / `checkRateLimit` sulla sessione demo e mostra l'esito
- **Ispezione chiavi:** elenca le chiavi Redis con il prefisso `chatbot:` con tipo, TTL e valore (troncato per sorted-set)
- **Azioni:** `FLUSHDB` (svuota il database Redis — azione distruttiva, richiede conferma) e reset chiavi singole

---

### Platform

#### Health Check (`index.php?module=health`)

Verifica approfondita di tutti i requisiti runtime:
- Versione PHP
- Estensioni obbligatorie (`curl`, `json`, `openssl`) e opzionali (`redis`)
- Caricamento e validità del file `chatbot_config.php`
- Costanti critiche definite: `CHATBOT_ALLOWED_ORIGINS`, `CHATBOT_SESSION_DIR`, `CHATBOT_WIDGET_DYNAMIC_SECRET`
- Permessi di scrittura su `CHATBOT_SESSION_DIR` e `CHATBOT_LOGS_DIR`

#### Logs Viewer (`logs.php`)

Visualizzatore dei log applicativi scritti da `Logger`. Richiede sessione admin attiva.

Funzionalità:
- Elenca i file di log presenti in `CHATBOT_LOGS_DIR` con dimensione e data
- Permette la lettura paginata di ciascun file (con filtro per livello: `DEBUG`, `ERROR`, `INFO`)
- Mostra il numero di righe e la dimensione in formato leggibile
- **Non permette la cancellazione dei log** (sola lettura)

#### Prompt Manager (`prompt.php`)

Visualizzazione in sola lettura del system prompt inviato a Claude ad ogni conversazione.

Mostra:
- Percorso del file sorgente (`basic_config.php`) e data di ultima modifica
- Statistiche: numero di caratteri, righe e parole del prompt
- Testo completo della costante `AI_PROMPT` in un'area di testo non modificabile

> Il prompt è editabile **solo** modificando `AI_PROMPT` in `basic_config.php`. L'admin non ha funzionalità di salvataggio per limitare le modifiche accidentali.

---

### Governance

#### CORS Origins (`cors_origins.php`)

Gestione della lista di domini autorizzati a chiamare `chat_api.php`.

Funzionalità:
- Visualizza i domini definiti in `CHATBOT_ALLOWED_ORIGINS`
- Legge anche le direttive `allow from` dall'`.htaccess` a livello radice (se presente) per avere un quadro completo delle restrizioni attive
- Mostra origini raggruppate per sezione `.htaccess`

> La modifica degli `ALLOWED_ORIGINS` si effettua direttamente in `basic_config.php`; il modulo è in sola lettura.

#### Key Rotation (`keys.php`)

Pannello di configurazione e verifica delle chiavi API e credenziali runtime.

Funzionalità:
- Elenca tutte le costanti di configurazione rilevanti con il valore mascherato per i segreti (mostra i primi 4 e gli ultimi 4 caratteri, il resto come `****`)
- Costanti visualizzate: `ANTHROPIC_API_KEY`, `CLAUDE_MODEL`, `DOOFINDER_TOKEN`, `ORDER_API_TOKEN`, `BASE_API_URL`, `ORDER_API_URL`, `ORDERS_API_URL`, `CHECKSESSION_API_URL`, `CHATBOT_WIDGET_TOKEN`, `CHATBOT_WIDGET_DYNAMIC_SECRET`, `CHATBOT_REDIS_*`, `CHATBOT_MYSQL_*`, `DASHBOARD_PASSWORD`, `CHATBOT_ALLOWED_ORIGINS`
- Indica il file sorgente di ciascuna costante (`basic_config.php` o `chatbot_config.php`)
- Permette di aprire `basic_config.php` in editor per la modifica diretta (link al file sul filesystem server)
