<?php
// module/wartungstool/dashboard.php  (INNER VIEW!)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);

// Rechte
$canSeePunkt = user_can_see($userId, 'wartungstool', 'global', null);
$canSeeUebersicht = user_can_see($userId, 'wartungstool', 'global', null);
$canAdmin = user_can_edit($userId, 'wartungstool', 'global', null);

// Filter: all | due | soon | critical
$f = (string)($_GET['f'] ?? 'all');
$allowedF = ['all','due','soon','critical'];
if (!in_array($f, $allowedF, true)) $f = 'all';

// Search (clientseitig in PHP): sucht in Code/Name/Kategorie/WP
$q = trim((string)($_GET['q'] ?? ''));
$qNorm = mb_strtolower($q);

// Assets laden
$maschinen = db_all("
  SELECT a.id, a.code, a.name, a.prioritaet, a.aktiv,
         k.name AS kategorie_name, k.kritischkeitsstufe
  FROM core_asset a
  LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
  WHERE a.aktiv=1
  ORDER BY COALESCE(k.kritischkeitsstufe,1) DESC, a.prioritaet DESC, a.name ASC
");

function rest_label(?float $rest): string {
  if ($rest === null) return '—';
  return number_format((float)$rest, 1, ',', '.') . ' h';
}

function ui_badge_for(?float $rest, float $interval, ?float $soonRatio = null): array {

  if ($rest === null)
    return ['cls'=>'ui-badge', 'label'=>'Keine Punkte', 'type'=>'none'];

  if ($rest < 0)
    return ['cls'=>'ui-badge ui-badge--danger','label'=>'Überfällig', 'type'=>'due'];

  $ratioLimit = ($soonRatio !== null && $soonRatio > 0)
    ? $soonRatio
    : 0.20; // Fallback

  $ratio = $interval > 0 ? ($rest / $interval) : 1.0;

  if ($ratio <= $ratioLimit)
    return ['cls'=>'ui-badge ui-badge--warn','label'=>'Bald fällig', 'type'=>'soon'];

  return ['cls'=>'ui-badge ui-badge--ok','label'=>'OK', 'type'=>'ok'];
}

function trend_badge(string $trend): array {
  if ($trend === '▲') return ['cls'=>'ui-badge ui-badge--ok', 'label'=>'▲ steigend'];
  if ($trend === '▼') return ['cls'=>'ui-badge ui-badge--danger', 'label'=>'▼ fallend'];
  return ['cls'=>'ui-badge', 'label'=>'➝ stabil'];
}

function pct_label(?float $pct): string {
  if ($pct === null) return '—';
  // Cap-Anzeige nicer
  if ($pct >= 999.0) return '≥ 999%';
  if ($pct <= -999.0) return '≤ -999%';
  return number_format((float)$pct, 1, ',', '.') . '%';
}

// Dashboard-Metriken pro Asset
function berechneDashboard(int $assetId): array {
  // 1) Counter (Produktivstunden)
  $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
  $gesamtStd = (float)($row['h'] ?? 0);

  // 2) 28 Tage Laufzeit (für Wochen-Schnitt)
  $row = db_one("
      SELECT IFNULL(SUM(run_seconds)/3600,0) AS h
      FROM core_runtime_agg_day
      WHERE asset_id=? AND day >= CURDATE() - INTERVAL 28 DAY
    ", [$assetId]);
  $std4wo = (float)($row['h'] ?? 0);
  $wochenschnitt = $std4wo / 4.0;

  // 3) Trend: letzte 14 Tage vs davorliegende 14 Tage
  $row = db_one("
      SELECT IFNULL(SUM(run_seconds)/3600,0) AS h
      FROM core_runtime_agg_day
      WHERE asset_id=? AND day >= CURDATE() - INTERVAL 14 DAY
    ", [$assetId]);
  $h14_new = (float)($row['h'] ?? 0);

  $row = db_one("
      SELECT IFNULL(SUM(run_seconds)/3600,0) AS h
      FROM core_runtime_agg_day
      WHERE asset_id=?
        AND day <  CURDATE() - INTERVAL 14 DAY
        AND day >= CURDATE() - INTERVAL 28 DAY
    ", [$assetId]);
  $h14_old = (float)($row['h'] ?? 0);

  $trend = '➝';
  if ($h14_old > 0) {
    if ($h14_new > $h14_old * 1.10) $trend = '▲';
    elseif ($h14_new < $h14_old * 0.90) $trend = '▼';
  } elseif ($h14_new > 0) {
    $trend = '▲';
  }

  // Trend robust
  $trendPct = null;
  $trendMode = 'pct'; // 'pct' | 'neu'
  $deltaH = $h14_new - $h14_old;

  if ($h14_old < 1.0) {
    if ($h14_new >= 1.0) {
      $trendMode = 'neu';
      $trendPct = null;
    } else {
      $trendMode = 'pct';
      $trendPct = 0.0;
    }
  } else {
    $trendMode = 'pct';
    $trendPct = ($deltaH / $h14_old) * 100.0;
    if ($trendPct > 999.0) $trendPct = 999.0;
    if ($trendPct < -999.0) $trendPct = -999.0;
  }

  // 4) Nächst fälliger Wartungspunkt (PRODUKTIV!)
  $punkt = db_one("
    SELECT
      wp.id,
      wp.text_kurz,
      wp.plan_interval,
      wp.letzte_wartung,
      wp.soon_ratio
    FROM wartungstool_wartungspunkt wp
    WHERE
      wp.asset_id = ?
      AND wp.aktiv = 1
      AND wp.intervall_typ = 'produktiv'
      AND wp.letzte_wartung IS NOT NULL
    ORDER BY (wp.letzte_wartung + wp.plan_interval) ASC
    LIMIT 1
  ", [$assetId]);

  $rest = null;
  $wpId = null;
  $wpText = null;
  $wpInterval = null;
  $soonRatio = null;

  if ($punkt) {
    $wpId = (int)$punkt['id'];
    $wpText = (string)$punkt['text_kurz'];
    $wpInterval = (float)$punkt['plan_interval'];
    $soonRatio = ($punkt['soon_ratio'] !== null ? (float)$punkt['soon_ratio'] : null);

    $dueAt = (float)$punkt['letzte_wartung'] + (float)$punkt['plan_interval'];
    $rest = $dueAt - $gesamtStd;
  }

  // 5) Prognose KW
  $kw = '-';
  if ($rest !== null) {
    if ($rest < 0) {
      $kw = 'überfällig';
    } elseif ($wochenschnitt > 0) {
      $weeksLeft = $rest / $wochenschnitt;
      $daysLeft = (int)round($weeksLeft * 7);
      $dueDate = strtotime("+{$daysLeft} days");
      $kw = $dueDate ? ('KW ' . date('W', $dueDate)) : '-';
    }
  }

  return [
    'rest' => ($rest !== null ? (float)$rest : null),
    'laufzeit' => $gesamtStd,
    'kw' => $kw,
    'schnitt' => round($wochenschnitt, 1),
    'trend' => $trend,
    'trend_pct' => $trendPct,
    'trend_mode' => $trendMode,
    'trend_delta_h' => $deltaH,
    'h14_new' => $h14_new,
    'h14_old' => $h14_old,
    'h28_total' => $std4wo,
    'wp_id' => $wpId,
    'wp_text' => $wpText,
    'wp_interval' => $wpInterval,
    'soon_ratio' => $soonRatio,
  ];
}

function dash_url(string $base, string $f, string $q, array $params = []): string {
  $query = array_merge(['r' => 'wartung.dashboard', 'f' => $f], $params);
  if ($q !== '') $query['q'] = $q;
  return $base . '/app.php?' . http_build_query($query);
}

function renderSimpleTable(array $rows, string $title, string $base, bool $canSeePunkt, string $f, string $q): void {
  ?>
  <div class="ui-card" style="margin-bottom: var(--s-6);">
    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <h2 style="margin:0;"><?= e($title) ?></h2>
      <div class="ui-muted small"><?= (int)count($rows) ?> Anlagen</div>
    </div>

    <div style="margin-top: var(--s-4);" class="ui-table-wrap">
      <table class="ui-table">
        <thead>
          <tr>
            <th scope="col" class="ui-col-ampel">Status</th>
            <th scope="col">Anlage</th>
            <th scope="col">Nächster Punkt</th>
            <th scope="col" class="ui-col-rest">Rest</th>
            <th scope="col" class="ui-th-actions ui-col-actions">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="5" class="small ui-muted">Keine Treffer.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $interval = isset($r['wp_interval']) && $r['wp_interval'] !== null ? (float)$r['wp_interval'] : 0.0;
                $ampel = ui_badge_for($r['rest'], $interval, $r['soon_ratio'] ?? null);
                $assetLabel = (($r['code'] ? $r['code'].' — ' : '') . ($r['name'] ?? ''));
                $assetSearch = (string)($r['code'] ?: ($r['name'] ?? ''));
              ?>
              <tr>
                <td>
                  <span class="<?= e($ampel['cls']) ?>"><?= e($ampel['label']) ?></span>
                </td>

                <td>
                  <div>
                    <strong>
                      <a class="ui-link" href="<?= e(dash_url($base, $f, $q, ['q' => $assetSearch])) ?>">
                        <?= e($assetLabel) ?>
                      </a>
                    </strong>
                  </div>
                  <div class="small ui-muted">
                    <?= e($r['kategorie_name'] ?: '—') ?>
                    <?= !empty($r['kritischkeitsstufe']) ? ' · Krit ' . e((string)$r['kritischkeitsstufe']) : '' ?>
                  </div>
                </td>

                <td class="ui-last-entry">
                  <?php if (!empty($r['wp_id'])): ?>
                    <?php if ($canSeePunkt): ?>
                      <div>
                        <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$r['wp_id'] ?>">
                          <?= e((string)$r['wp_text']) ?>
                        </a>
                        <span class="small ui-muted"> · WP #<?= (int)$r['wp_id'] ?></span>
                      </div>
                    <?php else: ?>
                      <div><?= e((string)$r['wp_text']) ?> <span class="small ui-muted">(WP #<?= (int)$r['wp_id'] ?>)</span></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="small ui-muted">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <strong><?= e(rest_label($r['rest'])) ?></strong>
                </td>

                <td class="ui-td-actions">
                  <?php if (!empty($r['wp_id']) && $canSeePunkt): ?>
                    <a class="ui-btn ui-btn--sm ui-btn--primary" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$r['wp_id'] ?>">
                      Öffnen
                    </a>
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
  </div>
  <?php
}

// Aufteilen nach Kritikalität
$wichtig = [];
$unwichtig = [];

foreach ($maschinen as $m) {
  $daten = berechneDashboard((int)$m['id']);
  $row = array_merge($m, $daten);

  if ((int)($m['kritischkeitsstufe'] ?? 1) >= 3) $wichtig[] = $row;
  else $unwichtig[] = $row;
}

// Sortierung: kleinster Rest zuerst
$sortFn = function($a, $b) {
  $ar = $a['rest']; $br = $b['rest'];
  if ($ar === null && $br === null) return 0;
  if ($ar === null) return 1;
  if ($br === null) return -1;
  return $ar <=> $br;
};
usort($wichtig, $sortFn);
usort($unwichtig, $sortFn);

// KPI counts + Filter-Logik
$kritischCount = count($wichtig);
$weitereCount = count($unwichtig);
$gesamtCount = $kritischCount + $weitereCount;

$ueberfaellig = 0;
$bald = 0;

$allRows = array_merge($wichtig, $unwichtig);

foreach ($allRows as $r) {
  $interval = isset($r['wp_interval']) && $r['wp_interval'] !== null ? (float)$r['wp_interval'] : 0.0;
  $ampel = ui_badge_for($r['rest'], $interval, $r['soon_ratio'] ?? null);
  if ($ampel['type'] === 'due') $ueberfaellig++;
  if ($ampel['type'] === 'soon') $bald++;
}

// Apply filter
$filterFn = function($r) use ($f, $qNorm) {
  $krit = (int)($r['kritischkeitsstufe'] ?? 1);
  $interval = isset($r['wp_interval']) && $r['wp_interval'] !== null ? (float)$r['wp_interval'] : 0.0;
  $ampel = ui_badge_for($r['rest'], $interval, $r['soon_ratio'] ?? null);

  // f-Filter
  if ($f === 'critical' && $krit < 3) return false;
  if ($f === 'due' && $ampel['type'] !== 'due') return false;
  if ($f === 'soon' && $ampel['type'] !== 'soon') return false;

  // q-Filter (substring match)
  if ($qNorm !== '') {
    $hay = [
      (string)($r['code'] ?? ''),
      (string)($r['name'] ?? ''),
      (string)($r['kategorie_name'] ?? ''),
      (string)($r['wp_text'] ?? ''),
    ];
    $blob = mb_strtolower(implode(' | ', $hay));
    if (mb_strpos($blob, $qNorm) === false) return false;
  }

  return true;
};
// gefilterte Listen
$wichtigF = array_values(array_filter($wichtig, $filterFn));
$unwichtigF = array_values(array_filter($unwichtig, $filterFn));

// Trend Insights: Δh primary
$rowsWithTrend = array_filter($allRows, fn($r) => isset($r['trend_delta_h']));

$topUp = $rowsWithTrend;
usort($topUp, function($a, $b) {
  // 'neu' zuerst nach Δh, sonst nach %
  $am = $a['trend_mode'] ?? 'pct';
  $bm = $b['trend_mode'] ?? 'pct';

  if ($am === 'neu' && $bm !== 'neu') return -1;
  if ($bm === 'neu' && $am !== 'neu') return 1;

  if ($am === 'neu' && $bm === 'neu') {
    return ((float)($b['trend_delta_h'] ?? 0)) <=> ((float)($a['trend_delta_h'] ?? 0));
  }

  return ((float)($b['trend_pct'] ?? 0)) <=> ((float)($a['trend_pct'] ?? 0));
});
$topUp = array_slice($topUp, 0, 5);

$topDown = $rowsWithTrend;
usort($topDown, function($a, $b) {
  $am = $a['trend_mode'] ?? 'pct';
  $bm = $b['trend_mode'] ?? 'pct';

  // 'neu' ist quasi steigend => bei fallend ans Ende
  if ($am === 'neu' && $bm !== 'neu') return 1;
  if ($bm === 'neu' && $am !== 'neu') return -1;

  return ((float)($a['trend_pct'] ?? 0)) <=> ((float)($b['trend_pct'] ?? 0));
});
$topDown = array_slice($topDown, 0, 5);

// Global Trend Summary
$sumNew = 0.0; $sumOld = 0.0;
foreach ($allRows as $r) {
  $sumNew += (float)($r['h14_new'] ?? 0);
  $sumOld += (float)($r['h14_old'] ?? 0);
}
$globalDeltaH = $sumNew - $sumOld;

$globalPct = null;
$globalMode = 'pct';
if ($sumOld < 10.0) {
  if ($sumNew >= 10.0) {
    $globalMode = 'neu';
    $globalPct = null;
  } else {
    $globalMode = 'pct';
    $globalPct = 0.0;
  }
} else {
  $globalMode = 'pct';
  $globalPct = ($globalDeltaH / $sumOld) * 100.0;
  if ($globalPct > 999.0) $globalPct = 999.0;
  if ($globalPct < -999.0) $globalPct = -999.0;
}

$globalTrend = '➝';
if ($sumOld > 0) {
  if ($sumNew > $sumOld * 1.10) $globalTrend = '▲';
  elseif ($sumNew < $sumOld * 0.90) $globalTrend = '▼';
} elseif ($sumNew > 0) {
  $globalTrend = '▲';
}
$gBadge = trend_badge($globalTrend);

// KPI URLs (behält q)
$mkUrl = function(string $fTarget) use ($base, $q) {
  $url = $base . '/app.php?r=wartung.dashboard&f=' . urlencode($fTarget);
  if ($q !== '') $url .= '&q=' . urlencode($q);
  return $url;
};

// generischer URL-Builder (behält f + q)
$mkDashUrl = function(array $params = []) use ($base, $f, $q) {
  $query = array_merge(['r' => 'wartung.dashboard', 'f' => $f], $params);
  if ($q !== '') $query['q'] = $q;
  return $base . '/app.php?' . http_build_query($query);
};
?>

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Wartung – Dashboard</h1>
    <p class="ui-page-subtitle ui-muted">
      Übersicht nach Kritikalität · Quelle: <code>core_runtime_counter</code> · Trend: <code>core_runtime_agg_day</code> (4-Wochen Vergleich 14/14 Tage)
    </p>

    <div style="margin-top: 8px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
      <div class="small">
        <?php if ($canSeeUebersicht): ?>
          <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.uebersicht">Zur Anlagen-Übersicht</a>
        <?php endif; ?>
        <?php if ($canAdmin): ?>
          · <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte">Admin Wartungspunkte</a>
        <?php endif; ?>
      </div>

      <form method="get" action="<?= e($base) ?>/app.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="r" value="wartung.dashboard">
        <input type="hidden" name="f" value="<?= e($f) ?>">

        <label class="sr-only" for="dash_q">Suche</label>
        <input
          id="dash_q"
          name="q"
          value="<?= e($q) ?>"
          placeholder="Suche: BAZ-18, Kompressor, Messwert …"
          class="ui-input"
          style="min-width: 320px;"
        >

        <button class="ui-btn ui-btn--primary ui-btn--sm" type="submit">Suchen</button>

        <?php if ($q !== ''): ?>
          <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($base) ?>/app.php?r=wartung.dashboard&f=<?= urlencode($f) ?>">Zurücksetzen</a>
        <?php endif; ?>
      </form>
    </div>

    <div style="margin-top: 10px; display:flex; gap:8px; flex-wrap:wrap;">
      <?php if (!$canSeePunkt): ?>
        <span class="ui-badge">Detailansicht gesperrt</span>
      <?php endif; ?>
      <?php if ($canAdmin): ?>
        <span class="ui-badge ui-badge--ok">Admin</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPI row clickable -->
  <div class="ui-kpi-row">
    <a class="ui-kpi ui-kpi--danger" style="text-decoration:none;" href="<?= e($mkUrl('due')) ?>">
      <div class="ui-kpi__label">Überfällig</div>
      <div class="ui-kpi__value"><?= (int)$ueberfaellig ?></div>
    </a>

    <a class="ui-kpi ui-kpi--warn" style="text-decoration:none;" href="<?= e($mkUrl('soon')) ?>">
      <div class="ui-kpi__label">Bald fällig</div>
      <div class="ui-kpi__value"><?= (int)$bald ?></div>
    </a>

    <a class="ui-kpi" style="text-decoration:none;" href="<?= e($mkUrl('critical')) ?>">
      <div class="ui-kpi__label">Kritische Anlagen</div>
      <div class="ui-kpi__value"><?= (int)$kritischCount ?></div>
    </a>

    <a class="ui-kpi" style="text-decoration:none;" href="<?= e($mkUrl('all')) ?>">
      <div class="ui-kpi__label">Gesamtanlagen</div>
      <div class="ui-kpi__value"><?= (int)$gesamtCount ?></div>
    </a>
  </div>

  <!-- 2-Spalten Layout -->
  <div class="ui-grid" style="display:grid; grid-template-columns: 1.6fr 0.9fr; gap: var(--s-6); align-items:start;">
    <div>
      <?php renderSimpleTable($wichtigF, 'Kritisch (Kritikalität 3)', $base, $canSeePunkt, $f, $q); ?>

      <div class="ui-card">
        <details>
          <summary style="cursor:pointer; font-weight:700;">
            Weitere Anlagen (Kritikalität 1–2) · <?= e(count($unwichtigF)) ?> Anlagen
            <?php if ($f !== 'all'): ?>
              <span class="small ui-muted" style="font-weight:400;">(Filter aktiv)</span>
            <?php endif; ?>
          </summary>
          <div style="margin-top: var(--s-4);">
            <?php renderSimpleTable($unwichtigF, 'Weitere Anlagen (Kritikalität 1–2)', $base, $canSeePunkt, $f, $q); ?>
          </div>
        </details>
      </div>
    </div>

    <aside>
      <div class="ui-card" style="margin-bottom: var(--s-6);">
        <h2 style="margin:0;">Trend – 4 Wochen</h2>
        <p class="small ui-muted" style="margin-top:6px;">
          Vergleich: letzte 14 Tage vs. davorliegende 14 Tage (Proxy für 4-Wochen Entwicklung).
        </p>

        <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <span class="<?= e($gBadge['cls']) ?>"><?= e($gBadge['label']) ?></span>
        </div>

        <div style="margin-top:10px;">
          <div style="font-size:20px; font-weight:800;">
            Δ <?= number_format((float)$globalDeltaH, 1, ',', '.') ?> h
          </div>
          <div class="small ui-muted" style="margin-top:4px;">
            <?php if ($globalMode === 'neu'): ?>
              Gesamt: <b>Neu</b>
            <?php else: ?>
              Gesamt: <b><?= e(pct_label($globalPct)) ?></b>
            <?php endif; ?>
            · Neu: <?= number_format($sumNew, 1, ',', '.') ?> h
            · Alt: <?= number_format($sumOld, 1, ',', '.') ?> h
          </div>
        </div>
      </div>

      <div class="ui-card" style="margin-bottom: var(--s-6);">
        <h2 style="margin:0;">Top steigend</h2>
        <p class="small ui-muted" style="margin-top:6px;">Δh als Primärwert, % sekundär (stabiler).</p>

        <?php if (empty($topUp)): ?>
          <p class="small ui-muted" style="margin-top:10px;">Keine Daten.</p>
        <?php else: ?>
          <div style="margin-top:10px; display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($topUp as $r): ?>
              <?php $b = trend_badge((string)$r['trend']); ?>
              <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                <div style="min-width:0;">
                  <div>
                    <strong>
                      <a class="ui-link" href="<?= e($mkDashUrl(['q' => $r['code'] ?: $r['name']])) ?>">
                        <?= e(($r['code'] ? $r['code'].' — ' : '') . $r['name']) ?>
                      </a>
                    </strong>
                  </div>
                  <div class="small ui-muted"><?= e($r['kategorie_name'] ?: '—') ?></div>
                </div>
                <div style="text-align:right; white-space:nowrap;">
                  <div><span class="<?= e($b['cls']) ?>"><?= e($b['label']) ?></span></div>
                  <div style="font-weight:800;">Δ <?= number_format((float)($r['trend_delta_h'] ?? 0), 1, ',', '.') ?> h</div>
                  <div class="small ui-muted">
                    <?php if (($r['trend_mode'] ?? 'pct') === 'neu'): ?>
                      Alt &lt; 1 h (Proxy)
                    <?php else: ?>
                      <?= e(pct_label((float)($r['trend_pct'] ?? 0))) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="ui-card">
        <h2 style="margin:0;">Top fallend</h2>
        <p class="small ui-muted" style="margin-top:6px;">Δh als Primärwert, % sekundär.</p>

        <?php if (empty($topDown)): ?>
          <p class="small ui-muted" style="margin-top:10px;">Keine Daten.</p>
        <?php else: ?>
          <div style="margin-top:10px; display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($topDown as $r): ?>
              <?php $b = trend_badge((string)$r['trend']); ?>
              <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                <div style="min-width:0;">
                  <div>
                    <strong>
                      <a class="ui-link" href="<?= e($mkDashUrl(['q' => $r['code'] ?: $r['name']])) ?>">
                        <?= e(($r['code'] ? $r['code'].' — ' : '') . $r['name']) ?>
                      </a>
                    </strong>
                  </div>
                  <div class="small ui-muted"><?= e($r['kategorie_name'] ?: '—') ?></div>
                </div>
                <div style="text-align:right; white-space:nowrap;">
                  <div><span class="<?= e($b['cls']) ?>"><?= e($b['label']) ?></span></div>
                  <div style="font-weight:800;">Δ <?= number_format((float)($r['trend_delta_h'] ?? 0), 1, ',', '.') ?> h</div>
                  <div class="small ui-muted">
                    <?php if (($r['trend_mode'] ?? 'pct') === 'neu'): ?>
                      —
                    <?php else: ?>
                      <?= e(pct_label((float)($r['trend_pct'] ?? 0))) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </aside>
  </div>

</div>
