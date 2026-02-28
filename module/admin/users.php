<?php
// module/admin/users.php (INNER VIEW)
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

$action = (string)($_GET['a'] ?? '');
$id = (int)($_GET['id'] ?? 0);

$ok = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);
  $postAction = (string)($_POST['action'] ?? '');

  try {
    if ($postAction === 'create') {
      $bn = trim((string)$_POST['benutzername']);
      $an = trim((string)($_POST['anzeigename'] ?? ''));
      $pw = (string)($_POST['passwort'] ?? '');
      $aktiv = !empty($_POST['aktiv']) ? 1 : 0;

      if ($bn === '' || $pw === '') throw new RuntimeException('Benutzername + Passwort erforderlich.');
      if (strlen($pw) < 8) throw new RuntimeException('Passwort min. 8 Zeichen.');

      $hash = password_hash($pw, PASSWORD_DEFAULT);
      db_exec(
        "INSERT INTO core_user (benutzername, passwort_hash, anzeigename, aktiv) VALUES (?,?,?,?)",
        [$bn, $hash, ($an ?: null), $aktiv]
      );
      $ok = 'Benutzer erstellt.';

    } elseif ($postAction === 'update') {
      $uid = (int)$_POST['id'];
      $an = trim((string)($_POST['anzeigename'] ?? ''));
      $aktiv = !empty($_POST['aktiv']) ? 1 : 0;
      $pw = (string)($_POST['passwort'] ?? '');

      db_exec("UPDATE core_user SET anzeigename=?, aktiv=? WHERE id=?", [($an ?: null), $aktiv, $uid]);

      if ($pw !== '') {
        if (strlen($pw) < 8) throw new RuntimeException('Passwort min. 8 Zeichen.');
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        db_exec("UPDATE core_user SET passwort_hash=? WHERE id=?", [$hash, $uid]);
      }

      $ok = 'Benutzer gespeichert.';

    } elseif ($postAction === 'disable') {
      $uid = (int)$_POST['id'];
      db_exec("UPDATE core_user SET aktiv=0 WHERE id=?", [$uid]);
      $ok = 'Benutzer deaktiviert.';
    }
  } catch (Throwable $e) {
    $err = 'Fehler: ' . $e->getMessage();
  }
}

if ($action === 'edit' && $id > 0) {
  $userRow = db_one("SELECT id, benutzername, anzeigename, aktiv, created_at, last_login_at FROM core_user WHERE id=?", [$id]);
  if (!$userRow) {
    echo '<div class="ui-container"><div class="ui-card"><h2>Benutzer nicht gefunden</h2></div></div>';
    return;
  }
  ?>

  <div class="ui-container">
    <div class="ui-page-header">
      <h1 class="ui-page-title">Admin – Benutzer</h1>
      <p class="ui-page-subtitle ui-muted">
        Benutzer bearbeiten: <?= e($userRow['benutzername']) ?>
        <span class="ui-muted">·</span>
        <a class="ui-link" href="<?= e($base) ?>/app.php?r=admin.users">zurück zur Liste</a>
      </p>

      <?php if ($ok || $err): ?>
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
          <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
          <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="ui-card" style="max-width:760px;">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$userRow['id'] ?>">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:end;">
          <div>
            <label for="anzeigename_edit">Anzeigename</label>
            <input class="ui-input" id="anzeigename_edit" name="anzeigename" value="<?= e($userRow['anzeigename'] ?? '') ?>">
          </div>

          <div>
            <label for="passwort_edit">Neues Passwort (optional)</label>
            <input class="ui-input" id="passwort_edit" type="password" name="passwort" placeholder="leer lassen = unverändert">
          </div>

          <div style="display:flex; align-items:center; gap:8px; padding-bottom:10px;">
            <input id="aktiv_edit" type="checkbox" name="aktiv" value="1" <?= ((int)$userRow['aktiv'] === 1 ? 'checked' : '') ?>>
            <label for="aktiv_edit" style="margin:0;">Aktiv</label>
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button class="ui-btn ui-btn--primary" type="submit">Speichern</button>
          <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=admin.users">Zurück</a>
        </div>

        <p class="small ui-muted" style="margin-top:10px;">
          Erstellt: <?= e($userRow['created_at']) ?> · Letzter Login: <?= e($userRow['last_login_at'] ?? '—') ?>
        </p>
      </form>
    </div>
  </div>

  <?php
  return;
}

$users = db_all("SELECT id, benutzername, anzeigename, aktiv, created_at, last_login_at FROM core_user ORDER BY id ASC");
?>

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Admin – Benutzer</h1>
    <p class="ui-page-subtitle ui-muted">Benutzer anlegen, bearbeiten oder deaktivieren.</p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-grid" style="display:grid; grid-template-columns: 1fr 1.2fr; gap: var(--s-6); align-items:start;">
    <div class="ui-card">
      <h2 style="margin:0;">Neuen Benutzer anlegen</h2>

      <form method="post" style="margin-top: var(--s-4);">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <label for="benutzername_create">Benutzername</label>
        <input class="ui-input" id="benutzername_create" name="benutzername" required aria-required="true">

        <label for="anzeigename_create">Anzeigename (optional)</label>
        <input class="ui-input" id="anzeigename_create" name="anzeigename">

        <label for="passwort_create">Passwort</label>
        <input class="ui-input" id="passwort_create" type="password" name="passwort" required aria-required="true">

        <div style="display:flex; align-items:center; gap:8px; margin-top:10px;">
          <input id="aktiv_create" type="checkbox" name="aktiv" value="1" checked>
          <label for="aktiv_create" style="margin:0;">Aktiv</label>
        </div>

        <div style="margin-top:12px;">
          <button class="ui-btn ui-btn--primary" type="submit">Anlegen</button>
        </div>
      </form>
    </div>

    <div class="ui-card">
      <h2 style="margin:0;">Benutzer</h2>

      <div class="ui-table-wrap" style="margin-top: var(--s-4);">
        <table class="ui-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Name</th>
              <th>Aktiv</th>
              <th class="ui-th-actions">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $ur): ?>
              <tr>
                <td><?= (int)$ur['id'] ?></td>
                <td><?= e($ur['benutzername']) ?></td>
                <td><?= e($ur['anzeigename'] ?? '') ?></td>
                <td><?= ((int)$ur['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                <td class="ui-td-actions">
                  <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($base) ?>/app.php?r=admin.users&a=edit&id=<?= (int)$ur['id'] ?>">Edit</a>
                  <?php if ((int)$ur['aktiv'] === 1): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="disable">
                      <input type="hidden" name="id" value="<?= (int)$ur['id'] ?>">
                      <button class="ui-btn ui-btn--ghost ui-btn--sm" type="submit">Deaktivieren</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="small ui-muted" style="margin-top:10px;">Hinweis: Löschen vermeiden → lieber deaktivieren (Audit/Traceability).</p>
    </div>
  </div>
</div>
