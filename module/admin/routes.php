<?php
// module/admin/routes.php (INNER VIEW)
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

$actorUserId = (int)($u['id'] ?? 0);
$actorText = $u['anzeigename'] ?? $u['benutzername'] ?? 'admin';

$action = (string)($_GET['a'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$ok = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);
  $postAction = (string)($_POST['action'] ?? '');

  try {
    if ($postAction === 'save') {
      $rid = (int)($_POST['id'] ?? 0);
      $route_key = trim((string)$_POST['route_key']);
      $titel = trim((string)$_POST['titel']);
      $file_path = trim((string)$_POST['file_path']);
      $modul = trim((string)($_POST['modul'] ?? ''));
      $objekt_typ = trim((string)($_POST['objekt_typ'] ?? ''));
      $objekt_id = ($_POST['objekt_id'] ?? '') !== '' ? (int)$_POST['objekt_id'] : null;
      $require_login = !empty($_POST['require_login']) ? 1 : 0;
      $aktiv = !empty($_POST['aktiv']) ? 1 : 0;
      $sort = (int)($_POST['sort'] ?? 0);

      if ($route_key === '' || $titel === '' || $file_path === '') throw new RuntimeException('route_key, titel, file_path sind Pflicht.');
      if (strpos($file_path, '..') !== false) throw new RuntimeException('file_path darf kein .. enthalten.');

      $modul = ($modul === '') ? null : $modul;
      $objekt_typ = ($objekt_typ === '') ? null : $objekt_typ;

      if ($rid > 0) {
        $old = db_one("SELECT * FROM core_route WHERE id=?", [$rid]);
        db_exec(
          "UPDATE core_route SET route_key=?, titel=?, file_path=?, modul=?, objekt_typ=?, objekt_id=?, require_login=?, aktiv=?, sort=? WHERE id=?",
          [$route_key, $titel, $file_path, $modul, $objekt_typ, $objekt_id, $require_login, $aktiv, $sort, $rid]
        );
        $new = db_one("SELECT * FROM core_route WHERE id=?", [$rid]);
        audit_log('admin', 'route', $rid, 'UPDATE', $old, $new, $actorUserId ?: null, $actorText);
        $ok = 'Route gespeichert.';
      } else {
        db_exec(
          "INSERT INTO core_route (route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv, sort)
           VALUES (?,?,?,?,?,?,?,?,?)",
          [$route_key, $titel, $file_path, $modul, $objekt_typ, $objekt_id, $require_login, $aktiv, $sort]
        );
        $newId = (int)db()->lastInsertId();
        $new = db_one("SELECT * FROM core_route WHERE id=?", [$newId]);
        audit_log('admin', 'route', $newId, 'CREATE', null, $new, $actorUserId ?: null, $actorText);
        $ok = 'Route angelegt.';
      }

    } elseif ($postAction === 'delete') {
      $rid = (int)$_POST['id'];
      $old = db_one("SELECT * FROM core_route WHERE id=?", [$rid]);
      db_exec("DELETE FROM core_route WHERE id=?", [$rid]);
      audit_log('admin', 'route', $rid, 'DELETE', $old, null, $actorUserId ?: null, $actorText);
      $ok = 'Route gelöscht.';
    }
  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

if ($action === 'edit') {
  $r = ($id > 0)
    ? db_one("SELECT * FROM core_route WHERE id=?", [$id])
    : ['id'=>0,'route_key'=>'','titel'=>'','file_path'=>'','modul'=>null,'objekt_typ'=>null,'objekt_id'=>null,'require_login'=>1,'aktiv'=>1,'sort'=>0];

  if (!is_array($r)) {
    $r = ['id'=>0,'route_key'=>'','titel'=>'','file_path'=>'','modul'=>null,'objekt_typ'=>null,'objekt_id'=>null,'require_login'=>1,'aktiv'=>1,'sort'=>0];
  }
  ?>

  <div class="ui-container">
    <div class="ui-page-header">
      <h1 class="ui-page-title">Admin – Routes</h1>
      <p class="ui-page-subtitle ui-muted">
        <?= $r['id'] ? 'Route bearbeiten' : 'Route anlegen' ?>
        <span class="ui-muted">·</span>
        <a class="ui-link" href="<?= e($base) ?>/app.php?r=admin.routes">zurück zur Liste</a>
      </p>

      <?php if ($ok || $err): ?>
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
          <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
          <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="ui-card" style="max-width:860px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:end;">
          <div>
            <label for="route_key_input">route_key</label>
            <input class="ui-input" id="route_key_input" name="route_key" value="<?= e($r['route_key']) ?>" required aria-required="true">
          </div>

          <div>
            <label for="route_titel">Titel</label>
            <input class="ui-input" id="route_titel" name="titel" value="<?= e($r['titel']) ?>" required aria-required="true">
          </div>

          <div style="grid-column: 1 / -1;">
            <label for="route_file_path">file_path (z.B. module/wartungstool/dashboard.php)</label>
            <input class="ui-input" id="route_file_path" name="file_path" value="<?= e($r['file_path']) ?>" required aria-required="true">
          </div>

          <div>
            <label for="route_modul">modul (für Permission)</label>
            <input class="ui-input" id="route_modul" name="modul" value="<?= e($r['modul'] ?? '') ?>">
          </div>

          <div>
            <label for="route_obj_typ">objekt_typ (für Permission)</label>
            <input class="ui-input" id="route_obj_typ" name="objekt_typ" value="<?= e($r['objekt_typ'] ?? '') ?>">
          </div>

          <div>
            <label for="route_obj_id">objekt_id (optional)</label>
            <input class="ui-input" id="route_obj_id" name="objekt_id" value="<?= e($r['objekt_id'] !== null ? (string)$r['objekt_id'] : '') ?>">
          </div>

          <div>
            <label for="route_sort">Sort</label>
            <input class="ui-input" id="route_sort" name="sort" value="<?= (int)$r['sort'] ?>">
          </div>

          <div style="display:flex; align-items:center; gap:8px; padding-bottom:10px;">
            <input id="route_req_login" type="checkbox" name="require_login" value="1" <?= ((int)$r['require_login']===1?'checked':'') ?>>
            <label for="route_req_login" style="margin:0;">Login erforderlich</label>
          </div>

          <div style="display:flex; align-items:center; gap:8px; padding-bottom:10px;">
            <input id="route_aktiv" type="checkbox" name="aktiv" value="1" <?= ((int)$r['aktiv']===1?'checked':'') ?>>
            <label for="route_aktiv" style="margin:0;">Aktiv</label>
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button class="ui-btn ui-btn--primary" type="submit">Speichern</button>
          <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=admin.routes">Zurück</a>
        </div>
      </form>

      <?php if ((int)$r['id'] > 0): ?>
        <form method="post" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="ui-btn ui-btn--ghost" type="submit">Route löschen</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php
  return;
}

$routes = db_all("SELECT * FROM core_route ORDER BY aktiv DESC, sort ASC, route_key ASC");
?>

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Admin – Routes</h1>
    <p class="ui-page-subtitle ui-muted">Routen anlegen, bearbeiten und aktivieren.</p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom: var(--s-4);">
      <h2 style="margin:0;">Routes</h2>
      <a class="ui-btn ui-btn--primary ui-btn--sm" href="<?= e($base) ?>/app.php?r=admin.routes&a=edit">+ Neu</a>
    </div>

    <div class="ui-table-wrap">
      <table class="ui-table">
        <thead>
          <tr>
            <th>route_key</th>
            <th>Titel</th>
            <th>file_path</th>
            <th>login</th>
            <th>modul/obj</th>
            <th>aktiv</th>
            <th class="ui-th-actions">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routes as $r): ?>
            <tr>
              <td><?= e($r['route_key']) ?></td>
              <td><?= e($r['titel']) ?></td>
              <td><span class="small ui-muted"><?= e($r['file_path']) ?></span></td>
              <td><?= ((int)$r['require_login']===1)?'ja':'nein' ?></td>
              <td><?= e(($r['modul'] ?? '—') . ' / ' . ($r['objekt_typ'] ?? '—')) ?></td>
              <td><?= ((int)$r['aktiv']===1)?'ja':'nein' ?></td>
              <td class="ui-td-actions">
                <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($base) ?>/app.php?r=admin.routes&a=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
