<?php
// module/stoerungstool/inbox.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$canEdit = user_can_edit($userId, 'stoerungstool', 'global', null);

$assetId   = (int)($_GET['asset_id'] ?? 0);
$status    = trim((string)($_GET['status'] ?? 'offen')); // offen|alle|neu|angenommen|in_arbeit|bestellt|erledigt|geschlossen
$meldungstyp = trim((string)($_GET['meldungstyp'] ?? '')); // ''=alle
$fachkat   = trim((string)($_GET['fachkategorie'] ?? ''));
$prio      = trim((string)($_GET['prio'] ?? ''));
$q         = trim((string)($_GET['q'] ?? ''));
$showDone  = !empty($_GET['show_done']) ? 1 : 0;
$showOlder = !empty($_GET['show_older']) ? 1 : 0;
$onlyStop  = !empty($_GET['only_stop']) ? 1 : 0;
$onlyUnassigned = !empty($_GET['only_unassigned']) ? 1 : 0;

function short_text(?string $s, int $max=90): string {
  $s = trim((string)$s);
  if ($s === '') return '';
  if (mb_strlen($s) <= $max) return $s;
  return mb_substr($s, 0, $max-1).'…';
}
function fmt_minutes(int $min): string {
  $h = intdiv($min, 60);
  $m = $min % 60;
  return sprintf('%02d:%02d', $h, $m);
}
function inbox_status_url(string $base, string $newStatus, int $assetId, string $meldungstyp, string $fachkat, string $prio, string $q, int $showDone, int $showOlder, int $onlyStop, int $onlyUnassigned): string {
  $p = ['r' => 'stoerung.inbox', 'status' => $newStatus];
  if ($assetId > 0) $p['asset_id'] = $assetId;
  if ($meldungstyp !== '') $p['meldungstyp'] = $meldungstyp;
  if ($fachkat !== '') $p['fachkategorie'] = $fachkat;
  if ($prio !== '') $p['prio'] = $prio;
  if ($q !== '') $p['q'] = $q;
  if ($showDone) $p['show_done'] = '1';
  if ($showOlder) $p['show_older'] = '1';
  if ($onlyStop) $p['only_stop'] = '1';
  if ($onlyUnassigned) $p['only_unassigned'] = '1';
  return $base . '/app.php?' . http_build_query($p);
}

$assets = db_all("SELECT id, code, name FROM core_asset WHERE aktiv=1 ORDER BY name ASC");

// Dropdowns (distinct)
$typRows = db_all("
  SELECT DISTINCT meldungstyp
  FROM stoerungstool_ticket
  WHERE meldungstyp IS NOT NULL AND meldungstyp <> ''
  ORDER BY meldungstyp ASC
");
$katRows = db_all("
  SELECT DISTINCT COALESCE(NULLIF(fachkategorie,''), NULLIF(kategorie,'')) AS k
  FROM stoerungstool_ticket
  WHERE COALESCE(NULLIF(fachkategorie,''), NULLIF(kategorie,'')) IS NOT NULL
  ORDER BY k ASC
");

// WHERE build
$where = ["1=1"];
$params = [];

if ($assetId > 0) { $where[] = "t.asset_id=?"; $params[] = $assetId; }
if ($meldungstyp !== '') { $where[] = "t.meldungstyp=?"; $params[] = $meldungstyp; }
if ($fachkat !== '') { $where[] = "COALESCE(NULLIF(t.fachkategorie,''), NULLIF(t.kategorie,'')) = ?"; $params[] = $fachkat; }
if ($prio !== '' && in_array($prio, ['1','2','3'], true)) { $where[] = "t.prioritaet=?"; $params[] = (int)$prio; }

if ($onlyStop) $where[] = "t.maschinenstillstand=1";
if ($onlyUnassigned) $where[] = "t.assigned_user_id IS NULL";

if ($status === 'offen') {
  $where[] = "t.status IN ('neu','angenommen','in_arbeit','bestellt')";
} elseif ($status !== 'alle' && in_array($status, ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'], true)) {
  $where[] = "t.status=?";
  $params[] = $status;
}

if (!$showDone && ($status === 'offen' || $status === 'alle')) {
  $where[] = "t.status NOT IN ('erledigt','geschlossen')";
}

if (!$showOlder) {
  $where[] = "t.created_at >= (NOW() - INTERVAL 30 DAY)";
}

if ($q !== '') {
  $like = '%'.$q.'%';
  $where[] = "(
    t.titel LIKE ?
    OR t.beschreibung LIKE ?
    OR t.gemeldet_von LIKE ?
    OR t.kontakt LIKE ?
    OR a.code LIKE ?
    OR a.name LIKE ?
    OR EXISTS (SELECT 1 FROM stoerungstool_aktion ax WHERE ax.ticket_id=t.id AND ax.text LIKE ?)
  )";
  array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$whereSql = implode(" AND ", $where);

// Last action + sum minutes
$sql = "
SELECT
  t.*,
  a.code AS asset_code, a.name AS asset_name,
  ass.anzeigename AS assigned_name,
  la.datum AS last_action_at,
  la.text  AS last_action_text,
  u.anzeigename AS last_action_user_name,
  sm.sum_min
FROM stoerungstool_ticket t
LEFT JOIN core_asset a ON a.id=t.asset_id
LEFT JOIN core_user ass ON ass.id=t.assigned_user_id
LEFT JOIN (
  SELECT a1.*
  FROM stoerungstool_aktion a1
  INNER JOIN (
    SELECT ticket_id, MAX(datum) AS max_datum
    FROM stoerungstool_aktion
    GROUP BY ticket_id
  ) x ON x.ticket_id=a1.ticket_id AND x.max_datum=a1.datum
) la ON la.ticket_id=t.id
LEFT JOIN core_user u ON u.id=la.user_id
LEFT JOIN (
  SELECT ticket_id, SUM(COALESCE(arbeitszeit_min,0)) AS sum_min
  FROM stoerungstool_aktion
  GROUP BY ticket_id
) sm ON sm.ticket_id=t.id
WHERE $whereSql
ORDER BY
  FIELD(t.status,'neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'),
  t.prioritaet ASC,
  t.ausfallzeitpunkt DESC,
  t.created_at DESC
LIMIT 300
";
$tickets = db_all($sql, $params);

// Auto-open Filter nur wenn wirklich Filter aktiv (defaults zählen nicht)
$filterActive = false;
if ($assetId > 0) $filterActive = true;
if ($status !== 'offen') $filterActive = true;
if ($meldungstyp !== '') $filterActive = true;
if ($fachkat !== '') $filterActive = true;
if ($prio !== '') $filterActive = true;
if ($q !== '') $filterActive = true;
if ($showDone) $filterActive = true;
if ($showOlder) $filterActive = true;
if ($onlyStop) $filterActive = true;
if ($onlyUnassigned) $filterActive = true;

$activeCount = 0;
foreach ([$assetId>0,$status!=='offen',$meldungstyp!=='',$fachkat!=='',$prio!=='',$q!=='',$showDone,$showOlder,$onlyStop,$onlyUnassigned] as $b) {
  if ($b) $activeCount++;
}
?>

<div class="card">
  <h1>Störungen – Inbox</h1>
  <p class="small">Ampel, Filter, Suche (inkl. Aktionen), Zuweisung und Status „bestellt“.</p>

  <details <?= $filterActive ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">
      Filter <?= $filterActive ? '(' . (int)$activeCount . ' aktiv)' : '(keine aktiv)' ?>
    </summary>

    <form method="get" action="<?= e($base) ?>/app.php" class="grid" style="align-items:end; margin-top:10px;">
      <input type="hidden" name="r" value="stoerung.inbox">

      <div class="col-6">
        <label>Maschine/Anlage</label>
        <select name="asset_id">
          <option value="0">Alle</option>
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===$assetId?'selected':'') ?>>
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6">
        <label>Status</label>
        <select name="status">
          <option value="offen" <?= $status==='offen'?'selected':'' ?>>offen</option>
          <option value="alle" <?= $status==='alle'?'selected':'' ?>>alle</option>
          <option value="neu" <?= $status==='neu'?'selected':'' ?>>neu</option>
          <option value="angenommen" <?= $status==='angenommen'?'selected':'' ?>>angenommen</option>
          <option value="in_arbeit" <?= $status==='in_arbeit'?'selected':'' ?>>in Arbeit</option>
          <option value="bestellt" <?= $status==='bestellt'?'selected':'' ?>>bestellt</option>
          <option value="erledigt" <?= $status==='erledigt'?'selected':'' ?>>erledigt</option>
          <option value="geschlossen" <?= $status==='geschlossen'?'selected':'' ?>>geschlossen</option>
        </select>
      </div>

      <div class="col-6">
        <label>Typ (Meldungsart)</label>
        <select name="meldungstyp">
          <option value="">Alle</option>
          <?php foreach ($typRows as $r): ?>
            <option value="<?= e($r['meldungstyp']) ?>" <?= ($meldungstyp===$r['meldungstyp']?'selected':'') ?>>
              <?= e($r['meldungstyp']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6">
        <label>Fachkategorie</label>
        <select name="fachkategorie">
          <option value="">Alle</option>
          <?php foreach ($katRows as $r): if (!$r['k']) continue; ?>
            <option value="<?= e($r['k']) ?>" <?= ($fachkat===$r['k']?'selected':'') ?>><?= e($r['k']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6">
        <label>Priorität</label>
        <select name="prio">
          <option value="">Alle</option>
          <option value="1" <?= $prio==='1'?'selected':'' ?>>1 (hoch)</option>
          <option value="2" <?= $prio==='2'?'selected':'' ?>>2</option>
          <option value="3" <?= $prio==='3'?'selected':'' ?>>3 (niedrig)</option>
        </select>
      </div>

      <div class="col-6">
        <label>Suche</label>
        <input name="q" value="<?= e($q) ?>" placeholder="Titel, Beschreibung, Anlage, Aktionen...">
      </div>

      <div class="col-12">
        <label>Optionen</label>
        <div class="small">
          <label style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="checkbox" name="show_done" value="1" <?= $showDone?'checked':'' ?>>
            erledigte/geschlossene anzeigen
          </label>
          <label style="display:flex; gap:8px; align-items:center; margin:6px 0 0;">
            <input type="checkbox" name="show_older" value="1" <?= $showOlder?'checked':'' ?>>
            älter als 30 Tage anzeigen
          </label>
          <label style="display:flex; gap:8px; align-items:center; margin:6px 0 0;">
            <input type="checkbox" name="only_stop" value="1" <?= $onlyStop?'checked':'' ?>>
            nur Maschinenstillstand
          </label>
          <label style="display:flex; gap:8px; align-items:center; margin:6px 0 0;">
            <input type="checkbox" name="only_unassigned" value="1" <?= $onlyUnassigned?'checked':'' ?>>
            nur nicht zugewiesen
          </label>
        </div>
      </div>

      <div class="col-12" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=stoerung.inbox">Reset</a>
      </div>
    </form>
  </details>
</div>

<div class="card">
  <h2>Tickets (<?= count($tickets) ?>)</h2>

  <table class="table">
    <thead>
      <tr>
        <th>Ampel</th>
        <th>Typ</th>
        <th>Erfasst am</th>
        <th>Anlage</th>
        <th>Melder</th>
        <th>Bemerkung</th>
        <th>Instandh Name</th>
        <th>Dauer</th>
        <th>Bearbeiten</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tickets as $t): $b = badge_for_ticket_status($t['status']); ?>
      <tr>
        <td><a href="<?= e(inbox_status_url($base, $t['status'], $assetId, $meldungstyp, $fachkat, $prio, $q, $showDone, $showOlder, $onlyStop, $onlyUnassigned)) ?>" style="text-decoration:none;"><span class="badge <?= e($b['cls']) ?>"><?= e($b['label']) ?></span></a></td>
        <td>
          <?= e($t['meldungstyp'] ?: '—') ?>
          <div class="small"><?= e($t['fachkategorie'] ?: $t['kategorie'] ?: '') ?></div>
        </td>
        <td><?= e($t['ausfallzeitpunkt'] ?: $t['created_at']) ?></td>
        <td><?= e(trim(($t['asset_code'] ?: '').' '.($t['asset_name'] ?: ''))) ?: '—' ?></td>
        <td><?= e($t['gemeldet_von'] ?: '—') ?></td>
        <td><?= e(short_text($t['last_action_text'] ?? $t['beschreibung'] ?? '', 90)) ?></td>
        <td><?= e($t['assigned_name'] ?: $t['last_action_user_name'] ?: '—') ?></td>
        <td><?= e(fmt_minutes((int)($t['sum_min'] ?? 0))) ?></td>
        <td><a class="btn btn--ghost" href="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$t['id'] ?>"><?= $canEdit ? 'Bearbeiten' : 'Ansehen' ?></a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$tickets): ?>
      <tr><td colspan="9" class="small">Keine Tickets gefunden.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>