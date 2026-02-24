<?php
// download.php — Secure document download endpoint.
// Files are served through this script so they are never directly accessible
// from the webroot.  Access requires a valid session and view permission for
// the module that owns the document.
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/auth.php';

require_login();

$u = current_user();
$userId = (int)($u['id'] ?? 0);

$docId = (int)($_GET['id'] ?? 0);
if ($docId <= 0) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$doc = db_one(
    "SELECT * FROM core_dokument WHERE id = ? LIMIT 1",
    [$docId]
);

if (!$doc) {
    http_response_code(404);
    exit('Dokument nicht gefunden.');
}

// Permission check: the requesting user must have view access for the module
// that owns this document (e.g. 'stoerungstool', objekt_typ 'global').
if (!user_can_flag($userId, (string)$doc['modul'], 'global', null, 'darf_sehen')) {
    http_response_code(403);
    exit('Keine Berechtigung.');
}

// Resolve the absolute path from the configured base directory.
$cfg = app_cfg();
$baseDir = rtrim((string)($cfg['upload']['base_dir'] ?? (dirname(__DIR__) . '/asset_private_uploads')), '/\\');
$relPath = (string)$doc['dateiname'];

// Guard against path traversal and null-byte injection.
if (
    strpos($relPath, '..') !== false ||
    strpos($relPath, "\0") !== false ||
    preg_match('~(^|[/\\\\])\\.~', $relPath)
) {
    http_response_code(400);
    exit('Ungültiger Dateipfad.');
}

$absPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

// Ensure the resolved path is still inside the configured base directory.
$realBase = realpath($baseDir);
$realFile = realpath($absPath);
if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

if (!is_file($realFile)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

// Derive a safe filename for Content-Disposition.
$origName = (string)($doc['originalname'] ?: basename($relPath));
$safeOrig = preg_replace('/[^\w.\-]/', '_', $origName);
if ($safeOrig === '' || $safeOrig === '.') {
    $safeOrig = 'download';
}

// Only allow the MIME types that are on the upload allowlist.
$allowed = $cfg['upload']['allowed_mimes'] ?? ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$mime = (string)($doc['mime'] ?: 'application/octet-stream');
if (!in_array($mime, $allowed, true)) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $safeOrig) . '"');
header('Content-Length: ' . filesize($realFile));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($realFile);
exit;
