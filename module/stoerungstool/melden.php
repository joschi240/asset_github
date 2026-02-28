<?php
// module/stoerungstool/melden.php (INNER VIEW, require_login=0 in route)
require_once __DIR__ . '/../../src/helpers.php';

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$assets = db_all("SELECT id, code, name FROM core_asset WHERE aktiv=1 ORDER BY name ASC");

$ok = null;
$err = null;
$stillChecked = !empty($_POST['maschinenstillstand']);

function upload_first_ticket_file(int $ticketId): void {
  if (empty($_FILES['file']) || !isset($_FILES['file']['error'])) return; // optional
  $err = $_FILES['file']['error'];
  if ($err !== UPLOAD_ERR_OK) {
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
      throw new RuntimeException("Datei zu groß. Bitte maximal 20 MB pro Datei hochladen.");
    } elseif ($err === UPLOAD_ERR_NO_FILE) {
      return; // optional
    } elseif ($err === UPLOAD_ERR_PARTIAL) {
      throw new RuntimeException("Upload unvollständig. Bitte erneut versuchen.");
    } else {
      throw new RuntimeException("Upload fehlgeschlagen (Fehlercode $err).");
    }
  }
  $cfg = app_cfg();
  $baseDir = $cfg['upload']['base_dir'] ?? (__DIR__ . '/../../uploads');
  $allowed = $cfg['upload']['allowed_mimes'] ?? ['image/jpeg','image/png','image/webp','application/pdf'];
  $maxBytes = (int)($cfg['upload']['max_bytes'] ?? (10*1024*1024));
  $f = $_FILES['file'];
  if ($f['size'] <= 0 || $f['size'] > $maxBytes) throw new RuntimeException("Datei zu groß oder leer.");
  $tmp = $f['tmp_name'];
  $orig = (string)$f['name'];
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmp) ?: 'application/octet-stream';
  if (!in_array($mime, $allowed, true)) throw new RuntimeException("Dateityp nicht erlaubt: {$mime}");
  $ext = '';
  if (preg_match('/\\.([a-zA-Z0-9]{1,8})$/', $orig, $m)) $ext = strtolower($m[1]);
  $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . ($ext ? '.'.$ext : '');
  $relPath = "stoerungstool/tickets/{$ticketId}/{$stored}";
  $absPath = rtrim($baseDir, '/\\') . '/' . $relPath;
  $dir = dirname($absPath);
  ensure_dir($dir);
  if (!move_uploaded_file($tmp, $absPath)) throw new RuntimeException("Konnte Datei nicht speichern.");
  $sha = hash_file('sha256', $absPath);
  $u = current_user();
  $userId = (int)($u['id'] ?? 0);
  db_exec(
    "INSERT INTO core_dokument
     (modul, referenz_typ, referenz_id, dateiname, originalname, mime, size_bytes, sha256, hochgeladen_am, hochgeladen_von_user_id)
     VALUES ('stoerungstool','ticket',?,?,?,?,?,?, NOW(), ?)",
    [$ticketId, $relPath, $orig, $mime, (int)$f['size'], $sha, $userId ?: null]
  );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check($_POST['csrf'] ?? null);

  $assetId = $_POST['asset_id'] !== '' ? (int)$_POST['asset_id'] : null;

  $meldungstyp = trim((string)($_POST['meldungstyp'] ?? 'Störmeldung'));
  if ($meldungstyp === '') $meldungstyp = 'Störmeldung';

  $fachkategorie = trim((string)($_POST['fachkategorie'] ?? ''));
  $fachkategorie = ($fachkategorie === '' ? null : $fachkategorie);

  $still = !empty($_POST['maschinenstillstand']) ? 1 : 0;

  $name = trim((string)($_POST['name'] ?? ''));
  $kontakt = trim((string)($_POST['kontakt'] ?? ''));
  $kontakt = ($kontakt === '' ? null : $kontakt);

  $ausfall = trim((string)($_POST['ausfallzeitpunkt'] ?? '')); // datetime-local
  $ausfall = ($ausfall === '' ? null : str_replace('T',' ',$ausfall).':00');

  $prio = (int)($_POST['prioritaet'] ?? 2);
  if (!in_array($prio, [1,2,3], true)) $prio = 2;

  $titel = trim((string)($_POST['titel'] ?? ''));
  $text  = trim((string)($_POST['beschreibung'] ?? ''));

  if ($text === '') {
    $err = "Bitte Fehlerbeschreibung eingeben.";
  } else {
    if ($titel === '') $titel = $meldungstyp . ($still ? ' (Stillstand)' : '');

    try {
      $pdo = db();
      $pdo->beginTransaction();

      db_exec(
        "INSERT INTO stoerungstool_ticket
         (asset_id, titel, beschreibung, meldungstyp, fachkategorie, prioritaet, status, gemeldet_von, kontakt, anonym,
          maschinenstillstand, ausfallzeitpunkt, created_at)
         VALUES (?,?,?,?,?, ?, 'neu', ?, ?, 0, ?, ?, NOW())",
        [
          $assetId,
          $titel,
          $text,
          $meldungstyp,
          $fachkategorie,
          $prio,
          ($name !== '' ? $name : null),
          $kontakt,
          $still,
          $ausfall
        ]
      );
      $ticketId = (int)$pdo->lastInsertId();

      db_exec(
        "INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
         VALUES (?, NOW(), NULL, 'Meldung erfasst', 'neu', NULL)",
        [$ticketId]
      );

      // optional Foto/PDF
      upload_first_ticket_file($ticketId);

      $pdo->commit();
      $ok = "Danke! Ticket #{$ticketId} wurde angelegt.";

    } catch (Throwable $e) {
      if (db()->inTransaction()) db()->rollBack();
      $err = "Fehler: " . $e->getMessage();
    }
  }
}
?>

<div class="ui-container">
  <div class="ui-page-header">
    <h1 class="ui-page-title">Neue Störung melden</h1>
    <p class="ui-page-subtitle ui-muted">
      Meldung direkt erfassen und optional mit Foto/PDF dokumentieren.
      <span class="ui-muted">·</span>
      <a class="ui-link" href="<?= e($base) ?>/app.php?r=stoerung.inbox">zur Inbox</a>
    </p>

    <?php if ($ok || $err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($ok): ?><span class="ui-badge ui-badge--ok" role="status"><?= e($ok) ?></span><?php endif; ?>
        <?php if ($err): ?><span class="ui-badge ui-badge--danger" role="alert"><?= e($err) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ui-card">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:end;">
        <div>
          <label for="melden_asset_id">Maschine/Anlage</label>
          <select id="melden_asset_id" name="asset_id" class="ui-input">
            <option value="">Bitte auswählen (optional)</option>
            <?php foreach ($assets as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="melden_typ">Meldungsart</label>
          <select id="melden_typ" name="meldungstyp" class="ui-input">
            <option value="Störmeldung">Störmeldung</option>
            <option value="Mängelkarte">Mängelkarte</option>
            <option value="Logeintrag">Logeintrag</option>
          </select>
        </div>

        <div>
          <label for="melden_fachkat">Fachkategorie (optional)</label>
          <input id="melden_fachkat" class="ui-input" name="fachkategorie" placeholder="z.B. Mechanik / Elektrik / Sicherheit / Qualität">
        </div>

        <div>
          <label for="melden_prio">Priorität</label>
          <select id="melden_prio" name="prioritaet" class="ui-input">
            <option value="1">1 (hoch)</option>
            <option value="2" selected>2</option>
            <option value="3">3 (niedrig)</option>
          </select>
        </div>

        <div>
          <label for="melden_name">Name (optional)</label>
          <input id="melden_name" class="ui-input" name="name" placeholder="z.B. Max Mustermann">
        </div>

        <div>
          <label for="melden_kontakt">Kontakt (optional)</label>
          <input id="melden_kontakt" class="ui-input" name="kontakt" placeholder="z.B. Tel / Schicht / Bereich">
        </div>

        <div>
          <label for="melden_ausfall">Ausfallzeitpunkt (optional)</label>
          <input id="melden_ausfall" class="ui-input" type="datetime-local" name="ausfallzeitpunkt">
        </div>

        <div>
          <label style="margin-bottom:6px; display:block;">Stillstand</label>
          <div style="display:inline-flex; align-items:center; gap:8px;">
            <input id="melden_stillstand" type="checkbox" name="maschinenstillstand" value="1" <?= $stillChecked ? 'checked' : '' ?>>
            <label for="melden_stillstand" style="margin:0; display:inline; font-weight:600;">Maschinenstillstand</label>
          </div>
        </div>
      </div>

      <div style="margin-top:12px; display:grid; grid-template-columns: 1fr; gap:12px;">
        <div>
          <label for="melden_titel">Titel (optional)</label>
          <input id="melden_titel" class="ui-input" name="titel" placeholder="z.B. Palettenwechsler klemmt">
        </div>

        <div>
          <label for="melden_beschreibung">Fehlerbeschreibung</label>
          <textarea id="melden_beschreibung" class="ui-input" name="beschreibung" required aria-required="true" placeholder="Was ist passiert?" style="min-height:120px;"></textarea>
        </div>

        <div>
          <label for="melden_file">Foto / PDF (optional)</label>
          <input id="melden_file" class="ui-input" type="file" name="file">
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <button class="ui-btn ui-btn--primary" type="submit">Absenden</button>
          <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=stoerung.inbox">Abbrechen</a>
        </div>
      </div>
    </form>
  </div>
</div>