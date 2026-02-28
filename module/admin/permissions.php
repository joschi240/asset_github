<?php
// module/admin/permissions.php

if (!defined('APP_INNER')) {
  require_once __DIR__ . '/../../src/layout.php';
  $standalone = true;
  render_header('Admin – Berechtigungen');
} else {
  $standalone = false;
}

$cfg = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

if (!has_any_user()) { header("Location: {$base}/app.php?r=admin.setup"); exit; }

require_login();
$u = current_user();
if (!is_admin_user($u['id'] ?? null)) {
  http_response_code(403);
  echo '<div class="card"><h2>Kein Zugriff</h2><p class="small">Admin-Recht erforderlich.</p></div>';
  if ($standalone) render_footer();
  return;
}

$users = db_all("SELECT id, benutzername, anzeigename FROM core_user WHERE aktiv=1 ORDER BY benutzername ASC");
$selected = (int)($_GET['user_id'] ?? ($users[0]['id'] ?? 0));

$ok = null; $err = null;

$routes = db_all(
  "SELECT route_key, titel, modul, objekt_typ, objekt_id
   FROM core_route
   WHERE aktiv=1
   ORDER BY sort ASC, route_key ASC"
);

function perm_row(int $userId, string $modul, string $objektTyp, $objektId) {
  return db_one(
    "SELECT * FROM core_permission
     WHERE user_id=? AND modul=? AND objekt_typ=? AND objekt_id <=> ?
     LIMIT 1",
    [$userId, $modul, $objektTyp, $objektId]
  );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  try {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Kein User gewählt.');

    // Admin wildcard
    $wantAdmin = !empty($_POST['is_admin']) ? 1 : 0;
    $adminRow = perm_row($userId, '*', '*', null);
    if ($wantAdmin) {
      if ($adminRow) {
        $oldPerm = $adminRow;
        db_exec("UPDATE core_permission SET darf_sehen=1, darf_aendern=1, darf_loeschen=1 WHERE id=?", [(int)$adminRow['id']]);
        $newPerm = db_one("SELECT * FROM core_permission WHERE id=?", [(int)$adminRow['id']]);
        audit_log('admin', 'permission', (int)$adminRow['id'], 'UPDATE', $oldPerm, $newPerm, $u['id'] ?? null, $u['benutzername'] ?? null);
      } else {
        db_exec(
          "INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
           VALUES (?,?,?,?,1,1,1)",
          [$userId, '*', '*', null]
        );
        $newId = (int)db()->lastInsertId();
        $newPerm = db_one("SELECT * FROM core_permission WHERE id=?", [$newId]);
        audit_log('admin', 'permission', $newId, 'CREATE', null, $newPerm, $u['id'] ?? null, $u['benutzername'] ?? null);
      }
    } else {
      if ($adminRow) {
        $oldPerm = $adminRow;
        db_exec("DELETE FROM core_permission WHERE id=?", [(int)$adminRow['id']]);
        audit_log('admin', 'permission', (int)$adminRow['id'], 'DELETE', $oldPerm, null, $u['id'] ?? null, $u['benutzername'] ?? null);
      }
    }

    // Route-Permissions
    foreach ($routes as $r) {
      if (empty($r['modul']) || empty($r['objekt_typ'])) continue; // public / keine Rechte nötig

      $key = $r['route_key'];
      $see = !empty($_POST['see'][$key]) ? 1 : 0;
      $chg = !empty($_POST['chg'][$key]) ? 1 : 0;
      $del = !empty($_POST['del'][$key]) ? 1 : 0;

      $modul = (string)$r['modul'];
      $objt  = (string)$r['objekt_typ'];
      $oid   = ($r['objekt_id'] !== null) ? (int)$r['objekt_id'] : null;

      $row = perm_row($userId, $modul, $objt, $oid);

      if ($see === 0 && $chg === 0 && $del === 0) {
        if ($row) {
          $oldPerm = $row;
          db_exec("DELETE FROM core_permission WHERE id=?", [(int)$row['id']]);
          audit_log('admin', 'permission', (int)$row['id'], 'DELETE', $oldPerm, null, $u['id'] ?? null, $u['benutzername'] ?? null);
        }
        continue;
      }

      if ($row) {
        $oldPerm = $row;
        db_exec(
          "UPDATE core_permission SET darf_sehen=?, darf_aendern=?, darf_loeschen=? WHERE id=?",
          [$see, $chg, $del, (int)$row['id']]
        );
        $newPerm = db_one("SELECT * FROM core_permission WHERE id=?", [(int)$row['id']]);
        audit_log('admin', 'permission', (int)$row['id'], 'UPDATE', $oldPerm, $newPerm, $u['id'] ?? null, $u['benutzername'] ?? null);
      } else {
        db_exec(
          "INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
           VALUES (?,?,?,?,?,?,?)",
          [$userId, $modul, $objt, $oid, $see, $chg, $del]
        );
        $newId = (int)db()->lastInsertId();
        $newPerm = db_one("SELECT * FROM core_permission WHERE id=?", [$newId]);
        audit_log('admin', 'permission', $newId, 'CREATE', null, $newPerm, $u['id'] ?? null, $u['benutzername'] ?? null);
      }
    }

    $ok = 'Berechtigungen gespeichert.';
    $selected = $userId;

  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

$isAdmin = (bool)db_one("SELECT 1 FROM core_permission WHERE user_id=? AND modul='*' AND darf_sehen=1 LIMIT 1", [$selected]);

// Vorladen bestehender Permissions (für Checkboxen)
$permMap = [];
$rows = db_all("SELECT modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen FROM core_permission WHERE user_id=?", [$selected]);
foreach ($rows as $pr) {
  $k = ($pr['modul'] ?? '') . '|' . ($pr['objekt_typ'] ?? '') . '|' . (($pr['objekt_id'] === null) ? 'null' : (string)$pr['objekt_id']);
  $permMap[$k] = $pr;
}

// UI
echo '<div class="ui-card ui-p-3">';
echo '<h2 class="ui-h2">Berechtigungen</h2>';

if ($ok) echo '<div class="ui-alert ui-alert-success ui-mt-2">' . e($ok) . '</div>';
if ($err) echo '<div class="ui-alert ui-alert-danger ui-mt-2">' . e($err) . '</div>';

echo '<form method="get" class="ui-mt-3 ui-flex ui-gap-2 ui-items-end">';
echo '<input type="hidden" name="r" value="admin.permissions">';
echo '<label class="ui-label">User</label>';
echo '<select name="user_id" class="ui-select">';
foreach ($users as $uu) {
  $sel = ((int)$uu['id'] === (int)$selected) ? ' selected' : '';
  $label = $uu['benutzername'] . (($uu['anzeigename'] ?? '') ? ' – ' . $uu['anzeigename'] : '');
  echo '<option value="' . (int)$uu['id'] . '"' . $sel . '>' . e($label) . '</option>';
}
echo '</select>';
echo '<button class="ui-btn ui-btn-primary" type="submit">Laden</button>';
echo '</form>';

echo '<form method="post" class="ui-mt-4">';
echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
echo '<input type="hidden" name="user_id" value="' . (int)$selected . '">';

echo '<div class="ui-card ui-p-2 ui-mb-3">';
echo '<label class="ui-inline ui-gap-2">';
echo '<input type="checkbox" name="is_admin" value="1"' . ($isAdmin ? ' checked' : '') . '>';
echo '<span>Admin (Wildcard)</span>';
echo '</label>';
echo '</div>';

echo '<table class="ui-table ui-table-sm">';
echo '<thead><tr><th>Route</th><th>Sehen</th><th>Ändern</th><th>Löschen</th></tr></thead><tbody>';

foreach ($routes as $r) {
  if (empty($r['modul']) || empty($r['objekt_typ'])) continue;

  $key = $r['route_key'];
  $k = ($r['modul'] ?? '') . '|' . ($r['objekt_typ'] ?? '') . '|' . (($r['objekt_id'] === null) ? 'null' : (string)$r['objekt_id']);
  $pr = $permMap[$k] ?? null;

  $see = !empty($pr['darf_sehen']) ? ' checked' : '';
  $chg = !empty($pr['darf_aendern']) ? ' checked' : '';
  $del = !empty($pr['darf_loeschen']) ? ' checked' : '';

  echo '<tr>';
  echo '<td>' . e($key . ' – ' . ($r['titel'] ?? '')) . '</td>';
  echo '<td><input type="checkbox" name="see[' . e($key) . ']" value="1"' . $see . '></td>';
  echo '<td><input type="checkbox" name="chg[' . e($key) . ']" value="1"' . $chg . '></td>';
  echo '<td><input type="checkbox" name="del[' . e($key) . ']" value="1"' . $del . '></td>';
  echo '</tr>';
}

echo '</tbody></table>';

echo '<div class="ui-mt-3">';
echo '<button class="ui-btn ui-btn-primary" type="submit">Speichern</button>';
echo '</div>';

echo '</form>';
echo '</div>';

if ($standalone) render_footer();