# Test Suite Chatbot

Suite di test lightweight senza dipendenze esterne.

## Esecuzione

Da root del workspace:

```bash
php chatbotchat/Chatbot/tests/run.php
```

Da `chatbotchat/Chatbot`:

```bash
php tests/run.php
```

## Copertura attuale

- Sanitizzazione HTML (`HtmlSanitizer`)
- Validazione token dinamico (`RequestGuard`, via reflection sul metodo interno)
- Metriche logger (`Logger::trackMetric`)
- Integrazione `health.php` (richiesta locale autorizzata + richiesta remota non autorizzata)
- Handoff umano: apertura richiesta e claim esclusivo operatore (contenzione)

## Nota

I test usano i file reali del progetto e scrivono metriche in `chatbotchat/chatbot_logs/`.
