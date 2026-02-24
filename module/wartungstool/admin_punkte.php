<?php
// module/wartungstool/admin_punkte.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();
require_can_edit('wartungstool', 'global', null);

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$actor  = $u['anzeigename'] ?? $u['benutzername'] ?? 'user';

function num_or_null($v): ?float {
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace(',', '.', $v);
  if (!is_numeric($v)) return null;
  return (float)$v;
}
function str_or_null($v): ?string {
  $v = trim((string)$v);
  return $v === '' ? null : $v;
}
function clamp_intervall_typ(string $t): string {
  $t = strtolower(trim($t));
  return ($t === 'produktiv') ? 'produktiv' : 'zeit';
}
function clamp_status_aktiv($v): int {
  return ((string)$v === '0' || strtolower((string)$v) === 'nein' || strtolower((string)$v) === 'false') ? 0 : 1;
}
function first_non_empty(array $arr): ?string {
  foreach ($arr as $a) { $a = trim((string)$a); if ($a !== '') return $a; }
  return null;
}
function guess_delim(string $line): string {
  // bevorzugt ; dann , dann \t
  if (substr_count($line, ';') >= 2) return ';';
  if (substr_count($line, "\t") >= 2) return "\t";
  if (substr_count($line, ',') >= 2) return ',';
  return ';';
}

// Assets fürs Dropdown
$assets = db_all("
  SELECT a.id, a.code, a.name, a.asset_typ,
         k.name AS kategorie_name, COALESCE(k.kritischkeitsstufe,1) AS kritischkeitsstufe
  FROM core_asset a
  LEFT JOIN core_asset_kategorie k ON k.id = a.kategorie_id
  WHERE a.aktiv=1
  ORDER BY COALESCE(k.kritischkeitsstufe,1) DESC, k.name ASC, a.prioritaet DESC, a.name ASC
");

$assetId = (int)($_GET['asset_id'] ?? 0);
if ($assetId <= 0 && !empty($assets)) $assetId = (int)$assets[0]['id'];

$editWpId = (int)($_GET['edit_wp'] ?? 0);

$ok = (int)($_GET['ok'] ?? 0);
$err = trim((string)($_GET['err'] ?? ''));

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  $action = (string)($_POST['action'] ?? '');
  $assetIdPost = (int)($_POST['asset_id'] ?? 0);
  if ($assetIdPost > 0) $assetId = $assetIdPost;

  try {
    $pdo = db();
    $pdo->beginTransaction();

    // Asset existiert?
    $asset = db_one("SELECT id, code, name FROM core_asset WHERE id=? AND aktiv=1 LIMIT 1", [$assetId]);
    if (!$asset) throw new RuntimeException("Asset nicht gefunden/aktiv.");

    if ($action === 'create' || $action === 'update') {
      $wpId = (int)($_POST['wp_id'] ?? 0);

      $textKurz = trim((string)($_POST['text_kurz'] ?? ''));
      if ($textKurz === '') throw new RuntimeException("Text kurz ist Pflicht.");

      $textLang = str_or_null($_POST['text_lang'] ?? null);
      $intervallTyp = clamp_intervall_typ((string)($_POST['intervall_typ'] ?? 'zeit'));

      $planInterval = num_or_null($_POST['plan_interval'] ?? '');
      if ($planInterval === null || $planInterval <= 0) throw new RuntimeException("Intervall (h) ist Pflicht und muss > 0 sein.");

      $messwertPflicht = !empty($_POST['messwert_pflicht']) ? 1 : 0;
      $einheit = str_or_null($_POST['einheit'] ?? null);
      $gwMin = num_or_null($_POST['grenzwert_min'] ?? '');
      $gwMax = num_or_null($_POST['grenzwert_max'] ?? '');

      // Init-Option: letzte Wartung auf "jetzt" setzen
      $initNow = !empty($_POST['init_now']) ? 1 : 0;

      $old = null;

      if ($action === 'create') {
        $letzteWartung = null;
        $datum = null;

        if ($initNow) {
          if ($intervallTyp === 'produktiv') {
            $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
            $letzteWartung = (float)($row['h'] ?? 0);
          } else {
            $datum = date('Y-m-d H:i:s');
          }
        }

        db_exec(
          "INSERT INTO wartungstool_wartungspunkt
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $textKurz,
            $textLang,
            $intervallTyp,
            $planInterval,
            $letzteWartung,
            $datum,
            $messwertPflicht,
            $gwMin,
            $gwMax,
            $einheit,
            1
          ]
        );
        $newId = (int)$pdo->lastInsertId();

        audit_log('wartungstool', 'wartungspunkt', $newId, 'CREATE', null, [
          'asset_id' => $assetId,
          'text_kurz' => $textKurz,
          'intervall_typ' => $intervallTyp,
          'plan_interval' => $planInterval,
          'messwert_pflicht' => $messwertPflicht,
          'grenzwert_min' => $gwMin,
          'grenzwert_max' => $gwMax,
          'einheit' => $einheit,
          'init_now' => $initNow
        ], $userId, $actor);

      } else { // update
        if ($wpId <= 0) throw new RuntimeException("wp_id fehlt.");

        $oldRow = db_one("SELECT * FROM wartungstool_wartungspunkt WHERE id=? AND asset_id=? LIMIT 1", [$wpId, $assetId]);
        if (!$oldRow) throw new RuntimeException("Wartungspunkt nicht gefunden.");

        $old = [
          'text_kurz' => $oldRow['text_kurz'],
          'text_lang' => $oldRow['text_lang'],
          'intervall_typ' => $oldRow['intervall_typ'],
          'plan_interval' => $oldRow['plan_interval'],
          'letzte_wartung' => $oldRow['letzte_wartung'],
          'datum' => $oldRow['datum'],
          'messwert_pflicht' => $oldRow['messwert_pflicht'],
          'grenzwert_min' => $oldRow['grenzwert_min'],
          'grenzwert_max' => $oldRow['grenzwert_max'],
          'einheit' => $oldRow['einheit'],
          'aktiv' => $oldRow['aktiv'],
        ];

        $letzteWartung = $oldRow['letzte_wartung'];
        $datum = $oldRow['datum'];

        if ($initNow) {
          if ($intervallTyp === 'produktiv') {
            $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
            $letzteWartung = (float)($row['h'] ?? 0);
            $datum = null;
          } else {
            $datum = date('Y-m-d H:i:s');
            $letzteWartung = null;
          }
        }

        db_exec(
          "UPDATE wartungstool_wartungspunkt
           SET text_kurz=?, text_lang=?, intervall_typ=?, plan_interval=?,
               letzte_wartung=?, datum=?,
               messwert_pflicht=?, grenzwert_min=?, grenzwert_max=?, einheit=?,
               updated_at=NOW()
           WHERE id=? AND asset_id=?",
          [
            $textKurz, $textLang, $intervallTyp, $planInterval,
            $letzteWartung, $datum,
            $messwertPflicht, $gwMin, $gwMax, $einheit,
            $wpId, $assetId
          ]
        );

        $newRow = db_one("SELECT * FROM wartungstool_wartungspunkt WHERE id=? LIMIT 1", [$wpId]);
        audit_log('wartungstool', 'wartungspunkt', $wpId, 'UPDATE', $old, [
          'text_kurz' => $newRow['text_kurz'],
          'text_lang' => $newRow['text_lang'],
          'intervall_typ' => $newRow['intervall_typ'],
          'plan_interval' => $newRow['plan_interval'],
          'letzte_wartung' => $newRow['letzte_wartung'],
          'datum' => $newRow['datum'],
          'messwert_pflicht' => $newRow['messwert_pflicht'],
          'grenzwert_min' => $newRow['grenzwert_min'],
          'grenzwert_max' => $newRow['grenzwert_max'],
          'einheit' => $newRow['einheit'],
          'aktiv' => $newRow['aktiv'],
          'init_now' => $initNow
        ], $userId, $actor);
      }

    } elseif ($action === 'toggle_active') {
      $wpId = (int)($_POST['wp_id'] ?? 0);
      if ($wpId <= 0) throw new RuntimeException("wp_id fehlt.");

      $row = db_one("SELECT id, aktiv, asset_id FROM wartungstool_wartungspunkt WHERE id=? LIMIT 1", [$wpId]);
      if (!$row) throw new RuntimeException("Wartungspunkt nicht gefunden.");

      $newAktiv = ((int)$row['aktiv'] === 1) ? 0 : 1;
      db_exec("UPDATE wartungstool_wartungspunkt SET aktiv=?, updated_at=NOW() WHERE id=?", [$newAktiv, $wpId]);

      audit_log('wartungstool', 'wartungspunkt', $wpId, 'STATUS',
        ['aktiv' => (int)$row['aktiv']],
        ['aktiv' => $newAktiv],
        $userId, $actor
      );

    } elseif ($action === 'copy_from_asset') {
      $sourceAssetId = (int)($_POST['source_asset_id'] ?? 0);
      if ($sourceAssetId <= 0) throw new RuntimeException("Quelle fehlt.");

      $includeInactive = !empty($_POST['include_inactive']) ? 1 : 0;
      $initNow = !empty($_POST['init_now']) ? 1 : 0;

      $src = db_one("SELECT id FROM core_asset WHERE id=? AND aktiv=1 LIMIT 1", [$sourceAssetId]);
      if (!$src) throw new RuntimeException("Quell-Asset nicht gefunden/aktiv.");

      $where = $includeInactive ? "" : "AND aktiv=1";
      $srcPoints = db_all("
        SELECT text_kurz, text_lang, intervall_typ, plan_interval,
               messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv
        FROM wartungstool_wartungspunkt
        WHERE asset_id=? $where
        ORDER BY id ASC
      ", [$sourceAssetId]);

      if (!$srcPoints) throw new RuntimeException("Keine Wartungspunkte in Quelle gefunden.");

      $created = 0;
      foreach ($srcPoints as $p) {
        $letzteW = null;
        $datum = null;

        if ($initNow) {
          if ($p['intervall_typ'] === 'produktiv') {
            $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
            $letzteW = (float)($row['h'] ?? 0);
          } else {
            $datum = date('Y-m-d H:i:s');
          }
        }

        db_exec(
          "INSERT INTO wartungstool_wartungspunkt
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $p['text_kurz'],
            $p['text_lang'],
            $p['intervall_typ'],
            (float)$p['plan_interval'],
            $letzteW,
            $datum,
            (int)$p['messwert_pflicht'],
            $p['grenzwert_min'] !== null ? (float)$p['grenzwert_min'] : null,
            $p['grenzwert_max'] !== null ? (float)$p['grenzwert_max'] : null,
            $p['einheit'],
            (int)$p['aktiv']
          ]
        );
        $newId = (int)$pdo->lastInsertId();

        audit_log('wartungstool', 'wartungspunkt', $newId, 'CREATE', null, [
          'copied_from_asset_id' => $sourceAssetId,
          'asset_id' => $assetId,
          'text_kurz' => $p['text_kurz'],
          'intervall_typ' => $p['intervall_typ'],
          'plan_interval' => (float)$p['plan_interval'],
          'init_now' => $initNow
        ], $userId, $actor);

        $created++;
      }

      // Info in Audit (optional, als eigener Log)
      audit_log('wartungstool', 'asset', $assetId, 'UPDATE', null, [
        'copy_from_asset_id' => $sourceAssetId,
        'created_points' => $created,
        'include_inactive' => $includeInactive,
        'init_now' => $initNow
      ], $userId, $actor);

    } elseif ($action === 'csv_import') {
      $raw = (string)($_POST['csv_text'] ?? '');
      $initNow = !empty($_POST['init_now']) ? 1 : 0;

      $raw = trim($raw);
      if ($raw === '') throw new RuntimeException("CSV/Paste ist leer.");

      $lines = preg_split('/\\r\\n|\\r|\\n/', $raw);
      $lines = array_values(array_filter($lines, fn($l) => trim((string)$l) !== ''));

      if (count($lines) === 0) throw new RuntimeException("Keine Zeilen gefunden.");

      // Header optional erkennen (enthält 'text_kurz' o.ä.)
      $first = strtolower(trim($lines[0]));
      $hasHeader = (strpos($first, 'text_kurz') !== false || strpos($first, 'plan_interval') !== false);

      if ($hasHeader) array_shift($lines);

      $created = 0;
      foreach ($lines as $idx => $line) {
        $delim = guess_delim($line);
        $cols = array_map('trim', explode($delim, $line));

        // erwartete Spalten (flexibel):
        // 0 text_kurz
        // 1 intervall_typ (zeit|produktiv)
        // 2 plan_interval (stunden)
        // 3 messwert_pflicht (0/1)
        // 4 einheit
        // 5 grenzwert_min
        // 6 grenzwert_max
        // 7 aktiv (0/1) optional
        // 8 text_lang optional (wenn du willst: hinten dran)
        $textKurz = $cols[0] ?? '';
        if ($textKurz === '') continue;

        $intervallTyp = clamp_intervall_typ($cols[1] ?? 'zeit');

        $planInterval = num_or_null($cols[2] ?? '');
        if ($planInterval === null || $planInterval <= 0) {
          throw new RuntimeException("Zeile ".($idx+1).": plan_interval ungültig.");
        }

        $messwertPflicht = (int)(num_or_null($cols[3] ?? '0') ?? 0);
        $messwertPflicht = ($messwertPflicht >= 1) ? 1 : 0;

        $einheit = str_or_null($cols[4] ?? '');
        $gwMin = num_or_null($cols[5] ?? '');
        $gwMax = num_or_null($cols[6] ?? '');

        $aktiv = isset($cols[7]) ? clamp_status_aktiv($cols[7]) : 1;

        // text_lang: entweder Spalte 8 oder — falls der Delimiter ; ist, kann auch alles ab 8 zusammen
        $textLang = null;
        if (isset($cols[8])) {
          // Rest zusammenführen falls es mehr Spalten gibt
          $tail = array_slice($cols, 8);
          $textLang = str_or_null(implode(' ' . $delim . ' ', $tail));
        }

        $letzteW = null;
        $datum = null;
        if ($initNow) {
          if ($intervallTyp === 'produktiv') {
            $row = db_one("SELECT COALESCE(productive_hours,0) AS h FROM core_runtime_counter WHERE asset_id=?", [$assetId]);
            $letzteW = (float)($row['h'] ?? 0);
          } else {
            $datum = date('Y-m-d H:i:s');
          }
        }

        db_exec(
          "INSERT INTO wartungstool_wartungspunkt
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $textKurz,
            $textLang,
            $intervallTyp,
            $planInterval,
            $letzteW,
            $datum,
            $messwertPflicht,
            $gwMin,
            $gwMax,
            $einheit,
            $aktiv
          ]
        );
        $newId = (int)$pdo->lastInsertId();

        audit_log('wartungstool', 'wartungspunkt', $newId, 'CREATE', null, [
          'asset_id' => $assetId,
          'text_kurz' => $textKurz,
          'intervall_typ' => $intervallTyp,
          'plan_interval' => $planInterval,
          'messwert_pflicht' => $messwertPflicht,
          'grenzwert_min' => $gwMin,
          'grenzwert_max' => $gwMax,
          'einheit' => $einheit,
          'aktiv' => $aktiv,
          'import' => 'csv_paste',
          'init_now' => $initNow
        ], $userId, $actor);

        $created++;
      }

      audit_log('wartungstool', 'asset', $assetId, 'UPDATE', null, [
        'csv_import_created' => $created
      ], $userId, $actor);

    } else {
      throw new RuntimeException("Unbekannte Aktion.");
    }

    $pdo->commit();
    header("Location: {$base}/app.php?r=wartung.admin_punkte&asset_id={$assetId}&ok=1");
    exit;

  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    header("Location: {$base}/app.php?r=wartung.admin_punkte&asset_id={$assetId}&err=" . urlencode($e->getMessage()));
    exit;
  }
}

// Daten für View
$asset = null;
if ($assetId > 0) {
  $asset = db_one("
    SELECT a.id, a.code, a.name, a.asset_typ,
           k.name AS kategorie_name, COALESCE(k.kritischkeitsstufe,1) AS kritischkeitsstufe
    FROM core_asset a
    LEFT JOIN core_asset_kategorie k ON k.id=a.kategorie_id
    WHERE a.id=? AND a.aktiv=1
    LIMIT 1
  ", [$assetId]);
}

$punkte = [];
if ($asset) {
  $punkte = db_all("
    SELECT *
    FROM wartungstool_wartungspunkt
    WHERE asset_id=?
    ORDER BY aktiv DESC, id ASC
  ", [$assetId]);
}

$edit = null;
$auditRows = [];
if ($editWpId > 0 && $asset) {
  $edit = db_one("SELECT * FROM wartungstool_wartungspunkt WHERE id=? AND asset_id=? LIMIT 1", [$editWpId, $assetId]);

  if ($edit) {
    $auditRows = db_all("
      SELECT modul, entity_type, entity_id, action, actor_user_id, actor_text, ip_addr, old_json, new_json, created_at
      FROM core_audit_log
      WHERE modul='wartungstool' AND entity_type='wartungspunkt' AND entity_id=?
      ORDER BY created_at DESC
      LIMIT 50
    ", [$editWpId]);
  }
}
?>

<div class="card">
  <h1>Admin – Wartungspunkte</h1>
  <p class="small">
    Wartungspunkte pro Anlage anlegen / bearbeiten / deaktivieren.
    Zusätzlich: Template kopieren, CSV/Paste-Import, Audit-Historie.
  </p>

  <?php if ($ok): ?><p class="badge badge--g">Gespeichert.</p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

  <form method="get" action="<?= e($base) ?>/app.php" style="margin-top:10px;">
    <input type="hidden" name="r" value="wartung.admin_punkte">
    <label>Anlage</label>
    <select name="asset_id" onchange="this.form.submit()">
      <?php foreach ($assets as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $assetId ? 'selected' : '') ?>>
          <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
          <?= e(' · ' . ($a['kategorie_name'] ?: '—') . ' · Krit ' . (int)$a['kritischkeitsstufe']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn" style="margin-top:10px;">Anzeigen</button></noscript>
  </form>
</div>

<?php if (!$asset): ?>
  <div class="card"><h2>Keine Anlage</h2><p class="small">Bitte Anlage auswählen.</p></div>
<?php else: ?>

<div class="grid">

  <!-- LINKS: Formular -->
  <div class="col-6">
    <div class="card">
      <h2><?= $edit ? 'Wartungspunkt bearbeiten' : 'Neuen Wartungspunkt anlegen' ?></h2>
      <div class="small">
        Anlage: <b><?= e(($asset['code'] ? $asset['code'].' — ' : '') . $asset['name']) ?></b>
        · Kategorie: <b><?= e($asset['kategorie_name'] ?: '—') ?></b>
      </div>

      <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <input type="hidden" name="wp_id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

        <label>Text kurz</label>
        <input name="text_kurz" required value="<?= e($edit['text_kurz'] ?? '') ?>">

        <label>Text lang (optional)</label>
        <textarea name="text_lang" placeholder="Arbeitsanweisung / Details..."><?= e($edit['text_lang'] ?? '') ?></textarea>

        <label>Intervall-Typ</label>
        <?php $it = $edit['intervall_typ'] ?? 'zeit'; ?>
        <select name="intervall_typ">
          <option value="zeit" <?= $it==='zeit' ? 'selected' : '' ?>>zeit</option>
          <option value="produktiv" <?= $it==='produktiv' ? 'selected' : '' ?>>produktiv</option>
        </select>

        <label>Intervall (Stunden)</label>
        <input name="plan_interval" inputmode="decimal" required value="<?= e(isset($edit['plan_interval']) ? (string)$edit['plan_interval'] : '') ?>" placeholder="z.B. 168">

        <label>
          <input type="checkbox" name="messwert_pflicht" value="1" <?= (!empty($edit) && (int)$edit['messwert_pflicht']===1) ? 'checked' : '' ?>>
          Messwertpflicht
        </label>

        <label>Einheit (optional)</label>
        <input name="einheit" value="<?= e($edit['einheit'] ?? '') ?>" placeholder="z.B. °C, bar">

        <div class="grid">
          <div class="col-6">
            <label>Grenzwert min (optional)</label>
            <input name="grenzwert_min" inputmode="decimal" value="<?= e(isset($edit['grenzwert_min']) ? (string)$edit['grenzwert_min'] : '') ?>">
          </div>
          <div class="col-6">
            <label>Grenzwert max (optional)</label>
            <input name="grenzwert_max" inputmode="decimal" value="<?= e(isset($edit['grenzwert_max']) ? (string)$edit['grenzwert_max'] : '') ?>">
          </div>
        </div>

        <label>
          <input type="checkbox" name="init_now" value="1">
          Initialisieren „letzte Wartung = jetzt“ (produktiv: aktueller Zähler / zeit: NOW)
        </label>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn" type="submit"><?= $edit ? 'Änderungen speichern' : 'Anlegen' ?></button>
          <?php if ($edit): ?>
            <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">Neu anlegen</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- CSV/Paste Import -->
    <div class="card" style="margin-top:12px;">
      <h2>CSV / Paste Import</h2>
      <p class="small">
        Format pro Zeile (Delimiter ; , oder TAB):
        <br><code>text_kurz;intervall_typ;plan_interval;messwert_pflicht;einheit;grenzwert_min;grenzwert_max;aktiv;text_lang</code>
        <br>Beispiel:
        <br><code>KSS Stand prüfen;zeit;168;0;;; ;1;Tank prüfen und nachfüllen</code>
      </p>

      <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
        <input type="hidden" name="action" value="csv_import">

        <label>Zeilen einfügen</label>
        <textarea name="csv_text" placeholder="..."></textarea>

        <label>
          <input type="checkbox" name="init_now" value="1">
          Initialisieren „letzte Wartung = jetzt“
        </label>

        <div style="margin-top:12px;">
          <button class="btn" type="submit" onclick="return confirm('Import starten?');">Import starten</button>
        </div>
      </form>
    </div>

    <!-- Template kopieren -->
    <div class="card" style="margin-top:12px;">
      <h2>Template kopieren</h2>
      <p class="small">Kopiert alle Wartungspunkte von einer Quelle auf diese Anlage.</p>

      <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
        <input type="hidden" name="action" value="copy_from_asset">

        <label>Quelle (Asset)</label>
        <select name="source_asset_id" required>
          <option value="">— wählen —</option>
          <?php foreach ($assets as $a): ?>
            <?php if ((int)$a['id'] === $assetId) continue; ?>
            <option value="<?= (int)$a['id'] ?>">
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label>
          <input type="checkbox" name="include_inactive" value="1">
          Auch deaktivierte Punkte mitkopieren
        </label>

        <label>
          <input type="checkbox" name="init_now" value="1">
          Initialisieren „letzte Wartung = jetzt“
        </label>

        <div style="margin-top:12px;">
          <button class="btn" type="submit" onclick="return confirm('Wirklich kopieren?');">Kopieren</button>
        </div>
      </form>
    </div>

  </div>

  <!-- RECHTS: Liste + Audit -->
  <div class="col-6">
    <div class="card">
      <h2>Wartungspunkte (<?= count($punkte) ?>)</h2>
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Aktiv</th>
            <th>Typ</th>
            <th>Intervall</th>
            <th>Text</th>
            <th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($punkte as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= (int)$p['aktiv'] === 1 ? 'ja' : 'nein' ?></td>
              <td><?= e($p['intervall_typ']) ?></td>
              <td><?= number_format((float)$p['plan_interval'], 1, ',', '.') ?> h</td>
              <td><?= e($p['text_kurz']) ?></td>
              <td>
                <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>&edit_wp=<?= (int)$p['id'] ?>">Bearbeiten</a>

                <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
                  <input type="hidden" name="wp_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn--ghost" type="submit" onclick="return confirm('Wartungspunkt wirklich <?= ((int)$p['aktiv']===1 ? 'deaktivieren' : 'aktivieren') ?>?');">
                    <?= ((int)$p['aktiv']===1 ? 'Deaktivieren' : 'Aktivieren') ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="small" style="margin-top:10px;">
        Hinweis: „Löschen“ = <b>Deaktivieren</b> (audit-sicher). Historie/Protokolle bleiben intakt.
      </p>
    </div>

    <div class="card" style="margin-top:12px;">
      <h2>Änderungshistorie (Audit)</h2>
      <?php if (!$edit): ?>
        <p class="small">Wähle rechts einen Wartungspunkt über „Bearbeiten“, dann siehst du hier die Audit-Historie.</p>
      <?php else: ?>
        <p class="small">
          Wartungspunkt <b>#<?= (int)$edit['id'] ?></b>: <?= e($edit['text_kurz']) ?>
        </p>

        <?php if (empty($auditRows)): ?>
          <p class="small">Keine Audit-Einträge vorhanden.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Zeit</th>
                <th>Action</th>
                <th>Actor</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($auditRows as $a): ?>
                <tr>
                  <td><?= e($a['created_at']) ?></td>
                  <td><?= e($a['action']) ?></td>
                  <td><?= e($a['actor_text'] ?: ('user#'.$a['actor_user_id'])) ?></td>
                  <td class="small">
                    <?php
                      $new = $a['new_json'] ? short_text($a['new_json'], 120) : '';
                      $old = $a['old_json'] ? short_text($a['old_json'], 120) : '';
                      echo e(trim("old: {$old} | new: {$new}"));
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php endif; ?>