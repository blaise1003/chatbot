<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

require_once dirname(__DIR__) . '/classes/storage/StorageInterface.php';
require_once dirname(__DIR__) . '/classes/storage/RedisStorage.php';

require_once __DIR__ . '/views/_admin_menu.php';
require_once __DIR__ . '/views/_admin_header.php';

use Chatbot\RedisStorage;

$host = defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1';
$port = defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379;
$password = defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '';
$prefix = defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'prezzy:';
$ttl = defined('CHATBOT_REDIS_TTL') ? (int) CHATBOT_REDIS_TTL : 3600;

$demoSessionId = 'demo_session_redis';
$demoRateKey = 'demo-rate-limit';
$demoSessionKey = $prefix . 'sess:' . $demoSessionId;
$demoRateRedisKey = $prefix . 'rl:' . $demoRateKey;

$redisExtensionEnabled = extension_loaded('redis') && class_exists('Redis');

$nativeRedis = null;
$nativeConnectionOk = false;
$pingResult = null;
$nativeError = '';

if ($redisExtensionEnabled) {
	try {
		$nativeRedis = new Redis();
		$nativeConnectionOk = @$nativeRedis->connect($host, $port, 2.0);
		if ($nativeConnectionOk && $password !== '') {
			$nativeConnectionOk = $nativeRedis->auth($password);
			if (!$nativeConnectionOk) {
				$nativeError = 'Autenticazione Redis fallita.';
			}
		}

		if ($nativeConnectionOk) {
			$pingResult = $nativeRedis->ping();
		} elseif ($nativeError === '') {
			$nativeError = 'Connessione Redis fallita.';
		}
	} catch (Throwable $e) {
		$nativeError = $e->getMessage();
	}
}

$storage = new RedisStorage($host, $port, $password, $prefix, $ttl);
$storageAvailable = $storage->isAvailable();

$sampleHistory = [
	['role' => 'system', 'content' => 'Demo RedisStorage'],
	['role' => 'user', 'content' => 'Cerco una lavatrice'],
	['role' => 'assistant', 'content' => json_encode([
		'reply' => 'Ti aiuto subito a trovare una lavatrice.',
		'Keyword search' => 'lavatrice'
	], JSON_UNESCAPED_UNICODE)],
];

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'overview';
$scanPattern = isset($_GET['pattern']) ? trim((string) $_GET['pattern']) : '*';
$scanPattern = $scanPattern === '' ? '*' : $scanPattern;
$demoMessages = [];
$loadedHistory = [];
$sessionTtl = null;
$rawSessionPayload = null;
$rateSnapshots = [];
$redisEntries = [];
$redisScanError = '';

function normalize_redis_value($value)
{
	if ($value === false || $value === null) {
		return null;
	}

	if (is_string($value)) {
		$decoded = json_decode($value, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}
		return $value;
	}

	return $value;
}

function redis_type_label($type)
{
	if ($type === Redis::REDIS_STRING) return 'string';
	if ($type === Redis::REDIS_SET) return 'set';
	if ($type === Redis::REDIS_LIST) return 'list';
	if ($type === Redis::REDIS_ZSET) return 'zset';
	if ($type === Redis::REDIS_HASH) return 'hash';
	if (defined('Redis::REDIS_STREAM') && $type === Redis::REDIS_STREAM) return 'stream';
	return 'unknown';
}

if ($storageAvailable) {
	try {
		if ($action === 'save-session') {
			$storage->saveHistory($demoSessionId, $sampleHistory);
			$demoMessages[] = 'Sessione demo salvata in Redis tramite RedisStorage::saveHistory().';
		}

		if ($action === 'load-session' || $action === 'save-session') {
			$loadedHistory = $storage->loadHistory($demoSessionId);
			$demoMessages[] = 'Sessione demo letta tramite RedisStorage::loadHistory().';
		}

		if ($action === 'rate-limit') {
			for ($attempt = 1; $attempt <= 15; $attempt++) {
				sleep(5);
				$blocked = $storage->checkRateLimit($demoRateKey, 10, 60);
				$rateSnapshots[] = [
					'attempt' => $attempt,
					'status' => $blocked ? 'BLOCCATA' : 'CONSENTITA',
				];
			}
			$demoMessages[] = 'Simulazione sliding-window completata: limite 3 richieste in 60 secondi.';
		}

		if ($action === 'cleanup' && $nativeRedis instanceof Redis && $nativeConnectionOk) {
			$nativeRedis->del($demoSessionKey, $demoRateRedisKey);
			$demoMessages[] = 'Chiavi demo Redis eliminate.';
		}

		if ($action === 'scan-all' && $nativeRedis instanceof Redis && $nativeConnectionOk) {
			$iterator = null;
			$allKeys = [];
			do {
				$batch = $nativeRedis->scan($iterator, $scanPattern, 200);
				if ($batch !== false) {
					$allKeys = array_merge($allKeys, $batch);
				}
			} while ($iterator > 0);

			$allKeys = array_values(array_unique($allKeys));
			sort($allKeys, SORT_NATURAL);

			$maxKeys = 500;
			if (count($allKeys) > $maxKeys) {
				$demoMessages[] = 'Trovate ' . count($allKeys) . ' chiavi; mostro solo le prime ' . $maxKeys . '.';
				$allKeys = array_slice($allKeys, 0, $maxKeys);
			}

			foreach ($allKeys as $key) {
				$type = (int) $nativeRedis->type($key);
				$ttlValue = $nativeRedis->ttl($key);
				$value = null;

				if ($type === Redis::REDIS_STRING) {
					$value = normalize_redis_value($nativeRedis->get($key));
				} elseif ($type === Redis::REDIS_HASH) {
					$value = $nativeRedis->hGetAll($key);
				} elseif ($type === Redis::REDIS_LIST) {
					$value = $nativeRedis->lRange($key, 0, 99);
				} elseif ($type === Redis::REDIS_SET) {
					$value = $nativeRedis->sMembers($key);
				} elseif ($type === Redis::REDIS_ZSET) {
					$value = $nativeRedis->zRange($key, 0, 99, true);
				} else {
					$value = '[Tipo non supportato in demo]';
				}

				$encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
				if ($encoded === false) {
					$encoded = '[Impossibile serializzare il valore]';
				}

				$isSessionKey = strpos($key, 'prezzy:sess:') === 0;
				$historyRows = [];
				if ($isSessionKey && is_array($value)) {
					foreach ($value as $row) {
						if (!is_array($row)) {
							continue;
						}
						$role = isset($row['role']) ? (string) $row['role'] : '';
						$content = isset($row['content']) ? (string) $row['content'] : '';
						if ($role === '' && $content === '') {
							continue;
						}
						$historyRows[] = [
							'role' => $role,
							'content' => $content,
						];
					}
				}

				$historyJson = json_encode($historyRows, JSON_UNESCAPED_UNICODE);
				if ($historyJson === false) {
					$historyJson = '[]';
				}

				$redisEntries[] = [
					'key' => $key,
					'type' => redis_type_label($type),
					'ttl' => $ttlValue,
					'value' => $encoded,
					'is_session_key' => $isSessionKey,
					'history_json' => $historyJson,
				];
			}

			$demoMessages[] = 'Scansione Redis completata: ' . count($redisEntries) . ' chiavi mostrate (pattern: ' . $scanPattern . ').';
		}

		if ($nativeRedis instanceof Redis && $nativeConnectionOk) {
			$sessionTtl = $nativeRedis->ttl($demoSessionKey);
			$rawSessionPayload = $nativeRedis->get($demoSessionKey);
		}
	} catch (Throwable $e) {
		$demoMessages[] = 'Errore demo: ' . $e->getMessage();
	}
}

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function badge(bool $value): string
{
	$class = $value ? 'ok' : 'ko';
	$label = $value ? 'OK' : 'NO';
	return '<span class="badge ' . $class . '">' . $label . '</span>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Demo Redis Prezzy</title>
	<link rel="stylesheet" href="css/admin-base.css">
	<link rel="stylesheet" href="css/admin-menu.css">
	<link rel="stylesheet" href="css/admin-header.css">
	<link rel="stylesheet" href="css/redis-demo.css">
</head>
<body>
	<div class="admin-layout">
		<?= admin_render_sidebar('redis') ?>
	<main class="admin-main">
	<div class="page">
		<?= admin_render_header(
			'redis',
			'Demo Redis del chatbot',
			'Disponibilita estensione, connessione, session storage con TTL e rate limit sliding-window.',
			[
				['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
				['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
			]
		) ?>

		<div class="grid">
			<div class="card">
				<h2>Estensioni PHP</h2>
				<div class="label mt-12">Redis</div>
				<div class="value"><?= badge($redisExtensionEnabled) ?></div>
			</div>
			<div class="card">
				<h2>Connessione nativa</h2>
				<div class="label">Host</div>
				<div class="value"><?= h($host . ':' . $port) ?></div>
				<div class="label mt-12">Connessione</div>
				<div class="value"><?= badge($nativeConnectionOk) ?></div>
				<div class="label mt-12">PING</div>
				<div class="value"><?= h($pingResult === null ? 'N/D' : (string) $pingResult) ?></div>
			</div>
			<div class="card">
				<h2>RedisStorage</h2>
				<div class="label">Disponibile</div>
				<div class="value"><?= badge($storageAvailable) ?></div>
				<div class="label mt-12">Prefix</div>
				<div class="value"><?= h($prefix) ?></div>
				<div class="label mt-12">TTL sessione</div>
				<div class="value"><?= h((string) $ttl) ?> secondi</div>
			</div>
			<div class="card">
				<h2>Chiavi demo</h2>
				<div class="label">Session key</div>
				<div class="value"><?= h($demoSessionKey) ?></div>
				<div class="label mt-12">Rate key</div>
				<div class="value"><?= h($demoRateRedisKey) ?></div>
			</div>
		</div>

		<div class="actions">
			<a class="btn" href="?action=save-session">Salva sessione demo</a>
			<a class="btn" href="?action=load-session">Leggi sessione demo</a>
			<a class="btn" href="?action=rate-limit">Simula rate limit</a>
			<a class="btn secondary" href="?action=cleanup">Pulisci chiavi demo</a>
			<a class="btn secondary" href="?action=scan-all&amp;pattern=*">Vedi tutte le chiavi Redis</a>
			<a class="btn secondary" href="?action=overview">Reset vista</a>
		</div>

		<div class="panel">
			<h3>Esplora chiavi Redis</h3>
			<p>Usa un pattern Redis SCAN (esempi: <strong>*</strong>, <strong>prezzy:*</strong>, <strong>*sess:*</strong>).</p>
			<form method="get" class="actions compact">
				<input type="hidden" name="action" value="scan-all">
				<input type="text" class="input-compact" name="pattern" value="<?= h($scanPattern) ?>">
				<button type="submit" class="btn">Scansiona</button>
			</form>
		</div>

		<?php if ($nativeError !== ''): ?>
		<div class="panel">
			<h3>Errore connessione</h3>
			<p class="note"><?= h($nativeError) ?></p>
		</div>
		<?php endif; ?>

		<?php if (!empty($demoMessages)): ?>
		<div class="panel">
			<h3>Output demo</h3>
			<ul>
				<?php foreach ($demoMessages as $message): ?>
				<li><?= h($message) ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<div class="panel">
			<h3>Session storage</h3>
			<p>La demo salva un array `history` come JSON con `SETEX`, esattamente come fa `RedisStorage::saveHistory()` nel progetto.</p>
			<div class="label">TTL corrente della sessione demo</div>
			<div class="value"><?= h($sessionTtl === null ? 'N/D' : (string) $sessionTtl) ?></div>
			<div class="label mt-12">Payload raw in Redis</div>
			<pre><?= h($rawSessionPayload === null || $rawSessionPayload === false ? 'Nessun payload presente' : $rawSessionPayload) ?></pre>
			<div class="label">History caricata tramite RedisStorage</div>
			<pre><?= h(json_encode($loadedHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
		</div>

		<div class="panel">
			<h3>Sliding-window rate limit</h3>
			<p>La simulazione usa `ZADD + ZREMRANGEBYSCORE + ZCARD`, lo stesso meccanismo usato dal chatbot per limitare le richieste per IP.</p>
			<?php if (empty($rateSnapshots)): ?>
			<p>Nessuna simulazione eseguita. Premi <a class="btn" href="?action=rate-limit">Simula rate limit</a> per vedere quando la quarta e quinta richiesta vengono bloccate.</p>
			<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Tentativo</th>
						<th>Esito</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rateSnapshots as $snapshot): ?>
					<tr>
						<td><?= h((string) $snapshot['attempt']) ?></td>
						<td><?= h($snapshot['status']) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<?php if ($action === 'scan-all'): ?>
		<div class="panel">
			<h3>Dump chiavi/valori Redis</h3>
			<?php if ($redisScanError !== ''): ?>
			<p class="note"><?= h($redisScanError) ?></p>
			<?php elseif (empty($redisEntries)): ?>
			<p>Nessuna chiave trovata per il pattern richiesto.</p>
			<?php else: ?>
			<table class="redis-dump-table">
				<thead>
					<tr>
						<th>Key</th>
						<th>Type</th>
						<th>TTL (s)</th>
						<th>Session View</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($redisEntries as $entry): ?>
					<tr>
						<td><?= h($entry['key']) ?></td>
						<td><?= h($entry['type']) ?></td>
						<td><?= h((string) $entry['ttl']) ?></td>
						<td>
							<?php if (!empty($entry['is_session_key'])): ?>
							<button
								type="button"
								class="btn secondary js-open-session-modal"
								data-key="<?= h($entry['key']) ?>"
								data-history="<?= h($entry['history_json']) ?>"
							>
								Visualizza
							</button>
							<?php else: ?>
							<span class="muted">-</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div id="session-view-modal" class="session-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="session-modal-title">
				<div class="session-modal">
					<div class="session-modal-head">
						<h3 id="session-modal-title">Dettaglio Sessione Redis</h3>
						<button type="button" class="session-modal-close" data-close-session-modal="1" aria-label="Chiudi">x</button>
					</div>
					<div id="session-modal-meta" class="session-modal-meta"></div>
					<div id="session-modal-body" class="session-modal-body"></div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
	</main>
	</div>

	<script>
	(function () {
		const modal = document.getElementById('session-view-modal');
		if (!modal) {
			return;
		}

		const modalBody = document.getElementById('session-modal-body');
		const modalMeta = document.getElementById('session-modal-meta');
		const closeButton = modal.querySelector('[data-close-session-modal="1"]');
		const openButtons = document.querySelectorAll('.js-open-session-modal');
		const focusableSelector = 'button, [href], [tabindex]:not([tabindex="-1"])';
		let lastTrigger = null;

		function escapeHtml(value) {
			return String(value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}

		function openModal() {
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
			document.body.classList.add('modal-open');
			if (closeButton) {
				closeButton.focus();
			}
		}

		function closeModal() {
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('modal-open');
			if (lastTrigger && typeof lastTrigger.focus === 'function') {
				lastTrigger.focus();
			}
		}

		function renderRows(key, rows) {
			modalMeta.innerHTML = '<strong>Key:</strong> ' + escapeHtml(key) + ' | <strong>Messaggi:</strong> ' + rows.length;

			if (!Array.isArray(rows) || rows.length === 0) {
				modalBody.innerHTML = '<p class="note">Nessun record role/content disponibile per questa sessione.</p>';
				return;
			}

			const tableRows = rows.map(function (row, index) {
				const role = row && row.role ? String(row.role) : '';
				const content = row && row.content ? String(row.content) : '';
				return '<tr>' +
					'<td>' + (index + 1) + '</td>' +
					'<td>' + escapeHtml(role) + '</td>' +
					'<td><pre>' + escapeHtml(content) + '</pre></td>' +
				'</tr>';
			}).join('');

			modalBody.innerHTML = '' +
				'<table class="session-history-table">' +
					'<thead>' +
						'<tr><th>#</th><th>Role</th><th>Content</th></tr>' +
					'</thead>' +
					'<tbody>' + tableRows + '</tbody>' +
				'</table>';
		}

		openButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				lastTrigger = button;
				const key = button.getAttribute('data-key') || '';
				const raw = button.getAttribute('data-history') || '[]';
				let rows = [];

				try {
					const parsed = JSON.parse(raw);
					if (Array.isArray(parsed)) {
						rows = parsed;
					}
				} catch (err) {
					rows = [];
				}

				renderRows(key, rows);
				openModal();
			});
		});

		if (closeButton) {
			closeButton.addEventListener('click', closeModal);
		}

		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeModal();
			}
		});

		document.addEventListener('keydown', function (event) {
			if (!modal.classList.contains('is-open')) {
				return;
			}

			if (event.key === 'Escape') {
				closeModal();
				return;
			}

			if (event.key === 'Tab') {
				const focusables = modal.querySelectorAll(focusableSelector);
				if (!focusables.length) {
					return;
				}
				const first = focusables[0];
				const last = focusables[focusables.length - 1];

				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				}

				if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}
		});
	})();
	</script>
</body>
</html>