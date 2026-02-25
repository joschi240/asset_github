<?php
// module/admin/menu.php

if (!defined('APP_INNER')) {
  require_once __DIR__ . '/../../src/layout.php';
  $standalone = true;
  render_header('Admin – Menü');
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

$menu = db_one("SELECT id, name FROM core_menu WHERE name='main' LIMIT 1");
if (!$menu) {
  echo '<div class="card"><h2>Menü fehlt</h2><p class="small">core_menu main existiert nicht.</p></div>';
  if ($standalone) render_footer();
  return;
}

$action = (string)($_GET['a'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$ok = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);
  $postAction = (string)($_POST['action'] ?? '');

  try {
    if ($postAction === 'save') {
      $mid = (int)$menu['id'];
      $iid = (int)($_POST['id'] ?? 0);

      $label = trim((string)$_POST['label']);
      $parent_id = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;
      $route_key = trim((string)($_POST['route_key'] ?? ''));
      $url = trim((string)($_POST['url'] ?? ''));

      $modul = trim((string)($_POST['modul'] ?? ''));
      $objekt_typ = trim((string)($_POST['objekt_typ'] ?? ''));
      $objekt_id = ($_POST['objekt_id'] ?? '') !== '' ? (int)$_POST['objekt_id'] : null;

      $sort = (int)($_POST['sort'] ?? 0);
      $aktiv = !empty($_POST['aktiv']) ? 1 : 0;

      if ($label === '') throw new RuntimeException('Label ist Pflicht.');

      // Auto-fill modul/objekt_typ aus Route, wenn route_key gewählt und Felder leer
      if ($route_key !== '' && ($modul === '' || $objekt_typ === '')) {
        $r = db_one("SELECT modul, objekt_typ, objekt_id FROM core_route WHERE route_key=? LIMIT 1", [$route_key]);
        if ($r) {
          if ($modul === '') $modul = (string)($r['modul'] ?? '');
          if ($objekt_typ === '') $objekt_typ = (string)($r['objekt_typ'] ?? '');
          if ($objekt_id === null && $r['objekt_id'] !== null) $objekt_id = (int)$r['objekt_id'];
        }
      }

      $modul = ($modul === '') ? null : $modul;
      $objekt_typ = ($objekt_typ === '') ? null : $objekt_typ;
      $route_key = ($route_key === '') ? null : $route_key;
      $url = ($url === '') ? null : $url;

      if ($iid > 0) {
        db_exec(
          "UPDATE core_menu_item
           SET parent_id=?, label=?, route_key=?, url=?, modul=?, objekt_typ=?, objekt_id=?, sort=?, aktiv=?
           WHERE id=? AND menu_id=?",
          [$parent_id, $label, $route_key, $url, $modul, $objekt_typ, $objekt_id, $sort, $aktiv, $iid, $mid]
        );
        $ok = 'Menüeintrag gespeichert.';
      } else {
        db_exec(
          "INSERT INTO core_menu_item (menu_id, parent_id, label, route_key, url, modul, objekt_typ, objekt_id, sort, aktiv)
           VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$mid, $parent_id, $label, $route_key, $url, $modul, $objekt_typ, $objekt_id, $sort, $aktiv]
        );
        $ok = 'Menüeintrag angelegt.';
      }

    } elseif ($postAction === 'delete') {
      $iid = (int)$_POST['id'];
      db_exec("DELETE FROM core_menu_item WHERE id=? AND menu_id=?", [$iid, (int)$menu['id']]);
      $ok = 'Menüeintrag gelöscht.';
    }
  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

$routes = db_all("SELECT route_key, titel FROM core_route WHERE aktiv=1 ORDER BY sort ASC, route_key ASC");
$items = db_all(
  "SELECT * FROM core_menu_item WHERE menu_id=? ORDER BY sort ASC, id ASC",
  [(int)$menu['id']]
);

// Hierarchie-Helper für Anzeige
$byParent = [];
foreach ($items as $it) {
  $pid = $it['parent_id'] ?? 0;
  $byParent[$pid][] = $it;
}
$flat = [];
$walk = function($pid, $depth) use (&$walk, &$flat, &$byParent) {
  foreach (($byParent[$pid] ?? []) as $it) {
    $it['_depth'] = $depth;
    $flat[] = $it;
    $walk((int)$it['id'], $depth + 1);
  }
};
$walk(0, 0);

if ($action === 'edit') {
  $default_row = ['id'=>0,'parent_id'=>null,'label'=>'','route_key'=>null,'url'=>null,'modul'=>null,'objekt_typ'=>null,'objekt_id'=>null,'sort'=>0,'aktiv'=>1];
  $row = ($id > 0)
    ? (db_one("SELECT * FROM core_menu_item WHERE id=? AND menu_id=?", [$id, (int)$menu['id']]) ?: $default_row)
    : $default_row;

  ?>
  <div class="grid">
    <div class="col-6">
      <div class="card">
        <h2><?= $row['id'] ? 'Menüeintrag bearbeiten' : 'Menüeintrag anlegen' ?></h2>
        <?php if ($ok): ?><p class="badge badge--g" role="status"><?= e($ok) ?></p><?php endif; ?>
        <?php if ($err): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

          <label for="menu_label">Label</label>
          <input id="menu_label" name="label" value="<?= e($row['label']) ?>" required aria-required="true">

          <label for="menu_parent">Parent (optional)</label>
          <select id="menu_parent" name="parent_id">
            <option value="">— keiner —</option>
            <?php foreach ($flat as $it): ?>
              <?php if ((int)$it['id'] === (int)$row['id']) continue; ?>
              <option value="<?= (int)$it['id'] ?>" <?= ((int)$row['parent_id']===(int)$it['id']?'selected':'') ?>>
                <?= e(str_repeat('— ', (int)$it['_depth']) . $it['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="menu_route">Route (empfohlen)</label>
          <select id="menu_route" name="route_key">
            <option value="">— keine —</option>
            <?php foreach ($routes as $r): ?>
              <option value="<?= e($r['route_key']) ?>" <?= (($row['route_key'] ?? '')===$r['route_key']?'selected':'') ?>>
                <?= e($r['route_key'].' — '.$r['titel']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="menu_url">URL (Fallback/Legacy)</label>
          <input id="menu_url" name="url" value="<?= e($row['url'] ?? '') ?>" placeholder="/module/... oder /app.php?r=...">

          <label for="menu_modul">modul (für Rechte-Filter im Menü)</label>
          <input id="menu_modul" name="modul" value="<?= e($row['modul'] ?? '') ?>">

          <label for="menu_obj_typ">objekt_typ</label>
          <input id="menu_obj_typ" name="objekt_typ" value="<?= e($row['objekt_typ'] ?? '') ?>">

          <label for="menu_obj_id">objekt_id (optional)</label>
          <input id="menu_obj_id" name="objekt_id" value="<?= e($row['objekt_id'] !== null ? (string)$row['objekt_id'] : '') ?>">

          <label for="menu_sort">Sort</label>
          <input id="menu_sort" name="sort" value="<?= (int)$row['sort'] ?>">

          <label><input type="checkbox" name="aktiv" value="1" <?= ((int)$row['aktiv']===1?'checked':'') ?>> Aktiv</label>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Speichern</button>
            <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.menu">Zurück</a>
          </div>
        </form>

        <?php if ((int)$row['id'] > 0): ?>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <button class="btn btn--ghost" type="submit">Löschen</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php
  if ($standalone) render_footer();
  return;
}
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
    <h2>Menü: main</h2>
    <a class="btn" href="<?= e($base) ?>/app.php?r=admin.menu&a=edit">+ Neu</a>
  </div>

  <?php if ($ok): ?><p class="badge badge--g" role="status"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

  <div class="tablewrap">
    <table class="table">
      <thead>
        <tr><th scope="col">Label</th><th scope="col">Route</th><th scope="col">modul/obj</th><th scope="col">sort</th><th scope="col">aktiv</th><th scope="col"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($flat as $it): ?>
          <tr>
            <td><?= e(str_repeat('— ', (int)$it['_depth']) . $it['label']) ?></td>
            <td><?= e($it['route_key'] ?? '') ?></td>
            <td><?= e(($it['modul'] ?? '—') . ' / ' . ($it['objekt_typ'] ?? '—')) ?></td>
            <td><?= (int)$it['sort'] ?></td>
            <td><?= ((int)$it['aktiv']===1)?'ja':'nein' ?></td>
            <td><a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.menu&a=edit&id=<?= (int)$it['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="small" style="margin-top:10px;">
    Tipp: Für Menü-Sichtbarkeit setze modul/objekt_typ passend zur Route (oder leer = immer sichtbar).
  </p>
</div>

<?php if ($standalone) render_footer(); ?>