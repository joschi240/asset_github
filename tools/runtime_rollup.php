<?php
require_once __DIR__ . '/../src/db.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedIntervalSec = (int)($_GET['interval'] ?? 60);
$gapThresholdSec = (int)($_GET['gap'] ?? ($expectedIntervalSec * 3));
$maxAssets = (int)($_GET['max_assets'] ?? 500);
$limitSamplesPerAsset = (int)($_GET['limit'] ?? 50000);

echo "runtime_rollup start\n";
echo "expectedIntervalSec=$expectedIntervalSec gapThresholdSec=$gapThresholdSec\n\n";

function split_interval_by_day(int $startTs, int $endTs): array {
  $out = [];
  $cur = $startTs;
  while ($cur < $endTs) {
    $day = date('Y-m-d', $cur);
    $dayEnd = strtotime($day . ' 23:59:59') + 1;
    $sliceEnd = min($dayEnd, $endTs);
    $sec = $sliceEnd - $cur;
    if ($sec > 0) $out[$day] = ($out[$day] ?? 0) + $sec;
    $cur = $sliceEnd;
  }
  return $out;
}

function ensure_counter_row(int $assetId): array {
  $row = db_one("SELECT asset_id, productive_hours, last_ts, last_state FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
  if ($row) return $row;
  db_exec("INSERT INTO core_runtime_counter (asset_id, productive_hours, last_ts, last_state) VALUES (?,0,NULL,NULL)", [$assetId]);
  return db_one("SELECT asset_id, productive_hours, last_ts, last_state FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
}

function upsert_agg(int $assetId, string $day, int $runSec, int $stopSec, int $intervals, int $gaps): void {
  db_exec(
    "INSERT INTO core_runtime_agg_day (asset_id, day, run_seconds, stop_seconds, intervals, gaps)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       run_seconds = run_seconds + VALUES(run_seconds),
       stop_seconds = stop_seconds + VALUES(stop_seconds),
       intervals = intervals + VALUES(intervals),
       gaps = gaps + VALUES(gaps)",
    [$assetId, $day, $runSec, $stopSec, $intervals, $gaps]
  );
}

$assets = db_all("SELECT DISTINCT asset_id FROM core_runtime_sample ORDER BY asset_id ASC LIMIT $maxAssets");
echo "assets found: " . count($assets) . "\n";

foreach ($assets as $aRow) {
  $assetId = (int)$aRow['asset_id'];
  $counter = ensure_counter_row($assetId);
  $lastTs = $counter['last_ts'] ? strtotime($counter['last_ts']) : null;

  $anchor = null;
  if ($lastTs !== null) {
    $anchor = db_one(
      "SELECT ts, state FROM core_runtime_sample
       WHERE asset_id=? AND ts <= ?
       ORDER BY ts DESC LIMIT 1",
      [$assetId, date('Y-m-d H:i:s', $lastTs)]
    );
  }

  $fromTs = $lastTs ? date('Y-m-d H:i:s', $lastTs) : '1970-01-01 00:00:00';
  $samples = db_all(
    "SELECT ts, state FROM core_runtime_sample
     WHERE asset_id=? AND ts > ?
     ORDER BY ts ASC
     LIMIT $limitSamplesPerAsset",
    [$assetId, $fromTs]
  );

  if (!$samples && !$anchor) continue;

  $prevTs = null;
  $prevState = null;

  if ($anchor) {
    $prevTs = strtotime($anchor['ts']);
    $prevState = (int)$anchor['state'];
  } else {
    $first = array_shift($samples);
    if (!$first) continue;
    $prevTs = strtotime($first['ts']);
    $prevState = (int)$first['state'];
  }

  $runAddedSec = 0;
  $processedIntervals = 0;
  $gaps = 0;

  foreach ($samples as $s) {
    $curTs = strtotime($s['ts']);
    $curState = (int)$s['state'];
    if ($curTs <= $prevTs) { $prevTs = $curTs; $prevState = $curState; continue; }

    $delta = $curTs - $prevTs;

    if ($delta > $gapThresholdSec) {
      $gaps++;
      $prevTs = $curTs;
      $prevState = $curState;
      continue;
    }

    $chunks = split_interval_by_day($prevTs, $curTs);
    foreach ($chunks as $day => $sec) {
      if ($prevState === 1) {
        upsert_agg($assetId, $day, $sec, 0, 1, 0);
        $runAddedSec += $sec;
      } else {
        upsert_agg($assetId, $day, 0, $sec, 1, 0);
      }
    }

    $processedIntervals++;
    $prevTs = $curTs;
    $prevState = $curState;
  }

  if ($gaps > 0 && $prevTs !== null) {
    $day = date('Y-m-d', $prevTs);
    upsert_agg($assetId, $day, 0, 0, 0, $gaps);
  }

  $addedHours = $runAddedSec / 3600.0;
  $newProd = ((float)$counter['productive_hours']) + $addedHours;
  $newLastTs = date('Y-m-d H:i:s', $prevTs);

  db_exec(
    "UPDATE core_runtime_counter SET productive_hours=?, last_ts=?, last_state=? WHERE asset_id=?",
    [$newProd, $newLastTs, $prevState, $assetId]
  );

  echo "asset {$assetId}: intervals={$processedIntervals} gaps={$gaps} run_added_sec={$runAddedSec} (+".round($addedHours,3)."h) last_ts={$newLastTs}\n";
}

echo "\nruntime_rollup done\n";