<?php
// module/admin/routes.php

if (!defined('APP_INNER')) {
  require_once __DIR__ . '/../../src/layout.php';
  $standalone = true;
  render_header('Admin – Routes');
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
        db_exec(
          "UPDATE core_route SET route_key=?, titel=?, file_path=?, modul=?, objekt_typ=?, objekt_id=?, require_login=?, aktiv=?, sort=? WHERE id=?",
          [$route_key, $titel, $file_path, $modul, $objekt_typ, $objekt_id, $require_login, $aktiv, $sort, $rid]
        );
        $ok = 'Route gespeichert.';
      } else {
        db_exec(
          "INSERT INTO core_route (route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv, sort)
           VALUES (?,?,?,?,?,?,?,?,?)",
          [$route_key, $titel, $file_path, $modul, $objekt_typ, $objekt_id, $require_login, $aktiv, $sort]
        );
        $ok = 'Route angelegt.';
      }

    } elseif ($postAction === 'delete') {
      $rid = (int)$_POST['id'];
      db_exec("DELETE FROM core_route WHERE id=?", [$rid]);
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

  ?>
  <div class="grid">
    <div class="col-6">
      <div class="card">
        <h2><?= $r['id'] ? 'Route bearbeiten' : 'Route anlegen' ?></h2>
        <?php if ($ok): ?><p class="badge badge--g"><?= e($ok) ?></p><?php endif; ?>
        <?php if ($err): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

          <label>route_key</label>
          <input name="route_key" value="<?= e($r['route_key']) ?>" required>

          <label>Titel</label>
          <input name="titel" value="<?= e($r['titel']) ?>" required>

          <label>file_path (z.B. module/wartungstool/dashboard.php)</label>
          <input name="file_path" value="<?= e($r['file_path']) ?>" required>

          <label>modul (für Permission)</label>
          <input name="modul" value="<?= e($r['modul'] ?? '') ?>">

          <label>objekt_typ (für Permission)</label>
          <input name="objekt_typ" value="<?= e($r['objekt_typ'] ?? '') ?>">

          <label>objekt_id (optional)</label>
          <input name="objekt_id" value="<?= e($r['objekt_id'] !== null ? (string)$r['objekt_id'] : '') ?>">

          <label><input type="checkbox" name="require_login" value="1" <?= ((int)$r['require_login']===1?'checked':'') ?>> Login erforderlich</label>
          <label><input type="checkbox" name="aktiv" value="1" <?= ((int)$r['aktiv']===1?'checked':'') ?>> Aktiv</label>

          <label>Sort</label>
          <input name="sort" value="<?= (int)$r['sort'] ?>">

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Speichern</button>
            <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.routes">Zurück</a>
          </div>
        </form>

        <?php if ((int)$r['id'] > 0): ?>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn--ghost" type="submit">Route löschen</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php
  if ($standalone) render_footer();
  return;
}

$routes = db_all("SELECT * FROM core_route ORDER BY aktiv DESC, sort ASC, route_key ASC");
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
    <h2>Routes</h2>
    <a class="btn" href="<?= e($base) ?>/app.php?r=admin.routes&a=edit">+ Neu</a>
  </div>

  <?php if ($ok): ?><p class="badge badge--g"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr>
          <th>route_key</th><th>Titel</th><th>file_path</th><th>login</th><th>modul/obj</th><th>aktiv</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($routes as $r): ?>
          <tr>
            <td><?= e($r['route_key']) ?></td>
            <td><?= e($r['titel']) ?></td>
            <td><code><?= e($r['file_path']) ?></code></td>
            <td><?= ((int)$r['require_login']===1)?'ja':'nein' ?></td>
            <td><?= e(($r['modul'] ?? '—') . ' / ' . ($r['objekt_typ'] ?? '—')) ?></td>
            <td><?= ((int)$r['aktiv']===1)?'ja':'nein' ?></td>
            <td><a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.routes&a=edit&id=<?= (int)$r['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($standalone) render_footer(); ?>