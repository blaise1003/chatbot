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