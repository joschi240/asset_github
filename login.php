<?php
require_once __DIR__ . '/src/layout.php';

session_boot();
$cfg = app_cfg();
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  $user = trim((string)($_POST['benutzername'] ?? ''));
  $pass = (string)($_POST['passwort'] ?? '');

  if (login($user, $pass)) {
    $r = urlencode((string)($cfg['app']['default_route'] ?? 'wartung.dashboard'));
    header("Location: {$cfg['app']['base_url']}/app.php?r={$r}");
    exit;
  }
  $err = 'Login fehlgeschlagen.';
}

render_header('Login');
?>
<div class="grid">
  <div class="col-6">
    <div class="card">
      <h2>Login</h2>
      <?php if ($err): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>Benutzername</label>
        <input name="benutzername" autocomplete="username" required>

        <label>Passwort</label>
        <input name="passwort" type="password" autocomplete="current-password" required>

        <div style="margin-top:12px;">
          <button class="btn" type="submit">Einloggen</button>
        </div>
      </form>

      <p class="small" style="margin-top:10px;">
        Admin-User in <code>core_user</code> anlegen (passwort_hash via <code>password_hash()</code>).
      </p>
    </div>
  </div>
</div>
<?php render_footer(); ?>