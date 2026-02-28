<?php
// module/admin/menu.php (INNER VIEW)
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

$menu = db_one("SELECT id, name FROM core_menu WHERE name='main' LIMIT 1");
if (!$menu) {
  echo '<div class="ui-container"><div class="ui-card"><h2>Menü fehlt</h2><p class="small ui-muted">core_menu main existiert nicht.</p></div></div>';
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

      if ($route_key !== '' && ($modul === '' || $objekt_typ === '')) {
        $r = db_one("SELECT modul, objekt_typ, objekt_id FROM core_route WHERE route_key=? LIMIT 1", [$route_key]);
        if (is_array($r)) {
          if ($modul === '') $modul = (string)($r['modul'] ?? '');
          if ($objekt_typ === '') $objekt_typ = (string)($r['objekt_typ'] ?? '');
          if ($objekt_id === null && array_key_exists('objekt_id', $r) && $r['objekt_id'] !== null) $objekt_id = (int)$r['objekt_id'];
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
  $row = ($id > 0)
    ? db_one("SELECT * FROM core_menu_item WHERE id=? AND menu_id=?", [$id, (int)$menu['id']])
    : ['id'=>0,'parent_id'=>null,'label'=>'','route_key'=>null,'url'=>null,'modul'=>null,'objekt_typ'=>null,'objekt_id'=>null,'sort'=>0,'aktiv'=>1];

  if (!is_array($row)) {
    $row = ['id'=>0,'parent_id'=>null,'label'=>'','route_key'=>null,'url'=>null,'modul'=>null,'objekt_typ'=>null,'objekt_id'=>null,'sort'=>0,'aktiv'=>1];
  }

  ?>
  <div class="ui-container">
    <div class="ui-page-header">
      <h1 class="ui-page-title">Admin – Menü</h1>
      <p class="ui-page-subtitle ui-muted">
        <?= $row['id'] ? 'Menüeintrag bearbeiten' : 'Menüeintrag anlegen' ?>
        <span class="ui-muted">·</span>
        <a class="ui-link" href="<?= e($base) ?>/app.php?r=admin.menu">zurück zur Liste</a>
      </p>

      <?php if ($ok || $err): ?>
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
          <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
          <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="ui-card" style="max-width:900px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:end;">
          <div>
            <label for="menu_label">Label</label>
            <input class="ui-input" id="menu_label" name="label" value="<?= e($row['label']) ?>" required aria-required="true">
          </div>

          <div>
            <label for="menu_parent">Parent (optional)</label>
            <select class="ui-input" id="menu_parent" name="parent_id">
              <option value="">— keiner —</option>
              <?php foreach ($flat as $it): ?>
                <?php if ((int)$it['id'] === (int)$row['id']) continue; ?>
                <option value="<?= (int)$it['id'] ?>" <?= ((int)$row['parent_id']===(int)$it['id']?'selected':'') ?>>
                  <?= e(str_repeat('— ', (int)$it['_depth']) . $it['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="menu_route">Route (empfohlen)</label>
            <select class="ui-input" id="menu_route" name="route_key">
              <option value="">— keine —</option>
              <?php foreach ($routes as $r): ?>
                <option value="<?= e($r['route_key']) ?>" <?= (($row['route_key'] ?? '')===$r['route_key']?'selected':'') ?>>
                  <?= e($r['route_key'].' — '.$r['titel']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="menu_url">URL (Fallback/Legacy)</label>
            <input class="ui-input" id="menu_url" name="url" value="<?= e($row['url'] ?? '') ?>" placeholder="/module/... oder /app.php?r=...">
          </div>

          <div>
            <label for="menu_modul">modul (für Rechte-Filter im Menü)</label>
            <input class="ui-input" id="menu_modul" name="modul" value="<?= e($row['modul'] ?? '') ?>">
          </div>

          <div>
            <label for="menu_obj_typ">objekt_typ</label>
            <input class="ui-input" id="menu_obj_typ" name="objekt_typ" value="<?= e($row['objekt_typ'] ?? '') ?>">
          </div>

          <div>
            <label for="menu_obj_id">objekt_id (optional)</label>
            <input class="ui-input" id="menu_obj_id" name="objekt_id" value="<?= e($row['objekt_id'] !== null ? (string)$row['objekt_id'] : '') ?>">
          </div>

          <div>
            <label for="menu_sort">Sort</label>
            <input class="ui-input" id="menu_sort" name="sort" value="<?= (int)$row['sort'] ?>">
          </div>

          <div style="display:flex; align-items:center; gap:8px; padding-bottom:10px;">
            <input id="menu_aktiv" type="checkbox" name="aktiv" value="1" <?= ((int)$row['aktiv']===1?'checked':'') ?>>
            <label for="menu_aktiv" style="margin:0;">Aktiv</label>
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button class="ui-btn ui-btn--primary" type="submit">Speichern</button>
          <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=admin.menu">Zurück</a>
        </div>
      </form>

      <?php if ((int)$row['id'] > 0): ?>
        <form method="post" style="margin-top:12px;">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="ui-btn ui-btn--ghost" type="submit">Löschen</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php
  return;
}
?>

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Admin – Menü</h1>
    <p class="ui-page-subtitle ui-muted">Menüstruktur für `main` verwalten.</p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom: var(--s-4);">
      <h2 style="margin:0;">Menü: main</h2>
      <a class="ui-btn ui-btn--primary ui-btn--sm" href="<?= e($base) ?>/app.php?r=admin.menu&a=edit">+ Neu</a>
    </div>

    <div class="ui-table-wrap">
      <table class="ui-table">
        <thead>
          <tr>
            <th>Label</th>
            <th>Route</th>
            <th>modul/obj</th>
            <th>sort</th>
            <th>aktiv</th>
            <th class="ui-th-actions">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($flat as $it): ?>
            <tr>
              <td><?= e(str_repeat('— ', (int)$it['_depth']) . $it['label']) ?></td>
              <td><?= e($it['route_key'] ?? '') ?></td>
              <td><?= e(($it['modul'] ?? '—') . ' / ' . ($it['objekt_typ'] ?? '—')) ?></td>
              <td><?= (int)$it['sort'] ?></td>
              <td><?= ((int)$it['aktiv']===1)?'ja':'nein' ?></td>
              <td class="ui-td-actions">
                <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($base) ?>/app.php?r=admin.menu&a=edit&id=<?= (int)$it['id'] ?>">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="small ui-muted" style="margin-top:10px;">
      Tipp: Für Menü-Sichtbarkeit setze modul/objekt_typ passend zur Route (oder leer = immer sichtbar).
    </p>
  </div>
</div>
