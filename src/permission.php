<?php
// src/permission.php
// Rechtesystem – basiert auf core_permission (user_id, modul, objekt_typ, darf_sehen/darf_aendern/darf_loeschen)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Gibt alle Permissions des aktuellen Users zurück:
 * [modul => ['sehen' => bool, 'aendern' => bool, 'loeschen' => bool], ...]
 */
function user_permissions(): array {
  static $cache = null;
  if ($cache !== null) return $cache;

  $u = current_user();
  if (!$u) { $cache = []; return []; }

  $rows = db_all(
    "SELECT modul, MAX(darf_sehen) AS sehen, MAX(darf_aendern) AS aendern, MAX(darf_loeschen) AS loeschen
     FROM core_permission
     WHERE user_id=?
     GROUP BY modul",
    [(int)$u['id']]
  );

  $perms = [];
  foreach ($rows as $r) {
    $perms[(string)$r['modul']] = [
      'sehen'    => (bool)(int)$r['sehen'],
      'aendern'  => (bool)(int)$r['aendern'],
      'loeschen' => (bool)(int)$r['loeschen'],
    ];
  }

  $cache = $perms;
  return $perms;
}

/**
 * Prüft ob der aktuelle User für ein Modul ein bestimmtes Recht hat.
 * $recht: 'sehen' | 'aendern' | 'loeschen'
 */
function can(string $modul, string $recht): bool {
  $u = current_user();
  if (!$u) return false;
  $userId = (int)$u['id'];

  // Whitelist column names to prevent any SQL injection
  $allowedCols = ['sehen' => 'darf_sehen', 'aendern' => 'darf_aendern', 'loeschen' => 'darf_loeschen'];
  if (!isset($allowedCols[$recht])) return false;
  $col = $allowedCols[$recht];

  // Fallback: exakt (modul) oder wildcard (modul='*')
  $row = db_one(
    "SELECT MAX($col) AS ok FROM core_permission
     WHERE user_id=? AND (modul=? OR modul='*')
     LIMIT 1",
    [$userId, $modul]
  );
  return (int)($row['ok'] ?? 0) === 1;
}

function require_permission(string $modul, string $recht): void {
  if (!can($modul, $recht)) {
    http_response_code(403);
    exit("Keine Berechtigung ($modul:$recht)");
  }
}