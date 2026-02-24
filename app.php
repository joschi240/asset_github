<?php
// app.php
require_once __DIR__ . '/src/layout.php';

define('APP_INNER', true);

$cfg = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$routeKey = trim((string)($_GET['r'] ?? ''));
if ($routeKey === '') {
  $routeKey = (string)($cfg['app']['default_route'] ?? 'wartung.dashboard');
}

$route = db_one(
  "SELECT route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv
   FROM core_route
   WHERE route_key = ? AND aktiv=1
   LIMIT 1",
  [$routeKey]
);

if (!$route) {
  http_response_code(404);
  render_header('404');
  echo '<div class="card"><h2>Seite nicht gefunden</h2><p class="small">Route existiert nicht oder ist deaktiviert.</p></div>';
  render_footer();
  exit;
}

if ((int)$route['require_login'] === 1) {
  require_login();
}

// Permission zentral
$u = current_user();
$userId = $u['id'] ?? null;
$oid = ($route['objekt_id'] !== null) ? (int)$route['objekt_id'] : null;

if (!user_can_see($userId, $route['modul'], $route['objekt_typ'], $oid)) {
  http_response_code(403);
  render_header('403 – Kein Zugriff');
  echo '<div class="card"><h2>Kein Zugriff</h2><p class="small">Dir fehlt die Berechtigung für diese Seite.</p></div>';
  render_footer();
  exit;
}

// Pfad-Absicherung
$file = str_replace('\\', '/', (string)$route['file_path']);
if (strpos($file, '..') !== false) {
  http_response_code(400);
  render_header('400');
  echo '<div class="card"><h2>Ungültiger Pfad</h2></div>';
  render_footer();
  exit;
}

$root = realpath(__DIR__);
$full = realpath(__DIR__ . '/' . ltrim($file, '/'));
if (!$full || strpos($full, $root) !== 0 || !is_file($full)) {
  http_response_code(500);
  render_header('500 – View fehlt');
  echo '<div class="card"><h2>Konfiguration fehlerhaft</h2><p class="small">View-Datei nicht gefunden: ' . e($file) . '</p></div>';
  render_footer();
  exit;
}

render_header((string)$route['titel']);
$GLOBALS['route'] = $route;

require $full;

render_footer();