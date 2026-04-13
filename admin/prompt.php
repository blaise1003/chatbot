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

function basic_config_path_prompt(): string
{
    $path = dirname(__DIR__) . '/basic_config.php';
    return is_file($path) ? $path : '';
}

$basicConfigPath  = basic_config_path_prompt();
$basicConfigMTime = ($basicConfigPath !== '' && is_readable($basicConfigPath))
    ? @filemtime($basicConfigPath)
    : false;

$promptDefined = defined('AI_PROMPT');
$promptText    = $promptDefined ? (string) constant('AI_PROMPT') : '';
$charCount     = mb_strlen($promptText);
$lineCount     = $promptText !== '' ? substr_count($promptText, "\n") + 1 : 0;
$wordCount     = $promptText !== '' ? str_word_count(strip_tags($promptText)) : 0;

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prompt Iniziale AI</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/prompt.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('prompts') ?>

    <main class="admin-main">
        <div class="page">
            <?= admin_render_header(
                'prompts',
                'Prompt Iniziale AI',
                'Visualizzazione in sola lettura del prompt di sistema inviato a Claude ad ogni conversazione (AI_PROMPT in basic_config.php).',
                [
                    ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
                    ['label' => 'Config Keys', 'href' => 'keys.php', 'class' => 'secondary'],
                ]
            ) ?>

            <section class="panel">
                <h2>Informazioni file</h2>
                <div class="prompt-meta-grid">
                    <div class="prompt-meta-item">
                        <div class="label">File sorgente</div>
                        <div class="value prompt-meta-path"><?= h($basicConfigPath !== '' ? $basicConfigPath : 'non trovato') ?></div>
                    </div>
                    <div class="prompt-meta-item">
                        <div class="label">Ultima modifica</div>
                        <div class="value"><?= h($basicConfigMTime !== false ? date('Y-m-d H:i:s', (int) $basicConfigMTime) : 'N/D') ?></div>
                    </div>
                    <div class="prompt-meta-item">
                        <div class="label">Costante definita</div>
                        <div class="value"><?= $promptDefined ? 'SI' : 'NO' ?></div>
                    </div>
                </div>
            </section>

            <?php if (!$promptDefined): ?>
                <div class="err">La costante AI_PROMPT non è definita. Verificare che basic_config.php sia caricato correttamente.</div>
            <?php else: ?>

            <section class="panel">
                <div class="prompt-stats-bar">
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $charCount) ?></span><span class="muted">caratteri</span></div>
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $lineCount) ?></span><span class="muted">righe</span></div>
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $wordCount) ?></span><span class="muted">parole</span></div>
                </div>
            </section>

            <section class="panel">
                <div class="prompt-panel-head">
                    <h2>Contenuto AI_PROMPT</h2>
                    <button type="button" class="btn secondary js-copy-prompt" data-copied-label="Copiato!">
                        Copia negli appunti
                    </button>
                </div>
                <pre id="prompt-content" class="prompt-pre"><?= h($promptText) ?></pre>
            </section>

            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    var copyBtn = document.querySelector('.js-copy-prompt');
    var promptPre = document.getElementById('prompt-content');

    if (!copyBtn || !promptPre) {
        return;
    }

    copyBtn.addEventListener('click', function () {
        var text = promptPre.textContent || '';
        if (!navigator.clipboard) {
            return;
        }
        var btn = this;
        var originalLabel = btn.textContent.trim();
        var copiedLabel = btn.getAttribute('data-copied-label') || 'Copiato!';
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = copiedLabel;
            btn.disabled = true;
            window.setTimeout(function () {
                btn.textContent = originalLabel;
                btn.disabled = false;
            }, 2000);
        });
    });
})();
</script>
</body>
</html>
