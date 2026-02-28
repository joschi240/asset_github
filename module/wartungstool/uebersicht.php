<?php
// module/wartungstool/uebersicht.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$canSeePunkt = user_can_see($userId, 'wartungstool', 'global', null);
$canAdmin = user_can_edit($userId, 'wartungstool', 'global', null);

$assetId = (int)($_GET['asset_id'] ?? 0);

// Suche
$q = trim((string)($_GET['q'] ?? ''));
$qNorm = mb_strtolower($q);

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
      wp.soon_ratio, wp.soon_hours,
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
    if ($qNorm !== '') {
      $haystack = mb_strtolower(trim((string)($wp['text_kurz'] ?? '') . ' ' . (string)($wp['text_lang'] ?? '')));
      if ($haystack === '' || mb_strpos($haystack, $qNorm) === false) {
        continue;
      }
    }

    $interval = (float)$wp['plan_interval'];
    $soonRatio = wartung_normalize_soon_ratio(($wp['soon_ratio'] ?? null) !== null ? (float)$wp['soon_ratio'] : null);
    $soonHours = wartung_normalize_soon_hours(($wp['soon_hours'] ?? null) !== null ? (float)$wp['soon_hours'] : null);

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

    $ampel = wartung_status_from_rest($restHours, $interval, $soonRatio, $soonHours);
    $open = in_array(($ampel['type'] ?? ''), ['new', 'due', 'soon'], true);

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

$urlFor = function(array $overrides = []) use ($base, $assetId, $mode, $q) {
  $params = [
    'r' => 'wartung.uebersicht',
    'asset_id' => $assetId,
    'mode' => $mode,
  ];
  if ($q !== '') $params['q'] = $q;
  foreach ($overrides as $k => $v) {
    if ($v === null || $v === '') unset($params[$k]);
    else $params[$k] = $v;
  }
  return $base . '/app.php?' . http_build_query($params);
};
?>

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Wartung – Übersicht</h1>
    <p class="ui-page-subtitle ui-muted">Anlage wählen, offene Punkte priorisieren, dann direkt in den Wartungspunkt springen.</p>

    <div style="margin-top:8px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
      <div class="small">
        <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.dashboard">Zum Dashboard</a>
        <?php if ($canAdmin): ?>
          · <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte">Admin Wartungspunkte</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="wartung.uebersicht">
      <input type="hidden" name="mode" value="<?= e($mode) ?>">

      <div class="ui-filterbar__group" style="min-width:360px; flex:1;">
        <label for="uebersicht_asset_id">Anlage auswählen</label>
        <select id="uebersicht_asset_id" name="asset_id">
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $assetId ? 'selected' : '') ?>>
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
              <?= e(' · ' . ($a['kategorie_name'] ?: '—') . ' · Krit ' . (int)$a['kritischkeitsstufe']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="ui-filterbar__group" style="min-width:300px;">
        <label for="uebersicht_q">Suche</label>
        <input id="uebersicht_q" name="q" class="ui-input" value="<?= e($q) ?>" placeholder="Wartungspunkt suchen…">
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary ui-btn--sm" type="submit">Anwenden</button>
        <?php if ($q !== ''): ?>
          <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($urlFor(['q'=>null])) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

<?php if (!$asset): ?>
  <div class="ui-card">
    <h2>Keine Anlage gefunden</h2>
    <p class="small">Bitte Anlage auswählen.</p>
  </div>
<?php else: ?>
  <div class="ui-grid" style="display:grid; grid-template-columns: 1.7fr 0.9fr; gap: var(--s-6); align-items:start;">

    <div>
      <div class="ui-card">
        <div class="ui-card-head">
          <div class="ui-tabs">
            <a class="ui-tab <?= $mode==='offen'?'ui-tab--active':'' ?>" href="<?= e($urlFor(['mode'=>'offen'])) ?>">
              Offen <span class="ui-count ui-count--warn"><?= (int)count($openList) ?></span>
            </a>
            <a class="ui-tab <?= $mode==='alle'?'ui-tab--active':'' ?>" href="<?= e($urlFor(['mode'=>'alle'])) ?>">
              Alle <span class="ui-count"><?= (int)count($punkte) ?></span>
            </a>
          </div>
        </div>

        <div class="ui-table-wrap">
          <table class="ui-table">
            <thead>
              <tr>
                <th>Wartungspunkt</th>
                <th>Rest</th>
                <th>Intervall</th>
                <th>Fällig bei</th>
                <th>Letzter Eintrag</th>
                <th>Status</th>
                <th class="ui-th-actions">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($punkteToShow)): ?>
                <tr><td colspan="7" class="small ui-muted">Keine passenden Wartungspunkte.</td></tr>
              <?php else: ?>
                <?php foreach ($punkteToShow as $p):
                  $wp = $p['wp'];
                  $restCls = 'ui-num';
                  if ($p['rest'] !== null && (float)$p['rest'] < 0) $restCls .= ' ui-num--danger';
                ?>
                <tr>
                  <td>
                    <?php if ($canSeePunkt): ?>
                      <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$wp['id'] ?>"><?= e($wp['text_kurz']) ?></a>
                      <span class="small ui-muted"> · WP #<?= (int)$wp['id'] ?></span>
                    <?php else: ?>
                      <?= e($wp['text_kurz']) ?> <span class="small ui-muted">(WP #<?= (int)$wp['id'] ?>)</span>
                    <?php endif; ?>
                    <?php if ((int)$wp['messwert_pflicht'] === 1): ?>
                      <div class="small ui-muted" style="margin-top:2px;">Messwert Pflicht<?= $wp['einheit'] ? ' · '.e($wp['einheit']) : '' ?></div>
                    <?php endif; ?>
                  </td>

                  <td class="<?= e($restCls) ?>"><strong><?= $p['rest'] === null ? '—' : e(number_format((float)$p['rest'], 1, ',', '.') . ' h') ?></strong></td>
                  <td class="ui-num"><?= e(number_format((float)$wp['plan_interval'], 1, ',', '.')) ?> h</td>
                  <td class="small ui-muted"><?= e($p['due']) ?></td>

                  <td class="small ui-muted">
                    <?php if (!empty($wp['last_datum'])): ?>
                      <div><strong><?= e((string)$wp['last_status']) ?></strong> · <?= e((string)$wp['last_datum']) ?></div>
                      <?php if ($wp['last_bemerkung']): ?>
                        <div><?= e(short_text((string)$wp['last_bemerkung'], 80)) ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="<?= e($p['ampel']['cls']) ?>"><?= e($p['ampel']['label']) ?></span>
                    <?php if ($p['ticket_id']): ?>
                      <div style="margin-top:4px;"><span class="ui-badge">Ticket #<?= (int)$p['ticket_id'] ?></span></div>
                    <?php endif; ?>
                  </td>

                  <td class="ui-td-actions">
                    <?php if ($canSeePunkt): ?>
                      <a class="ui-btn ui-btn--sm ui-btn--primary" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$wp['id'] ?>">Öffnen</a>
                    <?php else: ?>
                      <span class="ui-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top: var(--s-4);" class="small ui-muted">
          Sortierung: offene Punkte zuerst, danach nach Reststunden.
        </div>
      </div>
    </div>

    <aside>
      <div class="ui-card" style="margin-bottom: var(--s-6);">
        <h2 style="margin:0;"><?= e(($asset['code'] ? $asset['code'].' — ' : '') . $asset['name']) ?></h2>
        <p class="small ui-muted" style="margin-top:6px;">
          <?= e((string)($asset['asset_typ'] ?: '')) ?>
          · Kategorie: <?= e((string)($asset['kategorie_name'] ?: '—')) ?>
        </p>

        <div style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Produktivstunden</span>
            <span class="ui-count"><?= e(number_format($counterHours, 1, ',', '.')) ?> h</span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Offen</span>
            <span class="ui-count ui-count--warn"><?= (int)count($openList) ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">OK</span>
            <span class="ui-count ui-count--ok"><?= (int)count($okList) ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Gesamt Punkte</span>
            <span class="ui-count"><?= (int)count($punkte) ?></span>
          </div>
        </div>
      </div>

    </aside>

  </div>

<?php endif; ?>

</div> <!-- /.ui-container -->
