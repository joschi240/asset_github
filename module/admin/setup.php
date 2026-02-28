<?php
// module/admin/setup.php
require_once __DIR__ . '/../../src/helpers.php';

$cfg = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$ok = null;
$err = null;
$formUser = '';
$formName = '';

if (has_any_user()) {
  ?>
  <div class="ui-container">
    <div class="ui-page-header">
      <h1 class="ui-page-title">Setup – Erstuser</h1>
      <p class="ui-page-subtitle ui-muted">Initiales Setup für den ersten Admin-Benutzer.</p>
    </div>
    <div class="ui-card">
      <h2 style="margin:0;">Setup bereits erledigt</h2>
      <p class="small ui-muted" style="margin-top:10px;">Es existiert bereits mindestens ein Benutzer.</p>
      <div style="margin-top:12px;">
        <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/login.php">Zum Login</a>
      </div>
    </div>
  </div>
  <?php
  return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  $user = trim((string)($_POST['benutzername'] ?? ''));
  $name = trim((string)($_POST['anzeigename'] ?? ''));
  $formUser = $user;
  $formName = $name;
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

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Setup – Erstuser</h1>
    <p class="ui-page-subtitle ui-muted">Initiales Setup für den ersten Admin-Benutzer.</p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-card" style="max-width:760px;">
    <h2 style="margin:0;">Erstuser / Admin anlegen</h2>

    <form method="post" style="margin-top: var(--s-4);">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:end;">
        <div>
          <label for="setup_benutzername">Benutzername</label>
          <input class="ui-input" id="setup_benutzername" name="benutzername" value="<?= e($formUser) ?>" required aria-required="true">
        </div>

        <div>
          <label for="setup_anzeigename">Anzeigename (optional)</label>
          <input class="ui-input" id="setup_anzeigename" name="anzeigename" value="<?= e($formName) ?>">
        </div>

        <div>
          <label for="setup_passwort">Passwort</label>
          <input class="ui-input" id="setup_passwort" type="password" name="passwort" required aria-required="true">
        </div>

        <div>
          <label for="setup_passwort2">Passwort wiederholen</label>
          <input class="ui-input" id="setup_passwort2" type="password" name="passwort2" required aria-required="true">
        </div>
      </div>

      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <button class="ui-btn ui-btn--primary" type="submit">Admin erstellen</button>
        <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/login.php">Zum Login</a>
      </div>

      <p class="small ui-muted" style="margin-top:10px;">
        Setup ist nur möglich, solange noch kein Benutzer existiert.
      </p>
    </form>
  </div>
</div>

