/**
 * ============================================================
 * getty-widget.js - Logica del Widget Chatbot chatbot
 * Versione: Produzione (con tutte le fix di sicurezza)
 * 
 * ISTRUZIONI:
 *   1. Includere getty-widget.css nel <head>
 *   2. Includere DOMPurify prima di questo script
 *   3. Includere questo script prima di </body>
 *   4. Modificare SOLO la variabile CHATBOT_ENGINE_URL qui sotto
 *      con l'URL assoluto o relativo al vostro chat_api.php
 * ============================================================
 */
console.log("%c[gettyBot] Caricamento widget...", "color: #4A90E2; font-weight: bold;");
(function() {
	console.log("%c[gettyBot] Inizializzazione in corso...", "color: #4A90E2;");
	"use strict";

	const CHATBOT_ENGINE_URL = "//www.chatbot.info/Chatbot/chat_api.php";
	const CHATBOT_HANDOFF_POLL_URL = CHATBOT_ENGINE_URL.replace(/chat_api\.php$/i, 'handoff_poll.php');
	const HISTORY_STORAGE_PREFIX = "pf_chat_history_";

    // URL dell'avatar di getty (opzionale)
    const AVATAR_URL = "//www.chatbot.info/Chatbot/widget/getty.png";

	const widgetHTML = `
		<div id="pf-chat-window" class="handoff">
			<div id="pf-resize-handle" class="pf-resize-handle" title="Trascina per ridimensionare">
			</div>
			<div class="pf-chat-header">
				<div class="pf-chat-avatar">
					<img src="${AVATAR_URL}" alt="getty" onerror="this.style.display='none'">
				</div>
				<div class="pf-chat-title">
					<h3>getty</h3>
					<p><span class="pf-online-dot"></span>Online &mdash; rispondo subito!</p>
				</div>
				<button id="pf-test-toggle" class="pf-test-toggle" title="Attiva/disattiva modalità ordini di test">
					<span class="pf-test-dot"></span>TEST
				</button>
				<button id="pf-mobile-close" class="pf-mobile-close" title="Chiudi chat" aria-label="Chiudi chat">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
				</button>
			</div>
			<div class="pf-chat-body" id="pf-chat-body">
				<div class="pf-message ai">
					Ciao! Sono <strong>getty</strong>, l'assistente virtuale di chatbot &#128522;<br><br>
					Posso aiutarti a trovare <strong>prodotti</strong> o controllare lo <strong>stato del tuo ordine</strong>. Come posso esserti utile oggi?
				</div>
			</div>
			<div class="pf-chat-footer">
				<input type="text" id="pf-chat-input" class="pf-chat-input"
						placeholder="Scrivi un messaggio..." autocomplete="off" maxlength="500">
				<button id="pf-chat-send" class="pf-chat-send" aria-label="Invia messaggio">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<line x1="22" y1="2" x2="11" y2="13"></line>
						<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
					</svg>
				</button>
			</div>
		</div>
		<div id="pf-widget-fab" role="button" aria-label="Apri chat" tabindex="0">
			<svg class="pf-icon-chat" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
			</svg>
			<svg class="pf-icon-close" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
				<line x1="12" y1="5" x2="12" y2="19"></line>
				<polyline points="19 12 12 19 5 12"></polyline>
			</svg>
		</div>
	`;

	const container = document.createElement("div");
	container.innerHTML = widgetHTML;
	document.body.appendChild(container);

	const fab          = document.getElementById("pf-widget-fab");
	const chatWindow   = document.getElementById("pf-chat-window");
	const body         = document.getElementById("pf-chat-body");
	const input        = document.getElementById("pf-chat-input");
	const sendBtn      = document.getElementById("pf-chat-send");
	const resizeHandle = document.getElementById("pf-resize-handle");

	let sessionId = sessionStorage.getItem('pf_session_id');
	if (!sessionId) {
		sessionId = "ses_" + Math.random().toString(36).substring(2, 12) + window.crypto.randomUUID().substring(2, 12);
		sessionStorage.setItem('pf_session_id', sessionId);
	}
	let historyStorageKey = HISTORY_STORAGE_PREFIX + sessionId;

	// Gestione dimensioni widget
	const SIZE_STORAGE_KEY = 'pf_widget_size';
	const DEFAULT_WIDTH = 380;
	const DEFAULT_HEIGHT = 600;
	const MIN_WIDTH = 280;
	const MIN_HEIGHT = 350;
	const MAX_WIDTH = Math.min(window.innerWidth - 40, 800);
	const MAX_HEIGHT = Math.min(window.innerHeight - 120, 900);

	const loadWidgetSize = () => {
		try {
			const stored = localStorage.getItem(SIZE_STORAGE_KEY);
			if (stored) {
				const size = JSON.parse(stored);
				if (size.width && size.height) {
					return size;
				}
			}
		} catch (_) {}
		return { width: DEFAULT_WIDTH, height: DEFAULT_HEIGHT };
	};

	const saveWidgetSize = (width, height) => {
		try {
			localStorage.setItem(SIZE_STORAGE_KEY, JSON.stringify({ width, height }));
		} catch (_) {}
	};

	const applyWidgetSize = (width, height) => {
		chatWindow.style.width = width + 'px';
		chatWindow.style.height = height + 'px';
	};

	const initialSize = loadWidgetSize();
	applyWidgetSize(initialSize.width, initialSize.height);

	// Logica di resize drag
	let isResizing = false;
	let resizeStartX = 0;
	let resizeStartY = 0;
	let resizeStartWidth = DEFAULT_WIDTH;
	let resizeStartHeight = DEFAULT_HEIGHT;

	resizeHandle.addEventListener('mousedown', (e) => {
		e.preventDefault();
		isResizing = true;
		resizeStartX = e.clientX;
		resizeStartY = e.clientY;
		resizeStartWidth = parseInt(window.getComputedStyle(chatWindow).width, 10);
		resizeStartHeight = parseInt(window.getComputedStyle(chatWindow).height, 10);
		document.body.style.userSelect = 'none';
		chatWindow.style.transition = 'none';
	});

	document.addEventListener('mousemove', (e) => {
		if (!isResizing) return;
	
		const deltaX = e.clientX - resizeStartX;
		const deltaY = e.clientY - resizeStartY;
	
		let newWidth = Math.max(MIN_WIDTH, Math.min(resizeStartWidth - deltaX, MAX_WIDTH));
		let newHeight = Math.max(MIN_HEIGHT, Math.min(resizeStartHeight - deltaY, MAX_HEIGHT));
	
		applyWidgetSize(newWidth, newHeight);
	});

	document.addEventListener('mouseup', (e) => {
		if (!isResizing) return;
		isResizing = false;
		document.body.style.userSelect = '';
		chatWindow.style.transition = '';
	
		const finalWidth = parseInt(window.getComputedStyle(chatWindow).width, 10);
		const finalHeight = parseInt(window.getComputedStyle(chatWindow).height, 10);
		saveWidgetSize(finalWidth, finalHeight);
	});

	const saveCachedHistory = (history) => {
		if (!Array.isArray(history)) return;
		try {
			sessionStorage.setItem(historyStorageKey, JSON.stringify(history));
		} catch (_) {
			// Best effort cache: ignore quota/storage errors
		}
	};

	const loadCachedHistory = () => {
		try {
			const raw = sessionStorage.getItem(historyStorageKey);
			if (!raw) return null;
			const parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : null;
		} catch (_) {
			return null;
		}
	};

	// Parametro opzionale dall'URL dello script stesso: <script src="getty-widget.js?sessionId=...">
	const getScriptParams = () => {
		const scripts = Array.from(document.querySelectorAll('script'));
		const thisScript = scripts.find(s => s.src && s.src.includes('getty-widget'));
		if (!thisScript || !thisScript.src) return {};
		const url = new URL(thisScript.src, window.location.href);
		return Object.fromEntries(url.searchParams);
	};
	const scriptParams = getScriptParams();
	const customerSessionId = (scriptParams.customerSessionId || '').trim();
	const customerId = (scriptParams.v3 || '').trim();
	const authToken = (scriptParams.token || '').trim();


	console.log("[gettyBot] Sessione:", sessionId, "Sessione esterna:", customerSessionId || "N/A", "ID cliente:", customerId || "N/A", "Modalità test:", localStorage.getItem('pf_test_mode') === 'true' ? "Attiva" : "Disattiva");	

	let isSending = false;
	let isRecoveringWidget = false;
	let handoffStatus = "none";
	let lastOperatorMessageId = 0;
	let handoffPollTimer = null;
	let handoffPollInFlight = false;

	const HANDOFF_POLL_ACTIVE_MS = 2000;
	const HANDOFF_POLL_IDLE_MS = 7000;
	const HANDOFF_POLL_ERROR_MS = 10000;
	const HANDOFF_POLL_RATE_LIMIT_MS = 15000;
	const HANDOFF_POLL_JITTER_MS = 350;
	const HANDOFF_POLL_REQUEST_TIMEOUT_MS = 5000;

	const recoverWidgetAndRefreshToken = (reason) => {
		if (isRecoveringWidget) return;
		isRecoveringWidget = true;
		console.warn('[gettyBot] Avvio recovery widget. Motivo:', reason);

		setTimeout(() => {
			// Il token viene rigenerato lato server dal wrapper PHP del sito.
			window.location.reload();
		}, 900);
	};
	
	let testMode = localStorage.getItem('pf_test_mode');
	if (testMode === null) {
		testMode = true; // Modalità test attiva di default al primo caricamento
		localStorage.setItem('pf_test_mode', testMode);
	} else {
		testMode = testMode === 'true';
	}

	let inactivityTimeout;
	const resetInactivity = () => {
		clearTimeout(inactivityTimeout);
		inactivityTimeout = setTimeout(() => {
			// Resetta la sessione
			sessionStorage.removeItem(historyStorageKey);
			sessionStorage.removeItem('pf_session_id');
			sessionId = "ses_" + Math.random().toString(36).substring(2, 12);
			sessionStorage.setItem('pf_session_id', sessionId);
			historyStorageKey = HISTORY_STORAGE_PREFIX + sessionId;
			document.querySelectorAll('.pf-sessionId').forEach((el) => { el.textContent = sessionId; });
			
			// Notifica all'utente della scadenza
			const timeoutMsg = "&#9200; <i>La tua sessione &egrave; stata chiusa per inattivit&agrave; (10 minuti).<br>Scrivi un nuovo messaggio qui sotto se desideri ricominciare una conversazione!</i>";
			addMessage(timeoutMsg, "ai");
		}, 600000); // 10 minuti di inattività
	};

	// Ascolta interazioni per resettare il timer
	['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(evt => {
		document.addEventListener(evt, resetInactivity, { passive: true });
	});
	resetInactivity();

	const testToggleBtn = document.getElementById('pf-test-toggle');
	const applyTestMode = () => {
		if (testMode) {
			testToggleBtn.classList.add('active');
			testToggleBtn.title = 'Modalita TEST attiva - ordini fittizi (clicca per disattivare)';
		} else {
			testToggleBtn.classList.remove('active');
			testToggleBtn.title = 'Attiva modalita TEST ordini fittizi';
		}
	};
	applyTestMode();

	testToggleBtn.addEventListener('click', (e) => {
		e.stopPropagation();
		testMode = !testMode;
		localStorage.setItem('pf_test_mode', testMode);
		applyTestMode();
		const msg = testMode
			? '&#129514; <strong>Modalit&agrave; TEST attivata!</strong><br><small>Ordini: <b>1000001</b> (In lavorazione), <b>1000002</b> (Spedito/BRT), <b>1000003</b> (Consegnato/GLS), <b>1000004</b> (Rimborso/DHL), <b>1000005</b> (Annullato)</small>'
			: '&#9989; <strong>Modalit&agrave; REALE attivata.</strong><br><small>Il bot interrogher&agrave; l\'API reale di chatbot.</small>';
		addMessage(msg, 'ai');
	});

	const toggleChat = () => {
		fab.classList.toggle("pf-open");
		chatWindow.classList.toggle("pf-open");
		if (chatWindow.classList.contains("pf-open")) {
			setTimeout(() => input.focus(), 300);
		}
	};

	fab.addEventListener("click", toggleChat);
	document.getElementById("pf-mobile-close").addEventListener("click", toggleChat);

	const containsMaliciousPattern = (v) => {
		if (typeof v !== "string") return false;

		const patterns = [
			// SQL injection patterns
			/(?:\bselect\b|\binsert\b|\bupdate\b|\bdelete\b|\bdrop\b|\bunion\b|\btruncate\b|\balter\b|\bcreate\b|\bexec\b|\bexecute\b)\s+/i,
			/(?:--|#|\/\*)/,
			/\b(?:or|and)\b\s+['"]?\d+['"]?\s*=\s*['"]?\d+['"]?/i,
			// JavaScript/HTML injection patterns
			/<\s*script\b/i,
			/<\s*\/\s*script\s*>/i,
			/\bon\w+\s*=/i,
			/javascript\s*:/i,
			/\$\{[^}]*\}/,
			// PHP injection patterns
			/<\?(?:php|=)?/i,
			/\?>/,
			// Shell command injection patterns
			/(?:`[^`]*`|\$\([^)]*\))/,
			/(?:^|[^a-zA-Z0-9_])(rm|cat|curl|wget|bash|sh|chmod|chown|nc|netcat)(?:\s|$)/i,
			/(?:;|&&|\|\|)\s*(?:rm|cat|curl|wget|bash|sh|chmod|chown|nc|netcat)\b/i,
			// Path traversal patterns
			/(?:\.\.\/|\.\.\\|%2e%2e%2f|%2e%2e\\|%2e%2e)/i,
		];

		return patterns.some((re) => re.test(v));
	};

	const validateResponseSafety = (d, k = []) => {
		const seen = new WeakSet();

		const walk = (value, path = []) => {
			if (value === null || value === undefined) return true;
			if (typeof value === "string") return !containsMaliciousPattern(value);
			if (typeof value !== "object") return true;
			if (Array.isArray(value)) return value.every((item) => walk(item, path));

			if (seen.has(value)) return true;
			seen.add(value);

			for (const key of Object.keys(value)) {
				if (path.length === 0 && Array.isArray(k) && k.length > 0 && !k.includes(key)) {
					continue;
				}
				if (containsMaliciousPattern(key)) return false;
				if (!walk(value[key], path.concat(key))) return false;
			}

			return true;
		};

		return walk(d, []);
	};

	const escapeUserText = (text) => {
		const div = document.createElement("div");
		div.textContent = text;
		return div.innerHTML.replace(/\n/g, "<br>");
	};

	const formatOperatorMessage = (text) => {
		if (typeof text !== "string") return "";

		const escaped = escapeUserText(text);
		const urlRegex = /((?:https?:\/\/|www\.)[^\s<]+)/gi;

		return escaped.replace(urlRegex, (match) => {
			let url = match;
			let trailing = "";

			while (url.length > 0 && /[),.!?;:]$/.test(url)) {
				trailing = url.slice(-1) + trailing;
				url = url.slice(0, -1);
			}

			if (url === "") {
				return match;
			}

			const href = /^https?:\/\//i.test(url) ? url : "https://" + url;
			return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>' + trailing;
		});
	};

	const sanitizeBotHtml = (html) => {
		const template = document.createElement("template");
		template.innerHTML = html;

		const allowedTags = new Set(["A", "B", "BR", "CODE", "DIV", "EM", "H4", "I", "IMG", "SMALL", "SPAN", "STRONG"]);
		const allowedAttrs = new Set(["alt", "class", "href", "rel", "src", "style", "target"]);

		const cleanNode = (node) => {
			if (node.nodeType === Node.TEXT_NODE) {
				return;
			}

			if (node.nodeType !== Node.ELEMENT_NODE) {
				node.remove();
				return;
			}

			if (!allowedTags.has(node.tagName)) {
				const fragment = document.createDocumentFragment();
				while (node.firstChild) {
					fragment.appendChild(node.firstChild);
				}
				node.replaceWith(fragment);
				return;
			}

			[...node.attributes].forEach((attr) => {
				const name = attr.name.toLowerCase();
				const value = attr.value.trim();

				if (!allowedAttrs.has(name) || name.startsWith("on")) {
					node.removeAttribute(attr.name);
					return;
				}

				if ((name === "href" || name === "src") && value !== "") {
					if (!/^https?:\/\//i.test(value)) {
						node.removeAttribute(attr.name);
					}
				}

				if (name === "target" && value === "_blank") {
					node.setAttribute("rel", "noopener noreferrer");
				}
			});

			[...node.childNodes].forEach(cleanNode);
		};

		[...template.content.childNodes].forEach(cleanNode);
		return template.innerHTML;
	};

	let notificationAudioCtx = null;
	let notificationAudioUnlocked = false;
	const supportsNotificationSound = (() => {
		const Ctx = window.AudioContext || window.webkitAudioContext;
		return typeof Ctx === 'function';
	})();

	const getNotificationAudioContext = () => {
		if (!supportsNotificationSound) return null;
		if (notificationAudioCtx) return notificationAudioCtx;
		const Ctx = window.AudioContext || window.webkitAudioContext;
		if (!Ctx) return null;
		try {
			notificationAudioCtx = new Ctx();
		} catch (_) {
			notificationAudioCtx = null;
		}
		return notificationAudioCtx;
	};

	const unlockNotificationAudio = () => {
		const ctx = getNotificationAudioContext();
		if (!ctx) return;

		if (ctx.state === 'suspended') {
			const resumeResult = ctx.resume();
			if (resumeResult && typeof resumeResult.catch === 'function') {
				resumeResult.catch(() => {});
			}
		}

		notificationAudioUnlocked = ctx.state === 'running';
	};

	const playIncomingMessageSound = () => {
		const ctx = getNotificationAudioContext();
		if (!ctx || !notificationAudioUnlocked) return;

		try {
			const now = ctx.currentTime;
			const gain = ctx.createGain();
			gain.connect(ctx.destination);
			gain.gain.setValueAtTime(0.0001, now);
			gain.gain.exponentialRampToValueAtTime(0.05, now + 0.01);
			gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);

			const osc1 = ctx.createOscillator();
			osc1.type = 'sine';
			osc1.frequency.setValueAtTime(1046.5, now);
			osc1.connect(gain);
			osc1.start(now);
			osc1.stop(now + 0.09);

			const osc2 = ctx.createOscillator();
			osc2.type = 'sine';
			osc2.frequency.setValueAtTime(1318.5, now + 0.09);
			osc2.connect(gain);
			osc2.start(now + 0.09);
			osc2.stop(now + 0.18);
		} catch (_) {
			// Best effort notification sound
		}
	};

	if (supportsNotificationSound) {
		['pointerdown', 'keydown', 'touchstart'].forEach((evt) => {
			window.addEventListener(evt, unlockNotificationAudio, { once: true, passive: true });
		});
	}

	const addMessage = (html, sender, options = {}) => {
		console.log(`[gettyBot] Aggiunta messaggio (${sender}):`, html);
		const msg = document.createElement("div");
		msg.className = "pf-message " + sender;
		msg.innerHTML = sender === "user" ? escapeUserText(html) : sanitizeBotHtml(html);
		body.appendChild(msg);
		body.scrollTop = body.scrollHeight;

		if (!options.silent && sender !== "user") {
			playIncomingMessageSound();
		}
	};

	const extractAssistantReply = (content) => {
		if (typeof content !== "string") return "";
		const trimmed = content.trim();
		if (!trimmed) return "";

		try {
			const parsed = JSON.parse(trimmed);
			if (parsed && typeof parsed === "object" && typeof parsed.reply === "string") {
				if (parsed.operator_handoff === true) {
					return formatOperatorMessage(parsed.reply);
				}
				return parsed.reply;
			}
		} catch (_) {
			// Non JSON: fallback al contenuto raw
		}

		return trimmed;
	};

	const hydrateHistory = (history) => {
		if (!Array.isArray(history) || history.length === 0) return false;

		const rendered = [];
		for (const item of history) {
			if (!item || typeof item !== "object") continue;
			const role = typeof item.role === "string" ? item.role.toLowerCase() : "";
			const content = typeof item.content === "string" ? item.content : "";

			if (role === "system" || !content.trim()) continue;

			if (role === "user") {
				rendered.push({ sender: "user", html: content });
				continue;
			}

			if (role === "assistant" || role === "ai") {
				const reply = extractAssistantReply(content);
				if (reply) {
					rendered.push({ sender: "ai", html: reply });
				}
			}
		}

		if (rendered.length === 0) return false;

		console.log("[gettyBot] Storia caricata dalla cache:", rendered);

		body.innerHTML = "";
		for (const msg of rendered) {
			addMessage(msg.html, msg.sender, { silent: true });
		}

		return true;
	};

	// Primo caricamento widget: ripristina subito la history cache della sessione corrente
	const cachedHistory = loadCachedHistory();
	if (cachedHistory) {
		hydrateHistory(cachedHistory);
	}

	const showTyping = () => {
		const id  = "typing-" + Date.now();
		const msg = document.createElement("div");
		msg.className = "pf-message ai pf-typing";
		msg.id        = id;
		msg.innerHTML = '<div class="pf-typing-dot"></div><div class="pf-typing-dot"></div><div class="pf-typing-dot"></div>';
		body.appendChild(msg);
		body.scrollTop = body.scrollHeight;
		return id;
	};
	const removeTyping = (id) => { const el = document.getElementById(id); if (el) el.remove(); };

	const OPERATOR_TYPING_ID = 'pf-operator-typing';
	const showOperatorTyping = (operatorName) => {
		const existing = document.getElementById(OPERATOR_TYPING_ID);
		const safeName = typeof operatorName === 'string' && operatorName.trim() !== '' ? operatorName.trim() : 'Operatore';
		const label = '<span class="pf-operator-typing-badge"><span class="pf-dot"></span>' + safeName + ' sta scrivendo...</span>';
		const typingDots = '<div class="pf-typing-dot"></div><div class="pf-typing-dot"></div><div class="pf-typing-dot"></div>';

		if (existing) {
			existing.setAttribute('data-operator', safeName);
			existing.innerHTML = typingDots + label;
			return;
		}

		const msg = document.createElement('div');
		msg.id = OPERATOR_TYPING_ID;
		msg.className = 'pf-message ai pf-typing pf-typing-operator';
		msg.setAttribute('data-operator', safeName);
		msg.innerHTML = typingDots + label;
		body.appendChild(msg);
		body.scrollTop = body.scrollHeight;
	};

	const hideOperatorTyping = () => {
		const el = document.getElementById(OPERATOR_TYPING_ID);
		if (el) el.remove();
	};

    // ---- Invio Messaggio ----
    const sendChat = async (text, first = false) => {
        if (!text.trim() || isSending) return;
        isSending = true;
        sendBtn.style.opacity = "0.5";
        addMessage(text, "user");
        input.value = "";
        const typingId = showTyping();

        try {
            const res = await fetch(CHATBOT_ENGINE_URL, {
				method:  "POST",
				headers: { "Content-Type": "application/json", "Authorization": "Bearer " + authToken },
				body:    JSON.stringify({ 
					session_id: sessionId, 
					message: text, 
					test_mode: testMode, 
					customerSessionId: customerSessionId,
					customerId: customerId // V3 per compatibilità con vecchi script che usano "v3" come parametro per ID cliente
				})
            });
            removeTyping(typingId);

            // Gestione errori HTTP differenziata
            if (!res.ok) {
				console.warn("[gettyBot] Risposta non OK dal server:", res.status, res.statusText);
                try {
                    const errData = await res.json();
                    if (errData.reply) {
                        addMessage(errData.reply, "ai");
					if (res.status === 401 || res.status === 403) {
						recoverWidgetAndRefreshToken('http_' + res.status);
					}
                        return;
                    }
                } catch(_) {}

                const errorMessages = {
					429: "&#9203; Troppe richieste! Attendi qualche secondo prima di inviare un nuovo messaggio.",
					503: "&#128591; Il servizio è momentaneamente sovraccarico. Riprova tra qualche secondo!",
					500: "&#9888;&#65039; Errore interno del server. Stiamo lavorando per risolverlo, riprova tra poco!",
                };
                const errMsg = errorMessages[res.status] 
					|| (res.status >= 500 ? "&#9888;&#65039; I server sono temporaneamente non disponibili. Riprova tra poco!" 
					: "&#9888;&#65039; Errore di comunicazione (" + res.status + "). Riprova!");
                addMessage(errMsg, "ai");
				if (res.status === 401 || res.status === 403 || res.status >= 500) {
					recoverWidgetAndRefreshToken('http_' + res.status);
				}
                return;
            }

			const data = await res.json();
			// // Validazione sicurezza: rilevamento injection pattern nella risposta
			// if (!validateResponseSafety(data, ['reply', 'options', 'handoff', 'history'])) {
			// 	console.error("[gettyBot] Risposta contiene pattern sospetti di injection");
			// 	addMessage("&#9888;&#65039; Errore di sicurezza nella risposta del server. Riprova.", "ai");
			// 	return;
			// }
			if (data && data.handoff && typeof data.handoff.status === 'string') {
				console.log("[gettyBot] Stato handoff aggiornato:", data.handoff.status);
				handoffStatus = data.handoff.status;
			}
			if (data && data.reply && handoffStatus != 'claimed') {
				console.warn("[gettyBot] Risposta del server non valida o handoff non ancora preso in carico:", data);
				addMessage(data.reply || "Non ho capito, puoi ripetere? &#128522;", "ai");
			}
			if (Array.isArray(data.history)) {
				saveCachedHistory(data.history);
			}
        } catch (e) {
            removeTyping(typingId);
            if (!navigator.onLine) {
				addMessage("&#128225; Sembra che tu sia offline. Controlla la connessione e riprova! ", "ai");
				console.log("[gettyBot] Utente offline:", e);
            } else {
				addMessage("&#9888;&#65039; Impossibile raggiungere il server. Riprova tra qualche secondo! " + e.message, "ai");
				console.log("[gettyBot] Errore server:", e);
				recoverWidgetAndRefreshToken('fetch_exception');
            }
            console.error("[gettyBot] Errore fetch:", e);
        } finally {
            isSending = false;
            sendBtn.style.opacity = "1";
            input.focus();
        }
    };

	sendBtn.addEventListener("click", () => sendChat(input.value));
	input.addEventListener("keypress", (e) => { if (e.key === "Enter") sendChat(input.value); });

	// Fix body height per scroll quando le dimensioni cambiano
	const updateBodyHeight = () => {
		const header = chatWindow.querySelector('.pf-chat-header');
		const footer = chatWindow.querySelector('.pf-chat-footer');
		if (header && footer) {
			const headerHeight = header.offsetHeight;
			const footerHeight = footer.offsetHeight;
			const chatHeight = chatWindow.offsetHeight;
			body.style.maxHeight = (chatHeight - headerHeight - footerHeight) + 'px';
		}
	};

	const clearHandoffPollTimer = () => {
		if (handoffPollTimer !== null) {
			clearTimeout(handoffPollTimer);
			handoffPollTimer = null;
		}
	};

	const pollJitter = () => Math.floor(Math.random() * (HANDOFF_POLL_JITTER_MS + 1));

	const nextPollDelay = (reason) => {
		switch (reason) {
			case 'active':
				return HANDOFF_POLL_ACTIVE_MS + pollJitter();
			case 'rate_limited':
				return HANDOFF_POLL_RATE_LIMIT_MS + pollJitter();
			case 'error':
				return HANDOFF_POLL_ERROR_MS + pollJitter();
			default:
				return HANDOFF_POLL_IDLE_MS + pollJitter();
		}
	};

	const scheduleNextHandoffPoll = (reason) => {
		clearHandoffPollTimer();
		handoffPollTimer = setTimeout(() => {
			pollHandoff();
		}, nextPollDelay(reason));
	};

	const pollHandoff = async () => {
		if (!sessionId || !authToken) {
			hideOperatorTyping();
			scheduleNextHandoffPoll('idle');
			return;
		}

		if (handoffStatus !== 'requested' && handoffStatus !== 'claimed') {
			hideOperatorTyping();
			scheduleNextHandoffPoll('idle');
			return;
		}

		if (handoffPollInFlight) {
			scheduleNextHandoffPoll('active');
			return;
		}

		handoffPollInFlight = true;
		const controller = new AbortController();
		const timeoutId = setTimeout(() => controller.abort(), HANDOFF_POLL_REQUEST_TIMEOUT_MS);
		let nextReason = 'active';

		try {
			const res = await fetch(CHATBOT_HANDOFF_POLL_URL, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + authToken },
				signal: controller.signal,
				body: JSON.stringify({
					session_id: sessionId,
					last_operator_message_id: lastOperatorMessageId,
				})
			});

			if (!res.ok) {
				nextReason = res.status === 429 ? 'rate_limited' : 'error';
				return;
			}
			const data = await res.json();
			if (!validateResponseSafety(data, ['ok', 'handoff', 'messages'])) {
				console.error('[gettyBot] Risposta polling handoff contiene pattern sospetti di injection');
				nextReason = 'error';
				return;
			}

			if (!data || data.ok !== true) {
				nextReason = 'error';
				return;
			}

			if (data.handoff && typeof data.handoff.status === 'string') {
				const previous = handoffStatus;
				handoffStatus = data.handoff.status;
				if (data.handoff.operator_typing === true) {
					showOperatorTyping(data.handoff.operator_typing_by || data.handoff.claimed_by || 'Operatore');
				} else {
					hideOperatorTyping();
				}
				if (previous !== handoffStatus && handoffStatus === 'claimed') {
					addMessage('Un operatore umano ha preso in carico la conversazione.', 'ai');
				}
				if (previous !== handoffStatus && handoffStatus === 'closed') {
					hideOperatorTyping();
					addMessage('La conversazione con l\'operatore &egrave; terminata. Puoi continuare con l\'assistente virtuale.', 'ai');
				}

				if (handoffStatus !== 'requested' && handoffStatus !== 'claimed') {
					nextReason = 'idle';
				}
			}

			if (Array.isArray(data.messages) && data.messages.length > 0) {
				hideOperatorTyping();
				for (const msg of data.messages) {
					if (!msg || typeof msg !== 'object') continue;
					const id = Number(msg.id || 0);
					if (id > lastOperatorMessageId) {
						lastOperatorMessageId = id;
					}
					const text = typeof msg.text === 'string' ? msg.text : '';
					if (text.trim() !== '') {
						addMessage(formatOperatorMessage(text), 'ai');
					}
				}
			}
		} catch (_) {
			nextReason = 'error';
		} finally {
			clearTimeout(timeoutId);
			handoffPollInFlight = false;
			scheduleNextHandoffPoll(nextReason);
		}
	};
	updateBodyHeight();
	window.addEventListener('load', updateBodyHeight);
	document.addEventListener('visibilitychange', () => {
		scheduleNextHandoffPoll(document.hidden ? 'idle' : 'active');
	});
	
	// Aggiorna l'altezza del body durante il resize
	const observer = new MutationObserver(updateBodyHeight);
	observer.observe(chatWindow, { attributes: true, attributeFilter: ['style'] });
	scheduleNextHandoffPoll('idle');
})();