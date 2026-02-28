<?php
// module/admin/permissions.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';

$cfg = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

if (!has_any_user()) {
  header("Location: {$base}/app.php?r=admin.setup");
  exit;
}

require_login();
$u = current_user();
if (!is_admin_user($u['id'] ?? null)) {
  http_response_code(403);
  echo '<div class="ui-container"><div class="ui-card"><h2>Kein Zugriff</h2><p class="small ui-muted">Admin-Recht erforderlich.</p></div></div>';
  return;
}

$users = db_all("SELECT id, benutzername, anzeigename FROM core_user WHERE aktiv=1 ORDER BY benutzername ASC");
$selected = (int)($_GET['user_id'] ?? ($users[0]['id'] ?? 0));

$ok = null;
$err = null;

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

function perm_scope_key(string $modul, string $objektTyp, $objektId): string {
  return $modul . '|' . $objektTyp . '|' . (($objektId === null) ? 'null' : (string)(int)$objektId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  try {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Kein User gewählt.');

    $wantAdmin = !empty($_POST['is_admin']) ? 1 : 0;
    $adminRow = perm_row($userId, '*', '*', null);

    if ($wantAdmin) {
      if ($adminRow) {
        $oldPerm = $adminRow;
        $oldSee = (int)($adminRow['darf_sehen'] ?? 0);
        $oldChg = (int)($adminRow['darf_aendern'] ?? 0);
        $oldDel = (int)($adminRow['darf_loeschen'] ?? 0);
        if (!($oldSee === 1 && $oldChg === 1 && $oldDel === 1)) {
          db_exec("UPDATE core_permission SET darf_sehen=1, darf_aendern=1, darf_loeschen=1 WHERE id=?", [(int)$adminRow['id']]);
          $newPerm = db_one("SELECT * FROM core_permission WHERE id=?", [(int)$adminRow['id']]);
          audit_log('admin', 'permission', (int)$adminRow['id'], 'UPDATE', $oldPerm, $newPerm, $u['id'] ?? null, $u['benutzername'] ?? null);
        }
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

    $desiredByScope = [];
    foreach ($routes as $r) {
      if (empty($r['modul']) || empty($r['objekt_typ'])) continue;

      $key = (string)$r['route_key'];
      $modul = (string)$r['modul'];
      $objt  = (string)$r['objekt_typ'];
      $oid   = ($r['objekt_id'] !== null) ? (int)$r['objekt_id'] : null;
      $scopeKey = perm_scope_key($modul, $objt, $oid);

      $see = !empty($_POST['see'][$key]) ? 1 : 0;
      $chg = !empty($_POST['chg'][$key]) ? 1 : 0;
      $del = !empty($_POST['del'][$key]) ? 1 : 0;

      if (!isset($desiredByScope[$scopeKey])) {
        $desiredByScope[$scopeKey] = [
          'modul' => $modul,
          'objekt_typ' => $objt,
          'objekt_id' => $oid,
          'see' => 0,
          'chg' => 0,
          'del' => 0,
        ];
      }

      if ($see === 1) $desiredByScope[$scopeKey]['see'] = 1;
      if ($chg === 1) $desiredByScope[$scopeKey]['chg'] = 1;
      if ($del === 1) $desiredByScope[$scopeKey]['del'] = 1;
    }

    foreach ($desiredByScope as $scope) {
      $modul = (string)$scope['modul'];
      $objt  = (string)$scope['objekt_typ'];
      $oid   = $scope['objekt_id'];
      $see   = (int)$scope['see'];
      $chg   = (int)$scope['chg'];
      $del   = (int)$scope['del'];

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
        $oldSee = (int)($row['darf_sehen'] ?? 0);
        $oldChg = (int)($row['darf_aendern'] ?? 0);
        $oldDel = (int)($row['darf_loeschen'] ?? 0);
        if ($oldSee === $see && $oldChg === $chg && $oldDel === $del) {
          continue;
        }
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

$permMap = [];
$rows = db_all("SELECT modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen FROM core_permission WHERE user_id=?", [$selected]);
foreach ($rows as $pr) {
  $k = perm_scope_key((string)($pr['modul'] ?? ''), (string)($pr['objekt_typ'] ?? ''), $pr['objekt_id']);
  $permMap[$k] = $pr;
}
?>

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Admin – Berechtigungen</h1>
    <p class="ui-page-subtitle ui-muted">Benutzerrechte je Route verwalten.</p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="admin.permissions">

      <div class="ui-filterbar__group" style="min-width: 420px;">
        <label for="perm_user_id">User</label>
        <select id="perm_user_id" name="user_id" class="ui-input">
          <?php foreach ($users as $uu): ?>
            <?php
              $label = $uu['benutzername'] . (($uu['anzeigename'] ?? '') ? ' – ' . $uu['anzeigename'] : '');
              $sel = ((int)$uu['id'] === (int)$selected) ? 'selected' : '';
            ?>
            <option value="<?= (int)$uu['id'] ?>" <?= $sel ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary ui-btn--sm" type="submit">Laden</button>
      </div>
    </form>
  </div>

  <div class="ui-card">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="user_id" value="<?= (int)$selected ?>">

      <div style="margin-bottom: var(--s-4); display:flex; align-items:center; gap:8px;">
        <input id="perm_is_admin" type="checkbox" name="is_admin" value="1" <?= $isAdmin ? 'checked' : '' ?>>
        <label for="perm_is_admin" style="margin:0;">Admin (Wildcard)</label>
      </div>

      <div class="ui-table-wrap">
        <table class="ui-table">
          <thead>
            <tr>
              <th>Route</th>
              <th>Sehen</th>
              <th>Ändern</th>
              <th>Löschen</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($routes as $r): ?>
              <?php if (empty($r['modul']) || empty($r['objekt_typ'])) continue; ?>
              <?php
                $key = $r['route_key'];
                $k = perm_scope_key((string)($r['modul'] ?? ''), (string)($r['objekt_typ'] ?? ''), $r['objekt_id']);
                $pr = $permMap[$k] ?? null;

                $see = !empty($pr['darf_sehen']) ? 'checked' : '';
                $chg = !empty($pr['darf_aendern']) ? 'checked' : '';
                $del = !empty($pr['darf_loeschen']) ? 'checked' : '';
              ?>
              <tr>
                <td><?= e($key . ' – ' . ($r['titel'] ?? '')) ?></td>
                <td><input type="checkbox" name="see[<?= e($key) ?>]" value="1" <?= $see ?>></td>
                <td><input type="checkbox" name="chg[<?= e($key) ?>]" value="1" <?= $chg ?>></td>
                <td><input type="checkbox" name="del[<?= e($key) ?>]" value="1" <?= $del ?>></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top: var(--s-4);">
        <button class="ui-btn ui-btn--primary" type="submit">Speichern</button>
      </div>
    </form>
  </div>
</div>
