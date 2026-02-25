<?php
// module/wartungstool/uebersicht.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$assetId = (int)($_GET['asset_id'] ?? 0);

// Filter / Toggles
$mode = (string)($_GET['mode'] ?? 'offen'); // 'offen' | 'alle'
$mode = ($mode === 'alle') ? 'alle' : 'offen';

// Assets fürs Dropdown (Sortierung anhand Kategorie/Kritikalität)
$assets = db_all("
  SELECT
    a.id, a.code, a.name, a.asset_typ,
    k.name AS kategorie_name,
    COALESCE(k.kritischkeitsstufe,1) AS kritischkeitsstufe
  FROM core_asset a
  LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
  WHERE a.aktiv=1
  ORDER BY COALESCE(k.kritischkeitsstufe,1) DESC, k.name ASC, a.prioritaet DESC, a.name ASC
");

// Default Asset: erstes aus Liste
if ($assetId <= 0 && !empty($assets)) {
  $assetId = (int)$assets[0]['id'];
}

// Hilfsfunktionen
function ampel_from_rest(?float $restHours, float $intervalHours): array {
  if ($restHours === null) return ['cls'=>'', 'label'=>'Neu/Unbekannt'];
  if ($restHours < 0) return ['cls'=>'badge--r','label'=>'Überfällig'];
  $ratio = $intervalHours > 0 ? ($restHours / $intervalHours) : 1.0;
  if ($ratio <= 0.20) return ['cls'=>'badge--y','label'=>'Bald fällig'];
  return ['cls'=>'badge--g','label'=>'OK'];
}

function is_open_item(?float $restHours, float $intervalHours): bool {
  // "offen" = überfällig oder bald fällig oder unbekannt (initiale Wartung)
  if ($restHours === null) return true;
  if ($restHours < 0) return true;
  $ratio = $intervalHours > 0 ? ($restHours / $intervalHours) : 1.0;
  return ($ratio <= 0.20);
}

function extract_ticket_marker(?string $bemerkung): ?int {
  if (!$bemerkung) return null;
  if (preg_match('/\\[#TICKET:(\\d+)\\]/', $bemerkung, $m)) {
    return (int)$m[1];
  }
  return null;
}

if (!function_exists('short_text')) {
  function short_text(?string $s, int $max = 90): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max - 1) . '…';
  }
}

// Daten für ausgewähltes Asset laden
$asset = null;
$counterHours = 0.0;
$punkte = [];

if ($assetId > 0) {
  $asset = db_one("
    SELECT
      a.id, a.code, a.name, a.asset_typ,
      k.name AS kategorie_name,
      COALESCE(k.kritischkeitsstufe,1) AS kritischkeitsstufe
    FROM core_asset a
    LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
    WHERE a.id=? AND a.aktiv=1
    LIMIT 1
  ", [$assetId]);

  $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
  $counterHours = (float)($row['h'] ?? 0);

  // Wartungspunkte + letzter Protokoll-Eintrag in EINEM Rutsch
  $punkteRaw = db_all("
    SELECT
      wp.id, wp.asset_id, wp.text_kurz, wp.text_lang,
      wp.intervall_typ, wp.plan_interval, wp.letzte_wartung, wp.datum,
      wp.messwert_pflicht, wp.grenzwert_min, wp.grenzwert_max, wp.einheit,
      lp.datum AS last_datum,
      lp.status AS last_status,
      lp.messwert AS last_messwert,
      lp.team_text AS last_team_text,
      lp.bemerkung AS last_bemerkung
    FROM wartungstool_wartungspunkt wp
    LEFT JOIN (
      SELECT p1.*
      FROM wartungstool_protokoll p1
      INNER JOIN (
        SELECT wartungspunkt_id, MAX(datum) AS max_datum
        FROM wartungstool_protokoll
        WHERE asset_id = ?
        GROUP BY wartungspunkt_id
      ) x ON x.wartungspunkt_id = p1.wartungspunkt_id AND x.max_datum = p1.datum
    ) lp ON lp.wartungspunkt_id = wp.id
    WHERE wp.asset_id=? AND wp.aktiv=1
    ORDER BY wp.id ASC
  ", [$assetId, $assetId]);

  foreach ($punkteRaw as $wp) {
    $interval = (float)$wp['plan_interval'];

    // due/rest berechnen
    $dueLabel = '—';
    $restHours = null;

    if ($wp['intervall_typ'] === 'produktiv') {
      if ($wp['letzte_wartung'] !== null) {
        $dueAt = (float)$wp['letzte_wartung'] + $interval;
        $restHours = $dueAt - $counterHours;
        $dueLabel = number_format($dueAt, 1, ',', '.') . ' h';
      } else {
        $dueLabel = '— (keine letzte Wartung)';
      }
    } else { // zeit
      if (!empty($wp['datum'])) {
        $lastTs = strtotime($wp['datum']);
        $dueTs = $lastTs + (int)round($interval * 3600);
        $restHours = ($dueTs - time()) / 3600.0;
        $dueLabel = date('Y-m-d H:i', $dueTs);
      } else {
        $dueLabel = '— (keine letzte Wartung)';
      }
    }

    $ampel = ampel_from_rest($restHours, $interval);
    $open = is_open_item($restHours, $interval);

    // optional: Ticket Marker aus letzter Bemerkung
    $ticketId = extract_ticket_marker($wp['last_bemerkung'] ?? null);

    $punkte[] = [
      'wp' => $wp,
      'due' => $dueLabel,
      'rest' => $restHours,
      'ampel' => $ampel,
      'open' => $open,
      'ticket_id' => $ticketId
    ];
  }

  // Sortierung: offene zuerst, dann nach rest (kleinster zuerst), null ans Ende
  usort($punkte, function($a, $b) {
    if ($a['open'] && !$b['open']) return -1;
    if ($b['open'] && !$a['open']) return 1;

    $ar = $a['rest']; $br = $b['rest'];
    if ($ar === null && $br === null) return 0;
    if ($ar === null) return 1;
    if ($br === null) return -1;
    return $ar <=> $br;
  });
}

// ggf. filtern nach Mode
$openList = [];
$okList = [];

foreach ($punkte as $p) {
  if ($p['open']) $openList[] = $p;
  else $okList[] = $p;
}

if ($mode === 'offen') {
  $punkteToShow = $openList;
} else {
  $punkteToShow = $punkte;
}
?>

<div class="card">
  <h1>Wartung – Übersicht (pro Anlage)</h1>
  <p class="small">
    Oben Anlage wählen. Unten: Wartungspunkte sortiert nach Fälligkeit. „OK“ wird eingeklappt.
  </p>

  <form method="get" action="<?= e($base) ?>/app.php" style="margin-top:10px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
    <input type="hidden" name="r" value="wartung.uebersicht">

    <div style="min-width:360px; flex:1;">
      <label for="uebersicht_asset_id">Anlage auswählen</label>
      <select id="uebersicht_asset_id" name="asset_id" onchange="this.form.submit()">
        <?php foreach ($assets as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $assetId ? 'selected' : '') ?>>
            <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
            <?= e(' · ' . ($a['kategorie_name'] ?: '—') . ' · Krit ' . (int)$a['kritischkeitsstufe']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="min-width:220px;">
      <label for="uebersicht_mode">Anzeige</label>
      <select id="uebersicht_mode" name="mode" onchange="this.form.submit()">
        <option value="offen" <?= $mode==='offen' ? 'selected' : '' ?>>nur offene</option>
        <option value="alle" <?= $mode==='alle' ? 'selected' : '' ?>>alle</option>
      </select>
    </div>

    <noscript>
      <div style="margin-top:10px;">
        <button class="btn" type="submit">Anzeigen</button>
      </div>
    </noscript>
  </form>
</div>

<?php if (!$asset): ?>
  <div class="card">
    <h2>Keine Anlage gefunden</h2>
    <p class="small">Bitte Anlage auswählen.</p>
  </div>
<?php else: ?>
  <div class="card">
    <h2><?= e(($asset['code'] ? $asset['code'].' — ' : '') . $asset['name']) ?></h2>
    <div class="small">
      <?= e($asset['asset_typ'] ?: '') ?>
      · Kategorie: <b><?= e($asset['kategorie_name'] ?: '—') ?></b>
      · Kritikalität: <b><?= (int)$asset['kritischkeitsstufe'] ?></b>
      · Produktivstunden: <b><?= number_format($counterHours, 1, ',', '.') ?> h</b>
      · Offene Punkte: <b><?= count($openList) ?></b>
      · OK Punkte: <b><?= count($okList) ?></b>
    </div>
  </div>

  <div class="card">
    <h2><?= $mode === 'offen' ? 'Offene Wartungen' : 'Wartungspunkte (sortiert)' ?></h2>

    <?php if (empty($punkteToShow)): ?>
      <p class="small">Keine passenden Wartungspunkte.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Ampel</th>
            <th scope="col">Punkt</th>
            <th scope="col">Typ</th>
            <th scope="col">Intervall</th>
            <th scope="col">Fällig bei</th>
            <th scope="col">Rest</th>
            <th scope="col">Letzter Eintrag</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($punkteToShow as $p):
            $wp = $p['wp'];
          ?>
          <tr>
            <td><span class="badge <?= e($p['ampel']['cls']) ?>"><?= e($p['ampel']['label']) ?></span></td>

            <td>
              <a href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$wp['id'] ?>">
                <?= e($wp['text_kurz']) ?>
              </a>
              <div class="small">WP #<?= (int)$wp['id'] ?></div>
              <?php if ((int)$wp['messwert_pflicht'] === 1): ?>
                <div class="small">
                  Messwert: Pflicht
                  <?php if ($wp['einheit']): ?> (<?= e($wp['einheit']) ?>)<?php endif; ?>
                  <?php if ($wp['grenzwert_min'] !== null || $wp['grenzwert_max'] !== null): ?>
                    · Grenzen: <?= $wp['grenzwert_min'] !== null ? e((string)$wp['grenzwert_min']) : '—' ?>
                    bis <?= $wp['grenzwert_max'] !== null ? e((string)$wp['grenzwert_max']) : '—' ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>

            <td><?= e($wp['intervall_typ']) ?></td>

            <td><?= number_format((float)$wp['plan_interval'], 1, ',', '.') ?> h</td>

            <td><?= e($p['due']) ?></td>

            <td>
              <?php if ($p['rest'] === null): ?>
                —
              <?php else: ?>
                <?= number_format((float)$p['rest'], 1, ',', '.') ?> h
              <?php endif; ?>
            </td>

            <td class="small">
              <?php if (!empty($wp['last_datum'])): ?>
                <div>
                  <b><?= e($wp['last_status']) ?></b>
                  · <?= e($wp['last_datum']) ?>
                  <?php if ($wp['last_team_text']): ?> · <?= e($wp['last_team_text']) ?><?php endif; ?>
                </div>
                <?php if ($wp['last_messwert'] !== null): ?>
                  <div>Messwert: <?= e((string)$wp['last_messwert']) ?><?= $wp['einheit'] ? ' ' . e($wp['einheit']) : '' ?></div>
                <?php endif; ?>
                <?php if ($wp['last_bemerkung']): ?>
                  <div><?= e(short_text($wp['last_bemerkung'], 100)) ?></div>
                <?php endif; ?>
                <?php if ($p['ticket_id']): ?>
                  <div><span class="badge">Ticket <?= (int)$p['ticket_id'] ?></span></div>
                <?php endif; ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($mode === 'alle' && count($okList) > 0): ?>
    <div class="card">
      <details>
        <summary style="cursor:pointer; font-weight:600;">
          OK Punkte einklappen/anzeigen (<?= count($okList) ?>)
        </summary>
        <div style="margin-top:10px;">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Ampel</th>
                <th scope="col">Punkt</th>
                <th scope="col">Typ</th>
                <th scope="col">Fällig bei</th>
                <th scope="col">Rest</th>
                <th scope="col">Letzter Eintrag</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($okList as $p):
                $wp = $p['wp'];
              ?>
              <tr>
                <td><span class="badge <?= e($p['ampel']['cls']) ?>"><?= e($p['ampel']['label']) ?></span></td>
                <td>
                  <a href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$wp['id'] ?>"><?= e($wp['text_kurz']) ?></a>
                  <div class="small">WP #<?= (int)$wp['id'] ?></div>
                </td>
                <td><?= e($wp['intervall_typ']) ?></td>
                <td><?= e($p['due']) ?></td>
                <td><?= $p['rest'] === null ? '—' : number_format((float)$p['rest'], 1, ',', '.') . ' h' ?></td>
                <td class="small">
                  <?php if (!empty($wp['last_datum'])): ?>
                    <b><?= e($wp['last_status']) ?></b> · <?= e($wp['last_datum']) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  <?php endif; ?>

<?php endif; ?>