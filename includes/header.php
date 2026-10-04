<?php
/**
 * Opens the HTML page, loads the stylesheets, and includes navbar + sidebar.
 * Before including this, a page should set:
 *   $pageTitle  - text for the browser tab
 *   $activePage - key of the sidebar item to highlight
 */
require_once __DIR__ . '/functions.php';

$pageTitle  = $pageTitle  ?? 'Dashboard';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<?php require __DIR__ . '/navbar.php'; ?>

<div class="app-wrapper">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="app-main">
        <?= render_flash() ?>
