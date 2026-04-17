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
