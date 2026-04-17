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

$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;
if (!$isAuthenticated) {
    header('Location: dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function prompt_csrf_token(): string
{
    if (!isset($_SESSION['prompt_csrf']) || !is_string($_SESSION['prompt_csrf'])) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['prompt_csrf'] = bin2hex(openssl_random_pseudo_bytes(24));
        } else {
            $_SESSION['prompt_csrf'] = hash('sha256', session_id() . ':' . time());
        }
    }

    return (string) $_SESSION['prompt_csrf'];
}

function prompt_verify_csrf(): void
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $valid = isset($_SESSION['prompt_csrf'])
        && is_string($_SESSION['prompt_csrf'])
        && hash_equals($_SESSION['prompt_csrf'], $token);

    if (!$valid) {
        http_response_code(403);
        echo 'CSRF token non valido.';
        exit;
    }
}

function prompt_storage_dir(): string
{
    return defined('CHATBOT_LOGS_DIR') && is_string(CHATBOT_LOGS_DIR) && CHATBOT_LOGS_DIR !== ''
        ? rtrim((string) CHATBOT_LOGS_DIR, '/\\')
        : dirname(__DIR__, 3) . '/chatbot_logs';
}

function prompt_override_path(): string
{
    return prompt_storage_dir() . '/ai_prompt_override.txt';
}

function prompt_versions_dir(): string
{
    return prompt_storage_dir() . '/prompt_versions';
}

function prompt_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0700, true);
}

function prompt_write_file(string $path, string $content): bool
{
    $dir = dirname($path);
    if (!prompt_ensure_dir($dir)) {
        return false;
    }

    $fh = @fopen($path, 'w');
    if ($fh === false) {
        return false;
    }

    $ok = false;
    try {
        if (flock($fh, LOCK_EX)) {
            $written = fwrite($fh, $content);
            fflush($fh);
            flock($fh, LOCK_UN);
            $ok = $written !== false;
        }
    } finally {
        fclose($fh);
    }

    return $ok;
}

function prompt_create_backup(string $content, string $reason): string
{
    if (trim($content) === '') {
        return '';
    }

    $versionsDir = prompt_versions_dir();
    if (!prompt_ensure_dir($versionsDir)) {
        return '';
    }

    $safeReason = preg_replace('/[^a-z0-9_-]/i', '-', strtolower(trim($reason)));
    $safeReason = is_string($safeReason) && $safeReason !== '' ? $safeReason : 'manual';
    $fileName = 'prompt-' . date('Ymd-His') . '-' . substr(sha1($content . microtime(true)), 0, 8) . '-' . $safeReason . '.txt';
    $path = $versionsDir . '/' . $fileName;

    return prompt_write_file($path, $content) ? $path : '';
}

function prompt_list_backups(int $limit = 20): array
{
    $dir = prompt_versions_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $backups = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || !preg_match('/^prompt-\d{8}-\d{6}-[a-f0-9]{8}-[a-z0-9_-]+\.txt$/i', $entry)) {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $mtime = @filemtime($path);
        $parts = explode('-', basename($entry, '.txt'));
        $reason = count($parts) >= 4 ? implode('-', array_slice($parts, 3)) : 'manual';
        $backups[] = [
            'file' => $entry,
            'path' => $path,
            'mtime' => $mtime !== false ? (int) $mtime : 0,
            'size' => (int) (@filesize($path) ?: 0),
            'reason' => $reason,
        ];
    }

    usort($backups, function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });

    return array_slice($backups, 0, $limit);
}

function prompt_read_backup(string $fileName): string
{
    if (!preg_match('/^prompt-\d{8}-\d{6}-[a-f0-9]{8}-[a-z0-9_-]+\.txt$/i', $fileName)) {
        return '';
    }

    $path = prompt_versions_dir() . '/' . $fileName;
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

function basic_config_path_prompt(): string
{
    $path = dirname(__DIR__) . '/basic_config.php';
    return is_file($path) ? $path : '';
}

function prompt_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

$flashMessage = '';
$flashError = '';

$promptDefined = defined('AI_PROMPT');
$defaultText = $promptDefined ? (string) constant('AI_PROMPT') : '';
$overridePath = prompt_override_path();
$overrideExists = is_file($overridePath) && is_readable($overridePath);
$overrideContent = $overrideExists ? (string) (@file_get_contents($overridePath) ?: '') : '';
$hasOverride = trim($overrideContent) !== '';
$effectiveText = $hasOverride ? $overrideContent : $defaultText;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    prompt_verify_csrf();

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'save') {
        $newPrompt = isset($_POST['prompt_text']) ? (string) $_POST['prompt_text'] : '';

        if (trim($newPrompt) === '') {
            $flashError = 'Il prompt non può essere vuoto.';
        } elseif (mb_strlen($newPrompt) > 20000) {
            $flashError = 'Il prompt supera la dimensione massima consentita (20.000 caratteri).';
        } else {
            if ($effectiveText !== '' && $effectiveText !== $newPrompt) {
                prompt_create_backup($effectiveText, 'save');
            }

            if (!prompt_write_file($overridePath, $newPrompt)) {
                $flashError = 'Impossibile scrivere il file di override. Verificare i permessi della directory: ' . dirname($overridePath);
            } else {
                $flashMessage = 'Prompt salvato con successo. Backup automatico creato e nuova versione attiva dalla prossima conversazione.';
                $overrideContent = $newPrompt;
                $hasOverride = true;
                $effectiveText = $newPrompt;
            }
        }
    } elseif ($action === 'reset') {
        if ($hasOverride) {
            prompt_create_backup($overrideContent, 'reset');
        }

        if (is_file($overridePath)) {
            if (@unlink($overridePath)) {
                $flashMessage = 'Override rimosso. Backup creato prima del ripristino del prompt predefinito.';
                $overrideContent = '';
                $hasOverride = false;
                $effectiveText = $defaultText;
            } else {
                $flashError = 'Impossibile eliminare il file di override. Verificare i permessi.';
            }
        } else {
            $flashMessage = 'Nessun override attivo da rimuovere.';
        }
    } elseif ($action === 'restore') {
        $backupFile = isset($_POST['backup_file']) ? (string) $_POST['backup_file'] : '';
        $backupContent = prompt_read_backup($backupFile);

        if ($backupContent === '') {
            $flashError = 'Versione selezionata non valida o non leggibile.';
        } else {
            if ($effectiveText !== '' && $effectiveText !== $backupContent) {
                prompt_create_backup($effectiveText, 'restore');
            }

            if (!prompt_write_file($overridePath, $backupContent)) {
                $flashError = 'Impossibile ripristinare la versione selezionata.';
            } else {
                $flashMessage = 'Versione ripristinata con successo. Lo stato precedente è stato salvato come nuovo backup.';
                $overrideContent = $backupContent;
                $hasOverride = true;
                $effectiveText = $backupContent;
            }
        }
    }
}

$basicConfigPath = basic_config_path_prompt();
$basicConfigMTime = ($basicConfigPath !== '' && is_readable($basicConfigPath)) ? @filemtime($basicConfigPath) : false;
$overrideMTime = ($hasOverride && is_file($overridePath)) ? @filemtime($overridePath) : false;
$charCount = mb_strlen($effectiveText);
$lineCount = $effectiveText !== '' ? substr_count($effectiveText, "\n") + 1 : 0;
$wordCount = $effectiveText !== '' ? str_word_count(strip_tags($effectiveText)) : 0;
$csrfToken = prompt_csrf_token();
$backups = prompt_list_backups();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prompt Manager</title>
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
                'Prompt Manager',
                'Modifica il prompt di sistema inviato a Claude con backup automatici e storico versioni.',
                [
                    ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
                    ['label' => 'Config Keys', 'href' => 'keys.php', 'class' => 'secondary'],
                ]
            ) ?>

            <?php if ($flashMessage !== ''): ?>
                <div class="flash"><?= h($flashMessage) ?></div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="err"><?= h($flashError) ?></div>
            <?php endif; ?>

            <section class="panel">
                <h2>Stato corrente</h2>
                <div class="prompt-meta-grid">
                    <div class="prompt-meta-item">
                        <div class="label">Sorgente attiva</div>
                        <div class="value">
                            <?php if ($hasOverride): ?>
                                <span class="prompt-badge-override">Override attivo</span>
                            <?php else: ?>
                                <span class="prompt-badge-default">Predefinito (basic_config.php)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="prompt-meta-item">
                        <div class="label">File basic_config.php</div>
                        <div class="value prompt-meta-path"><?= h($basicConfigPath !== '' ? $basicConfigPath : 'non trovato') ?></div>
                    </div>
                    <div class="prompt-meta-item">
                        <div class="label">Directory versioni</div>
                        <div class="value prompt-meta-path"><?= h(prompt_versions_dir()) ?></div>
                    </div>
                    <?php if ($hasOverride): ?>
                    <div class="prompt-meta-item">
                        <div class="label">File override</div>
                        <div class="value prompt-meta-path"><?= h($overridePath) ?></div>
                    </div>
                    <div class="prompt-meta-item">
                        <div class="label">Override salvato il</div>
                        <div class="value"><?= h($overrideMTime !== false ? date('Y-m-d H:i:s', (int) $overrideMTime) : 'N/D') ?></div>
                    </div>
                    <?php else: ?>
                    <div class="prompt-meta-item">
                        <div class="label">Ultima modifica config</div>
                        <div class="value"><?= h($basicConfigMTime !== false ? date('Y-m-d H:i:s', (int) $basicConfigMTime) : 'N/D') ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="prompt-meta-item">
                        <div class="label">Versioni disponibili</div>
                        <div class="value"><?= h((string) count($backups)) ?></div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="prompt-stats-bar">
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $charCount) ?></span><span class="muted">caratteri</span></div>
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $lineCount) ?></span><span class="muted">righe</span></div>
                    <div class="prompt-stat"><span class="prompt-stat-value"><?= h((string) $wordCount) ?></span><span class="muted">parole</span></div>
                </div>
            </section>

            <section class="panel">
                <div class="prompt-panel-head">
                    <h2>Prompt attivo</h2>
                    <div class="prompt-panel-actions">
                        <button type="button" class="btn secondary js-copy-prompt" data-copied-label="Copiato!">Copia</button>
                        <?php if ($hasOverride): ?>
                            <form method="POST" action="prompt.php" class="prompt-inline-form" onsubmit="return confirm('Sei sicuro di voler ripristinare il prompt predefinito? Verrà creato un backup dell\'override attuale.');">
                                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action" value="reset">
                                <button type="submit" class="btn warn">Ripristina predefinito</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" action="prompt.php" id="prompt-edit-form">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="save">

                    <textarea id="prompt-editor" name="prompt_text" class="prompt-textarea" spellcheck="false" rows="30"><?= h($effectiveText) ?></textarea>

                    <div class="prompt-editor-footer">
                        <span class="muted prompt-char-counter" id="prompt-char-counter"><?= h((string) $charCount) ?> / 20.000 caratteri</span>
                        <button type="submit" class="btn">Salva prompt</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="prompt-panel-head">
                    <h2>Storico versioni</h2>
                    <span class="muted">Backup automatici creati a ogni salvataggio, ripristino o reset</span>
                </div>

                <?php if (empty($backups)): ?>
                    <p class="muted">Nessuna versione salvata ancora.</p>
                <?php else: ?>
                    <div class="prompt-history-list">
                        <?php foreach ($backups as $backup): ?>
                            <div class="prompt-history-item">
                                <div class="prompt-history-meta">
                                    <strong><?= h(date('Y-m-d H:i:s', $backup['mtime'])) ?></strong>
                                    <span class="prompt-history-reason"><?= h(str_replace('-', ' ', $backup['reason'])) ?></span>
                                    <span class="muted"><?= h(prompt_format_bytes($backup['size'])) ?></span>
                                </div>
                                <div class="prompt-history-actions">
                                    <span class="prompt-history-file"><?= h($backup['file']) ?></span>
                                    <form method="POST" action="prompt.php" class="prompt-inline-form" onsubmit="return confirm('Ripristinare questa versione del prompt? Verrà creato un backup dello stato attuale.');">
                                        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="backup_file" value="<?= h($backup['file']) ?>">
                                        <button type="submit" class="btn secondary">Ripristina</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($hasOverride && trim($defaultText) !== '' && $defaultText !== $effectiveText): ?>
            <section class="panel">
                <details>
                    <summary class="prompt-details-summary">Mostra prompt predefinito (basic_config.php)</summary>
                    <pre id="prompt-content" class="prompt-pre prompt-pre-spaced"><?= h($defaultText) ?></pre>
                </details>
            </section>
            <?php elseif (!$hasOverride): ?>
            <pre id="prompt-content" class="prompt-pre prompt-hidden"><?= h($defaultText) ?></pre>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    var copyBtn = document.querySelector('.js-copy-prompt');
    var textarea = document.getElementById('prompt-editor');
    var counter = document.getElementById('prompt-char-counter');
    var form = document.getElementById('prompt-edit-form');
    var originalValue = textarea ? textarea.value : '';
    var formSubmitted = false;

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var text = textarea ? textarea.value : '';
            if (!navigator.clipboard || !text) {
                return;
            }

            var originalLabel = copyBtn.textContent.trim();
            var copiedLabel = copyBtn.getAttribute('data-copied-label') || 'Copiato!';
            navigator.clipboard.writeText(text).then(function () {
                copyBtn.textContent = copiedLabel;
                copyBtn.disabled = true;
                window.setTimeout(function () {
                    copyBtn.textContent = originalLabel;
                    copyBtn.disabled = false;
                }, 2000);
            });
        });
    }

    if (textarea && counter) {
        textarea.addEventListener('input', function () {
            var len = textarea.value.length;
            counter.textContent = len.toLocaleString('it-IT') + ' / 20.000 caratteri';
            counter.style.color = len > 18000 ? '#dc2626' : '';
        });
    }

    if (form) {
        form.addEventListener('submit', function () {
            formSubmitted = true;
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (!formSubmitted && textarea && textarea.value !== originalValue) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
</script>
</body>
</html>