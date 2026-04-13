<?php

declare(strict_types=1);

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

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function starts_with_ci(string $value, string $prefix): bool
{
	return stripos($value, $prefix) === 0;
}

function find_main_htaccess_path(): string
{
	$candidates = [
		dirname(__DIR__, 2) . '/.htaccess',
		dirname(__DIR__) . '/.htaccess',
	];

	foreach ($candidates as $candidate) {
		if (is_file($candidate)) {
			return $candidate;
		}
	}

	return '';
}

function parse_allowed_ips_from_htaccess(string $path): array
{
	if ($path === '' || !is_file($path)) {
		return [];
	}

	$lines = @file($path, FILE_IGNORE_NEW_LINES);
	if (!is_array($lines)) {
		return [];
	}

	$items = [];
	$currentSection = 'Generale';

	foreach ($lines as $line) {
		$trimmed = trim((string) $line);
		if ($trimmed === '') {
			continue;
		}

		if (strpos($trimmed, '#') === 0) {
			$label = trim(substr($trimmed, 1));
			if ($label !== '') {
				$currentSection = $label;
			}
			continue;
		}

		if (preg_match('/^allow\s+from\s+(.+)$/i', $trimmed, $m) === 1) {
			$value = trim((string) $m[1]);
			if ($value === '' || strcasecmp($value, 'all') === 0) {
				continue;
			}
			$items[] = [
				'section' => $currentSection,
				'ip' => $value,
				'line' => $trimmed,
			];
		}
	}

	return $items;
}

$allowedOrigins = [];
if (defined('CHATBOT_ALLOWED_ORIGINS') && is_array(CHATBOT_ALLOWED_ORIGINS)) {
	foreach (CHATBOT_ALLOWED_ORIGINS as $origin) {
		if (is_string($origin) && trim($origin) !== '') {
			$allowedOrigins[] = trim($origin);
		}
	}
}

$mainHtaccessPath = find_main_htaccess_path();
$allowedIpEntries = parse_allowed_ips_from_htaccess($mainHtaccessPath);

$originRows = [];
$nonHttpsOrigins = [];
foreach ($allowedOrigins as $origin) {
	$isHttps = starts_with_ci($origin, 'https://');
	$originRows[] = [
		'origin' => $origin,
		'is_https' => $isHttps,
	];
	if (!$isHttps) {
		$nonHttpsOrigins[] = $origin;
	}
}

$ipCounts = [];
foreach ($allowedIpEntries as $entry) {
	$ip = (string) $entry['ip'];
	if (!isset($ipCounts[$ip])) {
		$ipCounts[$ip] = 0;
	}
	$ipCounts[$ip]++;
}

$duplicateIps = [];
foreach ($ipCounts as $ip => $count) {
	if ($count > 1) {
		$duplicateIps[$ip] = $count;
	}
}

$uniqueIpCount = count($ipCounts);
$totalIpRules = count($allowedIpEntries);

?>
<!DOCTYPE html>
<html lang="it">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>CORS Origins e IP ammessi</title>
	<link rel="stylesheet" href="css/admin-base.css">
	<link rel="stylesheet" href="css/admin-menu.css">
	<link rel="stylesheet" href="css/admin-header.css">
	<link rel="stylesheet" href="css/cors-origins.css">
</head>
<body>
<div class="admin-layout">
	<?= admin_render_sidebar('origins') ?>

	<main class="admin-main">
		<div class="page">
			<?= admin_render_header(
				'origins',
				'CORS Origins e IP ammessi',
				'Vista di sola lettura delle allowed origins da configurazione e della whitelist IP da .htaccess principale.',
				[
					['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
					['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
				]
			) ?>

			<section class="panel">
				<h2>Allowed Origins (CHATBOT_ALLOWED_ORIGINS)</h2>
				<p class="muted">Origini abilitate lato applicazione, lette dalla configurazione runtime.</p>
				<div class="coherence-wrap">
					<span class="coherence-pill <?= empty($nonHttpsOrigins) ? 'ok' : 'warn' ?>">
						HTTPS only: <?= empty($nonHttpsOrigins) ? 'SI' : 'NO' ?>
					</span>
					<span class="coherence-pill">
						Totale origins: <?= h((string) count($allowedOrigins)) ?>
					</span>
				</div>

				<?php if (empty($allowedOrigins)): ?>
					<p class="note">Nessuna origin configurata o costante non definita.</p>
				<?php else: ?>
					<table class="origins-table">
						<thead>
						<tr>
							<th>#</th>
							<th>Origin</th>
							<th>HTTPS</th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ($originRows as $index => $row): ?>
							<tr>
								<td><?= h((string) ($index + 1)) ?></td>
								<td><?= h((string) $row['origin']) ?></td>
								<td>
									<span class="coherence-pill <?= !empty($row['is_https']) ? 'ok' : 'warn' ?>"><?= !empty($row['is_https']) ? 'OK' : 'NO' ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if (!empty($nonHttpsOrigins)): ?>
						<p class="note">Attenzione: rilevate origins non HTTPS (consigliato HTTPS-only in produzione).</p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<section class="panel">
				<h2>IP ammessi (.htaccess principale)</h2>
				<p class="muted">Regole Apache `allow from` rilevate nel file principale.</p>
				<p class="muted"><strong>File:</strong> <?= h($mainHtaccessPath !== '' ? $mainHtaccessPath : 'non trovato') ?></p>
				<div class="coherence-wrap">
					<span class="coherence-pill">Regole allow: <?= h((string) $totalIpRules) ?></span>
					<span class="coherence-pill">IP unici: <?= h((string) $uniqueIpCount) ?></span>
					<span class="coherence-pill <?= empty($duplicateIps) ? 'ok' : 'warn' ?>">Duplicati: <?= h((string) count($duplicateIps)) ?></span>
				</div>

				<?php if ($mainHtaccessPath === ''): ?>
					<p class="note">Impossibile trovare il file .htaccess principale.</p>
				<?php elseif (empty($allowedIpEntries)): ?>
					<p class="note">Nessuna regola `allow from` specifica trovata.</p>
				<?php else: ?>
					<?php if (!empty($duplicateIps)): ?>
						<p class="note">Sono presenti IP duplicati in whitelist: <?php
						$dupSummary = [];
						foreach ($duplicateIps as $ip => $count) {
							$dupSummary[] = $ip . ' (' . $count . 'x)';
						}
						echo h(implode(', ', $dupSummary));
						?></p>
					<?php endif; ?>

					<table class="ips-table">
						<thead>
						<tr>
							<th>#</th>
							<th>Sezione</th>
							<th>IP / Regola</th>
							<th>Duplicato</th>
							<th>Riga</th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ($allowedIpEntries as $index => $entry): ?>
							<?php $ip = (string) $entry['ip']; $dupCount = $ipCounts[$ip] ?? 0; ?>
							<tr>
								<td><?= h((string) ($index + 1)) ?></td>
								<td><?= h((string) $entry['section']) ?></td>
								<td><?= h($ip) ?></td>
								<td>
									<span class="coherence-pill <?= $dupCount > 1 ? 'warn' : 'ok' ?>">
										<?= $dupCount > 1 ? 'SI (' . h((string) $dupCount) . 'x)' : 'NO' ?>
									</span>
								</td>
								<td><code><?= h((string) $entry['line']) ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
	</main>
</div>
</body>
</html>
