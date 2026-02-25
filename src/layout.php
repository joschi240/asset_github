<?php
// src/layout.php
require_once __DIR__ . '/helpers.php';

function render_header(string $title): void {
  $cfg  = app_cfg();
  $base = $cfg['app']['base_url'] ?? '';
  $u = current_user();

  $menu = load_menu_tree('main');

  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/main.css">
    <link rel="stylesheet" href="<?= e($base) ?>/src/css/ticket.css">
  </head>
  <body>
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
          <a class="btn btn--ghost sidebar__logout" href="<?= e($base) ?>/logout.php">Logout</a>
        <?php else: ?>
          <a class="btn btn--ghost sidebar__logout" href="<?= e($base) ?>/login.php">Login</a>
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
      <div class="content__top">
        <h1><?= e($title) ?></h1>
      </div>
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