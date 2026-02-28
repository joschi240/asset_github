<?php
// module/wartungstool/admin_punkte.php (INNER VIEW)
// UI v2: Seite ist bereits weitgehend migriert. Diese Version räumt Inline-Styling etwas auf,
// vereinheitlicht Layout/Blöcke und fixt inkonsistente Inserts (soon_hours) bei Copy/Import.
// Legacy: nichts gelöscht, nur konsolidiert.

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
function clamp_soon_ratio($v): ?float {
  $n = num_or_null($v);
  if ($n === null) return null;
  // NULL gewinnt Logik: <=0 bedeutet "nicht gesetzt"
  if ($n <= 0) return null;
  if ($n > 1.0) $n = 1.0;
  return (float)$n;
}
function clamp_soon_hours($v): ?float {
  $n = num_or_null($v);
  if ($n === null) return null;
  if ($n <= 0) return null; // nicht gesetzt
  return (float)$n;
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

      $soonRatio = clamp_soon_ratio($_POST['soon_ratio'] ?? '');
      $soonHours = clamp_soon_hours($_POST['soon_hours'] ?? '');

      $messwertPflicht = !empty($_POST['messwert_pflicht']) ? 1 : 0;
      $einheit = str_or_null($_POST['einheit'] ?? null);
      $gwMin = num_or_null($_POST['grenzwert_min'] ?? '');
      $gwMax = num_or_null($_POST['grenzwert_max'] ?? '');

      // Init-Option: letzte Wartung auf "jetzt" setzen
      $initNow = !empty($_POST['init_now']) ? 1 : 0;

      $old = null;
      $letzteWartung = null;
      $datum = null;

      if ($action === 'create') {
        // Hinweis: Standardmäßig bleiben letzte_wartung/datum NULL. Optional init_now wird aktuell nur bei update genutzt.
        // (Legacy-Verhalten beibehalten)
        db_exec(
          "INSERT INTO wartungstool_wartungspunkt
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, soon_ratio, soon_hours, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $textKurz,
            $textLang,
            $intervallTyp,
            $planInterval,
            $soonRatio,
            $soonHours,
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
          'soon_ratio' => $soonRatio,
          'soon_hours' => $soonHours,
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
          'soon_ratio' => $oldRow['soon_ratio'],
          'soon_hours' => $oldRow['soon_hours'],
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
           SET text_kurz=?, text_lang=?, intervall_typ=?, plan_interval=?, soon_ratio=?, soon_hours=?,
               letzte_wartung=?, datum=?,
               messwert_pflicht=?, grenzwert_min=?, grenzwert_max=?, einheit=?,
               updated_at=NOW()
           WHERE id=? AND asset_id=?",
          [
            $textKurz, $textLang, $intervallTyp, $planInterval, $soonRatio, $soonHours,
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
          'soon_ratio' => $newRow['soon_ratio'],
          'soon_hours' => $newRow['soon_hours'],
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
        SELECT text_kurz, text_lang, intervall_typ, plan_interval, soon_ratio, soon_hours,
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
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, soon_ratio, soon_hours, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $p['text_kurz'],
            $p['text_lang'],
            $p['intervall_typ'],
            (float)$p['plan_interval'],
            isset($p['soon_ratio']) ? $p['soon_ratio'] : null,
            isset($p['soon_hours']) ? $p['soon_hours'] : null,
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
          'soon_ratio' => isset($p['soon_ratio']) ? $p['soon_ratio'] : null,
          'soon_hours' => isset($p['soon_hours']) ? $p['soon_hours'] : null,
          'init_now' => $initNow
        ], $userId, $actor);

        $created++;
      }

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

      $lines = preg_split('/\r\n|\r|\n/', $raw);
      $lines = array_values(array_filter($lines, fn($l) => trim((string)$l) !== ''));

      if (count($lines) === 0) throw new RuntimeException("Keine Zeilen gefunden.");

      $first = strtolower(trim($lines[0]));
      $hasHeader = (
        strpos($first, 'text_kurz') !== false ||
        strpos($first, 'plan_interval') !== false ||
        strpos($first, 'soon_ratio') !== false ||
        strpos($first, 'soon_hours') !== false
      );
      if ($hasHeader) array_shift($lines);

      $created = 0;
      foreach ($lines as $idx => $line) {
        $delim = guess_delim($line);
        $cols = array_map('trim', explode($delim, $line));

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

        // Erweiterung: soon_hours + soon_ratio + text_lang
        // Akzeptierte Formen:
        //  - ...;aktiv;soon_hours;soon_ratio;text_lang
        //  - ...;aktiv;soon_ratio;text_lang (legacy)
        $soonHours = null;
        $soonRatio = null;
        $textLang = null;

        if (isset($cols[8])) {
          $col8 = trim((string)$cols[8]);
          $col8num = num_or_null($col8);

          // Heuristik:
          // - wenn <=1 => ratio, wenn >1 => hours (oder wenn int/float)
          if ($col8num !== null) {
            if ($col8num > 1) {
              $soonHours = clamp_soon_hours($col8num);
              if (isset($cols[9])) {
                $col9 = trim((string)$cols[9]);
                $col9num = num_or_null($col9);
                if ($col9num !== null && $col9num >= 0 && $col9num <= 1) {
                  $soonRatio = clamp_soon_ratio($col9num);
                  if (isset($cols[10])) {
                    $tail = array_slice($cols, 10);
                    $textLang = str_or_null(implode(' ' . $delim . ' ', $tail));
                  }
                } else {
                  // falls Spalte 9 nicht numerisch: als text_lang behandeln
                  $tail = array_slice($cols, 9);
                  $textLang = str_or_null(implode(' ' . $delim . ' ', $tail));
                }
              }
            } else {
              $soonRatio = clamp_soon_ratio($col8num);
              if (isset($cols[9])) {
                $tail = array_slice($cols, 9);
                $textLang = str_or_null(implode(' ' . $delim . ' ', $tail));
              }
            }
          } else {
            // col8 nicht numerisch => text_lang
            $textLang = str_or_null(implode(' ' . $delim . ' ', array_slice($cols, 8)));
          }
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
           (asset_id, text_kurz, text_lang, intervall_typ, plan_interval, soon_ratio, soon_hours, letzte_wartung, datum,
            messwert_pflicht, grenzwert_min, grenzwert_max, einheit, aktiv, created_at, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())",
          [
            $assetId,
            $textKurz,
            $textLang,
            $intervallTyp,
            $planInterval,
            $soonRatio,
            $soonHours,
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
          'soon_ratio' => $soonRatio,
          'soon_hours' => $soonHours,
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

<div class="ui-container">

  <div class="ui-page-header">
    <h1 class="ui-page-title">Admin – Wartungspunkte</h1>
    <p class="ui-page-subtitle ui-muted">
      Wartungspunkte pro Anlage anlegen / bearbeiten / deaktivieren. Zusätzlich: Template kopieren, CSV/Paste-Import, Audit-Historie.
    </p>

    <?php if ($ok || $err !== ''): ?>
      <div class="ui-row" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top: var(--s-3);">
        <?php if ($ok): ?>
          <span class="ui-badge ui-badge--ok">Gespeichert</span>
        <?php endif; ?>
        <?php if ($err !== ''): ?>
          <span class="ui-badge ui-badge--danger"><?= e($err) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Anlage wählen -->
  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="wartung.admin_punkte">

      <div class="ui-filterbar__group" style="min-width: 420px;">
        <label for="admin_asset_id">Anlage</label>
        <select id="admin_asset_id" name="asset_id" onchange="this.form.submit()">
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id'] === $assetId ? 'selected' : '') ?>>
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
              <?= e(' · ' . ($a['kategorie_name'] ?: '—') . ' · Krit ' . (int)$a['kritischkeitsstufe']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="ui-btn ui-btn--primary ui-btn--sm" style="margin-top:10px;">Anzeigen</button></noscript>
      </div>

      <div class="ui-filterbar__actions">
        <span class="ui-muted small">Wechsel lädt automatisch</span>
      </div>
    </form>
  </div>

  <?php if (!$asset): ?>
    <div class="ui-card">
      <h2>Keine Anlage</h2>
      <p class="small ui-muted">Bitte Anlage auswählen.</p>
    </div>
  <?php else: ?>

  <div class="ui-grid" style="display:grid; grid-template-columns: 1.1fr 1.4fr; gap: var(--s-6); align-items:start;">

    <!-- LINKS -->
    <div>

      <!-- Create/Update -->
      <div class="ui-card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
          <div>
            <h2 style="margin:0;"><?= $edit ? 'Wartungspunkt bearbeiten' : 'Neuen Wartungspunkt anlegen' ?></h2>
            <div class="small ui-muted" style="margin-top:6px;">
              Anlage: <b><?= e(($asset['code'] ? $asset['code'].' — ' : '') . $asset['name']) ?></b>
              · Kategorie: <b><?= e($asset['kategorie_name'] ?: '—') ?></b>
            </div>
          </div>

          <?php if ($edit): ?>
            <a class="ui-btn ui-btn--ghost ui-btn--sm" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">
              Neu anlegen
            </a>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>" style="margin-top: var(--s-5);">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
          <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
          <input type="hidden" name="wp_id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

          <div style="display:grid; grid-template-columns: 1fr; gap: 12px;">
            <div>
              <label for="text_kurz">Text kurz</label>
              <input id="text_kurz" class="ui-input" name="text_kurz" required value="<?= e($edit['text_kurz'] ?? '') ?>">
            </div>

            <div>
              <label for="text_lang">Text lang (optional)</label>
              <textarea id="text_lang" class="ui-input" name="text_lang" placeholder="Arbeitsanweisung / Details..." style="min-height: 110px;"><?= e($edit['text_lang'] ?? '') ?></textarea>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div>
                <label for="intervall_typ">Intervall-Typ</label>
                <?php $it = $edit['intervall_typ'] ?? 'zeit'; ?>
                <select id="intervall_typ" class="ui-input" name="intervall_typ">
                  <option value="zeit" <?= $it==='zeit' ? 'selected' : '' ?>>zeit</option>
                  <option value="produktiv" <?= $it==='produktiv' ? 'selected' : '' ?>>produktiv</option>
                </select>
              </div>

              <div>
                <label for="plan_interval">Intervall (Stunden)</label>
                <input id="plan_interval" class="ui-input" name="plan_interval" inputmode="decimal" required
                       value="<?= e(isset($edit['plan_interval']) ? (string)$edit['plan_interval'] : '') ?>" placeholder="z.B. 168">
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div>
                <label for="soon_ratio">Bald fällig (Ratio, optional)</label>
                <input id="soon_ratio" class="ui-input" name="soon_ratio" inputmode="decimal"
                       value="<?= e(isset($edit['soon_ratio']) ? (string)$edit['soon_ratio'] : '') ?>"
                       placeholder="z.B. 0,10 für 10%">
                <div class="small ui-muted" style="margin-top:6px;">
                  0,10 = „bald fällig“, wenn ≤ 10% Restzeit/Reststunden übrig sind.
                </div>
              </div>

              <div>
                <label for="soon_hours">Bald fällig (Stunden, optional)</label>
                <input id="soon_hours" class="ui-input" name="soon_hours" inputmode="decimal"
                       value="<?= e(isset($edit['soon_hours']) ? (string)$edit['soon_hours'] : '') ?>"
                       placeholder="z.B. 12">
                <div class="small ui-muted" style="margin-top:6px;">
                  Wenn gesetzt, gilt diese Schwelle (Gewinner = nicht NULL).
                </div>
              </div>
            </div>

            <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
              <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" name="messwert_pflicht" value="1" <?= (!empty($edit) && (int)$edit['messwert_pflicht']===1) ? 'checked' : '' ?>>
                <span>Messwertpflicht</span>
              </label>

              <label style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="checkbox" name="init_now" value="1">
                <span>Initialisieren „letzte Wartung = jetzt“</span>
              </label>
            </div>

            <div>
              <label for="einheit">Einheit (optional)</label>
              <input id="einheit" class="ui-input" name="einheit" value="<?= e($edit['einheit'] ?? '') ?>" placeholder="z.B. °C, bar">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
              <div>
                <label for="grenzwert_min">Grenzwert min (optional)</label>
                <input id="grenzwert_min" class="ui-input" name="grenzwert_min" inputmode="decimal" value="<?= e(isset($edit['grenzwert_min']) ? (string)$edit['grenzwert_min'] : '') ?>">
              </div>
              <div>
                <label for="grenzwert_max">Grenzwert max (optional)</label>
                <input id="grenzwert_max" class="ui-input" name="grenzwert_max" inputmode="decimal" value="<?= e(isset($edit['grenzwert_max']) ? (string)$edit['grenzwert_max'] : '') ?>">
              </div>
            </div>

            <div class="small ui-muted">
              Hinweis: Initialisieren setzt bei <b>produktiv</b> den aktuellen Zähler, bei <b>zeit</b> auf NOW.
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top: 4px;">
              <button class="ui-btn ui-btn--primary" type="submit"><?= $edit ? 'Änderungen speichern' : 'Anlegen' ?></button>
              <?php if ($edit): ?>
                <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>">Abbrechen</a>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>

      <!-- CSV / Paste Import -->
      <div class="ui-card" style="margin-top: var(--s-6);">
        <h2 style="margin:0;">CSV / Paste Import</h2>
        <p class="small ui-muted" style="margin-top:8px;">
          Format pro Zeile (Delimiter <code>;</code>, <code>,</code> oder TAB):
          <br><code>text_kurz;intervall_typ;plan_interval;messwert_pflicht;einheit;grenzwert_min;grenzwert_max;aktiv;soon_hours;soon_ratio;text_lang</code>
          <br><span class="ui-muted">Legacy: <code>...;aktiv;soon_ratio;text_lang</code> wird weiterhin akzeptiert.</span>
        </p>

        <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>" style="margin-top: var(--s-4);">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
          <input type="hidden" name="action" value="csv_import">

          <div>
            <label for="csv_text">Zeilen einfügen</label>
            <textarea id="csv_text" class="ui-input" name="csv_text" placeholder="..." style="min-height: 140px;"></textarea>
          </div>

          <div style="margin-top:10px; display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
              <input type="checkbox" name="init_now" value="1">
              <span>Initialisieren „letzte Wartung = jetzt“</span>
            </label>
          </div>

          <div style="margin-top:12px;">
            <button class="ui-btn ui-btn--primary" type="submit" onclick="return confirm('Import starten?');">Import starten</button>
          </div>
        </form>
      </div>

      <!-- Template kopieren -->
      <div class="ui-card" style="margin-top: var(--s-6);">
        <h2 style="margin:0;">Template kopieren</h2>
        <p class="small ui-muted" style="margin-top:8px;">Kopiert alle Wartungspunkte von einer Quelle auf diese Anlage.</p>

        <form method="post" action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>" style="margin-top: var(--s-4);">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
          <input type="hidden" name="action" value="copy_from_asset">

          <div>
            <label for="source_asset_id">Quelle (Asset)</label>
            <select id="source_asset_id" class="ui-input" name="source_asset_id" required>
              <option value="">— wählen —</option>
              <?php foreach ($assets as $a): ?>
                <?php if ((int)$a['id'] === $assetId) continue; ?>
                <option value="<?= (int)$a['id'] ?>">
                  <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="margin-top:10px; display:flex; gap:14px; flex-wrap:wrap; align-items:center;">
            <label style="display:flex; gap:8px; align-items:center; margin:0;">
              <input type="checkbox" name="include_inactive" value="1">
              <span>Auch deaktivierte Punkte mitkopieren</span>
            </label>

            <label style="display:flex; gap:8px; align-items:center; margin:0;">
              <input type="checkbox" name="init_now" value="1">
              <span>Initialisieren „letzte Wartung = jetzt“</span>
            </label>
          </div>

          <div style="margin-top:12px;">
            <button class="ui-btn ui-btn--primary" type="submit" onclick="return confirm('Wirklich kopieren?');">Kopieren</button>
          </div>
        </form>
      </div>

    </div>

    <!-- RECHTS -->
    <div>

      <div class="ui-card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-end;">
          <h2 style="margin:0;">Wartungspunkte</h2>
          <div class="small ui-muted"><?= e(count($punkte)) ?> Punkte</div>
        </div>

        <div style="margin-top: var(--s-4);" class="ui-table-wrap">
          <table class="ui-table">
            <thead>
              <tr>
                <th scope="col" style="width:70px;">ID</th>
                <th scope="col" style="width:90px;">Aktiv</th>
                <th scope="col" style="width:90px;">Typ</th>
                <th scope="col" style="width:130px;">Intervall</th>
                <th scope="col">Text</th>
                <th scope="col" style="width:140px;">Bald fällig</th>
                <th scope="col" class="ui-th-actions" style="width:220px;">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($punkte as $p): ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td>
                    <?php if ((int)$p['aktiv'] === 1): ?>
                      <span class="ui-badge ui-badge--ok">aktiv</span>
                    <?php else: ?>
                      <span class="ui-badge">inaktiv</span>
                    <?php endif; ?>
                  </td>
                  <td><?= e($p['intervall_typ']) ?></td>
                  <td style="white-space:nowrap;"><?= number_format((float)$p['plan_interval'], 1, ',', '.') ?> h</td>
                  <td style="min-width:220px;">
                    <a class="ui-link" href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>&edit_wp=<?= (int)$p['id'] ?>">
                      <?= e($p['text_kurz']) ?>
                    </a>
                    <div class="small ui-muted">WP #<?= (int)$p['id'] ?></div>
                  </td>
                  <td style="white-space:nowrap;">
                    <?php if (!empty($p['soon_hours']) && (float)$p['soon_hours'] > 0): ?>
                      <span><?= number_format((float)$p['soon_hours'], 1, ',', '.') ?> h</span>
                    <?php elseif (isset($p['soon_ratio']) && $p['soon_ratio'] !== null): ?>
                      <span><?= number_format(((float)$p['soon_ratio']) * 100, 1, ',', '.') ?> %</span>
                    <?php else: ?>
                      <span class="ui-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="ui-td-actions" style="white-space:nowrap;">
                    <a class="ui-btn ui-btn--sm ui-btn--primary"
                       href="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>&edit_wp=<?= (int)$p['id'] ?>">
                      Bearbeiten
                    </a>

                    <form method="post"
                          action="<?= e($base) ?>/app.php?r=wartung.admin_punkte&asset_id=<?= (int)$assetId ?>"
                          style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="asset_id" value="<?= (int)$assetId ?>">
                      <input type="hidden" name="wp_id" value="<?= (int)$p['id'] ?>">
                      <button class="ui-btn ui-btn--sm ui-btn--ghost"
                              type="submit"
                              onclick="return confirm('Wartungspunkt wirklich <?= ((int)$p['aktiv']===1 ? 'deaktivieren' : 'aktivieren') ?>?');">
                        <?= ((int)$p['aktiv']===1 ? 'Deaktivieren' : 'Aktivieren') ?>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="small ui-muted" style="margin-top:10px;">
          Hinweis: „Löschen“ = <b>Deaktivieren</b> (audit-sicher). Historie/Protokolle bleiben intakt.
        </p>
      </div>

      <!-- Audit -->
      <div class="ui-card" style="margin-top: var(--s-6);">
        <h2 style="margin:0;">Änderungshistorie (Audit)</h2>

        <?php if (!$edit): ?>
          <p class="small ui-muted" style="margin-top:10px;">
            Wähle oben in der Liste einen Wartungspunkt über „Bearbeiten“, dann siehst du hier die Audit-Historie.
          </p>
        <?php else: ?>
          <p class="small ui-muted" style="margin-top:10px;">
            Wartungspunkt <b>#<?= (int)$edit['id'] ?></b>: <?= e($edit['text_kurz']) ?>
          </p>

          <?php if (empty($auditRows)): ?>
            <p class="small ui-muted">Keine Audit-Einträge vorhanden.</p>
          <?php else: ?>
            <div style="margin-top: var(--s-4);" class="ui-table-wrap">
              <table class="ui-table">
                <thead>
                  <tr>
                    <th scope="col" style="width:170px;">Zeit</th>
                    <th scope="col" style="width:90px;">Action</th>
                    <th scope="col" style="width:160px;">Actor</th>
                    <th scope="col">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($auditRows as $a): ?>
                    <tr>
                      <td style="white-space:nowrap;"><?= e($a['created_at']) ?></td>
                      <td><?= e($a['action']) ?></td>
                      <td><?= e($a['actor_text'] ?: ('user#'.$a['actor_user_id'])) ?></td>
                      <td class="small ui-muted">
                        <?php
                          $new = $a['new_json'] ? short_text($a['new_json'], 160) : '';
                          $old = $a['old_json'] ? short_text($a['old_json'], 160) : '';
                          echo e(trim("old: {$old} | new: {$new}"));
                        ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

    </div>

  </div>

  <?php endif; ?>

</div>