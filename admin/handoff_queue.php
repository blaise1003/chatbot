<?php

//declare(strict_types=1);

session_start();

$configCandidates = [
    __DIR__ . '/chatbot_config.php',
    dirname(__DIR__) . '/chatbot_config.php',
    dirname(__DIR__, 2) . '/chatbot_config.php',
];
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require_once $configPath;
        break;
    }
}

require_once __DIR__ . '/views/_admin_menu.php';
require_once __DIR__ . '/views/_admin_header.php';

if (!isset($_SESSION['dashboard_auth']) || $_SESSION['dashboard_auth'] !== true) {
    header('Location: dashboard.php');
    exit;
}

if (!isset($_SESSION['dashboard_csrf']) || !is_string($_SESSION['dashboard_csrf'])) {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['dashboard_csrf'] = bin2hex(openssl_random_pseudo_bytes(24));
    } else {
        $_SESSION['dashboard_csrf'] = hash('sha256', session_id() . ':' . (string) time());
    }
}

if (!isset($_SESSION['dashboard_operator']) || !is_string($_SESSION['dashboard_operator']) || trim($_SESSION['dashboard_operator']) === '') {
    $_SESSION['dashboard_operator'] = 'operatore-admin';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handoff Queue</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <style>
        .handoff-grid { display:grid; grid-template-columns: 360px 1fr; gap:16px; }
        .queue-filters { display:flex; gap:8px; margin:8px 0 10px; }
        .queue-filter { padding:6px 10px; border-radius:999px; border:1px solid #d1d5db; background:#fff; color:#374151; font-size:12px; cursor:pointer; }
        .queue-filter.active { background:#2563eb; border-color:#2563eb; color:#fff; }
        .handoff-list { max-height: 70vh; overflow:auto; border:1px solid #e5e7eb; border-radius:10px; }
        .handoff-item { padding:12px; border-bottom:1px solid #eef2f7; cursor:pointer; }
        .handoff-item.active { background:#eff6ff; }
        .handoff-item.expired { background:#fff7ed; border-left:4px solid #f59e0b; }
        .handoff-item.expired.active { background:#ffedd5; }
        .muted { color:#6b7280; font-size:12px; }
        .expired-label { display:inline-block; margin-left:6px; padding:2px 6px; border-radius:999px; font-size:11px; font-weight:700; color:#92400e; background:#fde68a; }
        .session-warning { margin-top:8px; padding:8px 10px; border-radius:8px; font-size:12px; color:#92400e; background:#ffedd5; border:1px solid #fdba74; }
        .session-warning.claim-required { color:#9f1239; background:#ffe4e6; border-color:#fda4af; font-weight:600; }
        .session-warning.claimed-locked { color:#1e40af; background:#dbeafe; border-color:#93c5fd; }
        .chat-box { border:1px solid #e5e7eb; border-radius:10px; min-height:420px; padding:12px; overflow:auto; background:#fff; }
        .chat-row { margin:8px 0; }
        .chat-user { background:#f3f4f6; padding:8px 10px; border-radius:8px; }
        .chat-op { background:#dbeafe; padding:8px 10px; border-radius:8px; }
        .chat-meta { color:#6b7280; font-size:12px; margin-bottom:4px; }
        .actions { display:flex; gap:8px; margin-top:12px; }
        .msg-form { margin-top:12px; display:flex; gap:8px; }
        .msg-form input { flex:1; }
		#claim-btn:disabled, #close-btn:disabled { cursor:not-allowed; opacity:0.6; background-color: #374151;}
        .emoji-toolbar { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
        .emoji-btn { border:1px solid #d1d5db; border-radius:8px; background:#fff; padding:4px 8px; cursor:pointer; font-size:16px; line-height:1; }
        .emoji-btn:hover { background:#f9fafb; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('handoff-queue') ?>
    <main class="admin-main">
        <?= admin_render_header(
            'handoff-queue',
            'Handoff Queue',
            'Sessioni che richiedono intervento umano e chat operatore.',
            [
                ['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
                ['label' => 'Aggiorna', 'href' => 'handoff_queue.php', 'class' => 'secondary'],
            ]
        ) ?>

        <section class="panel">
            <div class="handoff-grid">
                <div>
                    <h3>Sessioni in coda</h3>
                    <div class="queue-filters" id="queue-filters">
                        <button type="button" class="queue-filter active" data-filter="all">Tutte</button>
                        <button type="button" class="queue-filter" data-filter="active">Attive</button>
                        <button type="button" class="queue-filter" data-filter="expired">Scadute</button>
                    </div>
                    <div id="handoff-list" class="handoff-list"></div>
                </div>
                <div>
                    <h3>Dettaglio sessione</h3>
                    <div id="detail-head" class="muted">Seleziona una sessione dalla coda.</div>
                    <div id="chat-box" class="chat-box"></div>
                    <div class="actions">
                        <button id="claim-btn" class="btn secondary" type="button">Prendi in carico</button>
                        <button id="close-btn" class="btn secondary" type="button">Chiudi handoff</button>
                    </div>
                    <form id="msg-form" class="msg-form">
                        <input id="msg-input" type="text" placeholder="Scrivi risposta operatore..." maxlength="2000">
                        <button class="btn" type="submit">Invia</button>
                    </form>
                    <div class="emoji-toolbar" aria-label="Emoji rapide">
                        <button type="button" class="emoji-btn" data-emoji="🙂" title="Sorriso">🙂</button>
                        <button type="button" class="emoji-btn" data-emoji="😊" title="Felice">😊</button>
                        <button type="button" class="emoji-btn" data-emoji="😀" title="Grande sorriso">😀</button>
                        <button type="button" class="emoji-btn" data-emoji="😄" title="Entusiasta">😄</button>
                        <button type="button" class="emoji-btn" data-emoji="😉" title="Occhiolino">😉</button>
                        <button type="button" class="emoji-btn" data-emoji="🤩" title="Fantastico">🤩</button>
                        <button type="button" class="emoji-btn" data-emoji="😎" title="Ottimo">😎</button>
                        <button type="button" class="emoji-btn" data-emoji="🥳" title="Festeggiamo">🥳</button>
                        <button type="button" class="emoji-btn" data-emoji="👍" title="Ok">👍</button>
                        <button type="button" class="emoji-btn" data-emoji="👌" title="Perfetto">👌</button>
                        <button type="button" class="emoji-btn" data-emoji="🤝" title="Accordo">🤝</button>
                        <button type="button" class="emoji-btn" data-emoji="🙏" title="Grazie">🙏</button>
                        <button type="button" class="emoji-btn" data-emoji="❤️" title="Apprezzamento">❤️</button>
                        <button type="button" class="emoji-btn" data-emoji="✅" title="Confermato">✅</button>
                        <button type="button" class="emoji-btn" data-emoji="⭐" title="Ottimo servizio">⭐</button>
                        <button type="button" class="emoji-btn" data-emoji="🎉" title="Congratulazioni">🎉</button>
                        <button type="button" class="emoji-btn" data-emoji="📦" title="Ordine">📦</button>
                        <button type="button" class="emoji-btn" data-emoji="🚚" title="Spedizione">🚚</button>
                        <button type="button" class="emoji-btn" data-emoji="🔧" title="Assistenza tecnica">🔧</button>
                        <button type="button" class="emoji-btn" data-emoji="💡" title="Consiglio">💡</button>
                        <button type="button" class="emoji-btn" data-emoji="📝" title="Nota">📝</button>
                        <button type="button" class="emoji-btn" data-emoji="🔗" title="Link">🔗</button>
                        <button type="button" class="emoji-btn" data-emoji="⏳" title="In attesa">⏳</button>
                        <button type="button" class="emoji-btn" data-emoji="🔔" title="Notifica">🔔</button>
                        <button type="button" class="emoji-btn" data-emoji="❓" title="Domanda">❓</button>
                        <button type="button" class="emoji-btn" data-emoji="⚠️" title="Attenzione">⚠️</button>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
(function () {
    const csrf = <?= json_encode($_SESSION['dashboard_csrf'], JSON_UNESCAPED_UNICODE) ?>;
    const currentOperator = <?= json_encode((string) $_SESSION['dashboard_operator'], JSON_UNESCAPED_UNICODE) ?>;
    let selectedSessionId = '';

    const listEl = document.getElementById('handoff-list');
    const chatEl = document.getElementById('chat-box');
    const headEl = document.getElementById('detail-head');
    const claimBtn = document.getElementById('claim-btn');
    const closeBtn = document.getElementById('close-btn');
    const msgForm = document.getElementById('msg-form');
    const msgInput = document.getElementById('msg-input');
    const emojiButtons = Array.from(document.querySelectorAll('.emoji-btn'));
    const filterWrap = document.getElementById('queue-filters');
    const filterButtons = Array.from(filterWrap.querySelectorAll('[data-filter]'));
    let currentFilter = 'all';
    let latestItems = [];
    let typingStopTimer = null;
    let typingSessionId = '';
    let typingState = false;

    function setReplyEnabled(enabled, disabledMessage = '') {
        msgInput.disabled = !enabled;
        msgForm.querySelector('button[type="submit"]').disabled = !enabled;
        if (!enabled) {
            msgInput.placeholder = disabledMessage || 'Risposta operatore disabilitata';
        } else {
            msgInput.placeholder = 'Scrivi risposta operatore...';
        }
    }

    async function api(action, payload = {}, method = 'POST') {
        const data = new URLSearchParams({ action, csrf, ...payload });
        const res = await fetch('handoff_api.php', {
            method,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: method === 'POST' ? data.toString() : null,
        });
        return res.json();
    }

    function clearTypingStopTimer() {
        if (typingStopTimer !== null) {
            clearTimeout(typingStopTimer);
            typingStopTimer = null;
        }
    }

    async function setOperatorTyping(sessionId, isTyping, force = false) {
        const sid = String(sessionId || '').trim();
        if (!sid) return;

        if (!force && typingSessionId === sid && typingState === isTyping) {
            return;
        }

        typingSessionId = sid;
        typingState = isTyping;

        try {
            await api('typing', { session_id: sid, is_typing: isTyping ? '1' : '0' });
        } catch (_) {
            // best effort typing indicator
        }
    }

    function scheduleTypingStop(sessionId) {
        clearTypingStopTimer();
        typingStopTimer = setTimeout(() => {
            setOperatorTyping(sessionId, false);
        }, 1500);
    }

    function applyListFilter(items) {
        if (currentFilter === 'active') {
            return items.filter((item) => !Boolean(item._is_expired));
        }
        if (currentFilter === 'expired') {
            return items.filter((item) => Boolean(item._is_expired));
        }

        return items;
    }

    function emptyListMessage() {
        if (currentFilter === 'active') {
            return 'Nessuna sessione attiva in handoff.';
        }
        if (currentFilter === 'expired') {
            return 'Nessuna sessione scaduta in handoff.';
        }

        return 'Nessuna sessione in handoff.';
    }

    function renderList(items) {
        const visibleItems = applyListFilter(items);
        listEl.innerHTML = '';
        if (!Array.isArray(visibleItems) || visibleItems.length === 0) {
            listEl.innerHTML = '<div class="handoff-item muted">' + emptyListMessage() + '</div>';
            return;
        }

        visibleItems.forEach((item) => {
            const div = document.createElement('div');
            const isExpired = Boolean(item._is_expired);
            div.className = 'handoff-item'
                + (item.session_id === selectedSessionId ? ' active' : '')
                + (isExpired ? ' expired' : '');
            div.innerHTML = '<strong>' + item.session_id + (isExpired ? '<span class="expired-label">SCADUTA</span>' : '') + '</strong>'
                + '<div class="muted">Stato: ' + item.status + ' | by: ' + (item.requested_by || '-') + '</div>'
                + '<div class="muted">Aggiornata: ' + (item.updated_at || '-') + '</div>';
            div.addEventListener('click', () => {
                if (selectedSessionId && selectedSessionId !== item.session_id) {
                    setOperatorTyping(selectedSessionId, false, true);
                }
                selectedSessionId = item.session_id;
                loadState();
            });
            listEl.appendChild(div);
        });
    }

    function renderChat(state) {
        const isExpired = Boolean(state._is_expired);
        const status = typeof state.status === 'string' ? state.status : 'none';
        const claimedBy = typeof state.claimed_by === 'string' ? state.claimed_by.trim() : '';
        const isClaimed = status === 'claimed';
        const isClaimedByCurrent = isClaimed && claimedBy !== '' && claimedBy === currentOperator;
        const isClaimedByOther = isClaimed && claimedBy !== '' && claimedBy !== currentOperator;
        const requiresClaim = !isExpired && !isClaimed;

        headEl.textContent = 'Sessione: ' + state.session_id + ' | Stato: ' + state.status
            + ' | claimed by: ' + (state.claimed_by || '-')
            + (isExpired ? ' | SCADUTA' : '');

        if (isExpired) {
            setReplyEnabled(false, 'Sessione scaduta: risposta operatore disabilitata');
        } else if (requiresClaim) {
            setReplyEnabled(false, 'Clicca prima "Prendi in carico" per poter rispondere');
        } else if (isClaimedByOther) {
            setReplyEnabled(false, 'Sessione in carico ad altro operatore');
        } else if (isClaimedByCurrent) {
            setReplyEnabled(true);
        } else {
            setReplyEnabled(false, 'Sessione non in carico');
        }

        // Se gia claimed, non ha senso cliccare "Prendi in carico" di nuovo.
        claimBtn.disabled = isExpired || isClaimed;
        claimBtn.title = claimBtn.disabled
            ? (isExpired ? 'Sessione scaduta' : 'Sessione gia presa in carico')
            : 'Prendi in carico la sessione';

        chatEl.innerHTML = '';
        if (isExpired) {
            const warning = document.createElement('div');
            warning.className = 'session-warning';
            warning.textContent = 'Questa sessione è scaduta: l\'operatore non può più inviare risposte.';
            chatEl.appendChild(warning);
        }

        if (requiresClaim) {
            const warning = document.createElement('div');
            warning.className = 'session-warning claim-required';
            warning.textContent = 'Per rispondere devi prima cliccare "Prendi in carico".';
            chatEl.appendChild(warning);
        }

        if (isClaimedByOther) {
            const warning = document.createElement('div');
            warning.className = 'session-warning claimed-locked';
            warning.textContent = 'Questa conversazione è già in carico a ' + claimedBy + '.';
            chatEl.appendChild(warning);
        }

        const widgetMessages = Array.isArray(state.widget_messages) ? state.widget_messages : [];
        const operatorMessages = Array.isArray(state.operator_messages) ? state.operator_messages : [];

        const timeline = [];
        let fallbackOrder = 0;
        const maxLen = Math.max(widgetMessages.length, operatorMessages.length);
        for (let i = 0; i < maxLen; i += 1) {
            const userMessage = widgetMessages[i];
            if (userMessage) {
                timeline.push({
                    role: 'user',
                    text: typeof userMessage.text === 'string' ? userMessage.text : '',
                    at: typeof userMessage.at === 'string' ? userMessage.at : '',
                    fallbackOrder,
                });
                fallbackOrder += 1;
            }

            const operatorMessage = operatorMessages[i];
            if (operatorMessage) {
                timeline.push({
                    role: 'operator',
                    text: typeof operatorMessage.text === 'string' ? operatorMessage.text : '',
                    at: typeof operatorMessage.at === 'string' ? operatorMessage.at : '',
                    operator: typeof operatorMessage.operator === 'string' ? operatorMessage.operator : 'Operatore',
                    fallbackOrder,
                });
                fallbackOrder += 1;
            }
        }

        timeline.sort((a, b) => {
            const ta = a.at ? Date.parse(a.at) : NaN;
            const tb = b.at ? Date.parse(b.at) : NaN;

            const aValid = Number.isFinite(ta);
            const bValid = Number.isFinite(tb);
            if (aValid && bValid && ta !== tb) {
                return ta - tb;
            }
            if (a.fallbackOrder !== b.fallbackOrder) {
                return a.fallbackOrder - b.fallbackOrder;
            }

            return a.role.localeCompare(b.role);
        });

        timeline.forEach((m) => {
            const row = document.createElement('div');
            row.className = 'chat-row';
            const when = formatMessageTime(m.at);
            if (m.role === 'user') {
                row.innerHTML = '<div class="chat-meta">' + escapeHtml(when) + '</div>'
                    + '<div class="chat-user"><strong>Cliente</strong><br>' + formatChatMessage(m.text) + '</div>';
            } else {
                row.innerHTML = '<div class="chat-meta">' + escapeHtml(when) + '</div>'
                    + '<div class="chat-op"><strong>' + escapeHtml(m.operator || 'Operatore') + '</strong><br>' + formatChatMessage(m.text) + '</div>';
            }
            chatEl.appendChild(row);
        });

        chatEl.scrollTop = chatEl.scrollHeight;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    function formatChatMessage(text) {
        const escaped = escapeHtml(text).replace(/\n/g, '<br>');
        const urlRegex = /((?:https?:\/\/|www\.)[^\s<]+)/gi;

        return escaped.replace(urlRegex, (match) => {
            let url = match;
            let trailing = '';

            while (url.length > 0 && /[),.!?;:]$/.test(url)) {
                trailing = url.slice(-1) + trailing;
                url = url.slice(0, -1);
            }

            if (url === '') {
                return match;
            }

            const href = /^https?:\/\//i.test(url) ? url : 'https://' + url;
            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trailing;
        });
    }

    function formatMessageTime(value) {
        const parsed = value ? Date.parse(value) : NaN;
        if (!Number.isFinite(parsed)) {
            return '-';
        }

        const date = new Date(parsed);
        return date.toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    async function loadList() {
        const response = await api('list', {}, 'POST');
        if (!response.ok) {
            return;
        }
        latestItems = Array.isArray(response.items) ? response.items : [];
        const visibleItems = applyListFilter(latestItems);
        renderList(latestItems);

        if (selectedSessionId && !visibleItems.some((item) => item.session_id === selectedSessionId)) {
            selectedSessionId = '';
            chatEl.innerHTML = '';
            headEl.textContent = 'Seleziona una sessione dalla coda.';
            setReplyEnabled(false, 'Seleziona una sessione per rispondere');
            claimBtn.disabled = true;
        }
    }

    filterButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            currentFilter = btn.getAttribute('data-filter') || 'all';
            filterButtons.forEach((item) => {
                item.classList.toggle('active', item === btn);
            });
            const visibleItems = applyListFilter(latestItems);
            renderList(latestItems);
            if (selectedSessionId && !visibleItems.some((item) => item.session_id === selectedSessionId)) {
                selectedSessionId = '';
                chatEl.innerHTML = '';
                headEl.textContent = 'Seleziona una sessione dalla coda.';
                setReplyEnabled(false, 'Seleziona una sessione per rispondere');
                claimBtn.disabled = true;
            }
        });
    });

    async function loadState() {
        if (!selectedSessionId) {
            return;
        }
        const response = await api('get', { session_id: selectedSessionId }, 'POST');
        if (!response.ok) {
            if (response.error) {
                headEl.textContent = response.error;
            }
            selectedSessionId = '';
            chatEl.innerHTML = '';
            setReplyEnabled(false, 'Seleziona una sessione per rispondere');
            claimBtn.disabled = true;
            return;
        }
        renderChat(response.state || {});
        await loadList();
    }

    claimBtn.addEventListener('click', async () => {
        if (!selectedSessionId) return;
        await api('claim', { session_id: selectedSessionId });
        await loadState();
    });

    closeBtn.addEventListener('click', async () => {
        if (!selectedSessionId) return;
        await api('close', { session_id: selectedSessionId, note: 'Chiusa da operatore' });
        selectedSessionId = '';
        chatEl.innerHTML = '';
        headEl.textContent = 'Seleziona una sessione dalla coda.';
        setReplyEnabled(false, 'Seleziona una sessione per rispondere');
        claimBtn.disabled = true;
        await loadList();
    });

    msgForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!selectedSessionId) return;
        if (msgInput.disabled) return;
        const text = msgInput.value.trim();
        if (!text) return;

        clearTypingStopTimer();
        await setOperatorTyping(selectedSessionId, false, true);
        await api('send', { session_id: selectedSessionId, message: text });
        msgInput.value = '';
        await loadState();
    });

    msgInput.addEventListener('input', () => {
        if (!selectedSessionId || msgInput.disabled) {
            return;
        }

        if (msgInput.value.trim() === '') {
            clearTypingStopTimer();
            setOperatorTyping(selectedSessionId, false);
            return;
        }

        setOperatorTyping(selectedSessionId, true);
        scheduleTypingStop(selectedSessionId);
    });

    msgInput.addEventListener('blur', () => {
        clearTypingStopTimer();
        if (selectedSessionId) {
            setOperatorTyping(selectedSessionId, false);
        }
    });

    emojiButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const emoji = button.getAttribute('data-emoji') || '';
            if (!emoji || msgInput.disabled) {
                return;
            }

            const start = msgInput.selectionStart ?? msgInput.value.length;
            const end = msgInput.selectionEnd ?? msgInput.value.length;
            const value = msgInput.value;
            msgInput.value = value.slice(0, start) + emoji + value.slice(end);
            const nextPos = start + emoji.length;
            msgInput.setSelectionRange(nextPos, nextPos);
            msgInput.focus();
        });
    });

    setInterval(async () => {
        if (selectedSessionId) {
            await loadState();
        } else {
            await loadList();
        }
    }, 3000);

    setReplyEnabled(false, 'Seleziona una sessione per rispondere');
    claimBtn.disabled = true;
    loadList();
})();
</script>
</body>
</html>
