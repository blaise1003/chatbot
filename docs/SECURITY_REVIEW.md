# Security Review Chatbot example

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