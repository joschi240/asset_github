<?php
// module/admin/setup.php

if (!defined('APP_INNER')) {
  require_once __DIR__ . '/../../src/layout.php';
  render_header('Setup – Erstuser');
  $standalone = true;
} else {
  $standalone = false;
}

$cfg = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$ok = null;
$err = null;

if (has_any_user()) {
  echo '<div class="card"><h2>Setup bereits erledigt</h2><p class="small">Es existiert bereits mindestens ein Benutzer.</p></div>';
  if ($standalone) render_footer();
  return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  $user = trim((string)($_POST['benutzername'] ?? ''));
  $name = trim((string)($_POST['anzeigename'] ?? ''));
  $pass = (string)($_POST['passwort'] ?? '');
  $pass2 = (string)($_POST['passwort2'] ?? '');

  if ($user === '' || $pass === '') {
    $err = 'Benutzername und Passwort sind Pflicht.';
  } elseif (strlen($pass) < 8) {
    $err = 'Passwort bitte mindestens 8 Zeichen.';
  } elseif ($pass !== $pass2) {
    $err = 'Passwörter stimmen nicht überein.';
  } else {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);

      db_exec(
        "INSERT INTO core_user (benutzername, passwort_hash, anzeigename, aktiv) VALUES (?,?,?,1)",
        [$user, $hash, ($name ?: null)]
      );
      $uid = (int)db()->lastInsertId();

      // Wildcard Admin
      db_exec(
        "INSERT INTO core_permission (user_id, modul, objekt_typ, objekt_id, darf_sehen, darf_aendern, darf_loeschen)
         VALUES (?,?,?,?,1,1,1)",
        [$uid, '*', '*', null]
      );

      $ok = "Admin-User angelegt: {$user}. Du kannst dich jetzt einloggen.";
    } catch (Throwable $e) {
      $err = 'Fehler: ' . $e->getMessage();
    }
  }
}
?>

<div class="grid">
  <div class="col-6">
    <div class="card">
      <h2>Erstuser / Admin anlegen</h2>

      <?php if ($ok): ?><p class="badge badge--g" role="status"><?= e($ok) ?></p><?php endif; ?>
      <?php if ($err): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label for="setup_benutzername">Benutzername</label>
        <input id="setup_benutzername" name="benutzername" required aria-required="true">

        <label for="setup_anzeigename">Anzeigename (optional)</label>
        <input id="setup_anzeigename" name="anzeigename">

        <label for="setup_passwort">Passwort</label>
        <input id="setup_passwort" type="password" name="passwort" required aria-required="true">

        <label for="setup_passwort2">Passwort wiederholen</label>
        <input id="setup_passwort2" type="password" name="passwort2" required aria-required="true">

        <div style="margin-top:12px;">
          <button class="btn" type="submit">Admin erstellen</button>
          <a class="btn btn--ghost" href="<?= e($base) ?>/login.php">Zum Login</a>
        </div>

        <p class="small" style="margin-top:10px;">
          Setup ist nur möglich, solange noch kein Benutzer existiert.
        </p>
      </form>
    </div>
  </div>
</div>

