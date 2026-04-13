<?php
declare(strict_types=1);

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_version(): string
{
	$versionFile = dirname(__DIR__) . '/version.txt';
	if (!is_file($versionFile)) {
		return 'n/a';
	}

	$version = trim((string) file_get_contents($versionFile));
	return $version !== '' ? $version : 'n/a';
}

function find_config_path(): string
{
	$candidates = [
		dirname(__DIR__) . '/chatbot_config.php',
		dirname(__DIR__, 2) . '/chatbot_config.php',
	];

	foreach ($candidates as $path) {
		if (is_file($path)) {
			return $path;
		}
	}

	return '';
}

require_once __DIR__ . '/views/_admin_menu.php';
require_once __DIR__ . '/views/_admin_header.php';

$currentModule = isset($_GET['module']) ? trim((string) $_GET['module']) : 'overview';

$moduleGroups = admin_menu_groups();

$allItems = [];
foreach ($moduleGroups as $group) {
	foreach ($group['items'] as $item) {
		$allItems[$item['id']] = $item;
	}
}

if (!isset($allItems[$currentModule])) {
	$currentModule = 'overview';
}

$phpVersion = PHP_VERSION;
$configPath = find_config_path();
$configLoaded = false;
if ($configPath !== '') {
	require_once $configPath;
	$configLoaded = true;
}

$health = [
	['name' => 'PHP >= 8.0', 'ok' => version_compare($phpVersion, '8.0.0', '>=')],
	['name' => 'cURL extension', 'ok' => extension_loaded('curl')],
	['name' => 'JSON extension', 'ok' => extension_loaded('json')],
	['name' => 'OpenSSL extension', 'ok' => extension_loaded('openssl')],
	['name' => 'Redis extension', 'ok' => extension_loaded('redis')],
	['name' => 'Config file found', 'ok' => $configLoaded],
	['name' => 'CHATBOT_ALLOWED_ORIGINS defined', 'ok' => defined('CHATBOT_ALLOWED_ORIGINS')],
	['name' => 'CHATBOT_SESSION_DIR defined', 'ok' => defined('CHATBOT_SESSION_DIR')],
	['name' => 'CHATBOT_WIDGET_DYNAMIC_SECRET defined', 'ok' => defined('CHATBOT_WIDGET_DYNAMIC_SECRET')],
];

$sessionDir = defined('CHATBOT_SESSION_DIR') ? (string) CHATBOT_SESSION_DIR : '';
$sessionDirWritable = $sessionDir !== '' && is_dir($sessionDir) && is_writable($sessionDir);

$logsDir = defined('CHATBOT_LOGS_DIR') ? (string) CHATBOT_LOGS_DIR : '';
$logsDirWritable = $logsDir !== '' && is_dir($logsDir) && is_writable($logsDir);

$stats = [
	'enabledModules' => 0,
	'plannedModules' => 0,
	'moduleGroups' => count($moduleGroups),
];
foreach ($allItems as $item) {
	if (!empty($item['enabled'])) {
		$stats['enabledModules']++;
	} else {
		$stats['plannedModules']++;
	}
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Chatbot Admin</title>
	<link rel="stylesheet" href="css/admin-base.css">
	<link rel="stylesheet" href="css/admin-menu.css">
	<link rel="stylesheet" href="css/admin-header.css">
	<link rel="stylesheet" href="css/index.css">
</head>
<body>
	<div class="admin-layout">
		<?= admin_render_sidebar($currentModule) ?>

		<main class="admin-main">
			<?= admin_render_header(
				$currentModule,
				$currentModule === 'health' ? 'Health Check' : 'Admin Console',
				$currentModule === 'health' ? 'Verifica requisiti runtime e configurazione.' : 'Pannello centrale per gestione e monitoraggio chatbot.',
				[
					['label' => 'Panoramica', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
					['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
					['label' => 'Redis Demo', 'href' => 'redis_demo.php', 'class' => 'secondary'],
				]
			) ?>
			<section class="hero">
				<h2>Admin Console</h2>
				<p>Versione: <?= h(app_version()) ?> | PHP: <?= h($phpVersion) ?></p>
				<div class="kpis">
					<div class="kpi-box">
						<div class="label">Feature groups</div>
						<div class="value"><?= (int) $stats['moduleGroups'] ?></div>
					</div>
					<div class="kpi-box">
						<div class="label">Moduli attivi</div>
						<div class="value"><?= (int) $stats['enabledModules'] ?></div>
					</div>
					<div class="kpi-box">
						<div class="label">Moduli pianificati</div>
						<div class="value"><?= (int) $stats['plannedModules'] ?></div>
					</div>
				</div>
			</section>

			<?php if ($currentModule === 'overview'): ?>
				<section class="panel">
					<h3>Sezioni principali</h3>
					<div class="links">
						<a class="link-card" href="dashboard.php">
							<strong>Dashboard Conversazioni</strong>
							<span>Monitoraggio storico chat, ricerca, insight e manutenzione record.</span>
						</a>
						<a class="link-card" href="redis_admin.php">
							<strong>Redis Admin</strong>
							<span>Diagnostica runtime Redis, key scan, TTL e rate limit sliding window.</span>
						</a>
						<a class="link-card" href="index.php?module=health">
							<strong>Health Check</strong>
							<span>Controllo rapido requisiti ambiente e configurazione critica.</span>
						</a>
					</div>
				</section>

				<section class="panel">
					<h3>Roadmap moduli suggeriti</h3>
					<table>
						<thead>
							<tr>
								<th>Modulo</th>
								<th>Valore operativo</th>
								<th>Stato</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>Prompt Manager</td>
								<td>Versioning prompt, rollback rapido, audit modifiche.</td>
								<td><span class="status bad">Planned</span></td>
							</tr>
							<tr>
								<td>Logs Viewer</td>
								<td>Accesso centralizzato a error log e trace applicativi.</td>
								<td><span class="status bad">Planned</span></td>
							</tr>
							<tr>
								<td>CORS Origins Manager</td>
								<td>Gestione domini autorizzati senza edit manuale file.</td>
								<td><span class="status bad">Planned</span></td>
							</tr>
							<tr>
								<td>Key Rotation Checklist</td>
								<td>Procedure guidate per rinnovo token e segreti.</td>
								<td><span class="status bad">Planned</span></td>
							</tr>
						</tbody>
					</table>
				</section>
			<?php elseif ($currentModule === 'health'): ?>
				<section class="panel">
					<h3>Health Check</h3>
					<p class="muted">Controllo ambiente locale/server per l'admin e il backend chatbot.</p>
					<table>
						<thead>
							<tr>
								<th>Check</th>
								<th>Esito</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($health as $row): ?>
								<tr>
									<td><?= h($row['name']) ?></td>
									<td>
										<?php if ($row['ok']): ?>
											<span class="status ok">OK</span>
										<?php else: ?>
											<span class="status bad">KO</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td>CHATBOT_SESSION_DIR writable</td>
								<td>
									<?php if ($sessionDirWritable): ?>
										<span class="status ok">OK</span>
									<?php else: ?>
										<span class="status bad">KO</span>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="panel">
					<h3>Dettagli runtime</h3>
					<p><strong>Config path:</strong> <?= h($configPath !== '' ? $configPath : 'non trovato') ?></p>
					<p><strong>Session dir:</strong> <?= h($sessionDir !== '' ? $sessionDir : 'non definito') ?></p>
					<p><strong>Logs dir:</strong> <?= h($logsDir !== '' ? $logsDir : 'non definito') ?></p>
					<a class="cta" href="index.php?module=overview">Torna alla panoramica</a>
				</section>
			<?php endif; ?>
		</main>
	</div>
</body>
</html>
