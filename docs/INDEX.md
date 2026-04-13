# Chatbot example — Hub Documentazione

**Versione:** 1.0  
**Data:** 13-04-2026  
**Tecnologia:** PHP 8+ · Anthropic Claude · Redis · MySQL · JavaScript

---

## ?? Documentazione Centrale

Questo file è il punto di ingresso alla documentazione completa del chatbot. Tutti gli aspetti del sistema sono documentati in file specializzati che puoi consultare navigando i link sottostanti.

---

## ?? Accesso Rapido per Ruolo

### ????? Sviluppatore / Tecnico

Parti da qui se devi **comprendere il sistema**, fare **modifiche al codice** o **effettuare debug**:

1. Leggi [**ISTRUZIONI.md**](ISTRUZIONI.md) — Panoramica completa (architettura, flussi, struttura codice)
2. Consulta [**API_INTEGRAZIONI.md**](API_INTEGRAZIONI.md) — Dettagli su Claude, Doofinder, API ordini
3. Approfondisci il codice — Vedi sezione **Mappa struttura file** più sotto

### ?? DevOps / Deployment

Se devi **distribuire il sistema** in produzione o staging:

1. Leggi [**ISTRUZIONI.md**](ISTRUZIONI.md#7-modalità-test-vs-modalità-reale) — Configurazione e modalità test
2. Segui [**DEPLOY_CHECKLIST.md**](DEPLOY_CHECKLIST.md) — Passi esatti per il deploy
3. Rivedi [**SECURITY_REVIEW.md**](SECURITY_REVIEW.md) — Aspetti di sicurezza critici

### ?? Integratore / Frontend

Se devi **integrare il widget** nel tuo sito:

1. Leggi [**GUIDA_INTEGRAZIONE.md**](GUIDA_INTEGRAZIONE.md) — Istruzioni passo per passo
2. Consulta [**ISTRUZIONI.md**](ISTRUZIONI.md#5-sicurezza-delle-comunicazioni--token-dinamici) — Gestione token dinamici
3. Vedi gli esempi di codice in [**API_INTEGRAZIONI.md**](API_INTEGRAZIONI.md#5-costanti-di-configurazione)

### ?? Security Officer / Compliance

Se devi **verificare l'aspetto di sicurezza** del sistema:

1. Leggi [**SECURITY_REVIEW.md**](SECURITY_REVIEW.md) — Panoramica completa dei rischi
2. Consulta [**ISTRUZIONI.md**](ISTRUZIONI.md#5-sicurezza-delle-comunicazioni--token-dinamici) — Gestione token e autenticazione
3. Verifica [**DEPLOY_CHECKLIST.md**](DEPLOY_CHECKLIST.md#6-verifiche-di-sicurezza) — Checklist di sicurezza pre-deploy

---

## ?? Documentazione per Argomento

### Requisiti e Pianificazione

| Documento | Argomento | Pubblico |
|---|---|---|
| [ISTRUZIONI.md](ISTRUZIONI.md#1-requisiti-tecnici) | Requisiti tecnici (PHP 8+, estensioni, librerie) | Tecnico, DevOps |
| [ISTRUZIONI.md](ISTRUZIONI.md#2-requisiti-funzionali) | Requisiti funzionali (AI, ricerca, ordini, persistenza) | Tutti |

### Architettura e Design

| Documento | Argomento | Pubblico |
|---|---|---|
| [ISTRUZIONI.md](ISTRUZIONI.md#3-progettazione-e-architettura) | Pattern DI, flusso request/response, storage a 3 layer | Sviluppatore |
| [ISTRUZIONI.md](ISTRUZIONI.md#4-strutturazione-del-codice) | Mappa file, namespace, interfacce | Sviluppatore |
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#6-architettura-di-storage--sessioni-e-conversazioni) | Storage Redis/File/MySQL con diagramma | Sviluppatore, DevOps |

### Integrazioni esterne

| Documento | Argomento | Pubblico |
|---|---|---|
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#1-claude-anthropic--ai-engine) | Claude: endpoint, retry, format risposta | Sviluppatore |
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#2-doofinder--ricerca-prodotti) | Doofinder: ricerca, mapping campi | Sviluppatore |
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#3-exampleorg--oct8ne--api-ordini) | Oct8ne: getOrderDetails, checkSession, sicurezza | Sviluppatore |
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#4-rate-limiting-per-provider) | Rate limiting bucket provider | Sviluppatore, DevOps |

### Sicurezza

| Documento | Argomento | Pubblico |
|---|---|---|
| [ISTRUZIONI.md](ISTRUZIONI.md#5-sicurezza-delle-comunicazioni--token-dinamici) | Token dinamici HMAC-SHA256 con format e validazione | Sviluppatore, Integratore |
| [SECURITY_REVIEW.md](SECURITY_REVIEW.md) | Analisi completa di rischi e remediazione | Security Officer, DevOps |
| [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md#6-verifiche-di-sicurezza) | Checklist precisi di hardening | DevOps |

### Rate Limiting

| Documento | Argomento | Pubblico |
|---|---|---|
| [ISTRUZIONI.md](ISTRUZIONI.md#6-gestione-dei-limiti--rate-limiting) | Architettura 4 bucket, Redis 3-layer, FileStorage | Sviluppatore, DevOps |
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#4-rate-limiting-per-provider) | Limitazione provider specifici (Claude, Doofinder) | Sviluppatore |

### Pannello Admin

| Documento | Argomento | Pubblico |
|---|---|---|
| [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md#7-pannello-admin) | Panoramica moduli admin (Dashboard, Redis Admin, Logs) | Tecnico, DevOps |
| [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md#7-configurazione-admin) | Setup e hardening admin | DevOps |

### Modalità Test

| Documento | Argomento | Pubblico |
|---|---|---|
| [ISTRUZIONI.md](ISTRUZIONI.md#7-modalità-test-vs-modalità-reale) | Differenze test mode vs reale, ordini fittizi | Sviluppatore, Tester |

### Integrazione Widget

| Documento | Argomento | Pubblico |
|---|---|---|
| [GUIDA_INTEGRAZIONE.md](GUIDA_INTEGRAZIONE.md) | Istruzioni step-by-step integrazione nel sito | Integratore, Frontend |
| [ISTRUZIONI.md](ISTRUZIONI.md#5-sicurezza-delle-comunicazioni--token-dinamici) | Token dinamico per widget | Integratore |

### Deployment

| Documento | Argomento | Pubblico |
|---|---|---|
| [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md) | Checklist completa prima/dopo deploy | DevOps |
| [ISTRUZIONI.md](ISTRUZIONI.md#1-requisiti-tecnici) | Requisiti tecnici server | DevOps |

---

## ??? Mappa Struttura File Progetto

```
examplechat/Chatbot/
?
??? ?? DOCUMENTAZIONE
?   ??? INDEX.md                    ? Sei qui (hub centrale)
?   ??? ISTRUZIONI.md               ? Documentazione tecnica completa
?   ??? API_INTEGRAZIONI.md         ? Dettagli API esterne
?   ??? GUIDA_INTEGRAZIONE.md       ? Istruzioni integrazione widget
?   ??? DEPLOY_CHECKLIST.md         ? Checklist pre/post deploy
?   ??? SECURITY_REVIEW.md          ? Analisi sicurezza
?
??? ?? CONFIGURAZIONE (non esporre in web root)
?   ??? chatbot_config.php          ? Segreti API reali + costanti
?   ??? basic_config.php            ? Configurazione aggiuntiva
?
??? ?? ENTRY POINT
?   ??? chat_api.php                ? Endpoint principale chatbot
?   ??? widget.php                  ? Serving del widget JavaScript
?
??? ??? CLASSI PHP (namespace Chatbot)
?   ??? ApplicationBootstrap.php    ? DI container factory
?   ??? ChatbotApplication.php      ? Handler principale flusso request
?   ??? RequestGuard.php            ? CORS + Bearer token + security headers
?   ??? SessionManager.php          ? Orchestratore storage multi-layer
?   ??? TrafficLimiter.php          ? Rate limiter centralizzato
?   ??? ClaudeClient.php            ? Client Anthropic API
?   ??? ProductSearchService.php    ? Client Doofinder
?   ??? OrderService.php            ? Client API ordini (Oct8ne)
?   ??? HtmlSanitizer.php           ? Sanitizzatore XSS
?   ??? Logger.php                  ? Helper logging
?   ??? AiClientInterface.php       ? Interfaccia per provider AI
?   ?
?   ??? storage/                    ? Layer storage
?       ??? StorageInterface.php    ? Contratto polimorfismo storage
?       ??? RedisStorage.php        ? Layer 1: in-memory veloce
?       ??? FileStorage.php         ? Layer 2: fallback (sempre disponibile)
?       ??? MySqlStorage.php        ? Layer 3: persistenza analitica
?
??? ?? WIDGET FRONTEND
?   ??? widget/
?   ?   ??? getty-widget.js        ? IIFE autocontenuta (zero dipendenze)
?   ?   ??? getty-widget.css       ? Stili scoped
?   ?   ??? widget.html             ? Template (placeholder sostituiti)
?
??? ??? ADMIN PANEL
?   ??? admin/
?   ?   ??? index.php               ? Overview + health check
?   ?   ??? dashboard.php           ? Lista/ricerca conversazioni MySQL
?   ?   ??? redis_admin.php         ? Diagnostica Redis
?   ?   ??? logs.php                ? Visualizzatore log
?   ?   ??? prompt.php              ? Visualizzatore system prompt AI
?   ?   ??? cors_origins.php        ? Gestione domini CORS
?   ?   ??? keys.php                ? Visualizzatore config (mascherato)
?   ?   ??? css/                    ? Fogli di stile admin
?   ?   ??? views/
?   ?       ??? _admin_menu.php     ? Navigazione sidebar
?   ?       ??? _admin_header.php   ? Header pagine admin
?   ?
?   ??? migration.sql               ? Schema MySQL per dashboard
?
??? ?? RUNTIME
?   ??? version.txt                 ? Versione applicazione
?   ??? .htaccess                   ? Configurazione Apache
?   ??? index.html                  ? Landing page dev
?   ??? chatbot_sessions/           ? Storage file sessioni (Layer 2)
?   ??? chatbot_logs/               ? Log applicativi
?
```

---

## ?? Flusso di Lettura Consigliato

### Per nuovi sviluppatori

```
1. Leggi qui (INDEX.md)
   ?
2. ISTRUZIONI.md (sezioni 1-4)  [Cosa è, come è strutturato]
   ?
3. ISTRUZIONI.md (sezione 6)    [Rate limiting]
   ?
4. API_INTEGRAZIONI.md          [Integrazioni esterne dettagliate]
   ?
5. Approfondimenti specifici (token, admin, storage)
```

### Per deploy in produzione

```
1. Leggi ISTRUZIONI.md (sezione 1)  [Requisiti server]
   ?
2. SECURITY_REVIEW.md               [Rischi e mitigazioni]
   ?
3. DEPLOY_CHECKLIST.md              [Passi esatti]
   ?
4. Verifica ISTRUZIONI.md (sezione 5) [Token dinamici hardened]
```

### Per integrare il widget

```
1. Leggi GUIDA_INTEGRAZIONE.md      [Come integrare]
   ?
2. ISTRUZIONI.md sezione 5          [Token dinamici]
   ?
3. Implementa token generation nel tuo sito
   ?
4. Test con example.org/Chatbot/admin/index.php?module=health
```

---

## ?? Concetti Chiave

### Token Dinamici
- **Formato:** `pfw1.{expires}.{nonce}.{hmac}`
- **Firma:** HMAC-SHA256
- **Validazione:** 8 controlli di sicurezza (vedi [ISTRUZIONI.md §5](ISTRUZIONI.md#5-sicurezza-delle-comunicazioni--token-dinamici))
- **File:** [RequestGuard.php](classes/RequestGuard.php)

### Rate Limiting
- **4 bucket indipendenti:** per-IP, globale, Claude, Doofinder
- **3 layer Redis:** sliding window (minuto) + fixed (ora) + fixed (giorno)
- **Fallback:** FileStorage se Redis unavailable
- **Dettagli:** [ISTRUZIONI.md §6](ISTRUZIONI.md#6-gestione-dei-limiti--rate-limiting)

### Storage a 3 Layer
- **Layer 1 (Redis):** Veloce, in-memory, sessioni + rate limit con TTL
- **Layer 2 (File):** Sempre disponibile, backup durevole, fallback Redis
- **Layer 3 (MySQL):** Analitica persistente, flush event-driven
- **Dettagli:** [API_INTEGRAZIONI.md §6](API_INTEGRAZIONI.md#6-architettura-di-storage--sessioni-e-conversazioni)

### Test Mode
- Attivabile solo se `CHATBOT_ALLOW_TEST_MODE=true` + payload `test_mode: true`
- Restituisce ordini fittizi (1000001–1000005)
- Bypasspa checkSession e API ordini reale
- Togglabile da UI widget
- **Disabilitare in produzione** impostando `CHATBOT_ALLOW_TEST_MODE=false`
- **Dettagli:** [ISTRUZIONI.md §7](ISTRUZIONI.md#7-modalità-test-vs-modalità-reale)

---

## ?? Supporto e Contatti

| Ruolo | Documento di riferiemento |
|---|---|
| Bug/Issues tecnici | [ISTRUZIONI.md](ISTRUZIONI.md) + [API_INTEGRAZIONI.md](API_INTEGRAZIONI.md) |
| Sicurezza/Vulnerabilità | [SECURITY_REVIEW.md](SECURITY_REVIEW.md) |
| Deploy/Infrastructure | [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md) |
| Integrazione widget | [GUIDA_INTEGRAZIONE.md](GUIDA_INTEGRAZIONE.md) |

---

## ?? Versione e Aggiornamenti

| Data | Versione | Descrizione |
|---|---|---|
| 13-04-2026 | 1.0 | Release iniziale: documentazione completa, rate limiting 3-layer, token dinamici, API esterne |

---

**Ultima lettura consigliata:** Tutti vorrete leggere la sezione [Rate Limiting](ISTRUZIONI.md#6-gestione-dei-limiti--rate-limiting) di [ISTRUZIONI.md](ISTRUZIONI.md) — è il cuore del sistema di protezione da carichi anomali.
