<?php

declare(strict_types=1);

session_start();

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

require_once __DIR__ . '/views/_admin_menu.php';
require_once __DIR__ . '/views/_admin_header.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function dashboard_password(): string
{
    if (defined('DASHBOARD_PASSWORD') && is_string(DASHBOARD_PASSWORD) && DASHBOARD_PASSWORD !== '') {
        return DASHBOARD_PASSWORD;
    }

    return 'change-me';
}

function mysql_config(): array
{
    return [
        'dsn' => defined('CHATBOT_MYSQL_DSN') ? (string) CHATBOT_MYSQL_DSN : '',
        'user' => defined('CHATBOT_MYSQL_USER') ? (string) CHATBOT_MYSQL_USER : '',
        'password' => defined('CHATBOT_MYSQL_PASSWORD') ? (string) CHATBOT_MYSQL_PASSWORD : '',
        'table' => defined('CHATBOT_MYSQL_TABLE') ? (string) CHATBOT_MYSQL_TABLE : 'chatbot_conversations',
    ];
}

function csrf_token(): string
{
    if (!isset($_SESSION['dashboard_csrf']) || !is_string($_SESSION['dashboard_csrf'])) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['dashboard_csrf'] = \bin2hex(\openssl_random_pseudo_bytes(24));
        } else {
            $_SESSION['dashboard_csrf'] = hash(
                'sha256',
                session_id() . ':' . (string) time() . ':' . (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0)
            );
        }
    }

    return $_SESSION['dashboard_csrf'];
}

function verify_csrf_or_die(): void
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $valid = isset($_SESSION['dashboard_csrf'])
        && is_string($_SESSION['dashboard_csrf'])
        && hash_equals($_SESSION['dashboard_csrf'], $token);

    if (!$valid) {
        http_response_code(403);
        echo 'CSRF token non valido.';
        exit;
    }
}

function parse_history(string $historyJson): array
{
    $decoded = json_decode($historyJson, true);
    return is_array($decoded) ? $decoded : [];
}

function str_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }

    return strtolower($value);
}

function str_contains_ci(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle) !== false;
    }

    return stripos($haystack, $needle) !== false;
}

function str_slice(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length);
    }

    return substr($value, $start, $length);
}

function extract_user_messages(array $history): array
{
    $messages = [];
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = isset($item['role']) ? (string) $item['role'] : '';
        $content = isset($item['content']) ? (string) $item['content'] : '';
        if ($role === 'user' && $content !== '') {
            $messages[] = $content;
        }
        if ($role === 'assistant' && $content !== '') {
            $messages[] = $content;
        }
    }

    return $messages;
}

function extract_assistant_messages(array $history): array
{
    $messages = [];
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = isset($item['role']) ? (string) $item['role'] : '';
        $content = isset($item['content']) ? (string) $item['content'] : '';
        if (($role === 'assistant' || $role === 'ai') && $content !== '') {
            $messages[] = $content;
        }
    }

    return $messages;
}

function flatten_text_for_search(array $history): string
{
    $chunks = [];
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }

        foreach (['role', 'content'] as $field) {
            if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== '') {
                $chunks[] = $item[$field];
            }
        }

        foreach ($item as $value) {
            if (is_string($value)) {
                $chunks[] = $value;
            }
        }
    }

    return str_lower(implode("\n", $chunks));
}

function contains_operator_handoff(array $history): bool
{
    $assistantMessages = extract_assistant_messages($history);
    foreach ($assistantMessages as $msg) {
        if (str_contains_ci($msg, 'operatore') || str_contains_ci($msg, 'agente') || (str_contains_ci($msg, 'contatta') && str_contains_ci($msg, 'umano'))) {
            return true;
        }
    }

    return false;
}

function build_prompt_suggestions(array $conversations): array
{
    $wordCounts = [];
    $unresolvedSamples = [];
    $unresolvedCount = 0;
    $totalConversations = count($conversations);

    foreach ($conversations as $conv) {
        $history = parse_history((string) $conv['history']);
        $userMessages = extract_user_messages($history);
        $allUserText = str_lower(implode(' ', $userMessages));

        if ($allUserText !== '') {
            if (preg_match_all('/[\p{L}\p{N}]{4,}/u', $allUserText, $matches)) {
                foreach ($matches[0] as $word) {
                    if (in_array($word, ['questa', 'quello', 'ordine', 'prodotto', 'prodotti', 'prezzo', 'prezzi'], true)) {
                        continue;
                    }
                    if (!isset($wordCounts[$word])) {
                        $wordCounts[$word] = 0;
                    }
                    $wordCounts[$word]++;
                }
            }
        }

        if (contains_operator_handoff($history)) {
            $unresolvedCount++;
            if (count($unresolvedSamples) < 5) {
                $snippet = '';
                foreach ($userMessages as $msg) {
                    $snippet .= ($snippet === '' ? '' : ' | ') . trim(preg_replace('/\s+/', ' ', $msg));
                }
                if ($snippet !== '') {
                    $preview = str_slice($snippet, 0, 220);
                    if (strlen($snippet) > 220) {
                        $preview .= '...';
                    }

                    $unresolvedSamples[] = [
                        'id' => isset($conv['id']) ? (int) $conv['id'] : 0,
                        'session_id' => isset($conv['session_id']) ? (string) $conv['session_id'] : '',
                        'message_count' => isset($conv['message_count']) ? (int) $conv['message_count'] : count($history),
                        'preview' => $preview,
                    ];
                }
            }
        }
    }

    arsort($wordCounts);
    $topWords = array_slice($wordCounts, 0, 12, true);

    $handoffRate = $totalConversations > 0
        ? round(($unresolvedCount / $totalConversations) * 100, 2)
        : 0.0;

    $suggestions = [];

    if (!empty($topWords)) {
        $topics = implode(', ', array_keys(array_slice($topWords, 0, 6, true)));
        $suggestions[] = 'Aggiungi nel prompt iniziale una sezione FAQ prioritaria sui temi piu ricorrenti: ' . $topics . '.';
    }

    if ($handoffRate > 0) {
        $suggestions[] = 'Riduci i passaggi a operatore: oggi ' . $handoffRate . '% delle conversazioni contiene "contatta un operatore o un agente". Inserisci regole piu specifiche su quando proporre alternative autonome prima dell escalation.';
    }

    if (!empty($unresolvedSamples)) {
        $suggestions[] = 'Nel prompt iniziale aggiungi esempi guidati per richieste ambigue simili a quelle non risolte visibili qui sotto.';
    }

    if (empty($suggestions)) {
        $suggestions[] = 'Non ci sono abbastanza dati per insight forti. Continua a collezionare conversazioni e riesegui l analisi.';
    }

    return [
        'topWords' => $topWords,
        'unresolvedCount' => $unresolvedCount,
        'handoffRate' => $handoffRate,
        'unresolvedSamples' => $unresolvedSamples,
        'suggestions' => $suggestions,
    ];
}

$authError = '';
$flash = '';

if (isset($_POST['login'])) {
    verify_csrf_or_die();
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if (hash_equals(dashboard_password(), $password)) {
        $_SESSION['dashboard_auth'] = true;
        header('Location: dashboard.php');
        exit;
    }
    $authError = 'Password non valida.';
}

if (isset($_GET['logout'])) {
    $_SESSION['dashboard_auth'] = false;
    header('Location: dashboard.php');
    exit;
}

$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;

if (!$isAuthenticated):
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Dashboard Chatbot</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body class="login-body">
<div class="login-wrap">
    <form method="post" class="login-card">
        <h1>Dashboard Chatbot</h1>
        <p>Accedi con la password amministrativa.</p>
        <?php if ($authError !== ''): ?><div class="login-error"><?= h($authError) ?></div><?php endif; ?>
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="login" value="1">Accedi</button>
    </form>
</div>
</body>
</html>
<?php
exit;
endif;

$cfg = mysql_config();
$dsn = $cfg['dsn'];
$user = $cfg['user'];
$pass = $cfg['password'];
$table = preg_replace('/[^a-zA-Z0-9_]/', '', $cfg['table']);
if ($table === '') {
    $table = 'chatbot_conversations';
}

$pdo = null;
$connectionError = '';

if ($dsn === '') {
    $connectionError = 'MySQL disabilitato: CHATBOT_MYSQL_DSN e vuoto.';
} else {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    } catch (Throwable $e) {
        $connectionError = 'Errore connessione MySQL: ' . $e->getMessage();
    }
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

if ($pdo instanceof PDO && $action !== '') {
    verify_csrf_or_die();

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $flash = 'Conversazione eliminata.';
        }
    }

    if ($action === 'update') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sessionId = isset($_POST['session_id']) ? trim((string) $_POST['session_id']) : '';
        $flushReason = isset($_POST['last_flush_reason']) ? trim((string) $_POST['last_flush_reason']) : '';
        $historyRaw = isset($_POST['history']) ? trim((string) $_POST['history']) : '';
        $historyParsed = json_decode($historyRaw, true);

        if ($id > 0 && $sessionId !== '' && is_array($historyParsed)) {
            $messageCount = count($historyParsed);
            $stmt = $pdo->prepare("UPDATE `{$table}`
                SET session_id = :sid,
                    last_flush_reason = :reason,
                    history = :history,
                    message_count = :mc,
                    last_activity_at = :now
                WHERE id = :id
                LIMIT 1");
            $stmt->execute([
                ':sid' => $sessionId,
                ':reason' => $flushReason,
                ':history' => json_encode($historyParsed, JSON_UNESCAPED_UNICODE),
                ':mc' => $messageCount,
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $id,
            ]);
            $flash = 'Conversazione aggiornata.';
        } else {
            $flash = 'Update non eseguito: controlla session_id e JSON history.';
        }
    }
}

if ($pdo instanceof PDO && isset($_GET['ajax']) && (string) $_GET['ajax'] === 'view') {
    header('Content-Type: application/json; charset=UTF-8');

    $viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($viewId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID conversazione non valido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, session_id, ip_hash, started_at, last_activity_at, message_count, last_flush_reason, customer_email, history FROM `{$table}` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $viewId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Errore durante il recupero della conversazione.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_array($row)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Conversazione non trovata.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'conversation' => [
            'id' => (int) $row['id'],
            'session_id' => (string) $row['session_id'],
            'ip_hash' => (string) $row['ip_hash'],
            'started_at' => (string) $row['started_at'],
            'last_activity_at' => (string) $row['last_activity_at'],
            'message_count' => (int) $row['message_count'],
            'last_flush_reason' => (string) $row['last_flush_reason'],
            'customer_email' => (string) $row['customer_email'],
            'history' => parse_history((string) $row['history']),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($pdo instanceof PDO && isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
    $exportKeyword = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

    try {
        $allExportStmt = $pdo->query("SELECT id, session_id, ip_hash, started_at, last_activity_at, message_count, last_flush_reason, customer_email, history FROM `{$table}` ORDER BY last_activity_at DESC");
        $allExportRows = $allExportStmt->fetchAll();
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Errore durante il recupero dei dati per l\'esportazione.';
        exit;
    }

    if ($exportKeyword !== '') {
        $exportKeywordLower = str_lower($exportKeyword);
        $filteredExport = [];
        foreach ($allExportRows as $r) {
            $history = parse_history((string) $r['history']);
            if (str_contains_ci(flatten_text_for_search($history), $exportKeywordLower)) {
                $filteredExport[] = $r;
            }
        }
        $allExportRows = $filteredExport;
    }

    $filename = 'conversazioni_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'id', 'session_id', 'customer_email', 'started_at', 'last_activity_at',
        'message_count', 'last_flush_reason', 'ip_hash', 'has_operator_handoff', 'user_messages',
    ]);

    foreach ($allExportRows as $r) {
        $history = parse_history((string) $r['history']);
        $userMessages = extract_user_messages($history);
        fputcsv($out, [
            (string) $r['id'],
            (string) $r['session_id'],
            (string) $r['customer_email'],
            (string) $r['started_at'],
            (string) $r['last_activity_at'],
            (string) $r['message_count'],
            (string) $r['last_flush_reason'],
            (string) $r['ip_hash'],
            contains_operator_handoff($history) ? '1' : '0',
            implode(' | ', $userMessages),
        ]);
    }

    fclose($out);
    exit;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 50;
$keyword = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$statsKeyword = isset($_GET['stats_word']) ? trim((string) $_GET['stats_word']) : '';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$totalRows = 0;
$rows = [];
$stats = [
    'totalConversations' => 0,
    'operatorHandoffConversations' => 0,
    'conversationsWithKeyword' => 0,
    'userMessagesWithKeyword' => 0,
    'topWords' => [],
    'handoffRate' => 0,
    'unresolvedSamples' => [],
    'suggestions' => [],
];

$editingRow = null;

if ($pdo instanceof PDO) {
    $stmtCount = $pdo->query("SELECT COUNT(*) AS c FROM `{$table}`");
    $totalRows = (int) ($stmtCount->fetch()['c'] ?? 0);

    $allStmt = $pdo->query("SELECT id, session_id, ip_hash, started_at, last_activity_at, message_count, last_flush_reason, customer_email, history FROM `{$table}` ORDER BY last_activity_at DESC");
    $allRows = $allStmt->fetchAll();

    $stats['totalConversations'] = count($allRows);

    $keywordLower = str_lower($statsKeyword);

    foreach ($allRows as $r) {
        $history = parse_history((string) $r['history']);
        $searchText = flatten_text_for_search($history);

        if ($keywordLower !== '' && str_contains_ci($searchText, $keywordLower)) {
            $stats['conversationsWithKeyword']++;

            $userMsgs = extract_user_messages($history);
            foreach ($userMsgs as $msg) {
                if (str_contains_ci(str_lower($msg), $keywordLower)) {
                    $stats['userMessagesWithKeyword']++;
                }
            }
        }

        if (contains_operator_handoff($history)) {
            $stats['operatorHandoffConversations']++;
        }
    }

    $insights = build_prompt_suggestions($allRows);
    $stats['topWords'] = $insights['topWords'];
    $stats['handoffRate'] = $insights['handoffRate'];
    $stats['unresolvedSamples'] = $insights['unresolvedSamples'];
    $stats['suggestions'] = $insights['suggestions'];

    $filtered = [];
    $keywordFilter = str_lower($keyword);
    if ($keywordFilter === '') {
        $filtered = $allRows;
    } else {
        foreach ($allRows as $r) {
            $history = parse_history((string) $r['history']);
            $searchText = flatten_text_for_search($history);
            if (str_contains_ci($searchText, $keywordFilter)) {
                $filtered[] = $r;
            }
        }
    }

    $totalRows = count($filtered);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;
    $rows = array_slice($filtered, $offset, $perPage);

    if ($editId > 0) {
        foreach ($allRows as $r) {
            if ((int) $r['id'] === $editId) {
                $editingRow = $r;
                break;
            }
        }
    }
} else {
    $totalPages = 1;
}

function page_url(int $targetPage, string $q, string $statsWord): string
{
    $params = [
        'page' => max(1, $targetPage),
    ];

    if ($q !== '') {
        $params['q'] = $q;
    }

    if ($statsWord !== '') {
        $params['stats_word'] = $statsWord;
    }

    return 'dashboard.php?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Conversazioni Chatbot</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('dashboard') ?>
<main class="admin-main">
<div class="page">
    <?= admin_render_header(
        'dashboard',
        'Dashboard Conversazioni Chatbot',
        'Paginazione a 50 conversazioni per pagina, ricerca contenuti, modifica/cancellazione e insight prompt.',
        [
            ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
            ['label' => 'Redis Admin', 'href' => 'redis_admin.php', 'class' => 'secondary'],
            ['label' => 'Esci', 'href' => 'dashboard.php?logout=1', 'class' => 'gray'],
        ]
    ) ?>

    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($connectionError !== ''): ?><div class="err"><?= h($connectionError) ?></div><?php endif; ?>

    <?php if ($pdo instanceof PDO): ?>
    <div class="grid dashboard-kpi-grid">
        <div class="card">
            <h3>Conversazioni Totali</h3>
            <div class="kpi"><?= h((string) $stats['totalConversations']) ?></div>
        </div>
        <div class="card">
            <h3>Risposte Con Operatore</h3>
            <div class="kpi"><?= h((string) $stats['operatorHandoffConversations']) ?></div>
            <div class="muted">Tasso: <?= h((string) $stats['handoffRate']) ?>%</div>
        </div>
        <div class="card">
            <h3>Keyword In Conversazioni</h3>
            <div class="kpi"><?= h((string) $stats['conversationsWithKeyword']) ?></div>
            <div class="muted">Filtro statistiche: <?= h($statsKeyword === '' ? 'nessuno' : $statsKeyword) ?></div>
        </div>
        <div class="card">
            <h3>Messaggi Utente Con Keyword</h3>
            <div class="kpi"><?= h((string) $stats['userMessagesWithKeyword']) ?></div>
        </div>
    </div>

    <div class="panel">
        <h2>Filtri e Ricerca</h2>
        <form method="get" class="row">
            <div class="filter-col-wide">
                <label for="q" class="muted">Cerca parole dentro tutte le conversazioni</label>
                <input id="q" type="text" name="q" value="<?= h($keyword) ?>" placeholder="es. spedizione, reso, bonifico">
            </div>
            <div class="filter-col-medium">
                <label for="stats_word" class="muted">Keyword statistiche</label>
                <input id="stats_word" type="text" name="stats_word" value="<?= h($statsKeyword) ?>" placeholder="es. ordine">
            </div>
            <div class="filter-actions-end">
                <button class="btn" type="submit">Applica</button>
            </div>
            <div class="filter-actions-end">
                <a class="btn secondary" href="dashboard.php">Reset</a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Conversazioni</h2>
                <div class="muted">Pagina <?= h((string) $page) ?> di <?= h((string) $totalPages) ?> - <?= h((string) $totalRows) ?> righe filtrate</div>
            </div>
            <?php
                $exportParams = ['export' => 'csv'];
                if ($keyword !== '') {
                    $exportParams['q'] = $keyword;
                }
            ?>
            <a class="btn secondary btn-export-csv" href="dashboard.php?<?= h(http_build_query($exportParams)) ?>">
                Esporta CSV (<?= h((string) $totalRows) ?>)
            </a>
        </div>
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Sessione</th>
                    <th>Started</th>
					<th>Email</th>
                    <th>Last Activity</th>
                    <th>Messaggi</th>
                    <th>Flush Reason</th>
                    <th>Operatore</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $rowHistory = parse_history((string) $row['history']); ?>
                <tr>
                    <td><?= h((string) $row['id']) ?></td>
                    <td><span class="pill"><?= h((string) $row['session_id']) ?></span></td>
                    <td><?= h((string) $row['started_at']) ?></td>
					<td><?= h((string) $row['customer_email']) ?></td>
                    <td><?= h((string) $row['last_activity_at']) ?></td>
                    <td><?= h((string) $row['message_count']) ?></td>
                    <td><?= h((string) $row['last_flush_reason']) ?></td>
                    <td><?= contains_operator_handoff($rowHistory) ? 'SI' : 'NO' ?></td>
                    <td>
                        <button class="btn secondary btn-view js-view-conversation" type="button" data-id="<?= h((string) $row['id']) ?>" data-default-label="Visualizza">
                            <span class="btn-view-label">Visualizza</span>
                            <span class="btn-view-loading" aria-hidden="true">...</span>
                        </button>
                        <a class="btn secondary" href="<?= h(page_url($page, $keyword, $statsKeyword) . '&edit=' . (int) $row['id']) ?>">Modifica</a>
                        <form method="post" class="inline-form" onsubmit="return confirm('Eliminare la conversazione ID <?= (int) $row['id'] ?>?');">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                            <button class="btn warn" type="submit">Cancella</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="pagination current current"><?= h((string) $p) ?></span>
                <?php else: ?>
                    <a href="<?= h(page_url($p, $keyword, $statsKeyword)) ?>"><?= h((string) $p) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>

    <?php if ($editingRow !== null): ?>
    <div class="panel">
        <h2>Modifica Conversazione #<?= h((string) $editingRow['id']) ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= h((string) $editingRow['id']) ?>">

            <div class="row">
                <div class="editor-col">
                    <label class="muted">Session ID</label>
                    <input type="text" name="session_id" value="<?= h((string) $editingRow['session_id']) ?>" required>
                </div>
                <div class="editor-col">
                    <label class="muted">Last Flush Reason</label>
                    <input type="text" name="last_flush_reason" value="<?= h((string) $editingRow['last_flush_reason']) ?>">
                </div>
            </div>

            <label class="muted">History (JSON valido)</label>
            <textarea name="history" required><?= h((string) json_encode(parse_history((string) $editingRow['history']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>

            <div class="row mt-10">
                <button class="btn" type="submit">Salva Modifiche</button>
                <a class="btn secondary" href="<?= h(page_url($page, $keyword, $statsKeyword)) ?>">Chiudi Editor</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <h2>Analisi Conversazioni e Suggerimenti Prompt</h2>
        <p class="muted">Questa sezione analizza i log conversazionali e propone azioni per migliorare il prompt iniziale del chatbot.</p>

        <h3>Parole frequenti nei messaggi utente</h3>
        <?php if (empty($stats['topWords'])): ?>
            <p>Nessun dato disponibile.</p>
        <?php else: ?>
            <div class="row">
                <?php foreach ($stats['topWords'] as $w => $cnt): ?>
                    <span class="pill"><?= h($w) ?> (<?= h((string) $cnt) ?>)</span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3>Conversazioni con handoff a operatore umano</h3>
        <?php if (empty($stats['unresolvedSamples'])): ?>
            <p>Nessun caso campione disponibile.</p>
        <?php else: ?>
            <div class="handoff-list">
                <?php foreach ($stats['unresolvedSamples'] as $sample): ?>
                    <article class="handoff-item">
                        <div class="handoff-item-head">
                            <div>
                                <strong>Conversazione #<?= h((string) ($sample['id'] ?? 0)) ?></strong>
                                <div class="muted">Sessione: <?= h((string) ($sample['session_id'] ?? '-')) ?> - Messaggi: <?= h((string) ($sample['message_count'] ?? 0)) ?></div>
                            </div>
                            <?php if (!empty($sample['id'])): ?>
                                <button class="btn secondary btn-view js-view-conversation" type="button" data-id="<?= h((string) $sample['id']) ?>" data-default-label="Visualizza completa">
                                    <span class="btn-view-label">Visualizza completa</span>
                                    <span class="btn-view-loading" aria-hidden="true">...</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <p class="handoff-preview"><?= h((string) ($sample['preview'] ?? '')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3>Suggerimenti operativi sul prompt iniziale</h3>
        <ol class="suggestions">
            <?php foreach ($stats['suggestions'] as $s): ?>
                <li><?= h($s) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>

    <?php endif; ?>
</div>

<div id="conversation-modal" class="conversation-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="conversation-modal-title">
    <div class="conversation-modal">
        <div class="conversation-modal-head">
            <h2 id="conversation-modal-title">Dettaglio Conversazione</h2>
            <button type="button" class="conversation-modal-close" data-close-modal="1" aria-label="Chiudi">x</button>
        </div>
        <div id="conversation-modal-body" class="conversation-modal-body">
            <p class="muted">Seleziona una conversazione da visualizzare.</p>
        </div>
    </div>
</div>
</main>
</div>

<script>
(function () {
    const modalKeyword = <?= json_encode($keyword, JSON_UNESCAPED_UNICODE) ?>;
    const modalStatsKeyword = <?= json_encode($statsKeyword, JSON_UNESCAPED_UNICODE) ?>;
    const highlightTerms = [modalKeyword, modalStatsKeyword]
        .map(function (term) { return String(term || '').trim(); })
        .filter(function (term, idx, arr) {
            return term !== '' && arr.indexOf(term) === idx;
        });
    const historyPageSize = 8;

    const modal = document.getElementById('conversation-modal');
    const modalBody = document.getElementById('conversation-modal-body');
    const modalCloseButton = modal ? modal.querySelector('.conversation-modal-close') : null;
    const closeButtons = document.querySelectorAll('[data-close-modal="1"]');
    const viewButtons = document.querySelectorAll('.js-view-conversation');
    const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    let activeConversation = null;
    let lastTriggerButton = null;
    let previousFocusedElement = null;

    if (!modal || !modalBody || !viewButtons.length) {
        return;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function highlightText(value) {
        let output = escapeHtml(value);

        highlightTerms.forEach(function (term) {
            const escapedTerm = escapeHtml(term);
            if (escapedTerm === '') {
                return;
            }
            const pattern = new RegExp('(' + escapeRegExp(escapedTerm) + ')', 'gi');
            output = output.replace(pattern, '<mark class="hl">$1</mark>');
        });

        return output;
    }

    function setButtonLoading(button, isLoading) {
        if (!button) {
            return;
        }

        const label = button.querySelector('.btn-view-label');
        if (isLoading) {
            button.disabled = true;
            button.classList.add('is-loading');
            if (label) {
                label.textContent = 'Caricamento';
            }
            return;
        }

        button.disabled = false;
        button.classList.remove('is-loading');
        if (label) {
            label.textContent = button.getAttribute('data-default-label') || 'Visualizza';
        }
    }

    function openModal() {
        previousFocusedElement = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        window.setTimeout(function () {
            if (modalCloseButton) {
                modalCloseButton.focus();
            }
        }, 0);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        const focusTarget = lastTriggerButton || previousFocusedElement;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }

        if (lastTriggerButton) {
            setButtonLoading(lastTriggerButton, false);
            lastTriggerButton = null;
        }
    }

    function renderHistory(history, currentPage) {
        if (!Array.isArray(history) || history.length === 0) {
            return '<p class="muted">Nessun messaggio disponibile.</p>';
        }

        const totalPages = Math.max(1, Math.ceil(history.length / historyPageSize));
        const safePage = Math.min(Math.max(1, currentPage), totalPages);
        const start = (safePage - 1) * historyPageSize;
        const end = start + historyPageSize;
        const pageEntries = history.slice(start, end);

        const items = pageEntries.map(function (entry, idx) {
            const role = entry && entry.role ? String(entry.role) : 'unknown';
            const content = entry && entry.content ? String(entry.content) : '';
            const roleClass = role === 'user' ? 'user' : (role === 'assistant' || role === 'ai' ? 'assistant' : 'system');
            const itemIndex = start + idx + 1;

            return '<article class="history-item ' + roleClass + '">' +
                '<header><strong>#' + itemIndex + ' - ' + escapeHtml(role) + '</strong></header>' +
                '<pre>' + highlightText(content) + '</pre>' +
                '</article>';
        }).join('');

        if (totalPages === 1) {
            return items;
        }

        return items +
            '<div class="history-pagination">' +
            '<button type="button" class="btn secondary" data-history-page="' + (safePage - 1) + '" ' + (safePage <= 1 ? 'disabled' : '') + '>Precedente</button>' +
            '<span class="muted">Pagina ' + safePage + ' di ' + totalPages + '</span>' +
            '<button type="button" class="btn secondary" data-history-page="' + (safePage + 1) + '" ' + (safePage >= totalPages ? 'disabled' : '') + '>Successiva</button>' +
            '</div>';
    }

    function renderConversation(conv, page) {
        const currentPage = page || 1;
        const html = [
            '<div class="conversation-meta">',
            '<div><span class="muted">ID</span><strong>' + highlightText(conv.id) + '</strong></div>',
            '<div><span class="muted">Sessione</span><strong>' + highlightText(conv.session_id || '') + '</strong></div>',
            '<div><span class="muted">Email</span><strong>' + highlightText(conv.customer_email || '-') + '</strong></div>',
            '<div><span class="muted">Messaggi</span><strong>' + escapeHtml(conv.message_count || 0) + '</strong></div>',
            '<div><span class="muted">Started</span><strong>' + highlightText(conv.started_at || '') + '</strong></div>',
            '<div><span class="muted">Last Activity</span><strong>' + highlightText(conv.last_activity_at || '') + '</strong></div>',
            '<div><span class="muted">Flush Reason</span><strong>' + highlightText(conv.last_flush_reason || '-') + '</strong></div>',
            '<div><span class="muted">IP Hash</span><strong>' + highlightText(conv.ip_hash || '-') + '</strong></div>',
            '</div>',
            '<div class="conversation-history">',
            '<h3>Storico Messaggi</h3>',
            renderHistory(conv.history, currentPage),
            '</div>'
        ];

        modalBody.innerHTML = html.join('');
    }

    function loadConversation(id, triggerButton) {
        if (lastTriggerButton && lastTriggerButton !== triggerButton) {
            setButtonLoading(lastTriggerButton, false);
        }
        lastTriggerButton = triggerButton || null;
        setButtonLoading(lastTriggerButton, true);

        modalBody.innerHTML = '<p class="muted">Caricamento conversazione...</p>';
        openModal();

        const url = 'dashboard.php?ajax=view&id=' + encodeURIComponent(id);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.ok) {
                        throw new Error(data && data.error ? data.error : 'Errore imprevisto durante il caricamento.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                activeConversation = data.conversation || {};
                renderConversation(activeConversation, 1);
                setButtonLoading(lastTriggerButton, false);
            })
            .catch(function (error) {
                modalBody.innerHTML = '<div class="err">' + escapeHtml(error.message) + '</div>';
                setButtonLoading(lastTriggerButton, false);
            });
    }

    viewButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id') || '';
            if (id !== '') {
                loadConversation(id, this);
            }
        });
    });

    modalBody.addEventListener('click', function (event) {
        const target = event.target;
        if (!target || !target.getAttribute) {
            return;
        }

        const pageAttr = target.getAttribute('data-history-page');
        if (pageAttr === null) {
            return;
        }

        const page = parseInt(pageAttr, 10);
        if (activeConversation && !Number.isNaN(page)) {
            renderConversation(activeConversation, page);
        }
    });

    closeButtons.forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Tab' && modal.classList.contains('is-open')) {
            const focusableElements = modal.querySelectorAll(focusableSelector);
            if (!focusableElements.length) {
                return;
            }

            const first = focusableElements[0];
            const last = focusableElements[focusableElements.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
                return;
            }

            if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
                return;
            }
        }

        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>
