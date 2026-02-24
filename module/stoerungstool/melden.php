<?php
// module/stoerungstool/melden.php (INNER VIEW, require_login=0 in route)
require_once __DIR__ . '/../../src/helpers.php';

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$assets = db_all("SELECT id, code, name FROM core_asset WHERE aktiv=1 ORDER BY name ASC");

$ok = null;
$err = null;

function upload_first_ticket_file(int $ticketId): void {
  if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) return; // optional
  // reuse simple logic from ticket.php-like behavior (minimal duplicate)
  $cfg = app_cfg();
  $baseDir = $cfg['upload']['base_dir'] ?? (__DIR__ . '/../../uploads');
  $allowed = $cfg['upload']['allowed_mimes'] ?? ['image/jpeg','image/png','image/webp','application/pdf'];
  $maxBytes = (int)($cfg['upload']['max_bytes'] ?? (10*1024*1024));

  if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Upload fehlgeschlagen.");
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
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("Upload-Verzeichnis nicht anlegbar.");
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

<div class="card">
  <h1>Neue Meldung</h1>

  <?php if ($ok): ?><p class="badge badge--g"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="badge badge--r"><?= e($err) ?></p><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label>Maschine/Anlage</label>
    <select name="asset_id">
      <option value="">Bitte auswählen (optional)</option>
      <?php foreach ($assets as $a): ?>
        <option value="<?= (int)$a['id'] ?>"><?= e(($a['code'] ? $a['code'].' — ' : '') . $a['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Meldungsart</label>
    <select name="meldungstyp">
      <option value="Störmeldung">Störmeldung</option>
      <option value="Mängelkarte">Mängelkarte</option>
      <option value="Logeintrag">Logeintrag</option>
    </select>

    <label>Fachkategorie (optional)</label>
    <input name="fachkategorie" placeholder="z.B. Mechanik / Elektrik / Sicherheit / Qualität">

    <label><input type="checkbox" name="maschinenstillstand" value="1"> Maschinenstillstand</label>

    <label>Name (optional)</label>
    <input name="name" placeholder="z.B. Max Mustermann">

    <label>Kontakt (optional)</label>
    <input name="kontakt" placeholder="z.B. Tel / Schicht / Bereich">

    <label>Ausfallzeitpunkt (optional)</label>
    <input type="datetime-local" name="ausfallzeitpunkt">

    <label>Priorität</label>
    <select name="prioritaet">
      <option value="1">1 (hoch)</option>
      <option value="2" selected>2</option>
      <option value="3">3 (niedrig)</option>
    </select>

    <label>Titel (optional)</label>
    <input name="titel" placeholder="z.B. Palettenwechsler klemmt">

    <label>Fehlerbeschreibung</label>
    <textarea name="beschreibung" required placeholder="Was ist passiert?"></textarea>

    <label>Foto / PDF (optional)</label>
    <input type="file" name="file">

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Absenden</button>
    </div>
  </form>
</div>