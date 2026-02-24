<?php
// module/wartungstool/punkt.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);

// Rechte
$canDoWartung = function_exists('user_can_edit') ? user_can_edit($userId, 'wartungstool', 'global', null) : true;
$canCreateTicket = function_exists('user_can_edit') ? user_can_edit($userId, 'stoerungstool', 'global', null) : true;

$wpId = (int)($_GET['wp'] ?? 0);
if ($wpId <= 0) {
  echo '<div class="card"><h2>Fehler</h2><p class="small">Wartungspunkt fehlt (Parameter wp).</p></div>';
  exit;
}

$wp = db_one(
  "SELECT
      wp.*,
      a.code AS asset_code, a.name AS asset_name, a.asset_typ,
      k.kritischkeitsstufe,
      COALESCE(rc.productive_hours, 0) AS productive_hours
   FROM wartungstool_wartungspunkt wp
   JOIN core_asset a ON a.id = wp.asset_id
   LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
   LEFT JOIN core_runtime_counter rc ON rc.asset_id = a.id
   WHERE wp.id = ? AND wp.aktiv=1
   LIMIT 1",
  [$wpId]
);

if (!$wp) {
  echo '<div class="card"><h2>Nicht gefunden</h2><p class="small">Wartungspunkt existiert nicht oder ist deaktiviert.</p></div>';
  exit;
}

// Fälligkeit berechnen
$dueStr = '—';
$restStr = '—';
$ampel = ['cls'=>'badge--g','label'=>'OK'];

if ($wp['intervall_typ'] === 'produktiv' && $wp['letzte_wartung'] !== null) {
  $dueAt = (float)$wp['letzte_wartung'] + (float)$wp['plan_interval'];
  $rest  = $dueAt - (float)$wp['productive_hours'];

  $dueStr  = number_format($dueAt, 1, ',', '.') . ' h';
  $restStr = number_format($rest, 1, ',', '.') . ' h';

  $ratio = ((float)$wp['plan_interval'] > 0) ? ($rest / (float)$wp['plan_interval']) : 1.0;
  if ($rest < 0) $ampel = ['cls'=>'badge--r','label'=>'Überfällig'];
  elseif ($ratio <= 0.20) $ampel = ['cls'=>'badge--y','label'=>'Bald fällig'];
} elseif ($wp['intervall_typ'] === 'zeit' && $wp['datum']) {
  $lastTs = strtotime($wp['datum']);
  $dueTs  = $lastTs + (int)round(((float)$wp['plan_interval']) * 3600);
  $restH  = ($dueTs - time()) / 3600.0;

  $dueStr  = date('Y-m-d H:i', $dueTs);
  $restStr = number_format($restH, 1, ',', '.') . ' h';

  $ratio = ((float)$wp['plan_interval'] > 0) ? ($restH / (float)$wp['plan_interval']) : 1.0;
  if ($restH < 0) $ampel = ['cls'=>'badge--r','label'=>'Überfällig'];
  elseif ($ratio <= 0.20) $ampel = ['cls'=>'badge--y','label'=>'Bald fällig'];
}

// Letzte Protokolle
$prot = db_all(
  "SELECT p.*, u.anzeigename
   FROM wartungstool_protokoll p
   LEFT JOIN core_user u ON u.id = p.user_id
   WHERE p.wartungspunkt_id = ?
   ORDER BY p.datum DESC
   LIMIT 5",
  [$wpId]
);

// Team default aus Session
session_boot();
$defaultTeam = $_SESSION['wartung_team_text'] ?? '';
$ok = (int)($_GET['ok'] ?? 0);
$err = trim((string)($_GET['err'] ?? ''));

// Ticket default: wenn Messwertpflicht + Grenzwerte existieren
$ticketDefault = 0;
if ((int)$wp['messwert_pflicht'] === 1 && ($wp['grenzwert_min'] !== null || $wp['grenzwert_max'] !== null)) {
  $ticketDefault = 1;
}
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1>Wartungspunkt</h1>
      <div class="small">
        <a href="<?= e($base) ?>/app.php?r=wartung.dashboard">← zurück zum Dashboard</a>
      </div>
    </div>
    <div>
      <span class="badge <?= e($ampel['cls']) ?>"><?= e($ampel['label']) ?></span>
    </div>
  </div>

  <?php if ($ok): ?><p class="badge badge--g">Gespeichert.</p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

  <div class="grid" style="margin-top:8px;">
    <div class="col-6">
      <div class="card" style="border:none; box-shadow:none; padding:0;">
        <h2>Anlage</h2>
        <div><strong><?= e(($wp['asset_code'] ? $wp['asset_code'].' — ' : '') . $wp['asset_name']) ?></strong></div>
        <div class="small"><?= e($wp['asset_typ'] ?: '') ?><?= $wp['kritischkeitsstufe'] ? ' · Krit '.$wp['kritischkeitsstufe'] : '' ?></div>
      </div>
    </div>
    <div class="col-6">
      <div class="card" style="border:none; box-shadow:none; padding:0;">
        <h2>Fälligkeit</h2>
        <div class="small">
          Typ: <b><?= e($wp['intervall_typ']) ?></b> · Intervall: <b><?= number_format((float)$wp['plan_interval'], 1, ',', '.') ?> h</b><br>
          Fällig bei: <b><?= e($dueStr) ?></b> · Rest: <b><?= e($restStr) ?></b><br>
          Aktuell (Produktiv): <b><?= number_format((float)$wp['productive_hours'], 1, ',', '.') ?> h</b>
        </div>
      </div>
    </div>

    <div class="col-12">
      <h2><?= e($wp['text_kurz']) ?></h2>
      <?php if ($wp['text_lang']): ?>
        <div class="small" style="white-space:pre-wrap;"><?= e($wp['text_lang']) ?></div>
      <?php else: ?>
        <div class="small">Keine Langbeschreibung hinterlegt.</div>
      <?php endif; ?>

      <div class="small" style="margin-top:10px;">
        Messwertpflicht: <b><?= ((int)$wp['messwert_pflicht']===1 ? 'Ja' : 'Nein') ?></b>
        <?php if ($wp['einheit']): ?> · Einheit: <b><?= e($wp['einheit']) ?></b><?php endif; ?>
        <?php if ($wp['grenzwert_min'] !== null || $wp['grenzwert_max'] !== null): ?>
          · Grenzwerte: <b><?= ($wp['grenzwert_min']!==null ? e((string)$wp['grenzwert_min']) : '—') ?></b> bis
          <b><?= ($wp['grenzwert_max']!==null ? e((string)$wp['grenzwert_max']) : '—') ?></b>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12" style="margin-top:8px;">
      <h2>Durchführen</h2>

      <?php if (!$canDoWartung): ?>
        <p class="badge">Nur Lesen: keine Bearbeitungsrechte.</p>
      <?php else: ?>
        <form method="post" action="<?= e($base) ?>/app.php?r=wartung.punkt_save">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="wp_id" value="<?= (int)$wpId ?>">

          <label>Team (wird gemerkt)</label>
          <input name="team_text" value="<?= e($defaultTeam) ?>" placeholder="z.B. Team A / Schicht 2">

          <?php if ((int)$wp['messwert_pflicht'] === 1): ?>
            <label>Messwert <?= $wp['einheit'] ? '(' . e($wp['einheit']) . ')' : '' ?></label>
            <input
              id="messwert"
              name="messwert"
              inputmode="decimal"
              placeholder="z.B. 58.2"
              data-min="<?= $wp['grenzwert_min'] !== null ? e((string)$wp['grenzwert_min']) : '' ?>"
              data-max="<?= $wp['grenzwert_max'] !== null ? e((string)$wp['grenzwert_max']) : '' ?>"
            >
            <div id="mw_hint" class="small" style="margin-top:6px;"></div>
          <?php endif; ?>

          <label>Status</label>
          <select id="status" name="status">
            <option value="ok">ok</option>
            <option value="abweichung">abweichung</option>
          </select>

          <label>Bemerkung</label>
          <textarea name="bemerkung" placeholder="Kurz notieren was gemacht wurde / Auffälligkeiten..."></textarea>

          <?php if ($canCreateTicket): ?>
            <label>
              <input id="create_ticket" type="checkbox" name="create_ticket" value="1" <?= $ticketDefault ? 'checked' : '' ?>>
              Optional: Ticket erzeugen (Verschleiß/Beschaffung/Follow-Up)
            </label>
          <?php else: ?>
            <p class="small">Ticket: Keine Berechtigung zum Erstellen von Störungen.</p>
          <?php endif; ?>

          <div style="margin-top:12px;">
            <button class="btn" type="submit">Speichern</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="col-12" style="margin-top:8px;">
      <h2>Letzte Protokolle</h2>
      <?php if (!$prot): ?>
        <div class="small">Noch keine Einträge.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Datum</th><th>Status</th><th>Messwert</th><th>Team</th><th>Bemerkung</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($prot as $p): ?>
            <tr>
              <td><?= e($p['datum']) ?></td>
              <td><?= e($p['status']) ?></td>
              <td><?= $p['messwert'] !== null ? e((string)$p['messwert']) : '—' ?></td>
              <td><?= e($p['team_text'] ?: '—') ?></td>
              <td><?= e($p['bemerkung'] ?: '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canDoWartung && (int)$wp['messwert_pflicht'] === 1 && $canCreateTicket): ?>
<script>
(function(){
  const mw = document.getElementById('messwert');
  const status = document.getElementById('status');
  const ticket = document.getElementById('create_ticket');
  const hint = document.getElementById('mw_hint');
  if (!mw || !status || !ticket || !hint) return;

  const minRaw = mw.dataset.min;
  const maxRaw = mw.dataset.max;
  const min = (minRaw !== '') ? parseFloat(String(minRaw).replace(',', '.')) : null;
  const max = (maxRaw !== '') ? parseFloat(String(maxRaw).replace(',', '.')) : null;

  function fmt(v){ return (v === null || Number.isNaN(v)) ? '—' : String(v); }

  function evaluate(){
    const vRaw = String(mw.value || '').trim().replace(',', '.');
    if (vRaw === '') {
      hint.textContent = (min !== null || max !== null) ? ('Grenzwerte: ' + fmt(min) + ' bis ' + fmt(max)) : '';
      return;
    }
    const v = parseFloat(vRaw);
    if (Number.isNaN(v)) { hint.textContent = 'Messwert ist nicht numerisch.'; return; }

    let oob = false;
    if (min !== null && v < min) oob = true;
    if (max !== null && v > max) oob = true;

    if (min !== null || max !== null) {
      hint.textContent = oob
        ? ('⚠ Messwert außerhalb Grenzwerte (' + fmt(min) + ' bis ' + fmt(max) + ').')
        : ('✓ Messwert innerhalb Grenzwerte (' + fmt(min) + ' bis ' + fmt(max) + ').');
    } else hint.textContent = '';

    if (oob) { status.value = 'abweichung'; ticket.checked = true; }
  }

  evaluate();
  mw.addEventListener('input', evaluate);
})();
</script>
<?php endif; ?>