<?php
// module/wartungstool/punkt.php (INNER VIEW)
// UI v2 Final (Desktop-first, ui-* patterns).
// Wichtig: Diese View rendert kein eigenes Layout (Front-Controller app.php).
// Hinweis: "Bald fällig" Logik ist zentral in src/helpers.php umgesetzt:
// wartung_status_from_rest() inkl. soon_hours > soon_ratio > Fallback 0.20.

require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);

// Rechte
$canDoWartung = user_can_edit($userId, 'wartungstool', 'global', null);
$canCreateTicket = user_can_edit($userId, 'stoerungstool', 'global', null);

$wpId = (int)($_GET['wp'] ?? 0);
if ($wpId <= 0) {
  echo '<div class="ui-container"><div class="ui-card"><h2>Fehler</h2><p class="small ui-muted">Wartungspunkt fehlt (Parameter wp).</p></div></div>';
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
  echo '<div class="ui-container"><div class="ui-card"><h2>Nicht gefunden</h2><p class="small ui-muted">Wartungspunkt existiert nicht oder ist deaktiviert.</p></div></div>';
  exit;
}

// ------- Fälligkeit berechnen (inkl. zentraler soon_hours/soon_ratio-Logik) -------
$planInterval = (float)($wp['plan_interval'] ?? 0);
$soonHours = wartung_normalize_soon_hours(($wp['soon_hours'] !== null) ? (float)$wp['soon_hours'] : null);
$soonRatio = wartung_normalize_soon_ratio(($wp['soon_ratio'] !== null) ? (float)$wp['soon_ratio'] : null);

$dueStr = '—';
$restStr = '—';
$restVal = null;   // Rest in Stunden (bei zeit) bzw. productive-hours (bei produktiv)
$dueVal  = null;

$ampel = ['cls' => 'ui-badge ui-badge--ok', 'label' => 'OK', 'type' => 'ok'];

$thresholdLabel = wartung_threshold_label($soonRatio, $soonHours);

if ($wp['intervall_typ'] === 'produktiv' && $wp['letzte_wartung'] !== null && $planInterval > 0) {
  $dueAt = (float)$wp['letzte_wartung'] + $planInterval;
  $rest  = $dueAt - (float)$wp['productive_hours'];

  $dueVal = $dueAt;
  $restVal = $rest;

  $dueStr  = number_format($dueAt, 1, ',', '.') . ' h';
  $restStr = number_format($rest, 1, ',', '.') . ' h';

} elseif ($wp['intervall_typ'] === 'zeit' && !empty($wp['datum']) && $planInterval > 0) {
  $lastTs = strtotime((string)$wp['datum']);
  $dueTs  = $lastTs + (int)round($planInterval * 3600);
  $restH  = ($dueTs - time()) / 3600.0;

  $dueVal = $dueTs;
  $restVal = $restH;

  $dueStr  = date('Y-m-d H:i', $dueTs);
  $restStr = number_format($restH, 1, ',', '.') . ' h';
}

$ampel = wartung_status_from_rest($restVal, $planInterval, $soonRatio, $soonHours);
if (($ampel['type'] ?? '') === 'new') {
  $ampel['label'] = 'Nicht initialisiert';
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

// UI/UX: Ticket nie pauschal vorauswählen.
// Auto-Haken nur bei echter Abweichung (JS, Messwert außerhalb Grenzwerte).
$ticketDefault = 0;

$doks = db_all("
  SELECT *
  FROM core_dokument
  WHERE modul='wartungstool' AND referenz_typ='wartungspunkt' AND referenz_id=?
  ORDER BY hochgeladen_am DESC, id DESC
", [$wpId]);

$assetLabel = (($wp['asset_code'] ? $wp['asset_code'].' — ' : '') . $wp['asset_name']);
$krit = (int)($wp['kritischkeitsstufe'] ?? 0);
?>
<div class="ui-container">

  <div class="ui-page-header">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <h1 class="ui-page-title">Wartungspunkt</h1>
        <p class="ui-page-subtitle ui-muted">
          <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.dashboard">← zurück zum Dashboard</a>
          <span class="ui-muted">·</span>
          <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.uebersicht&asset_id=<?= (int)$wp['asset_id'] ?>">zur Übersicht</a>
          <span class="ui-muted">·</span>
          WP #<?= (int)$wpId ?>
        </p>
      </div>
      <div>
        <span class="<?= e($ampel['cls']) ?>"><?= e($ampel['label']) ?></span>
      </div>
    </div>

    <?php if ($ok || $err !== ''): ?>
      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top: var(--s-3);">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status">Gespeichert</span><?php endif; ?>
        <?php if ($err !== ''): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

<div class="ui-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: var(--s-6); align-items:start;">

  <!-- Reihe 1 / Spalte 1: Details -->
  <div class="ui-card">
    <h2 style="margin:0;"><?= e($wp['text_kurz']) ?></h2>

    <div class="small ui-muted" style="margin-top:8px;">
      Anlage: <b><?= e($assetLabel) ?></b>
      <?php if (!empty($wp['asset_typ'])): ?> · Typ: <b><?= e((string)$wp['asset_typ']) ?></b><?php endif; ?>
      <?php if ($krit > 0): ?> · Krit: <b><?= $krit ?></b><?php endif; ?>
    </div>

    <div style="margin-top: var(--s-4);">
      <?php if (!empty($wp['text_lang'])): ?>
        <div class="small" style="white-space:pre-wrap;"><?= e((string)$wp['text_lang']) ?></div>
      <?php else: ?>
        <div class="small ui-muted">Keine Langbeschreibung hinterlegt.</div>
      <?php endif; ?>
    </div>

    <div class="small ui-muted" style="margin-top: var(--s-4);">
      Messwertpflicht: <b><?= ((int)$wp['messwert_pflicht']===1 ? 'Ja' : 'Nein') ?></b>
      <?php if (!empty($wp['einheit'])): ?> · Einheit: <b><?= e((string)$wp['einheit']) ?></b><?php endif; ?>
      <?php if ($wp['grenzwert_min'] !== null || $wp['grenzwert_max'] !== null): ?>
        · Grenzwerte: <b><?= ($wp['grenzwert_min']!==null ? e((string)$wp['grenzwert_min']) : '—') ?></b> bis
        <b><?= ($wp['grenzwert_max']!==null ? e((string)$wp['grenzwert_max']) : '—') ?></b>
      <?php endif; ?>
    </div>
  </div>

  <!-- Reihe 1 / Spalte 2: Fälligkeit -->
  <div class="ui-card">
    <h2 style="margin:0;">Fälligkeit</h2>

    <div class="small ui-muted" style="margin-top:10px;">
      Typ: <b><?= e((string)$wp['intervall_typ']) ?></b>
      · Intervall: <b><?= number_format($planInterval, 1, ',', '.') ?> h</b>
      · Bald-fällig Schwelle: <b><?= e($thresholdLabel) ?></b>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: var(--s-4);">
      <!-- kein ui-card-in-ui-card mehr, nur “boxed” Felder -->
      <div style="border:1px solid var(--ui-border, #e6e9ef); border-radius: 12px; padding: var(--s-4); background: var(--ui-surface-2, #fff);">
        <div class="small ui-muted">Fällig bei</div>
        <div style="font-weight:700; margin-top:6px;"><?= e($dueStr) ?></div>
      </div>
      <div style="border:1px solid var(--ui-border, #e6e9ef); border-radius: 12px; padding: var(--s-4); background: var(--ui-surface-2, #fff);">
        <div class="small ui-muted">Rest</div>
        <div style="font-weight:700; margin-top:6px;"><?= e($restStr) ?></div>
      </div>
    </div>

    <div class="small ui-muted" style="margin-top: var(--s-4);">
      Aktuell (Produktiv): <b><?= number_format((float)$wp['productive_hours'], 1, ',', '.') ?> h</b>
      <?php if ($wp['datum']): ?> · Letzte Wartung (Zeit): <b><?= e((string)$wp['datum']) ?></b><?php endif; ?>
      <?php if ($wp['letzte_wartung'] !== null): ?> · Letzte Wartung (Prod.): <b><?= number_format((float)$wp['letzte_wartung'], 1, ',', '.') ?> h</b><?php endif; ?>
    </div>
  </div>

  <!-- Reihe 2 / Spalte 1: Durchführung -->
  <div class="ui-card">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <h2 style="margin:0;">Durchführen</h2>
      <?php if (!$canDoWartung): ?><span class="ui-badge">Nur Lesen</span><?php endif; ?>
    </div>

    <?php if (!$canDoWartung): ?>
      <p class="small ui-muted" style="margin-top:10px;">Keine Bearbeitungsrechte.</p>
    <?php else: ?>
      <form method="post" action="<?= e($base) ?>/app.php?r=wartung.punkt_save" style="margin-top: var(--s-4);">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="wp_id" value="<?= (int)$wpId ?>">

        <div style="display:grid; grid-template-columns: 1fr; gap: 12px;">
          <div>
            <label for="punkt_team">Team (wird gemerkt)</label>
            <input class="ui-input" id="punkt_team" name="team_text" value="<?= e($defaultTeam) ?>" placeholder="z.B. Team A / Schicht 2">
          </div>

          <?php if ((int)$wp['messwert_pflicht'] === 1): ?>
            <div>
              <label for="messwert">Messwert <?= !empty($wp['einheit']) ? '(' . e((string)$wp['einheit']) . ')' : '' ?></label>
              <input
                class="ui-input"
                id="messwert"
                name="messwert"
                inputmode="decimal"
                placeholder="z.B. 58,2"
                data-min="<?= $wp['grenzwert_min'] !== null ? e((string)$wp['grenzwert_min']) : '' ?>"
                data-max="<?= $wp['grenzwert_max'] !== null ? e((string)$wp['grenzwert_max']) : '' ?>"
              >
              <div id="mw_hint" class="small ui-muted" style="margin-top:6px;"></div>
            </div>
          <?php endif; ?>

<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
  <div style="min-width: 260px; flex: 1 1 260px;">
    <label for="status">Status</label>
    <select class="ui-input" id="status" name="status">
      <option value="ok">ok</option>
      <option value="abweichung">abweichung</option>
    </select>
  </div>

  <div style="flex: 0 0 auto; padding-bottom: 10px;">
    <?php if ($canCreateTicket): ?>
      <label class="small" style="display:flex; gap:8px; align-items:center; margin:0; font-weight:600;">
        <input id="create_ticket" type="checkbox" name="create_ticket" value="1" <?= $ticketDefault ? 'checked' : '' ?>>
        <span>Ticket erzeugen (optional)</span>
      </label>
    <?php else: ?>
      <div class="small ui-muted">Ticket: keine Berechtigung</div>
    <?php endif; ?>
  </div>
</div>

          <div>
            <label for="punkt_bemerkung">Bemerkung</label>
            <textarea class="ui-input" id="punkt_bemerkung" name="bemerkung" placeholder="Kurz notieren was gemacht wurde / Auffälligkeiten..." style="min-height: 110px;"></textarea>
          </div>

          <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button class="ui-btn ui-btn--primary" type="submit">Speichern</button>
            <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=wartung.dashboard">Abbrechen</a>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>

<!-- Reihe 2 / Spalte 2: Hinweise -->
<div class="ui-card">
  <h2 style="margin:0;">Hinweise</h2>

  <div class="small ui-muted" style="margin-top:10px; line-height:1.55;">
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <span class="ui-badge">WP #<?= (int)$wpId ?></span>
      <span class="ui-badge"><?= e((string)$wp['intervall_typ']) ?></span>
      <?php if (!empty($wp['einheit'])): ?><span class="ui-badge"><?= e((string)$wp['einheit']) ?></span><?php endif; ?>
      <?php if ((int)$wp['messwert_pflicht'] === 1): ?><span class="ui-badge ui-badge--warn">Messwertpflicht</span><?php endif; ?>
    </div>

    <div style="margin-top:12px;">
      <div><b>Bald fällig</b> wird so berechnet:</div>
      <ul style="margin:8px 0 0 18px; padding:0;">
        <li><b>soon_hours</b> gewinnt, wenn gesetzt</li>
        <li>sonst <b>soon_ratio</b> (z. B. 10%)</li>
        <li>Fallback: <b>20%</b>, wenn nichts gepflegt</li>
      </ul>
    </div>

    <?php if ($wp['grenzwert_min'] !== null || $wp['grenzwert_max'] !== null): ?>
      <div style="margin-top:12px;">
        <div><b>Grenzwerte</b>:</div>
        <div>
          Min: <b><?= $wp['grenzwert_min'] !== null ? e((string)$wp['grenzwert_min']) : '—' ?></b>
          · Max: <b><?= $wp['grenzwert_max'] !== null ? e((string)$wp['grenzwert_max']) : '—' ?></b>
        </div>
        <div class="ui-muted" style="margin-top:6px;">
          Tipp: Bei Abweichung Status auf <b>abweichung</b> setzen und optional ein Ticket anlegen.
        </div>
      </div>
    <?php endif; ?>

    <?php if ($canDoWartung): ?>
      <div style="margin-top:12px;">
        <a class="ui-btn ui-btn--ghost ui-btn--sm"
           href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$wp['asset_id'] ?>&edit_wp=<?= (int)$wpId ?>">
          Wartungspunkt im Admin öffnen
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

  <!-- Reihe 3: Dokumente volle Breite -->
  <div class="ui-card" style="grid-column: 1 / -1;">
    <h2 style="margin:0;">Dokumente</h2>

    <?php if ($canDoWartung): ?>
      <form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/app.php?r=wartung.punkt_dokument_upload" style="margin-top: var(--s-4);">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="wp_id" value="<?= (int)$wpId ?>">
        <div style="display:grid; grid-template-columns: 1fr auto; gap: 10px; align-items:end;">
          <div>
            <label for="punkt_doc_file">Datei (jpg/png/webp/pdf)</label>
            <input id="punkt_doc_file" class="ui-input" type="file" name="file" required>
          </div>
          <div>
            <button class="ui-btn ui-btn--primary" type="submit">Hochladen</button>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <?php if (!$doks): ?>
      <p class="small ui-muted" style="margin-top: var(--s-4);">Keine Dokumente.</p>
    <?php else: ?>
      <div class="ui-table-wrap" style="margin-top: var(--s-4);">
        <table class="ui-table">
          <thead>
            <tr>
              <th scope="col" style="width:180px;">Datum</th>
              <th scope="col">Datei</th>
              <th scope="col" style="width:140px;">Typ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($doks as $d): ?>
              <tr>
                <td style="white-space:nowrap;"><?= e((string)$d['hochgeladen_am']) ?></td>
                <td>
                  <a class="ui-link" href="<?= e($base) ?>/uploads/<?= e((string)$d['dateiname']) ?>" target="_blank" rel="noopener">
                    <?= e((string)($d['originalname'] ?: $d['dateiname'])) ?>
                  </a>
                </td>
                <td class="small ui-muted"><?= e((string)($d['mime'] ?: '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  
  <!-- Reihe 4 : Letzte Protokolle -->
<div class="ui-card" style="grid-column: 1 / -1;">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
    <h2 style="margin:0;">Letzte Protokolle</h2>
    <div class="small ui-muted"><?= e(count($prot)) ?> / 5</div>
  </div>

  <?php if (!$prot): ?>
    <p class="small ui-muted" style="margin-top:10px;">Noch keine Einträge.</p>
  <?php else: ?>
    <div class="ui-table-wrap" style="margin-top: var(--s-4); overflow-x:auto;">
      <table class="ui-table" style="width:100%; table-layout:fixed;">
        <colgroup>
          <col style="width:170px;">
          <col style="width:110px;">
          <col style="width:110px;">
          <col style="width:180px;">
          <col>
        </colgroup>
        <thead>
          <tr>
            <th scope="col">Datum</th>
            <th scope="col">Status</th>
            <th scope="col">Messwert</th>
            <th scope="col">Team</th>
            <th scope="col">Bemerkung</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($prot as $p): ?>
          <tr>
            <td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e((string)$p['datum']) ?></td>
            <td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e((string)$p['status']) ?></td>
            <td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              <?= $p['messwert'] !== null ? e((string)$p['messwert']) : '<span class="ui-muted">—</span>' ?>
            </td>
            <td style="white-space:normal; overflow-wrap:anywhere; word-break:break-word;">
              <?= e((string)($p['team_text'] ?: '—')) ?>
            </td>
            <td class="small ui-muted" style="white-space:normal; overflow-wrap:anywhere; word-break:break-word;">
              <?= e((string)($p['bemerkung'] ?: '')) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
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
      hint.innerHTML = oob
        ? ('<span aria-hidden="true">⚠</span> <span role="alert">Messwert außerhalb Grenzwerte (' + fmt(min) + ' bis ' + fmt(max) + ').</span>')
        : ('<span aria-hidden="true">✓</span> Messwert innerhalb Grenzwerte (' + fmt(min) + ' bis ' + fmt(max) + ').');
    } else hint.textContent = '';

    if (oob) {
  status.value = 'abweichung';
  ticket.checked = true;
} else {
  ticket.checked = false;
}
  }

  evaluate();
  mw.addEventListener('input', evaluate);
})();
</script>
<?php endif; ?>