<?php
// tools/runtime_ingest.php (single + bulk)
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';    // app_cfg()

header('Content-Type: application/json; charset=utf-8');

$cfg = app_cfg();
$token = $_SERVER['HTTP_X_INGEST_TOKEN'] ?? '';
if (!$token || !hash_equals($cfg['telemetry']['ingest_token'], $token)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

// JSON oder form-data lesen
$raw = file_get_contents('php://input');
$data = [];
if ($raw && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
  $data = json_decode($raw, true) ?: [];
} else {
  $data = $_POST;
}

function normalize_state($stateRaw): ?int {
  if ($stateRaw === 'run' || $stateRaw === '1' || $stateRaw === 1 || $stateRaw === true) return 1;
  if ($stateRaw === 'stop' || $stateRaw === '0' || $stateRaw === 0 || $stateRaw === false) return 0;
  return null;
}

function validate_ts(string $ts, int $maxSkewSec): bool {
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', $ts);
  if (!$dt) return false;
  if (strtotime($ts) > time() + $maxSkewSec) return false;
  return true;
}

$maxSkew = (int)($cfg['telemetry']['max_clock_skew_sec'] ?? 300);

// -------- Bulk oder Single erkennen --------
$samples = [];
if (isset($data['samples']) && is_array($data['samples'])) {
  $samples = $data['samples'];
} else {
  $samples = [$data];
}

if (count($samples) === 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'no_samples']);
  exit;
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $stmtAsset = $pdo->prepare("SELECT id FROM core_asset WHERE id=? AND aktiv=1");
  $stmtUpsert = $pdo->prepare(
    "INSERT INTO core_runtime_sample (asset_id, ts, state, source, quality, payload_json)
     VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE state=VALUES(state), source=VALUES(source), quality=VALUES(quality), payload_json=VALUES(payload_json)"
  );

  $okCount = 0;
  $errors = [];

  foreach ($samples as $i => $s) {
    $assetId = (int)($s['asset_id'] ?? 0);
    $stateRaw = $s['state'] ?? null;
    $source = trim((string)($s['source'] ?? 'plc_poll'));
    $quality = (int)($s['quality'] ?? 1);
    $payload = $s['payload'] ?? null;
    $ts = trim((string)($s['ts'] ?? ''));

    if ($assetId <= 0) { $errors[] = ['i'=>$i,'error'=>'asset_id_missing']; continue; }

    $state = normalize_state($stateRaw);
    if ($state === null) { $errors[] = ['i'=>$i,'error'=>'state_invalid']; continue; }

    if ($ts === '') $ts = date('Y-m-d H:i:s');
    if (!validate_ts($ts, $maxSkew)) { $errors[] = ['i'=>$i,'error'=>'ts_invalid_or_future']; continue; }

    // asset exists?
    $stmtAsset->execute([$assetId]);
    if (!$stmtAsset->fetch()) { $errors[] = ['i'=>$i,'error'=>'asset_not_found_or_inactive']; continue; }

    $payloadJson = null;
    if ($payload !== null && $payload !== '') {
      if (is_array($payload)) $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
      else {
        $tmp = json_decode((string)$payload, true);
        $payloadJson = $tmp !== null ? json_encode($tmp, JSON_UNESCAPED_UNICODE) : json_encode(['raw'=>(string)$payload], JSON_UNESCAPED_UNICODE);
      }
    }

    $stmtUpsert->execute([$assetId, $ts, $state, $source, $quality, $payloadJson]);
    $okCount++;
  }

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'received' => count($samples),
    'inserted_or_updated' => $okCount,
    'errors' => $errors
  ]);
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}