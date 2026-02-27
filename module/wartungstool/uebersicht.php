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
function ratio_limit(?float $soonRatio): float {
  // soon_ratio = Anteil vom Intervall (z.B. 0.2 = 20%)
  if ($soonRatio === null) return 0.20;
  $v = (float)$soonRatio;
  if ($v <= 0) return 0.20;
  if ($v > 1.0) return 1.0; // clamp
  return $v;
}

function ampel_from_rest(?float $restHours, float $intervalHours, ?float $soonRatio = null): array {
  if ($restHours === null) return ['cls'=>'ui-badge', 'label'=>'Neu/Unbekannt'];
  if ($restHours < 0) return ['cls'=>'ui-badge ui-badge--danger','label'=>'Überfällig'];

  $ratio = $intervalHours > 0 ? ($restHours / $intervalHours) : 1.0;
  $limit = ratio_limit($soonRatio);

  if ($ratio <= $limit) return ['cls'=>'ui-badge ui-badge--warn','label'=>'Bald fällig'];
  return ['cls'=>'ui-badge ui-badge--ok','label'=>'OK'];
}

function is_open_item(?float $restHours, float $intervalHours, ?float $soonRatio = null): bool {
  // "offen" = überfällig oder bald fällig oder unbekannt (initiale Wartung)
  if ($restHours === null) return true;
  if ($restHours < 0) return true;

  $ratio = $intervalHours > 0 ? ($restHours / $intervalHours) : 1.0;
  $limit = ratio_limit($soonRatio);

  return ($ratio <= $limit);
}

function extract_ticket_marker(?string $bemerkung): ?int {
  if (!$bemerkung) return null;
  if (preg_match('/\\[#TICKET:(\\d+)\\]/', $bemerkung, $m)) {
    return (int)$m[1];
  }
  return null;
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
      wp.soon_ratio,
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

    $sr = ($wp['soon_ratio'] !== null ? (float)$wp['soon_ratio'] : null);

    $ampel = ampel_from_rest($restHours, $interval, $sr);
    $open  = is_open_item($restHours, $interval, $sr);

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

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Wartung – Übersicht</h1>
    <p class="ui-page-subtitle ui-muted">
      Anlage wählen, Wartungspunkte nach Fälligkeit prüfen. „OK“ kann optional eingeklappt werden.
    </p>
  </div>

<?php if (!$asset): ?>
  <div class="ui-card">
    <h2>Keine Anlage gefunden</h2>
    <p class="small">Bitte Anlage auswählen.</p>
  </div>
<?php else: ?>

  <div class="ui-card ui-filterbar">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="wartung.uebersicht">

      <div class="ui-filterbar__group">
        <label for="uebersicht_asset_id">Anlage</label>
        <select id="uebersicht_asset_id" name="asset_id">
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $assetId ? 'selected' : '') ?>>
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
              <?= e(' · ' . ($a['kategorie_name'] ?: '—') . ' · Krit ' . (int)$a['kritischkeitsstufe']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ui-filterbar__group">
        <label for="uebersicht_mode">Anzeige</label>
        <select id="uebersicht_mode" name="mode">
          <option value="offen" <?= $mode==='offen' ? 'selected' : '' ?>>nur offene</option>
          <option value="alle" <?= $mode==='alle' ? 'selected' : '' ?>>alle</option>
        </select>
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary" type="submit">Filter anwenden</button>
      </div>
    </form>
  </div>

  <div class="ui-card">
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

  <div class="ui-card">
    <h2><?= $mode === 'offen' ? 'Offene Wartungen' : 'Wartungspunkte (sortiert)' ?></h2>

    <?php if (empty($punkteToShow)): ?>
      <p class="small">Keine passenden Wartungspunkte.</p>
    <?php else: ?>
      <table class="ui-table">
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
            <td><span class="<?= e($p['ampel']['cls']) ?>"><?= e($p['ampel']['label']) ?></span></td>

            <td>
              <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$wp['id'] ?>">
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

            <td class="small ui-last-entry">
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
                  <div><span class="ui-badge">Ticket <?= (int)$p['ticket_id'] ?></span></div>
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

<?php endif; ?>

</div>