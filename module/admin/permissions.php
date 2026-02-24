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
        db_exec("UPDATE core_permission SET darf_sehen=1, darf_aendern=1, darf_loeschen=1 WHERE id=?", [(int)$adminRow['id']]);
      } else {
        db_exec(
          "INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
           VALUES (?,?,?,?,1,1,1)",
          [$userId, '*', '*', null]
        );
      }
    } else {
      if ($adminRow) db_exec("DELETE FROM core_permission WHERE id=?", [(int)$adminRow['id']]);
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
        if ($row) db_exec("DELETE FROM core_permission WHERE id=?", [(int)$row['id']]);
        continue;
      }

      if ($row) {
        db_exec(
          "UPDATE core_permission SET darf_sehen=?, darf_aendern=?, darf_loeschen=? WHERE id=?",
          [$see, $chg, $del, (int)$row['id']]
        );
      } else {
        db_exec(
          "INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
           VALUES (?,?,?,?,?,?,?)",
          [$userId, $modul, $objt, $oid, $see, $chg, $del]
        );
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
$existing = db_all("SELECT modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen FROM core_permission WHERE user_id=?", [$selected]);
foreach ($existing as $p) {
  $k = ($p['modul'] ?? '') . '|' . ($p['objekt_typ'] ?? '') . '|' . (($p['objekt_id'] === null) ? 'NULL' : (string)$p['objekt_id']);
  $permMap[$k] = $p;
}
?>

<div class="card">
  <h2>Berechtigungen</h2>

  <?php if ($ok): ?><p class="badge badge--g"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

  <form method="get" style="margin-bottom:10px;">
    <input type="hidden" name="r" value="admin.permissions">
    <label>User auswählen</label>
    <select name="user_id" onchange="this.form.submit()">
      <?php foreach ($users as $usr): ?>
        <option value="<?= (int)$usr['id'] ?>" <?= ((int)$usr['id']===$selected?'selected':'') ?>>
          <?= e($usr['benutzername'] . ' — ' . ($usr['anzeigename'] ?? '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="user_id" value="<?= (int)$selected ?>">

    <label><input type="checkbox" name="is_admin" value="1" <?= $isAdmin ? 'checked' : '' ?>> Admin (Wildcard: alles sehen/ändern/löschen)</label>

    <div class="tablewrap" style="margin-top:10px;">
      <table class="table">
        <thead>
          <tr>
            <th>Route</th><th>modul/obj</th><th>Sehen</th><th>Ändern</th><th>Löschen</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routes as $r):
            if (empty($r['modul']) || empty($r['objekt_typ'])) continue;
            $k = $r['modul'].'|'.$r['objekt_typ'].'|'.(($r['objekt_id']===null)?'NULL':(string)$r['objekt_id']);
            $p = $permMap[$k] ?? null;
          ?>
            <tr>
              <td><code><?= e($r['route_key']) ?></code><div class="small"><?= e($r['titel']) ?></div></td>
              <td><?= e($r['modul'].' / '.$r['objekt_typ']) ?></td>
              <td><input type="checkbox" name="see[<?= e($r['route_key']) ?>]" value="1" <?= (!empty($p) && (int)$p['darf_sehen']===1)?'checked':'' ?>></td>
              <td><input type="checkbox" name="chg[<?= e($r['route_key']) ?>]" value="1" <?= (!empty($p) && (int)$p['darf_aendern']===1)?'checked':'' ?>></td>
              <td><input type="checkbox" name="del[<?= e($r['route_key']) ?>]" value="1" <?= (!empty($p) && (int)$p['darf_loeschen']===1)?'checked':'' ?>></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Speichern</button>
    </div>
  </form>
</div>

<?php if ($standalone) render_footer(); ?>