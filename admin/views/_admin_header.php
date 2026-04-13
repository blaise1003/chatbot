<?php

declare(strict_types=1);

if (!function_exists('admin_menu_groups')) {
    require_once __DIR__ . '/_admin_menu.php';
}

function admin_header_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_module_label(string $moduleId): string
{
    foreach (admin_menu_groups() as $group) {
        foreach ($group['items'] as $item) {
            if ((string) $item['id'] === $moduleId) {
                return (string) $item['label'];
            }
        }
    }

    return 'Modulo';
}

function admin_render_header(string $moduleId, string $title, string $subtitle = '', array $actions = []): string
{
    $moduleLabel = admin_module_label($moduleId);

    $hasKeysAction = false;
    foreach ($actions as $action) {
        $href = isset($action['href']) ? (string) $action['href'] : '';
        if ($href === 'keys.php') {
            $hasKeysAction = true;
            break;
        }
    }

    if (!$hasKeysAction && $moduleId !== 'keys') {
        $actions[] = [
            'label' => 'Config Keys',
            'href' => 'keys.php',
            'class' => 'secondary',
        ];
    }

    ob_start();
    ?>
    <header class="admin-header">
        <div class="admin-header__main">
            <nav class="admin-breadcrumb" aria-label="Breadcrumb">
                <a href="index.php?module=overview">Admin</a>
                <span>/</span>
                <span><?= admin_header_h($moduleLabel) ?></span>
            </nav>
            <h1 class="admin-header__title"><?= admin_header_h($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <p class="admin-header__subtitle"><?= admin_header_h($subtitle) ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($actions)): ?>
            <div class="admin-header__actions">
                <?php foreach ($actions as $action): ?>
                    <?php
                    $label = isset($action['label']) ? (string) $action['label'] : '';
                    $href = isset($action['href']) ? (string) $action['href'] : '#';
                    $class = isset($action['class']) ? (string) $action['class'] : 'secondary';
                    if ($label === '') {
                        continue;
                    }
                    ?>
                    <a class="btn <?= admin_header_h($class) ?>" href="<?= admin_header_h($href) ?>"><?= admin_header_h($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </header>
    <?php

    return (string) ob_get_clean();
}
