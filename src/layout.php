<?php
// src/layout.php
require_once __DIR__ . '/helpers.php';

function render_header(string $title): void {
  $cfg  = app_cfg();
  $base = $cfg['app']['base_url'] ?? '';
  $u = current_user();

  $menu = load_menu_tree('main');

  // Route aus Query (für UI-Schalter / CSS-Scoping)
  $route = (string)($_GET['r'] ?? '');
  $isWartung = str_starts_with($route, 'wartung.');
  $isTicket  = str_starts_with($route, 'stoerung.') || $route === 'ticket' || str_contains($route, 'ticket');

  // UI v2: Seiten wie wartung.* rendern ihren eigenen Page Header → Layout-Titel ausblenden
  $hideLayoutTitle = $isWartung;

  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>

    <!-- legacy -->
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/main.css?v=<?= (int)@filemtime(__DIR__ . '/css/main.css') ?>">

    <?php if ($isTicket): ?>
      <link rel="stylesheet" href="<?= e($base) ?>/src/css/ticket.css?v=<?= (int)@filemtime(__DIR__ . '/css/ticket.css') ?>">
    <?php endif; ?>

    <!-- UI v2 foundation (loaded after main.css so it can override) -->
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/ui-v2/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/css/ui-v2/tokens.css') ?>">
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/ui-v2/base.css?v=<?= (int)@filemtime(__DIR__ . '/css/ui-v2/base.css') ?>">
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/ui-v2/components.css?v=<?= (int)@filemtime(__DIR__ . '/css/ui-v2/components.css') ?>">
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/ui-v2/layout.css?v=<?= (int)@filemtime(__DIR__ . '/css/ui-v2/layout.css') ?>">
  </head>

  <body class="ui-v2">
  <a class="skip-link" href="#main-content">Zum Inhalt springen</a>
  <div class="app">

    <aside class="sidebar" aria-label="Seitenleiste">
      <div class="sidebar__brand">
        <a href="<?= e($base) ?>/app.php?r=<?= e(urlencode($cfg['app']['default_route'] ?? 'wartung.dashboard')) ?>">
          Instandhaltung
        </a>
      </div>

      <div class="sidebar__user">
        <?php if ($u): ?>
          <div class="sidebar__user-name"><span aria-hidden="true">👤</span> <?= e($u['anzeigename']) ?></div>
          <a class="ui-btn ui-btn--ghost sidebar__logout" href="<?= e($base) ?>/logout.php">Logout</a>
        <?php else: ?>
          <a class="ui-btn ui-btn--ghost sidebar__logout" href="<?= e($base) ?>/login.php">Login</a>
        <?php endif; ?>
      </div>

      <nav class="sidebar__nav" aria-label="Hauptnavigation">
        <?php
        $renderNode = function($node, $depth = 0) use (&$renderNode) {
          $isActive = !empty($node['active']) || !empty($node['branch_active']);
          $cls = $isActive ? 'navitem--active' : '';
          $indent = $depth > 0 ? ' navitem--sub' : '';

          // Gruppe (Parent ohne href)
          if (!empty($node['is_group'])) {
            echo '<div class="navgroup ' . ($isActive ? 'navgroup--active' : '') . '">';
            echo e($node['label']);
            echo '</div>';

            if (!empty($node['children'])) {
              echo '<div class="navsub">';
              foreach ($node['children'] as $ch) $renderNode($ch, $depth + 1);
              echo '</div>';
            }
            return;
          }

          // Normaler Link (oder Parent mit href)
          $href = $node['href'] ?? '#';
          $ariaCurrent = !empty($node['active']) ? ' aria-current="page"' : '';
          echo '<a class="navitem' . $indent . ' ' . $cls . '" href="' . e($href) . '"' . $ariaCurrent . '">';
          echo e($node['label']);
          echo '</a>';

          if (!empty($node['children'])) {
            echo '<div class="navsub">';
            foreach ($node['children'] as $ch) $renderNode($ch, $depth + 1);
            echo '</div>';
          }
        };

        foreach ($menu as $node) $renderNode($node, 0);
        ?>
      </nav>
    </aside>

    <main class="content" id="main-content" tabindex="-1">
      <?php if (!$hideLayoutTitle): ?>
        <div class="content__top">
          <h1><?= e($title) ?></h1>
        </div>
      <?php endif; ?>

      <div class="content__body">
  <?php
}

function render_footer(): void {
  ?>
      </div>
    </main>
  </div>
  </body>
  </html>
  <?php
}