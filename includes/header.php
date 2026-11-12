<?php
/**
 * Layout superior (sidebar + topbar). Variáveis esperadas: $pageTitle, $active.
 * Com ?clean=1 a navegação é ocultada (visão limpa para relatórios).
 */
$user = current_user();
$pageTitle = $pageTitle ?? '';
$active = $active ?? '';
$clean = !empty($_GET['clean']);
$sys = get_setting('system_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ? $pageTitle . ' · ' . $sys : $sys) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?= trim(($clean ? 'clean ' : '') . ($bodyClass ?? '')) ?>">
<?php if ($user): ?>
<div class="layout">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="dashboard.php">
            <img src="assets/img/favicon.png" alt="" width="28" height="28">
            <span><?= e($sys) ?></span>
        </a>
        <nav>
            <?php foreach (nav_items($user['role']) as [$file, $label, $ico]): ?>
                <a href="<?= e($file) ?>" class="<?= $active === $file ? 'active' : '' ?>">
                    <?= icon($ico) ?> <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="who">
                <strong><?= e($user['name']) ?></strong>
                <small><?= e(ROLES[$user['role']]) ?></small>
            </div>
            <a href="logout.php" class="logout" title="Sair"><?= icon('logout') ?> Sair</a>
        </div>
    </aside>
    <div class="main">
        <header class="topbar no-print">
            <button class="btn-icon menu-toggle" type="button" data-menu-toggle aria-label="Menu"><?= icon('menu', 22) ?></button>
            <h1><?= e($pageTitle) ?></h1>
        </header>
        <main class="content">
            <?php foreach (flashes() as [$type, $msg]): ?>
                <div class="alert alert-<?= e($type) ?>" role="status"><?= e($msg) ?></div>
            <?php endforeach; ?>
<?php else: ?>
<main class="auth-wrap">
<?php endif; ?>
