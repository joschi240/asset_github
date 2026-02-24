<?php
// src/auth.php
require_once __DIR__ . '/db.php';

function app_cfg(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;

  $cfg = require __DIR__ . '/config.php';

  // base_url automatisch aus SCRIPT_NAME ableiten
  if (($cfg['app']['base_url'] === null || $cfg['app']['base_url'] === '')
      && !empty($cfg['app']['base_url_auto'])) {

    $script = $_SERVER['SCRIPT_NAME'] ?? ''; // z.B. /asset_ki/app.php
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '/') $base = '';
    $cfg['app']['base_url'] = $base;
  }

  return $cfg;
}

function session_boot(): void {
  $cfg = app_cfg();
  if (session_status() === PHP_SESSION_ACTIVE) return;

  session_name($cfg['app']['session_name']);
  session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'path' => '/',
  ]);
  session_start();
}

function current_user(): ?array {
  session_boot();
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!current_user()) {
    $cfg = app_cfg();
    header("Location: {$cfg['app']['base_url']}/login.php");
    exit;
  }
}

function login(string $benutzername, string $passwort): bool {
  session_boot();

  $u = db_one(
    "SELECT id, benutzername, passwort_hash, anzeigename, aktiv
     FROM core_user
     WHERE benutzername = ?
     LIMIT 1",
    [$benutzername]
  );

  if (!$u || (int)$u['aktiv'] !== 1) return false;
  if (!password_verify($passwort, $u['passwort_hash'])) return false;

  session_regenerate_id(true);

  $_SESSION['user'] = [
    'id' => (int)$u['id'],
    'benutzername' => $u['benutzername'],
    'anzeigename' => $u['anzeigename'] ?: $u['benutzername'],
  ];

  db_exec("UPDATE core_user SET last_login_at = NOW() WHERE id = ?", [(int)$u['id']]);
  return true;
}

function logout(): void {
  session_boot();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
  }
  session_destroy();
}

function csrf_token(): string {
  session_boot();
  $cfg = app_cfg();
  $key = $cfg['app']['csrf_key'];

  if (empty($_SESSION[$key])) {
    $_SESSION[$key] = bin2hex(random_bytes(16));
  }
  return $_SESSION[$key];
}

function csrf_check(?string $token): void {
  session_boot();
  $cfg = app_cfg();
  $key = $cfg['app']['csrf_key'];

  if (!$token || empty($_SESSION[$key]) || !hash_equals($_SESSION[$key], $token)) {
    http_response_code(400);
    exit('CSRF token invalid');
  }
}

// ---- Permission Helpers (Capabilities) --------------------
// Erwartet Tabelle core_permission:
// user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen

if (!function_exists('user_can_flag')) {
  function user_can_flag(?int $userId, ?string $modul, ?string $objektTyp, $objektId, string $flagCol): bool {
    if (!$userId || !$modul || !$objektTyp) return false;

    // Objekt-ID optional (NULL => global)
    $objektId = ($objektId === null) ? null : (int)$objektId;

    // Matching: entweder spezifisches Objekt oder global (objekt_id IS NULL)
    // MAX() gibt 1 zurück, wenn irgendein passender Eintrag 1 ist.
    $row = db_one(
      "SELECT MAX($flagCol) AS ok
       FROM core_permission
       WHERE user_id=?
         AND modul=?
         AND objekt_typ=?
         AND (objekt_id IS NULL OR objekt_id=?)",
      [$userId, $modul, $objektTyp, $objektId]
    );

    return (int)($row['ok'] ?? 0) === 1;
  }
}

if (!function_exists('user_can_edit')) {
  function user_can_edit(?int $userId, ?string $modul, ?string $objektTyp, $objektId = null): bool {
    return user_can_flag($userId, $modul, $objektTyp, $objektId, 'darf_aendern');
  }
}

if (!function_exists('user_can_delete')) {
  function user_can_delete(?int $userId, ?string $modul, ?string $objektTyp, $objektId = null): bool {
    return user_can_flag($userId, $modul, $objektTyp, $objektId, 'darf_loeschen');
  }
}

if (!function_exists('require_can_edit')) {
  function require_can_edit(?string $modul, ?string $objektTyp, $objektId = null): void {
    $u = current_user();
    $userId = (int)($u['id'] ?? 0);
    if (!user_can_edit($userId, $modul, $objektTyp, $objektId)) {
      http_response_code(403);
      echo '<div class="card"><h2>Kein Zugriff</h2><p class="small">Dir fehlt die Bearbeiten-Berechtigung.</p></div>';
      exit;
    }
  }
}

if (!function_exists('require_can_delete')) {
  function require_can_delete(?string $modul, ?string $objektTyp, $objektId = null): void {
    $u = current_user();
    $userId = (int)($u['id'] ?? 0);
    if (!user_can_delete($userId, $modul, $objektTyp, $objektId)) {
      http_response_code(403);
      echo '<div class="card"><h2>Kein Zugriff</h2><p class="small">Dir fehlt die Lösch-Berechtigung.</p></div>';
      exit;
    }
  }
}