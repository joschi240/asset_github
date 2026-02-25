<?php
// module/wartungstool/punkt_dokument_upload.php (INNER, POST)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

// Bearbeiten-Recht erforderlich
require_can_edit('wartungstool', 'global', null);

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo '<div class="card"><h2>Method not allowed</h2></div>';
  exit;
}

csrf_check($_POST['csrf'] ?? null);

$wpId = (int)($_POST['wp_id'] ?? 0);
if ($wpId <= 0) {
  header("Location: {$base}/app.php?r=wartung.dashboard");
  exit;
}

$u      = current_user();
$userId = (int)($u['id'] ?? 0);
$actor  = $u['anzeigename'] ?? $u['benutzername'] ?? 'user';

try {
  $allowed  = $cfg['upload']['allowed_mimes'] ?? ['image/jpeg','image/png','image/webp','application/pdf'];
  $maxBytes = (int)($cfg['upload']['max_bytes'] ?? (10 * 1024 * 1024));
  $baseDir  = $cfg['upload']['base_dir'] ?? (__DIR__ . '/../../uploads');

  if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Upload fehlgeschlagen.");
  }
  $f = $_FILES['file'];
  if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
    throw new RuntimeException("Datei zu groß oder leer.");
  }

  $fi   = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($f['tmp_name']) ?: 'application/octet-stream';
  if (!in_array($mime, $allowed, true)) {
    throw new RuntimeException("Dateityp nicht erlaubt: {$mime}");
  }

  $ext = '';
  if (preg_match('/\.([a-zA-Z0-9]{1,8})$/', (string)$f['name'], $m)) $ext = strtolower($m[1]);

  $stored  = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . ($ext ? '.'.$ext : '');
  $relPath = "wartungstool/wartungspunkt/{$wpId}/{$stored}";
  $absPath = rtrim($baseDir, '/\\') . '/' . $relPath;

  $dir = dirname($absPath);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("Upload-Verzeichnis nicht anlegbar.");
  if (!move_uploaded_file($f['tmp_name'], $absPath)) throw new RuntimeException("Konnte Datei nicht speichern.");

  $sha = hash_file('sha256', $absPath);

  db_exec(
    "INSERT INTO core_dokument
     (modul, referenz_typ, referenz_id, dateiname, originalname, mime, size_bytes, sha256, hochgeladen_am, hochgeladen_von_user_id)
     VALUES ('wartungstool','wartungspunkt',?,?,?,?,?,?,NOW(),?)",
    [$wpId, $relPath, (string)$f['name'], $mime, (int)$f['size'], $sha, $userId ?: null]
  );
  $dokId = (int)db()->lastInsertId();

  audit_log('wartungstool', 'dokument', $dokId, 'CREATE', null, [
    'referenz_typ' => 'wartungspunkt',
    'referenz_id'  => $wpId,
    'dateiname'    => $relPath,
    'originalname' => (string)$f['name'],
    'mime'         => $mime,
  ], $userId, $actor);

  header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&ok=1");
  exit;

} catch (Throwable $e) {
  header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&err=" . urlencode($e->getMessage()));
  exit;
}
