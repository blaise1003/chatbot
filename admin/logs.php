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

$menuCandidates = [
    __DIR__ . '/views/_admin_menu.php',
    __DIR__ . '/_admin_menu.php',
];
foreach ($menuCandidates as $menuPath) {
    if (is_file($menuPath)) {
        require_once $menuPath;
        break;
    }
}

$headerCandidates = [
    __DIR__ . '/views/_admin_header.php',
    __DIR__ . '/_admin_header.php',
];
foreach ($headerCandidates as $headerPath) {
    if (is_file($headerPath)) {
        require_once $headerPath;
        break;
    }
}

if (!function_exists('admin_render_sidebar') || !function_exists('admin_render_header')) {
    http_response_code(500);
    echo 'Errore configurazione admin: componenti menu/header non disponibili.';
    exit;
}

// ??? Auth guard ??????????????????????????????????????????????????????????????
$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;
if (!$isAuthenticated) {
    header('Location: dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function logs_dir(): string
{
    if (defined('CHATBOT_LOGS_DIR') && is_string(CHATBOT_LOGS_DIR) && CHATBOT_LOGS_DIR !== '') {
        return rtrim((string) CHATBOT_LOGS_DIR, '/\\');
    }

    return '';
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function count_file_lines(string $path): int
{
    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        return 0;
    }

    $lineCount = substr_count($content, "\n");
    if (substr($content, -1) !== "\n") {
        $lineCount++;
    }

    return $lineCount;
}

/**
 * Returns the last $maxLines lines of a file as a single string.
 * If $maxLines <= 0, returns the entire file content.
 */
function read_tail(string $path, int $maxLines): string
{
    if ($maxLines <= 0) {
        $content = @file_get_contents($path);
        return $content !== false ? $content : '';
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }

    fseek($handle, 0, SEEK_END);
    $fileSize = ftell($handle);

    if ($fileSize === 0) {
        fclose($handle);
        return '';
    }

    // Read chunks from the end until we have enough lines
    $chunkSize  = 8192;
    $buffer     = '';
    $linesFound = 0;
    $pos        = $fileSize;

    while ($pos > 0 && $linesFound < $maxLines + 1) {
        $toRead  = min($chunkSize, $pos);
        $pos    -= $toRead;
        fseek($handle, $pos);
        $buffer = (string) fread($handle, $toRead) . $buffer;
        $linesFound = substr_count($buffer, "\n");
    }

    fclose($handle);

    if ($linesFound >= $maxLines) {
        $lines = explode("\n", $buffer);
        $lines = array_slice($lines, -$maxLines);
        return implode("\n", $lines);
    }

    return $buffer;
}

// ??? Collect log files ???????????????????????????????????????????????????????
const TAIL_LINES = 200;

$logsDir      = logs_dir();
$showFull     = isset($_GET['full']) && (string) $_GET['full'] === '1';
$selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'])
    ? (string) $_GET['date']
    : date('Y-m-d');
$maxLines     = $showFull ? 0 : TAIL_LINES;

/**
 * Extract date from log filename (format: xxxx--YYYY-MM-DD.log)
 */
function extract_date_from_log_name(string $filename): ?string
{
    if (preg_match('/.*-(\d{4}-\d{2}-\d{2})\.log$/i', $filename, $matches)) {
        return $matches[1];
    }
    return null;
}

$logFiles   = [];
$dirError   = '';

if ($logsDir === '') {
    $dirError = 'La costante CHATBOT_LOGS_DIR non � definita o � vuota.';
} elseif (!is_dir($logsDir)) {
    $dirError = 'Directory log non trovata: ' . $logsDir;
} else {
    $realLogsDir = realpath($logsDir);
    if ($realLogsDir === false) {
        $dirError = 'Impossibile risolvere il path della directory log.';
    } else {
        $entries = @scandir($realLogsDir);
        if ($entries === false) {
            $dirError = 'Impossibile leggere la directory log.';
        } else {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                // Only serve .log files
                if (!preg_match('/\.log$/i', $entry)) {
                    continue;
                }

                // Filter by selected date
                $fileDate = extract_date_from_log_name($entry);
                if ($fileDate !== $selectedDate) {
                    continue;
                }

                $fullPath = $realLogsDir . DIRECTORY_SEPARATOR . $entry;

                // Security: ensure resolved path is still inside logs dir
                $resolvedPath = realpath($fullPath);
                if ($resolvedPath === false) {
                    continue;
                }
                if (strpos($resolvedPath, $realLogsDir . DIRECTORY_SEPARATOR) !== 0
                    && $resolvedPath !== $realLogsDir) {
                    continue;
                }

                if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
                    continue;
                }

                $stat = @stat($resolvedPath);
                $logFiles[] = [
                    'name'     => $entry,
                    'path'     => $resolvedPath,
                    'size'     => $stat !== false ? (int) $stat['size'] : 0,
                    'mtime'    => $stat !== false ? (int) $stat['mtime'] : 0,
                    'content'  => read_tail($resolvedPath, $maxLines),
                    'lines'    => $stat !== false && $stat['size'] > 0
                        ? count_file_lines($resolvedPath)
                        : 0,
                ];
            }

            // Sort by name
            usort($logFiles, function (array $a, array $b): int {
                return strcmp($a['name'], $b['name']);
            });
        }
    }
}

$totalFiles = count($logFiles);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/logs-viewer.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('logs') ?>

    <main class="admin-main">
        <div class="page">
            <?= admin_render_header(
                'logs',
                'Log Viewer',
                'Lettura in tempo reale dei file di log nella directory CHATBOT_LOGS_DIR (sola lettura).',
                [
                    ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
                    ['label' => 'Dashboard', 'href' => 'dashboard.php', 'class' => 'secondary'],
                ]
            ) ?>

            <?php if ($dirError !== ''): ?>
                <div class="err"><?= h($dirError) ?></div>
            <?php else: ?>

            <section class="panel">
                <div class="logs-meta-bar">
                    <div>
                        <span class="logs-meta-label">Directory</span>
                        <code class="logs-meta-path"><?= h($logsDir) ?></code>
                    </div>
                    <div class="logs-meta-right">
                        <div class="logs-date-picker">
                            <label for="log-date-select" class="logs-meta-label">Data</label>
                            <input
                                type="date"
                                id="log-date-select"
                                value="<?= h($selectedDate) ?>"
                                onchange="window.location.href = 'logs.php?date=' + this.value + (<?= $showFull ? '1' : '0' ?> ? '&full=1' : '');"
                            >
                        </div>
                        <span class="muted"><?= h((string) $totalFiles) ?> file trovati</span>
                        <?php if (!$showFull): ?>
                            <a class="btn secondary" href="?date=<?= h($selectedDate) ?>&full=1">Mostra tutto</a>
                        <?php else: ?>
                            <a class="btn secondary" href="logs.php?date=<?= h($selectedDate) ?>">Ultimi <?= TAIL_LINES ?> righe</a>
                        <?php endif; ?>
                        <a class="btn secondary" href="logs.php?date=<?= h($selectedDate) ?><?= $showFull ? '&full=1' : '' ?>">Aggiorna</a>
                    </div>
                </div>
            </section>

            <?php if (empty($logFiles)): ?>
                <div class="panel"><p class="muted">Nessun file .log trovato nella directory.</p></div>
            <?php else: ?>

            <?php foreach ($logFiles as $file): ?>
                <section class="panel">
                    <div class="log-file-head">
                        <div class="log-file-info">
                            <h2 class="log-file-name"><?= h($file['name']) ?></h2>
                            <div class="log-file-meta">
                                <span><?= h(format_bytes($file['size'])) ?></span>
                                <span>&middot;</span>
                                <span><?= h($file['lines'] > 0 ? (string) $file['lines'] . ' righe totali' : 'vuoto') ?></span>
                                <span>&middot;</span>
                                <span>Modificato: <?= h($file['mtime'] > 0 ? date('Y-m-d H:i:s', $file['mtime']) : 'N/D') ?></span>
                                <?php if (!$showFull && $file['lines'] > TAIL_LINES): ?>
                                    <span class="log-tail-note">(ultime <?= TAIL_LINES ?> righe)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn secondary js-copy-log"
                            data-target="log-content-<?= h(preg_replace('/[^a-z0-9_-]/i', '_', $file['name'])) ?>"
                            data-copied-label="Copiato!"
                        >Copia</button>
                    </div>

                    <?php if ($file['content'] === ''): ?>
                        <p class="muted log-empty-note">File vuoto.</p>
                    <?php else: ?>
                        <pre
                            id="log-content-<?= h(preg_replace('/[^a-z0-9_-]/i', '_', $file['name'])) ?>"
                            class="log-pre"
                        ><?= h($file['content']) ?></pre>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php endif; ?>
            <?php endif; ?>

        </div>
    </main>
</div>

<script>
(function () {
    document.querySelectorAll('.js-copy-log').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var pre = targetId ? document.getElementById(targetId) : null;
            if (!pre || !navigator.clipboard) {
                return;
            }
            var originalLabel = btn.textContent.trim();
            var copiedLabel = btn.getAttribute('data-copied-label') || 'Copiato!';
            navigator.clipboard.writeText(pre.textContent || '').then(function () {
                btn.textContent = copiedLabel;
                btn.disabled = true;
                window.setTimeout(function () {
                    btn.textContent = originalLabel;
                    btn.disabled = false;
                }, 2000);
            });
        });
    });

    // Auto-scroll each log pre to the bottom on load
    document.querySelectorAll('.log-pre').forEach(function (pre) {
        pre.scrollTop = pre.scrollHeight;
    });
})();
</script>
</body>
</html>
