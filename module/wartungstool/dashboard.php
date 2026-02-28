<?php
// module/wartungstool/dashboard.php  (INNER VIEW!)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);

// Rechte
$canSeePunkt      = user_can_see($userId, 'wartungstool', 'global', null);
$canSeeUebersicht = user_can_see($userId, 'wartungstool', 'global', null);
$canAdmin         = user_can_edit($userId, 'wartungstool', 'global', null);

// Operative Scope: heute | woche | alle
$scope = (string)($_GET['scope'] ?? 'heute');
$allowedScopes = ['heute','woche','alle'];
if (!in_array($scope, $allowedScopes, true)) $scope = 'heute';

// Statusfilter: all | due | soon | ok | new | planned
$f = (string)($_GET['f'] ?? 'all');
$allowedF = ['all','due','soon','ok','new','planned'];
if (!in_array($f, $allowedF, true)) $f = 'all';

// Search
$q = trim((string)($_GET['q'] ?? ''));
$qNorm = mb_strtolower($q);

// ---------- Helpers ----------

function rest_label(?float $rest): string {
  if ($rest === null) return '—';
  return number_format((float)$rest, 1, ',', '.') . ' h';
}

function dash_url(string $base, array $params): string {
  $query = array_merge(['r' => 'wartung.dashboard'], $params);
  return $base . '/app.php?' . http_build_query($query);
}

function tab_count_cls(string $type): string {
  if ($type === 'due')  return 'ui-tab__count ui-tab__count--danger';
  if ($type === 'soon') return 'ui-tab__count ui-tab__count--warn';
  if ($type === 'ok')   return 'ui-tab__count ui-tab__count--ok';
  if ($type === 'planned') return 'ui-tab__count ui-tab__count--info';
  return 'ui-tab__count';
}

// ---------- Data loading ----------

$wps = db_all("
  SELECT
    wp.id,
    wp.asset_id,
    wp.text_kurz,
    wp.plan_interval,
    wp.intervall_typ,
    wp.datum,
    wp.letzte_wartung,
    wp.soon_ratio,
    wp.soon_hours,
    wp.planned_at,
    wp.planned_text,

    a.code AS asset_code,
    a.name AS asset_name,

    k.name AS kategorie_name,
    k.kritischkeitsstufe,

    COALESCE(c.productive_hours, 0) AS productive_hours
  FROM wartungstool_wartungspunkt wp
  JOIN core_asset a ON a.id = wp.asset_id
  LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
  LEFT JOIN core_runtime_counter c ON c.asset_id = a.id
  WHERE wp.aktiv = 1 AND a.aktiv = 1
  ORDER BY a.name ASC, wp.text_kurz ASC
");

$nowTs = time();

$rows = [];
foreach ($wps as $wp) {
  $interval = (float)($wp['plan_interval'] ?? 0);
  $rest = null;

  if (($wp['intervall_typ'] ?? '') === 'produktiv') {
    $lw = $wp['letzte_wartung'];
    if ($lw !== null && $interval > 0) {
      $dueAt = (float)$lw + $interval;
      $rest = $dueAt - (float)($wp['productive_hours'] ?? 0);
    }
  } else {
    // zeit
    $d = $wp['datum'];
    if ($d !== null && $interval > 0) {
      $dueTs = strtotime((string)$d);
      if ($dueTs !== false) {
        $dueTs = $dueTs + (int)round($interval * 3600);
        $rest = ($dueTs - $nowTs) / 3600;
      }
    }
  }

  $st = wartung_status_from_rest(
    $rest,
    $interval,
    $wp['soon_ratio'] !== null ? (float)$wp['soon_ratio'] : null,
    $wp['soon_hours'] !== null ? (float)$wp['soon_hours'] : null
  );

  $assetLabel = trim(((string)($wp['asset_code'] ?? '')));
  $assetName  = trim(((string)($wp['asset_name'] ?? '')));
  $wpText     = (string)($wp['text_kurz'] ?? '');

  // Search filter
  if ($qNorm !== '') {
    $blob = mb_strtolower($assetLabel . ' | ' . $assetName . ' | ' . (string)($wp['kategorie_name'] ?? '') . ' | ' . $wpText);
    if (mb_strpos($blob, $qNorm) === false) continue;
  }

  // Scope filter (operativ)
  $limit = null;
  if ($scope === 'heute') $limit = 8.0;
  if ($scope === 'woche') $limit = 40.0;

  if ($limit !== null) {
    if ($rest === null) {
      // in operativem Scope nicht anzeigen
      continue;
    }
    if (!($rest < 0 || $rest <= $limit)) {
      continue;
    }
  }

  // Status filter + Geplant-Filter (Flag)
  if ($f === 'planned') {
    if (($wp['planned_at'] ?? null) === null) continue;
  } else {
    if ($f !== 'all' && $st['type'] !== $f) continue;
  }

  $rows[] = [
    'wp_id' => (int)$wp['id'],
    'asset_code' => $assetLabel,
    'asset_name' => $assetName,
    'kategorie_name' => (string)($wp['kategorie_name'] ?? ''),
    'krit' => (int)($wp['kritischkeitsstufe'] ?? 1),
    'wp_text' => $wpText,
    'rest' => $rest,
    'interval' => $interval,
    'letzte' => ($wp['intervall_typ'] === 'produktiv') ? ($wp['letzte_wartung'] !== null ? (float)$wp['letzte_wartung'] : null) : null,
    'datum' => ($wp['intervall_typ'] === 'zeit') ? (string)($wp['datum'] ?? '') : '',
    'intervall_typ' => (string)($wp['intervall_typ'] ?? ''),
    'status' => $st,
    'planned_at' => $wp['planned_at'] ?? null,
    'planned_text' => (string)($wp['planned_text'] ?? ''),
    'planned' => ($wp['planned_at'] ?? null) !== null,
  ];
}

// Sort: due -> soon -> ok -> new, dann rest ASC (null last)
$prio = ['due'=>1,'soon'=>2,'ok'=>3,'new'=>4];
usort($rows, function($a, $b) use ($prio) {
  $pa = $prio[$a['status']['type']] ?? 9;
  $pb = $prio[$b['status']['type']] ?? 9;
  if ($pa !== $pb) return $pa <=> $pb;

  $ar = $a['rest'];
  $br = $b['rest'];
  if ($ar === null && $br === null) return 0;
  if ($ar === null) return 1;
  if ($br === null) return -1;
  return $ar <=> $br;
});

// Counts innerhalb Scope + Search (auf Basis der angezeigten Rows)
$counts = ['due'=>0,'soon'=>0,'ok'=>0,'new'=>0,'planned'=>0,'all'=>0];
foreach ($rows as $r) {
  $t = $r['status']['type'];
  if (isset($counts[$t])) $counts[$t]++;
  if (!empty($r['planned'])) $counts['planned']++;
  $counts['all']++;
}

// Sidebar: letzte Protokolle (Wartung)
$last = db_all("
  SELECT
    p.id,
    p.datum,
    p.status,
    p.user_id,
    COALESCE(u.anzeigename, u.benutzername) AS user_name,
    wp.id AS wp_id,
    wp.text_kurz,
    a.code AS asset_code
  FROM wartungstool_protokoll p
  JOIN wartungstool_wartungspunkt wp ON wp.id = p.wartungspunkt_id
  JOIN core_asset a ON a.id = wp.asset_id
  LEFT JOIN core_user u ON u.id = p.user_id
  ORDER BY p.id DESC
  LIMIT 20
");

/**
 * Sidebar Quickinfo:
 * - Gruppiert nach Asset (wenn viele Aktionen an derselben Maschine passieren)
 * - Zeigt max. 6 Gruppen
 */
$lastGroups = [];
$lastOrder  = [];

foreach ($last as $x) {
  $asset = (string)($x['asset_code'] ?? '');
  if ($asset === '') $asset = '—';

  if (!isset($lastGroups[$asset])) {
    $lastGroups[$asset] = [
      'asset_code' => $asset,
      'latest_dt'  => (string)($x['datum'] ?? ''),
      'latest_user'=> (string)($x['user_name'] ?? '—'),
      'items'      => [],
      'has_warn'   => false,
    ];
    $lastOrder[] = $asset;
  }

  $st = (string)($x['status'] ?? '');
  if ($st === 'abweichung') $lastGroups[$asset]['has_warn'] = true;

  $lastGroups[$asset]['items'][] = [
    'wp_id' => (int)($x['wp_id'] ?? 0),
    'text'  => (string)($x['text_kurz'] ?? ''),
    'dt'    => (string)($x['datum'] ?? ''),
    'user'  => (string)($x['user_name'] ?? '—'),
    'status'=> $st,
  ];
}

// Begrenzen auf 6 Gruppen (in Reihenfolge der neuesten Aktion)
$lastOrder = array_slice($lastOrder, 0, 6);
$lastGrouped = [];
foreach ($lastOrder as $asset) {
  $lastGrouped[] = $lastGroups[$asset];
}

// URLs
$mkScope = function(string $scopeTarget) use ($base, $f, $q) {
  $params = ['scope'=>$scopeTarget, 'f'=>$f];
  if ($q !== '') $params['q'] = $q;
  return dash_url($base, $params);
};

$mkFilter = function(string $fTarget) use ($base, $scope, $q) {
  $params = ['scope'=>$scope, 'f'=>$fTarget];
  if ($q !== '') $params['q'] = $q;
  return dash_url($base, $params);
};
?>

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Wartung – Dashboard</h1>
    <p class="ui-page-subtitle ui-muted">Operative Aufgabenliste (Werkhalle) · Überfällig & bald fällig zuerst</p>

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
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <input type="hidden" name="f" value="<?= e($f) ?>">

        <input
          id="dash_q"
          name="q"
          value="<?= e($q) ?>"
          placeholder="Suchen… (CNC-01, Kompressor, Filter, …)"
          class="ui-input"
          style="min-width: 320px;"
        >

        <button class="ui-btn ui-btn--primary ui-btn--sm" type="submit">Anwenden</button>

        <?php if ($q !== ''): ?>
          <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e(dash_url($base, ['scope'=>$scope, 'f'=>$f])) ?>">Reset</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- KPI row clickable -->
  <?php if (!empty($_GET['kpi'])): ?>
  <div class="ui-kpi-row">
    <a class="ui-kpi ui-kpi--danger <?= $f==='due' ? 'ui-kpi--active' : '' ?>" style="text-decoration:none;" href="<?= e($mkFilter('due')) ?>">
      <div class="ui-kpi__label">Überfällig</div>
      <div class="ui-kpi__value"><?= (int)$counts['due'] ?></div>
    </a>

    <a class="ui-kpi ui-kpi--warn <?= $f==='soon' ? 'ui-kpi--active' : '' ?>" style="text-decoration:none;" href="<?= e($mkFilter('soon')) ?>">
      <div class="ui-kpi__label">Bald fällig</div>
      <div class="ui-kpi__value"><?= (int)$counts['soon'] ?></div>
    </a>

    <a class="ui-kpi ui-kpi--ok <?= $f==='ok' ? 'ui-kpi--active' : '' ?>" style="text-decoration:none;" href="<?= e($mkFilter('ok')) ?>">
      <div class="ui-kpi__label">OK</div>
      <div class="ui-kpi__value"><?= (int)$counts['ok'] ?></div>
    </a>

    <a class="ui-kpi <?= $f==='all' ? 'ui-kpi--active' : '' ?>" style="text-decoration:none;" href="<?= e($mkFilter('all')) ?>">
      <div class="ui-kpi__label">Gesamt</div>
      <div class="ui-kpi__value"><?= (int)$counts['all'] ?></div>
    </a>
  </div>
<?php endif; ?>
  <div class="ui-grid" style="display:grid; grid-template-columns: 1.7fr 0.9fr; gap: var(--s-6); align-items:start;">

    <div>
      <div class="ui-card">

        <div class="ui-card-head">
          <div class="ui-tabs">
            <a class="ui-tab <?= $scope==='heute'?'ui-tab--active':'' ?>" href="<?= e($mkScope('heute')) ?>">Heute</a>
            <a class="ui-tab <?= $scope==='woche'?'ui-tab--active':'' ?>" href="<?= e($mkScope('woche')) ?>">Diese Woche</a>
            <a class="ui-tab <?= $scope==='alle'?'ui-tab--active':'' ?>" href="<?= e($mkScope('alle')) ?>">Alle</a>
          </div>

          <div class="ui-tabs" style="justify-content:flex-end;">
            <a class="ui-tab <?= $f==='all'?'ui-tab--active':'' ?>" href="<?= e($mkFilter('all')) ?>">Alle <span class="<?= e(tab_count_cls('all')) ?>"><?= (int)$counts['all'] ?></span></a>
            <a class="ui-tab <?= $f==='due'?'ui-tab--active':'' ?>" href="<?= e($mkFilter('due')) ?>">Überfällig <span class="<?= e(tab_count_cls('due')) ?>"><?= (int)$counts['due'] ?></span></a>
            <a class="ui-tab <?= $f==='soon'?'ui-tab--active':'' ?>" href="<?= e($mkFilter('soon')) ?>">Bald <span class="<?= e(tab_count_cls('soon')) ?>"><?= (int)$counts['soon'] ?></span></a>
            <a class="ui-tab <?= $f==='ok'?'ui-tab--active':'' ?>" href="<?= e($mkFilter('ok')) ?>">OK <span class="<?= e(tab_count_cls('ok')) ?>"><?= (int)$counts['ok'] ?></span></a>
            <a class="ui-tab <?= $f==='planned'?'ui-tab--active':'' ?>" href="<?= e($mkFilter('planned')) ?>">Geplant <span class="<?= e(tab_count_cls('planned')) ?>"><?= (int)$counts['planned'] ?></span></a>
          </div>
        </div>

        <div class="ui-table-wrap">
          <table class="ui-table">
            <thead>
              <tr>
                <th>Asset</th>
                <th>Wartungspunkt</th>
                <th>Rest</th>
                <th>Intervall</th>
                <th>Letzte Wartung</th>
                <th>Status</th>
                <th class="ui-th-actions">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="small ui-muted">Keine Treffer.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $restCls = 'ui-num';
                    if ($r['rest'] !== null && (float)$r['rest'] < 0) $restCls .= ' ui-num--danger';

                    $letzte = '—';
                    if ($r['intervall_typ'] === 'produktiv') {
                      $letzte = ($r['letzte'] !== null) ? (number_format((float)$r['letzte'], 1, ',', '.') . ' h') : '—';
                    } else {
                      $letzte = $r['datum'] ? e($r['datum']) : '—';
                    }
                  ?>
                  <tr>
                    <td>
                      <div style="font-weight:800; letter-spacing:0.2px;">
                        <?= e($r['asset_code'] ?: '—') ?>
                      </div>
                      <div class="small ui-muted" style="margin-top:2px;">
                        <?= e($r['asset_name'] ?: '') ?>
                      </div>
                    </td>

                    <td>
                      <?php if ($canSeePunkt): ?>
                        <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$r['wp_id'] ?>"><?= e($r['wp_text']) ?></a>
                        <span class="small ui-muted"> · WP #<?= (int)$r['wp_id'] ?></span>
                      <?php else: ?>
                        <?= e($r['wp_text']) ?> <span class="small ui-muted">(WP #<?= (int)$r['wp_id'] ?>)</span>
                      <?php endif; ?>
                    </td>

                    <td class="<?= e($restCls) ?>"><strong><?= e(rest_label($r['rest'])) ?></strong></td>
                    <td class="ui-num"><?= e(number_format((float)$r['interval'], 0, ',', '.')) ?> h</td>
                    <td class="small ui-muted"><?= $letzte ?></td>

                    <td>
                      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <span class="<?= e($r['status']['cls']) ?>"><?= e($r['status']['label']) ?></span>
                        <?php if (!empty($r['planned'])): ?>
                          <?php
                            $pt = (string)($r['planned_text'] ?? '');
                            $pd = (string)($r['planned_at'] ?? '');
                            $title = trim(($pd ? $pd : '') . ($pt ? ' · ' . $pt : ''));
                          ?>
                          <span class="ui-badge ui-badge--info" title="<?= e($title) ?>">Geplant</span>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td class="ui-td-actions">
                      <?php if ($canSeePunkt): ?>
                        <a class="ui-btn ui-btn--sm ui-btn--primary" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$r['wp_id'] ?>">Öffnen</a>
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
          Hinweis: „Bald fällig“ nutzt <code>soon_hours</code> (falls gesetzt) sonst <code>soon_ratio</code> (Fallback 0.20).
        </div>

      </div>
    </div>

    <aside>
      <div class="ui-card" style="margin-bottom: var(--s-6);">
        <h2 style="margin:0;">Quickinfo</h2>
        <p class="small ui-muted" style="margin-top:6px;">Letzte Wartungsaktionen</p>

        <?php if (empty($lastGrouped)): ?>
          <p class="small ui-muted" style="margin-top:10px;">Keine Daten.</p>
        <?php else: ?>
          <div class="ui-quicklist" style="margin-top:12px;">
            <?php foreach ($lastGrouped as $g): ?>
              <?php
                $badge = $g['has_warn'] ? 'ui-badge ui-badge--warn' : 'ui-badge ui-badge--ok';
                $label = $g['has_warn'] ? 'Abweichung' : 'OK';

                $items = $g['items'];
                $show  = array_slice($items, 0, 3);
                $more  = max(0, count($items) - count($show));
              ?>
              <div class="ui-quickitem">
                <div class="ui-quickitem__main">
                  <div class="ui-quickitem__top">
                    <span class="ui-quickitem__who"><?= e($g['latest_user']) ?></span>
                    <span class="ui-quickitem__meta"><?= e($g['latest_dt']) ?></span>
                  </div>

                  <div class="ui-quickitem__mid" style="white-space:normal;">
                    <strong><?= e($g['asset_code']) ?></strong>
                    <span class="small ui-muted">·</span>
                    <span class="small ui-muted"><?= (int)count($items) ?> Aktion(en)</span>
                  </div>

                  <div class="small ui-muted" style="margin-top:6px; display:flex; flex-direction:column; gap:4px;">
                    <?php foreach ($show as $it): ?>
                      <div style="display:flex; gap:8px; align-items:baseline; justify-content:space-between;">
                        <div style="min-width:0; flex:1;">
                          <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$it['wp_id'] ?>">
                            <?= e($it['text']) ?>
                          </a>
                        </div>
                        <div style="white-space:nowrap;" class="small ui-muted">WP #<?= (int)$it['wp_id'] ?></div>
                      </div>
                    <?php endforeach; ?>
                    <?php if ($more > 0): ?>
                      <div class="small ui-muted">+<?= (int)$more ?> weitere…</div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="ui-quickitem__right">
                  <span class="<?= e($badge) ?>"><?= e($label) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="ui-card">
        <h2 style="margin:0;">Wartung</h2>
        <div style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Überfällig</span>
            <span class="<?= e(tab_count_cls('due')) ?>"><?= (int)$counts['due'] ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Bald fällig</span>
            <span class="<?= e(tab_count_cls('soon')) ?>"><?= (int)$counts['soon'] ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">OK</span>
            <span class="<?= e(tab_count_cls('ok')) ?>"><?= (int)$counts['ok'] ?></span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span class="small ui-muted">Geplant</span>
            <span class="<?= e(tab_count_cls('planned')) ?>"><?= (int)$counts['planned'] ?></span>
          </div>
        </div>
      </div>
    </aside>

  </div>

</div>