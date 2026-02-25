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

// Admin-Link nur, wenn Bearbeiten-Recht vorhanden
$canAdmin = user_can_edit($userId, 'wartungstool', 'global', null);

// Assets laden
$maschinen = db_all("
  SELECT a.id, a.code, a.name, a.prioritaet, a.aktiv,
         k.name AS kategorie_name, k.kritischkeitsstufe
  FROM core_asset a
  LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
  WHERE a.aktiv=1
  ORDER BY COALESCE(k.kritischkeitsstufe,1) DESC, a.prioritaet DESC, a.name ASC
");

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

  // 4) Nächst fälliger Wartungspunkt (PRODUKTIV!)
  $punkt = db_one("
    SELECT wp.id, wp.text_kurz, wp.plan_interval, wp.letzte_wartung
    FROM wartungstool_wartungspunkt wp
    WHERE wp.asset_id=? AND wp.aktiv=1 AND wp.intervall_typ='produktiv' AND wp.letzte_wartung IS NOT NULL
    ORDER BY (wp.letzte_wartung + wp.plan_interval) ASC
    LIMIT 1
  ", [$assetId]);

  $rest = null;
  $wpId = null;
  $wpText = null;
  $wpInterval = null;

  if ($punkt) {
    $wpId = (int)$punkt['id'];
    $wpText = (string)$punkt['text_kurz'];
    $wpInterval = (float)$punkt['plan_interval'];

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
    'wp_id' => $wpId,
    'wp_text' => $wpText,
    'wp_interval' => $wpInterval
  ];
}

function ampel_for(?float $rest, float $interval): array {
  if ($rest === null) return ['cls'=>'', 'label'=>'Keine Punkte'];
  if ($rest < 0) return ['cls'=>'badge--r','label'=>'Überfällig'];
  $ratio = $interval > 0 ? ($rest / $interval) : 1.0;
  if ($ratio <= 0.20) return ['cls'=>'badge--y','label'=>'Bald fällig'];
  return ['cls'=>'badge--g','label'=>'OK'];
}

function renderTable(array $rows, string $title, string $base, bool $canSeePunkt): void {
  ?>
  <div class="card" style="margin-bottom:12px;">
    <h2 style="margin-bottom:8px;"><?= e($title) ?></h2>
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Ampel</th>
          <th scope="col">Anlage</th>
          <th scope="col">Nächster Wartungspunkt</th>
          <th scope="col">Rest (h)</th>
          <th scope="col">KW</th>
          <th scope="col">Schnitt (h/W)</th>
          <th scope="col">Trend</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $interval = isset($r['wp_interval']) && $r['wp_interval'] !== null ? (float)$r['wp_interval'] : 0.0;
          $ampel = ampel_for($r['rest'], $interval);
        ?>
        <tr>
          <td><span class="badge <?= e($ampel['cls']) ?>"><?= e($ampel['label']) ?></span></td>

          <td>
            <div><strong><?= e(($r['code'] ? $r['code'].' — ' : '') . $r['name']) ?></strong></div>
            <div class="small"><?= e($r['kategorie_name'] ?: '—') ?><?= $r['kritischkeitsstufe'] ? ' · Krit '.$r['kritischkeitsstufe'] : '' ?></div>
          </td>

          <td>
            <?php if (!empty($r['wp_id'])): ?>
              <?php if ($canSeePunkt): ?>
                <a href="<?= e($base) ?>/app.php?r=wartung.punkt&wp=<?= (int)$r['wp_id'] ?>">
                  <?= e($r['wp_text']) ?>
                </a>
              <?php else: ?>
                <?= e($r['wp_text']) ?>
              <?php endif; ?>
              <span class="small">(WP #<?= (int)$r['wp_id'] ?>)</span>
            <?php else: ?>
              <span class="small">—</span>
            <?php endif; ?>
          </td>

          <td>
            <?php if ($r['rest'] === null): ?>
              —
            <?php else: ?>
              <?= number_format((float)$r['rest'], 1, ',', '.') ?>
            <?php endif; ?>
          </td>

          <td><?= e($r['kw']) ?></td>
          <td><?= number_format((float)$r['schnitt'], 1, ',', '.') ?></td>
          <td style="font-size:16px;"><span aria-hidden="true"><?= e($r['trend']) ?></span><span class="sr-only"><?= e($r['trend'] === '▲' ? 'steigend' : ($r['trend'] === '▼' ? 'fallend' : 'gleichbleibend')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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

// Sortierung: kleinster Rest zuerst (überfällig ganz oben)
$sortFn = function($a, $b) {
  $ar = $a['rest']; $br = $b['rest'];
  if ($ar === null && $br === null) return 0;
  if ($ar === null) return 1;
  if ($br === null) return -1;
  if ($ar < $br) return -1;
  if ($ar > $br) return 1;
  return 0;
};
usort($wichtig, $sortFn);
usort($unwichtig, $sortFn);
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
    <div>
      <h1>Wartung Dashboard</h1>
      <p class="small">
        Quelle: <code>core_runtime_counter</code> · Trend: <code>core_runtime_agg_day</code>
      </p>
      <p class="small">
        <?php if ($canSeeUebersicht): ?>
          <a href="<?= e($base) ?>/app.php?r=wartung.uebersicht">Zur Anlagen-Übersicht</a>
        <?php endif; ?>
        <?php if ($canAdmin): ?>
          · <a href="<?= e($base) ?>/app.php?r=wartung.admin_punkte">Admin Wartungspunkte</a>
        <?php endif; ?>
      </p>
    </div>

    <div>
      <?php if (!$canSeePunkt): ?>
        <span class="badge">Detailansicht gesperrt</span>
      <?php endif; ?>
      <?php if ($canAdmin): ?>
        <span class="badge badge--g">Admin</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
renderTable($wichtig, 'Kritisch (Kritikalität 3)', $base, $canSeePunkt);
renderTable($unwichtig, 'Weitere Anlagen (Kritikalität 1–2)', $base, $canSeePunkt);