<?php

declare(strict_types=1);

function admin_menu_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_menu_groups(): array
{
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
                    'id' => 'dashboard',
                    'label' => 'Dashboard Conversazioni',
                    'href' => 'dashboard.php',
                    'description' => 'Analisi sessioni, ricerca, edit e statistiche.',
                    'enabled' => true,
                ],
                [
                    'id' => 'redis',
                    'label' => 'Redis Admin',
                    'href' => 'redis_admin.php',
                    'description' => 'Diagnostica Redis, TTL e rate limit.',
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
                    'label' => 'Health Check',
                    'href' => 'index.php?module=health',
                    'description' => 'Verifica requisiti runtime e configurazione.',
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
                    'description' => 'Visualizza il prompt di sistema inviato a Claude.',
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
                                <?= admin_menu_h((string) $item['label']) ?>
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
