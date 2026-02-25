<?php
// module/admin/users.php

if (!defined('APP_INNER')) {
  require_once __DIR__ . '/../../src/layout.php';
  $standalone = true;
  render_header('Admin – Benutzer');
} else {
  $standalone = false;
}

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
    if ($postAction === 'create') {
      $bn = trim((string)$_POST['benutzername']);
      $an = trim((string)($_POST['anzeigename'] ?? ''));
      $pw = (string)($_POST['passwort'] ?? '');
      $aktiv = !empty($_POST['aktiv']) ? 1 : 0;

      if ($bn === '' || $pw === '') throw new RuntimeException('Benutzername + Passwort erforderlich.');
      if (strlen($pw) < 8) throw new RuntimeException('Passwort min. 8 Zeichen.');

      $hash = password_hash($pw, PASSWORD_DEFAULT);
      db_exec("INSERT INTO core_user (benutzername, passwort_hash, anzeigename, aktiv) VALUES (?,?,?,?)",
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
    echo '<div class="card"><h2>Benutzer nicht gefunden</h2></div>';
    if ($standalone) render_footer();
    return;
  }
  ?>

  <div class="grid">
    <div class="col-6">
      <div class="card">
        <h2>Benutzer bearbeiten: <?= e($userRow['benutzername']) ?></h2>

        <?php if ($ok): ?><p class="badge badge--g" role="status"><?= e($ok) ?></p><?php endif; ?>
        <?php if ($err): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$userRow['id'] ?>">

          <label for="anzeigename_edit">Anzeigename</label>
          <input id="anzeigename_edit" name="anzeigename" value="<?= e($userRow['anzeigename'] ?? '') ?>">

          <label for="passwort_edit">Neues Passwort (optional)</label>
          <input id="passwort_edit" type="password" name="passwort" placeholder="leer lassen = unverändert">

          <label><input type="checkbox" name="aktiv" value="1" <?= ((int)$userRow['aktiv'] === 1 ? 'checked' : '') ?>> Aktiv</label>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Speichern</button>
            <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.users">Zurück</a>
          </div>
        </form>

        <p class="small" style="margin-top:10px;">
          Erstellt: <?= e($userRow['created_at']) ?> · Letzter Login: <?= e($userRow['last_login_at'] ?? '—') ?>
        </p>
      </div>
    </div>
  </div>

  <?php
  if ($standalone) render_footer();
  return;
}

// Default: Liste + Create Form
$users = db_all("SELECT id, benutzername, anzeigename, aktiv, created_at, last_login_at FROM core_user ORDER BY id ASC");
?>

<div class="grid">
  <div class="col-6">
    <div class="card">
      <h2>Neuen Benutzer anlegen</h2>

      <?php if ($ok): ?><p class="badge badge--g" role="status"><?= e($ok) ?></p><?php endif; ?>
      <?php if ($err): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <label for="benutzername_create">Benutzername</label>
        <input id="benutzername_create" name="benutzername" required aria-required="true">

        <label for="anzeigename_create">Anzeigename (optional)</label>
        <input id="anzeigename_create" name="anzeigename">

        <label for="passwort_create">Passwort</label>
        <input id="passwort_create" type="password" name="passwort" required aria-required="true">

        <label><input type="checkbox" name="aktiv" value="1" checked> Aktiv</label>

        <div style="margin-top:12px;">
          <button class="btn" type="submit">Anlegen</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-6">
    <div class="card">
      <h2>Benutzer</h2>
      <div class="tablewrap">
        <table class="table">
          <thead>
            <tr><th scope="col">ID</th><th scope="col">User</th><th scope="col">Name</th><th scope="col">Aktiv</th><th scope="col">Aktion</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $ur): ?>
              <tr>
                <td><?= (int)$ur['id'] ?></td>
                <td><?= e($ur['benutzername']) ?></td>
                <td><?= e($ur['anzeigename'] ?? '') ?></td>
                <td><?= ((int)$ur['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                <td>
                  <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=admin.users&a=edit&id=<?= (int)$ur['id'] ?>">Edit</a>
                  <?php if ((int)$ur['aktiv'] === 1): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="disable">
                      <input type="hidden" name="id" value="<?= (int)$ur['id'] ?>">
                      <button class="btn btn--ghost" type="submit">Deaktivieren</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="small" style="margin-top:10px;">Hinweis: Löschen vermeiden → lieber deaktivieren (Audit/Traceability).</p>
    </div>
  </div>
</div>

<?php if ($standalone) render_footer(); ?>