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

// Auth guard
$isAuthenticated = isset($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;
if (!$isAuthenticated) {
    header('Location: dashboard.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function analytics_logs_dir(): string
{
    if (defined('CHATBOT_LOGS_DIR') && is_string(CHATBOT_LOGS_DIR) && CHATBOT_LOGS_DIR !== '') {
        return rtrim((string) CHATBOT_LOGS_DIR, '/\\');
    }
    return dirname(__DIR__, 2) . '/chatbot_logs';
}

// ??? Raccolta metriche ultimi 30 giorni ??????????????????????????????????????
$logsDir   = analytics_logs_dir();
$daysRange = 30;
$allDays   = [];

for ($i = $daysRange - 1; $i >= 0; $i--) {
    $date    = date('Y-m-d', strtotime("-{$i} days"));
    $file    = $logsDir . '/metrics-' . $date . '.json';
    $metrics = [
        'requests_total'             => 0,
        'errors_total'               => 0,
        'rate_limit_exceeded_total'  => 0,
        'http_500_total'             => 0,
        'human_handoff_requested_total' => 0,
        'alerts_sent_total'          => 0,
    ];

    if (is_file($file)) {
        $raw     = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($metrics as $key => $default) {
                $metrics[$key] = isset($decoded[$key]) ? (int) $decoded[$key] : 0;
            }
        }
    }

    $allDays[] = array_merge(['date' => $date], $metrics);
}

$today     = $allDays[$daysRange - 1];
$yesterday = $allDays[$daysRange - 2];

// Trend helper: +/- percentage vs yesterday
function trend(int $today, int $yesterday): array
{
    if ($yesterday === 0) {
        return ['dir' => 'neutral', 'pct' => null];
    }
    $pct = (int) round((($today - $yesterday) / $yesterday) * 100);
    return ['dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral'), 'pct' => abs($pct)];
}

function trend_html(array $trend, bool $upIsBad = false): string
{
    if ($trend['dir'] === 'neutral' || $trend['pct'] === null) {
        return '<span class="trend-neutral">— </span>';
    }
    $isGood = ($trend['dir'] === 'up') ? !$upIsBad : $upIsBad;
    $cls    = $isGood ? 'trend-good' : 'trend-bad';
    $arrow  = $trend['dir'] === 'up' ? '?' : '?';
    return '<span class="' . $cls . '">' . $arrow . ' ' . $trend['pct'] . '%</span>';
}

$kpiCards = [
    [
        'label'    => 'Richieste oggi',
        'value'    => $today['requests_total'],
        'trend'    => trend($today['requests_total'], $yesterday['requests_total']),
        'upIsBad'  => false,
        'color'    => '#3b82f6',
    ],
    [
        'label'    => 'Errori oggi',
        'value'    => $today['errors_total'],
        'trend'    => trend($today['errors_total'], $yesterday['errors_total']),
        'upIsBad'  => true,
        'color'    => '#ef4444',
    ],
    [
        'label'    => 'Rate limit hits',
        'value'    => $today['rate_limit_exceeded_total'],
        'trend'    => trend($today['rate_limit_exceeded_total'], $yesterday['rate_limit_exceeded_total']),
        'upIsBad'  => true,
        'color'    => '#f59e0b',
    ],
    [
        'label'    => 'Handoff richiesti',
        'value'    => $today['human_handoff_requested_total'],
        'trend'    => trend($today['human_handoff_requested_total'], $yesterday['human_handoff_requested_total']),
        'upIsBad'  => false,
        'color'    => '#8b5cf6',
    ],
];

// ??? SVG chart: ultimi 14 giorni ?????????????????????????????????????????????
$chartDays = array_slice($allDays, -14);
$maxVal    = 1;
foreach ($chartDays as $d) {
    $maxVal = max($maxVal, $d['requests_total'], $d['errors_total']);
}

$svgW    = 800;
$svgH    = 240;
$lM      = 55;
$rM      = 15;
$tM      = 20;
$bM      = 60;
$chartW  = $svgW - $lM - $rM;
$chartH  = $svgH - $tM - $bM;
$n       = count($chartDays); // 14
$groupW  = $chartW / $n;
$barW    = max(8.0, min(18.0, $groupW * 0.36));
$barGap  = 3.0;

function svg_bar_rect(float $x, float $y, float $w, float $h, string $fill, string $title): string
{
    if ($h <= 0) {
        return '';
    }
    return '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" fill="' . $fill . '" rx="3"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></rect>';
}

ob_start();
?>
<svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" xmlns="http://www.w3.org/2000/svg" class="analytics-chart-svg" aria-label="Trend ultimi 14 giorni">

    <!-- Grid lines + Y labels -->
    <?php for ($t = 0; $t <= 5; $t++): ?>
        <?php
        $yVal  = (int) round($maxVal * $t / 5);
        $yPos  = round($tM + $chartH - ($chartH * $t / 5), 2);
        ?>
        <line x1="<?= $lM ?>" y1="<?= $yPos ?>" x2="<?= $svgW - $rM ?>" y2="<?= $yPos ?>" stroke="#e2e8f0" stroke-width="1"/>
        <text x="<?= $lM - 6 ?>" y="<?= $yPos + 4 ?>" text-anchor="end" font-size="10" fill="#64748b"><?= h(number_format($yVal)) ?></text>
    <?php endfor; ?>

    <!-- Bars -->
    <?php foreach ($chartDays as $i => $day): ?>
        <?php
        $groupX   = $lM + $i * $groupW;
        $barStart = $groupX + ($groupW - 2 * $barW - $barGap) / 2;

        // requests bar (blue)
        $h1 = $day['requests_total'] > 0 ? ($chartH * $day['requests_total'] / $maxVal) : 0;
        $y1 = $tM + $chartH - $h1;
        echo svg_bar_rect($barStart, $y1, $barW, $h1, '#3b82f6', 'Richieste: ' . $day['requests_total'] . ' (' . $day['date'] . ')');

        // errors bar (red)
        $h2 = $day['errors_total'] > 0 ? ($chartH * $day['errors_total'] / $maxVal) : 0;
        $y2 = $tM + $chartH - $h2;
        echo svg_bar_rect($barStart + $barW + $barGap, $y2, $barW, $h2, '#ef4444', 'Errori: ' . $day['errors_total'] . ' (' . $day['date'] . ')');

        // X label
        $xLabel = $lM + ($i + 0.5) * $groupW;
        $label  = date('d/m', (int) strtotime($day['date']));
        ?>
        <text
            x="<?= round($xLabel, 2) ?>"
            y="<?= $tM + $chartH + 14 ?>"
            text-anchor="end"
            font-size="10"
            fill="#64748b"
            transform="rotate(-45, <?= round($xLabel, 2) ?>, <?= $tM + $chartH + 14 ?>)"
        ><?= h($label) ?></text>
    <?php endforeach; ?>

    <!-- X axis line -->
    <line x1="<?= $lM ?>" y1="<?= $tM + $chartH ?>" x2="<?= $svgW - $rM ?>" y2="<?= $tM + $chartH ?>" stroke="#94a3b8" stroke-width="1.5"/>

    <!-- Legend -->
    <rect x="<?= $lM ?>" y="<?= $svgH - 18 ?>" width="12" height="12" fill="#3b82f6" rx="2"/>
    <text x="<?= $lM + 16 ?>" y="<?= $svgH - 8 ?>" font-size="11" fill="#475569">Richieste</text>
    <rect x="<?= $lM + 90 ?>" y="<?= $svgH - 18 ?>" width="12" height="12" fill="#ef4444" rx="2"/>
    <text x="<?= $lM + 106 ?>" y="<?= $svgH - 8 ?>" font-size="11" fill="#475569">Errori</text>

</svg>
<?php
$svgOutput = (string) ob_get_clean();

// ??? Totals per table ?????????????????????????????????????????????????????????
$totals = ['requests_total' => 0, 'errors_total' => 0, 'rate_limit_exceeded_total' => 0, 'human_handoff_requested_total' => 0];
foreach ($allDays as $d) {
    foreach ($totals as $key => $v) {
        $totals[$key] += $d[$key];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics</title>
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-menu.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/analytics.css">
</head>
<body>
<div class="admin-layout">
    <?= admin_render_sidebar('analytics') ?>

    <main class="admin-main">
        <div class="page">
            <?= admin_render_header(
                'analytics',
                'Analytics',
                'Metriche operative: richieste, errori, rate limit e handoff degli ultimi 30 giorni.',
                [
                    ['label' => 'Home Admin', 'href' => 'index.php?module=overview', 'class' => 'secondary'],
                    ['label' => 'Log Viewer', 'href' => 'logs.php', 'class' => 'secondary'],
                ]
            ) ?>

            <!-- KPI Cards -->
            <div class="analytics-kpi-grid">
                <?php foreach ($kpiCards as $card): ?>
                    <div class="analytics-kpi-card" style="--kpi-color: <?= h($card['color']) ?>">
                        <div class="analytics-kpi-label"><?= h($card['label']) ?></div>
                        <div class="analytics-kpi-value"><?= h(number_format($card['value'])) ?></div>
                        <div class="analytics-kpi-trend">
                            <?= trend_html($card['trend'], (bool) $card['upIsBad']) ?>
                            <span class="muted">vs ieri</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Trend Chart -->
            <section class="panel">
                <div class="analytics-chart-head">
                    <h2>Trend ultimi 14 giorni</h2>
                    <div class="muted" style="font-size:13px;">Passa il mouse sulle barre per i dettagli</div>
                </div>
                <div class="analytics-chart-wrap">
                    <?= $svgOutput ?>
                </div>
            </section>

            <!-- Day-by-day table (last 30 days) -->
            <section class="panel">
                <h2>Storico ultimi 30 giorni</h2>
                <div class="analytics-table-wrap">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th class="num">Richieste</th>
                                <th class="num">Errori</th>
                                <th class="num">Rate limit</th>
                                <th class="num">Handoff</th>
                                <th class="num">Err %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($allDays) as $d): ?>
                                <?php
                                $errPct = $d['requests_total'] > 0
                                    ? round($d['errors_total'] / $d['requests_total'] * 100, 1)
                                    : ($d['errors_total'] > 0 ? 100.0 : 0.0);
                                $isToday = $d['date'] === date('Y-m-d');
                                ?>
                                <tr class="<?= $isToday ? 'analytics-row-today' : '' ?>">
                                    <td><?= h($d['date']) ?><?= $isToday ? ' <span class="pill-today">oggi</span>' : '' ?></td>
                                    <td class="num"><?= h(number_format($d['requests_total'])) ?></td>
                                    <td class="num <?= $d['errors_total'] > 0 ? 'analytics-val-bad' : '' ?>"><?= h(number_format($d['errors_total'])) ?></td>
                                    <td class="num <?= $d['rate_limit_exceeded_total'] > 0 ? 'analytics-val-warn' : '' ?>"><?= h(number_format($d['rate_limit_exceeded_total'])) ?></td>
                                    <td class="num"><?= h(number_format($d['human_handoff_requested_total'])) ?></td>
                                    <td class="num <?= $errPct > 5 ? 'analytics-val-bad' : '' ?>"><?= $errPct > 0 ? h((string) $errPct) . '%' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="analytics-totals">
                                <td><strong>Totale 30 gg</strong></td>
                                <td class="num"><strong><?= h(number_format($totals['requests_total'])) ?></strong></td>
                                <td class="num"><strong><?= h(number_format($totals['errors_total'])) ?></strong></td>
                                <td class="num"><strong><?= h(number_format($totals['rate_limit_exceeded_total'])) ?></strong></td>
                                <td class="num"><strong><?= h(number_format($totals['human_handoff_requested_total'])) ?></strong></td>
                                <td class="num">—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

        </div>
    </main>
</div>
</body>
</html>
