<?php

//declare(strict_types=1);

function admin_menu_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_handoff_status_counts_from_redis(): array
{
    $counts = [
        'requested' => 0,
        'claimed' => 0,
    ];

    if (!extension_loaded('redis') || !class_exists('Redis')) {
        return $counts;
    }

    $host = defined('CHATBOT_REDIS_HOST') ? (string) CHATBOT_REDIS_HOST : '127.0.0.1';
    $port = defined('CHATBOT_REDIS_PORT') ? (int) CHATBOT_REDIS_PORT : 6379;
    $password = defined('CHATBOT_REDIS_PASSWORD') ? (string) CHATBOT_REDIS_PASSWORD : '';
    $prefix = defined('CHATBOT_REDIS_PREFIX') ? (string) CHATBOT_REDIS_PREFIX : 'getty:';
    $pattern = $prefix . 'sess:__handoff__*';

    try {
        $redisClass = 'Redis';
        $redis = new $redisClass();
        if (!@$redis->connect($host, $port, 2.0)) {
            return $counts;
        }

        if ($password !== '' && !$redis->auth($password)) {
            return $counts;
        }

        $it = null;
        while (($keys = $redis->scan($it, $pattern, 200)) !== false) {
            if (!is_array($keys) || empty($keys)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $raw = $redis->get($key);
                if (!is_string($raw) || $raw === '') {
                    continue;
                }

                $decoded = json_decode($raw, true);
                $status = is_array($decoded) && isset($decoded['status']) ? (string) $decoded['status'] : 'none';
                if ($status === 'requested') {
                    $counts['requested']++;
                } elseif ($status === 'claimed') {
                    $counts['claimed']++;
                }
            }
        }
    } catch (Throwable $e) {
        return [
            'requested' => 0,
            'claimed' => 0,
        ];
    }

    return $counts;
}

function admin_handoff_badge_count(): int
{
    $counts = admin_handoff_status_counts_from_redis();
    return (int) $counts['requested'] + (int) $counts['claimed'];
}

function admin_handoff_requested_badge_count(): int
{
    $counts = admin_handoff_status_counts_from_redis();
    return (int) $counts['requested'];
}

function admin_menu_groups(): array
{
    $handoffRequestedBadge = admin_handoff_requested_badge_count();
    $handoffLabel = 'Handoff Queue';

    return [
        [
            'id' => 'operations',
            'label' => 'Operations',
            'items' => [
                [
                    'id' => 'overview',
                    'label' => 'Panoramica',
                    'href' => 'index.php?module=overview',
                    'description' => 'Stato generale chatbot e quick links.',
                    'enabled' => true,
                ],
                [
                    'id' => 'analytics',
                    'label' => 'Analytics',
                    'href' => 'analytics.php',
                    'description' => 'Metriche operative: richieste, errori, handoff, trend.',
                    'enabled' => true,
                ],
                [
                    'id' => 'dashboard',
                    'label' => 'Gestione Conversazioni',
                    'href' => 'dashboard.php',
                    'description' => 'Analisi sessioni, ricerca, edit e statistiche.',
                    'enabled' => true,
                ],
                [
                    'id' => 'handoff-queue',
                    'label' => $handoffLabel,
                    'href' => 'handoff_queue.php',
                    'description' => 'Coda richieste operatore umano e takeover.',
                    'alert_badge' => $handoffRequestedBadge,
                    'enabled' => true,
                ],
                [
                    'id' => 'redis',
                    'label' => 'Gestione sessioni real-time',
                    'href' => 'redis_admin.php',
                    'description' => 'Diagnostica Redis, TTL e rate limit.',
                    'enabled' => true,
                ],
                [
                    'id' => 'load-test',
                    'label' => 'Test Carico',
                    'href' => 'load_test.php',
                    'description' => 'Simula traffico concorrente e verifica rate limit.',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'platform',
            'label' => 'Platform',
            'items' => [
                [
                    'id' => 'health',
                    'label' => 'System info',
                    'href' => 'index.php?module=health',
                    'description' => 'Verifica requisiti runtime e configurazione.',
                    'enabled' => true,
                ],
                [
                    'id' => 'runtime-health',
                    'label' => 'Runtime Health check',
                    'href' => 'runtime_health.php',
                    'description' => 'Stato dipendenze e metriche applicative live.',
                    'enabled' => true,
                ],
                [
                    'id' => 'logs',
                    'label' => 'Logs Viewer',
                    'href' => 'logs.php',
                    'description' => 'Consultazione log applicativi.',
                    'enabled' => true,
                ],
                [
                    'id' => 'prompts',
                    'label' => 'Prompt Manager',
                    'href' => 'prompt.php',
                    'description' => 'Modifica il prompt di sistema inviato a Claude.',
                    'enabled' => true,
                ],
            ],
        ],
        [
            'id' => 'governance',
            'label' => 'Governance',
            'items' => [
                [
                    'id' => 'origins',
                    'label' => 'CORS Origins',
                    'href' => 'cors_origins.php',
                    'description' => 'Gestione domini autorizzati.',
                    'enabled' => true,
                ],
                [
                    'id' => 'keys',
                    'label' => 'Key Rotation',
                    'href' => 'keys.php',
                    'description' => 'Chiavi Claude, Doofinder e runtime config.',
                    'enabled' => true,
                ],
            ],
        ],
    ];
}

function admin_render_sidebar(string $activeModule): string
{
    $groups = admin_menu_groups();

    ob_start();
    ?>
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <h1>Chatbot Admin</h1>
            <p>Feature tree modulare e scalabile</p>
        </div>

        <nav class="admin-tree" aria-label="Feature tree">
            <?php foreach ($groups as $group): ?>
                <details open>
                    <summary><?= admin_menu_h((string) $group['label']) ?></summary>
                    <div class="admin-items">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php
                            $isActive = $activeModule === $item['id'];
                            $classes = 'admin-item';
                            if ($isActive) {
                                $classes .= ' active';
                            }
                            if (empty($item['enabled'])) {
                                $classes .= ' disabled';
                            }
                            ?>
                            <a class="<?= admin_menu_h($classes) ?>" href="<?= admin_menu_h((string) $item['href']) ?>">
                                <span class="admin-item-title-wrap">
                                    <span class="admin-item-title"><?= admin_menu_h((string) $item['label']) ?></span>
                                    <?php if (!empty($item['alert_badge'])): ?>
                                        <span class="admin-alert-badge" aria-label="Richieste operatore non prese in carico">
                                            <?= admin_menu_h((string) $item['alert_badge']) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <small><?= admin_menu_h((string) $item['description']) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <a class="admin-item admin-item-logout" href="dashboard.php?logout=1">Logout Admin</a>
        </div>
    </aside>
    <?php

    return (string) ob_get_clean();
}
