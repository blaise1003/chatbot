<?php

//declare(strict_types=1);

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

function is_secret_key(string $constantName): bool
{
    $name = strtoupper($constantName);
    return strpos($name, 'KEY') !== false
        || strpos($name, 'TOKEN') !== false
        || strpos($name, 'PASSWORD') !== false
        || strpos($name, 'SECRET') !== false;
}

function mask_secret_value(string $value): string
{
    $len = strlen($value);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }

    $head = substr($value, 0, 4);
    $tail = substr($value, -4);
    return $head . str_repeat('*', max(4, $len - 8)) . $tail;
}

function format_config_value(string $name, $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_array($value)) {
        if ($name === 'CHATBOT_ALLOWED_ORIGINS') {
            return 'Array(' . count($value) . ' origins)';
        }
        return 'Array(' . count($value) . ')';
    }

    if (!is_string($value)) {
        return '[non visualizzabile]';
    }

    if (is_secret_key($name)) {
        return mask_secret_value($value) . ' (len: ' . strlen($value) . ')';
    }

    if ($name === 'AI_PROMPT') {
        return 'Prompt configurato (' . strlen($value) . ' caratteri)';
    }

    return $value;
}

function basic_config_path(): string
{
    $path = dirname(__DIR__) . '/basic_config.php';
    return is_file($path) ? $path : '';
}

$basicConfigPath = basic_config_path();
$basicConfigReadable = $basicConfigPath !== '' && is_readable($basicConfigPath);
$basicConfigMTime = $basicConfigReadable ? @filemtime($basicConfigPath) : false;

$groups = [
    'AI & Provider' => [
        'ANTHROPIC_API_KEY',
        'CLAUDE_MODEL',
        'CHATBOT_AI_PROVIDER',
        'AI_PROMPT',
    ],
    'Search & Order APIs' => [
        'DOOFINDER_TOKEN',
        'ORDER_API_TOKEN',
        'BASE_API_URL',
        'ORDER_API_URL',
        'ORDERS_API_URL',
        'CHECKSESSION_API_URL',
    ],
    'Runtime & Security' => [
        'CHATBOT_ALLOWED_ORIGINS',
        'CHATBOT_ALLOW_TEST_MODE',
        'CHATBOT_RATE_LIMIT_MAX_REQUESTS',
        'CHATBOT_RATE_LIMIT_WINDOW_SECONDS',
    ],
    'Storage Paths' => [
        'CHATBOT_SESSION_DIR',
        'CHATBOT_LOGS_DIR',
    ],
];

$tableGroups = [];
foreach ($groups as $label => $constants) {
    $rows = [];
    foreach ($constants as $constantName) {
        $defined = defined($constantName);
        $rawValue = $defined ? constant($constantName) : null;
        $rows[] = [
            'name' => $constantName,
            'defined' => $defined,
            'sensitive' => is_secret_key($constantName),
            'value' => $defined ? format_config_value($constantName, $rawValue) : 'N/D',
        ];
    }
    $tableGroups[$label] = $rows;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurazione chiavi e runtime</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/keys.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('keys') ?>

    <main class="admin-main">
        <div class="page">
            <?= admin_render_header(
                'keys',
                'Configurazione Chiavi e Runtime',
                'Panoramica in sola lettura delle costanti principali caricate da basic_config.php (segreti mascherati).',
                [
                    ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
                    ['label' => 'CORS Origins', 'href' => 'cors_origins.php', 'class' => 'secondary'],
                ]
            ) ?>

            <section class="panel">
                <h2>Stato file configurazione</h2>
                <div class="keys-meta-grid">
                    <div class="keys-meta-item">
                        <div class="label">File</div>
                        <div class="value"><?= h($basicConfigPath !== '' ? $basicConfigPath : 'non trovato') ?></div>
                    </div>
                    <div class="keys-meta-item">
                        <div class="label">Readable</div>
                        <div class="value"><?= $basicConfigReadable ? 'SI' : 'NO' ?></div>
                    </div>
                    <div class="keys-meta-item">
                        <div class="label">Ultima modifica</div>
                        <div class="value"><?= h($basicConfigMTime !== false ? date('Y-m-d H:i:s', (int) $basicConfigMTime) : 'N/D') ?></div>
                    </div>
                </div>
                <p class="muted mt-10">Nota: i valori sensibili (token/key/secret/password) vengono mostrati in forma mascherata.</p>
            </section>

            <?php foreach ($tableGroups as $groupLabel => $rows): ?>
                <section class="panel">
                    <h2><?= h($groupLabel) ?></h2>
                    <table class="keys-table">
                        <thead>
                        <tr>
                            <th>Costante</th>
                            <th>Defined</th>
                            <th>Sensibile</th>
                            <th>Valore</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><code><?= h((string) $row['name']) ?></code></td>
                                <td><?= !empty($row['defined']) ? 'SI' : 'NO' ?></td>
                                <td><?= !empty($row['sensitive']) ? 'SI' : 'NO' ?></td>
								<?php if ($row['name'] == 'AI_PROMPT') { ?>
									<td class="value-cell"><?= h((string) $row['value']) ?> (<a href="/Chatbot/admin/prompt.php">vedi</a>)</td>
								<?php } else { ?>
                                	<td class="value-cell"><?= h((string) $row['value']) ?></td>
								<?php } ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
</div>
</body>
</html>
