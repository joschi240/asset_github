<?php
// module/stoerungstool/ticket.php (INNER VIEW)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$actor  = $u['anzeigename'] ?? $u['benutzername'] ?? 'user';

$canEdit   = user_can_edit($userId,   'stoerungstool', 'global', null);
$canDelete = user_can_delete($userId, 'stoerungstool', 'global', null);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo '<div class="card"><h2>Fehler</h2><p class="small">Ticket-ID fehlt.</p></div>'; exit; }

$err = '';
$ok  = (int)($_GET['ok'] ?? 0);

function upload_ticket_file(int $ticketId, int $userId): void {
  $cfg = app_cfg();
  $baseDir = $cfg['upload']['base_dir'] ?? (__DIR__ . '/../../uploads');
  $allowed = $cfg['upload']['allowed_mimes'] ?? ['image/jpeg','image/png','image/webp','application/pdf'];
  $maxBytes = (int)($cfg['upload']['max_bytes'] ?? (10*1024*1024));

  $uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($uploadErr !== UPLOAD_ERR_OK) {
    $uploadMsg = match ($uploadErr) {
      UPLOAD_ERR_NO_FILE                          => "Keine Datei ausgewählt.",
      UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE   => "Datei zu groß (Serverlimit überschritten).",
      default                                     => "Upload fehlgeschlagen (Fehlercode {$uploadErr}).",
    };
    throw new RuntimeException($uploadMsg);
  }
  $f = $_FILES['file'];

  if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
    throw new RuntimeException("Datei zu groß oder leer.");
  }

  $tmp = $f['tmp_name'];
  $orig = (string)$f['name'];

  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmp) ?: 'application/octet-stream';
  if (!in_array($mime, $allowed, true)) {
    throw new RuntimeException("Dateityp nicht erlaubt: {$mime}");
  }

  $ext = '';
  if (preg_match('/\\.([a-zA-Z0-9]{1,8})$/', $orig, $m)) $ext = strtolower($m[1]);

  $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . ($ext ? '.'.$ext : '');
  $relPath = "stoerungstool/tickets/{$ticketId}/{$stored}";
  $absPath = rtrim($baseDir, '/\\') . '/' . $relPath;

  $dir = dirname($absPath);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("Upload-Verzeichnis nicht anlegbar.");
  if (!move_uploaded_file($tmp, $absPath)) throw new RuntimeException("Konnte Datei nicht speichern.");

  $sha = hash_file('sha256', $absPath);

  db_exec(
    "INSERT INTO core_dokument
     (modul, referenz_typ, referenz_id, dateiname, originalname, mime, size_bytes, sha256, hochgeladen_am, hochgeladen_von_user_id)
     VALUES ('stoerungstool','ticket',?,?,?,?,?,?, NOW(), ?)",
    [$ticketId, $relPath, $orig, $mime, (int)$f['size'], $sha, $userId ?: null]
  );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);
  $action = (string)($_POST['action'] ?? '');

  if (!$canEdit) {
    $err = 'Keine Berechtigung zum Bearbeiten.';
  } else {
  try {
    $pdo = db();
    $pdo->beginTransaction();

    $ticketOld = db_one("SELECT * FROM stoerungstool_ticket WHERE id=? LIMIT 1", [$id]);
    if (!$ticketOld) throw new RuntimeException("Ticket nicht gefunden.");

    if ($action === 'set_status') {
      $to = (string)($_POST['to'] ?? '');
      if (!in_array($to, ['angenommen','in_arbeit','bestellt','erledigt','geschlossen'], true)) throw new RuntimeException("Ungültiger Status.");

      $assign = null;
      if ($to === 'angenommen' && empty($ticketOld['assigned_user_id'])) $assign = $userId ?: null;

      if ($assign !== null) db_exec("UPDATE stoerungstool_ticket SET status=?, assigned_user_id=?, updated_at=NOW() WHERE id=?", [$to, $assign, $id]);
      else db_exec("UPDATE stoerungstool_ticket SET status=?, updated_at=NOW() WHERE id=?", [$to, $id]);

      // SLA auto-set
      $slaSet = [];
      if ($ticketOld['status'] === 'neu' && empty($ticketOld['first_response_at'])) {
        $slaSet[] = 'first_response_at = NOW()';
      }
      if ($to === 'geschlossen' && empty($ticketOld['closed_at'])) {
        $slaSet[] = 'closed_at = NOW()';
      }
      if ($slaSet) {
        db_exec("UPDATE stoerungstool_ticket SET " . implode(', ', $slaSet) . " WHERE id=?", [$id]);
      }

      db_exec("INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
               VALUES (?, NOW(), ?, ?, ?, NULL)",
              [$id, $userId ?: null, "Status gesetzt: {$to}", $to]);

      audit_log('stoerungstool', 'ticket', $id, 'STATUS',
        ['status'=>$ticketOld['status'], 'assigned_user_id'=>$ticketOld['assigned_user_id']],
        ['status'=>$to, 'assigned_user_id'=>($assign ?? $ticketOld['assigned_user_id'])],
        $userId, $actor
      );

    } elseif ($action === 'assign') {
      $ass = (int)($_POST['assigned_user_id'] ?? 0);
      $ass = $ass > 0 ? $ass : null;

      db_exec("UPDATE stoerungstool_ticket SET assigned_user_id=?, updated_at=NOW() WHERE id=?", [$ass, $id]);
      db_exec("INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
               VALUES (?, NOW(), ?, ?, NULL, NULL)",
              [$id, $userId ?: null, "Zuweisung geändert: " . ($ass ? "User #{$ass}" : "keiner")]);

      audit_log('stoerungstool', 'ticket', $id, 'UPDATE',
        ['assigned_user_id'=>$ticketOld['assigned_user_id']],
        ['assigned_user_id'=>$ass],
        $userId, $actor
      );

    } elseif ($action === 'add_action') {
      $text = trim((string)($_POST['text'] ?? ''));
      if ($text === '') throw new RuntimeException("Text ist Pflicht.");

      $statusNeu = trim((string)($_POST['status_neu'] ?? ''));
      $statusNeu = in_array($statusNeu, ['','neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'], true) ? $statusNeu : '';

      $mins = trim((string)($_POST['arbeitszeit_min'] ?? ''));
      $mins = ($mins === '' ? null : (is_numeric($mins) ? (int)$mins : null));

      db_exec("INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
               VALUES (?, NOW(), ?, ?, ?, ?)",
              [$id, $userId ?: null, $text, ($statusNeu !== '' ? $statusNeu : null), $mins]);

      if ($statusNeu !== '') {
        db_exec("UPDATE stoerungstool_ticket SET status=?, updated_at=NOW() WHERE id=?", [$statusNeu, $id]);

        // SLA auto-set
        $slaSet = [];
        if ($ticketOld['status'] === 'neu' && empty($ticketOld['first_response_at'])) {
          $slaSet[] = 'first_response_at = NOW()';
        }
        if ($statusNeu === 'geschlossen' && empty($ticketOld['closed_at'])) {
          $slaSet[] = 'closed_at = NOW()';
        }
        if ($slaSet) {
          db_exec("UPDATE stoerungstool_ticket SET " . implode(', ', $slaSet) . " WHERE id=?", [$id]);
        }

        audit_log('stoerungstool', 'ticket', $id, 'STATUS',
          ['status'=>$ticketOld['status']],
          ['status'=>$statusNeu],
          $userId, $actor
        );
      } else {
        db_exec("UPDATE stoerungstool_ticket SET updated_at=NOW() WHERE id=?", [$id]);
      }

    } elseif ($action === 'update_ticket') {
      $titel = trim((string)($_POST['titel'] ?? ''));
      $beschreibung = trim((string)($_POST['beschreibung'] ?? ''));
      if ($titel === '' || $beschreibung === '') throw new RuntimeException("Titel + Beschreibung sind Pflicht.");

      $assetId = (int)($_POST['asset_id'] ?? 0);
      $assetId = $assetId > 0 ? $assetId : null;

      $meldungstyp = trim((string)($_POST['meldungstyp'] ?? ''));
      $meldungstyp = ($meldungstyp === '' ? null : $meldungstyp);

      $fachkategorie = trim((string)($_POST['fachkategorie'] ?? ''));
      $fachkategorie = ($fachkategorie === '' ? null : $fachkategorie);

      $prior = (int)($_POST['prioritaet'] ?? 2);
      if (!in_array($prior, [1,2,3], true)) $prior = 2;

      $still = !empty($_POST['maschinenstillstand']) ? 1 : 0;

      $ausfall = trim((string)($_POST['ausfallzeitpunkt'] ?? ''));
      $ausfall = ($ausfall === '' ? null : str_replace('T',' ',$ausfall).':00');

      db_exec("UPDATE stoerungstool_ticket
               SET asset_id=?, titel=?, beschreibung=?, meldungstyp=?, fachkategorie=?, prioritaet=?,
                   maschinenstillstand=?, ausfallzeitpunkt=?, updated_at=NOW()
               WHERE id=?",
              [$assetId, $titel, $beschreibung, $meldungstyp, $fachkategorie, $prior, $still, $ausfall, $id]);

    } elseif ($action === 'upload_doc') {
      upload_ticket_file($id, $userId);

    } else {
      throw new RuntimeException("Unbekannte Aktion.");
    }

    $pdo->commit();
    header("Location: {$base}/app.php?r=stoerung.ticket&id={$id}&ok=1");
    exit;

  } catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $err = $e->getMessage();
  }
  } // end if ($canEdit)
}

$ticket = db_one("
  SELECT t.*, a.code AS asset_code, a.name AS asset_name, ass.anzeigename AS assigned_name
  FROM stoerungstool_ticket t
  LEFT JOIN core_asset a ON a.id=t.asset_id
  LEFT JOIN core_user ass ON ass.id=t.assigned_user_id
  WHERE t.id=?
  LIMIT 1
", [$id]);

if (!$ticket) { echo '<div class="card"><h2>Nicht gefunden</h2></div>'; exit; }

$badge = badge_for_ticket_status($ticket['status']);

$assets = db_all("SELECT id, code, name FROM core_asset WHERE aktiv=1 ORDER BY name ASC");
$users  = db_all("SELECT id, anzeigename, benutzername FROM core_user WHERE aktiv=1 ORDER BY anzeigename ASC, benutzername ASC");

$aktionen = db_all("
  SELECT a.*, u.anzeigename
  FROM stoerungstool_aktion a
  LEFT JOIN core_user u ON u.id=a.user_id
  WHERE a.ticket_id=?
  ORDER BY a.datum DESC, a.id DESC
", [$id]);

$sumRow = db_one("SELECT SUM(COALESCE(arbeitszeit_min,0)) AS m FROM stoerungstool_aktion WHERE ticket_id=?", [$id]);
$sumMin = (int)($sumRow['m'] ?? 0);

$doks = db_all("
  SELECT *
  FROM core_dokument
  WHERE modul='stoerungstool' AND referenz_typ='ticket' AND referenz_id=?
  ORDER BY hochgeladen_am DESC, id DESC
", [$id]);

// Auto-open rules (wie gewünscht)
$openAssign = !empty($ticket['assigned_user_id']);
$openEdit   = ($err !== '');
$openAction = in_array($ticket['status'], ['neu','angenommen','in_arbeit','bestellt'], true);
$openDocs   = !empty($doks);
$openHist   = !empty($aktionen);
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1>Ticket #<?= (int)$ticket['id'] ?></h1>
      <div class="small"><a href="<?= e($base) ?>/app.php?r=stoerung.inbox">← zurück zur Inbox</a></div>
    </div>
    <div>
      <span class="badge <?= e($badge['cls']) ?>"><?= e($badge['label']) ?></span>
      <span class="badge">Prio <?= (int)$ticket['prioritaet'] ?></span>
      <?php if ((int)$ticket['maschinenstillstand']===1): ?><span class="badge badge--r">Stillstand</span><?php endif; ?>
    </div>
  </div>

  <?php if ($ok): ?><p class="badge badge--g" role="status">Gespeichert.</p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="badge badge--r" role="alert"><?= e($err) ?></p><?php endif; ?>

  <div class="small" style="margin-top:10px;">
    Anlage: <b><?= e(trim(($ticket['asset_code'] ?: '').' '.($ticket['asset_name'] ?: ''))) ?: '—' ?></b>
    · Typ: <b><?= e($ticket['meldungstyp'] ?: '—') ?></b>
    · Fach: <b><?= e($ticket['fachkategorie'] ?: $ticket['kategorie'] ?: '—') ?></b>
    · Ausfall: <b><?= e($ticket['ausfallzeitpunkt'] ?: '—') ?></b>
    · Zugewiesen: <b><?= e($ticket['assigned_name'] ?: '—') ?></b>
    · Summe Arbeitszeit: <b><?= sprintf('%02d:%02d', intdiv($sumMin,60), $sumMin%60) ?></b>
  </div>

  <div style="margin-top:12px;">
    <h2 style="margin:0 0 8px;">Quick Status</h2>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$id ?>" style="display:flex; gap:8px; flex-wrap:wrap;">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="set_status">
      <button class="btn btn--ghost" name="to" value="angenommen">angenommen</button>
      <button class="btn btn--ghost" name="to" value="in_arbeit">in Arbeit</button>
      <button class="btn btn--ghost" name="to" value="bestellt">bestellt</button>
      <button class="btn btn--ghost" name="to" value="erledigt">erledigt</button>
      <button class="btn btn--ghost" name="to" value="geschlossen">geschlossen</button>
    </form>
    <p class="small" style="margin-top:6px;">Statuswechsel schreibt automatisch Aktion + Auditlog.</p>
    <?php else: ?>
    <p class="small">Kein Bearbeitungsrecht für Störungstickets.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <details <?= $openAssign ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">Zuweisung</summary>
    <div style="margin-top:10px;">
      <?php if ($canEdit): ?>
      <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="assign">
        <label for="ticket_assign_user">Instandhalter</label>
        <select id="ticket_assign_user" name="assigned_user_id">
          <option value="0">— niemand —</option>
          <?php foreach ($users as $uu): ?>
            <option value="<?= (int)$uu['id'] ?>" <?= ((int)$ticket['assigned_user_id']===(int)$uu['id']?'selected':'') ?>>
              <?= e($uu['anzeigename'] ?: $uu['benutzername']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:10px;">
          <button class="btn" type="submit">Zuweisen</button>
        </div>
      </form>
      <?php else: ?>
      <p class="small">Zugewiesen: <?= e($ticket['assigned_name'] ?: '—') ?></p>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="card">
  <details <?= $openEdit ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">Ticket bearbeiten</summary>
    <div style="margin-top:10px;">
      <?php if ($canEdit): ?>
      <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_ticket">

        <label for="ticket_asset_id">Anlage</label>
        <select id="ticket_asset_id" name="asset_id">
          <option value="0">— ohne Anlage —</option>
          <?php foreach ($assets as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$ticket['asset_id']===(int)$a['id']?'selected':'') ?>>
              <?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="ticket_meldungstyp">Meldungsart (Typ)</label>
        <input id="ticket_meldungstyp" name="meldungstyp" value="<?= e($ticket['meldungstyp'] ?? '') ?>">

        <label for="ticket_fachkat">Fachkategorie</label>
        <input id="ticket_fachkat" name="fachkategorie" value="<?= e($ticket['fachkategorie'] ?? '') ?>">

        <label for="ticket_prio">Priorität</label>
        <select id="ticket_prio" name="prioritaet">
          <option value="1" <?= ((int)$ticket['prioritaet']===1?'selected':'') ?>>1</option>
          <option value="2" <?= ((int)$ticket['prioritaet']===2?'selected':'') ?>>2</option>
          <option value="3" <?= ((int)$ticket['prioritaet']===3?'selected':'') ?>>3</option>
        </select>

        <label><input type="checkbox" name="maschinenstillstand" value="1" <?= ((int)$ticket['maschinenstillstand']===1?'checked':'') ?>> Maschinenstillstand</label>

        <label for="ticket_ausfall">Ausfallzeitpunkt</label>
        <input id="ticket_ausfall" type="datetime-local" name="ausfallzeitpunkt" value="<?= $ticket['ausfallzeitpunkt'] ? e(str_replace(' ','T', substr($ticket['ausfallzeitpunkt'],0,16))) : '' ?>">

        <label for="ticket_titel">Titel</label>
        <input id="ticket_titel" name="titel" required aria-required="true" value="<?= e($ticket['titel']) ?>">

        <label for="ticket_beschreibung">Beschreibung</label>
        <textarea id="ticket_beschreibung" name="beschreibung" required aria-required="true"><?= e($ticket['beschreibung']) ?></textarea>

        <div style="margin-top:10px;">
          <button class="btn" type="submit">Speichern</button>
        </div>
      </form>
      <?php else: ?>
      <p class="small">Kein Bearbeitungsrecht.</p>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="card">
  <details <?= $openAction ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">Aktion hinzufügen</summary>
    <div style="margin-top:10px;">
      <?php if ($canEdit): ?>
      <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_action">

        <label for="ticket_status_neu">Status ändern (optional)</label>
        <select id="ticket_status_neu" name="status_neu">
          <option value="">— keine Änderung —</option>
          <option value="neu">neu</option>
          <option value="angenommen">angenommen</option>
          <option value="in_arbeit">in Arbeit</option>
          <option value="bestellt">bestellt</option>
          <option value="erledigt">erledigt</option>
          <option value="geschlossen">geschlossen</option>
        </select>

        <label for="ticket_arbeitszeit">Arbeitszeit (Minuten, optional)</label>
        <input id="ticket_arbeitszeit" name="arbeitszeit_min" inputmode="numeric" placeholder="z.B. 20">

        <label>Schnelleingabe (Template)</label>
        <select onchange="if(this.value){document.getElementById('aktion_text').value=this.value;this.selectedIndex=0;}">
          <option value="">— Template wählen —</option>
          <option value="Teil bestellt">Teil bestellt</option>
          <option value="Warten auf Lieferung">Warten auf Lieferung</option>
          <option value="Techniker unterwegs">Techniker unterwegs</option>
          <option value="Fehler analysiert, Reparatur läuft">Fehler analysiert, Reparatur läuft</option>
          <option value="Reparatur abgeschlossen, Funktionstest läuft">Reparatur abgeschlossen, Funktionstest läuft</option>
          <option value="Anlage wieder in Betrieb">Anlage wieder in Betrieb</option>
        </select>

        <label for="aktion_text">Text</label>
        <textarea id="aktion_text" name="text" required aria-required="true"></textarea>

        <div style="margin-top:10px;">
          <button class="btn" type="submit">Aktion speichern</button>
        </div>
      </form>
      <?php else: ?>
      <p class="small">Kein Bearbeitungsrecht.</p>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="card">
  <details open>
    <summary style="cursor:pointer; font-weight:600;">Timeline</summary>
    <div style="margin-top:10px; border-left:3px solid #ccc; padding-left:16px;">
      <?php if (!$aktionen): ?>
        <p class="small">Noch keine Aktionen.</p>
      <?php else: ?>
        <?php foreach (array_reverse($aktionen) as $a): $b2 = $a['status_neu'] ? badge_for_ticket_status($a['status_neu']) : null; ?>
        <div style="margin-bottom:14px;">
          <div class="small" style="color:#666;">
            <?= e($a['datum']) ?> · <b><?= e($a['anzeigename'] ?: '—') ?></b>
            <?php if ($b2): ?>
              &nbsp;<span class="badge <?= e($b2['cls']) ?>"><?= e($b2['label']) ?></span>
            <?php endif; ?>
            <?php if ($a['arbeitszeit_min'] !== null): ?>
              &nbsp;· <?= (int)$a['arbeitszeit_min'] ?> min
            <?php endif; ?>
          </div>
          <div style="margin-top:2px;"><?= e($a['text']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="card">
  <details <?= $openDocs ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">Dokumente</summary>
    <div style="margin-top:10px;">
      <?php if ($canEdit): ?>
      <form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$id ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_doc">
        <label for="ticket_doc_file">Datei (jpg/png/webp/pdf)</label>
        <input id="ticket_doc_file" type="file" name="file" required>
        <div style="margin-top:10px;">
          <button class="btn" type="submit">Hochladen</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (!$doks): ?>
        <p class="small" style="margin-top:10px;">Keine Dokumente.</p>
      <?php else: ?>
        <table class="table" style="margin-top:10px;">
          <thead><tr><th scope="col">Datum</th><th scope="col">Datei</th><th scope="col">Typ</th></tr></thead>
          <tbody>
          <?php foreach ($doks as $d): ?>
            <tr>
              <td><?= e($d['hochgeladen_am']) ?></td>
              <td><a href="<?= e($base) ?>/uploads/<?= e($d['dateiname']) ?>" target="_blank" rel="noopener">
                <?= e($d['originalname'] ?: $d['dateiname']) ?>
              </a></td>
              <td class="small"><?= e($d['mime'] ?: '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </details>
</div>

<div class="card">
  <details <?= $openHist ? 'open' : '' ?>>
    <summary style="cursor:pointer; font-weight:600;">Historie</summary>
    <div style="margin-top:10px;">
      <?php if (!$aktionen): ?>
        <p class="small">Noch keine Aktionen.</p>
      <?php else: ?>
        <table class="table">
          <thead><tr><th scope="col">Datum</th><th scope="col">User</th><th scope="col">Status</th><th scope="col">Min</th><th scope="col">Text</th></tr></thead>
          <tbody>
          <?php foreach ($aktionen as $a): ?>
            <tr>
              <td><?= e($a['datum']) ?></td>
              <td><?= e($a['anzeigename'] ?: '—') ?></td>
              <td><?= e($a['status_neu'] ?: '—') ?></td>
              <td><?= $a['arbeitszeit_min'] !== null ? (int)$a['arbeitszeit_min'] : '—' ?></td>
              <td><?= e($a['text']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </details>
</div>