<?php
// module/wartungstool/punkt_save.php (INNER, POST)
require_once __DIR__ . '/../../src/helpers.php';
require_login();

// Bearbeiten-Recht erforderlich
require_can_edit('wartungstool', 'global', null);

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: {$base}/app.php?r=wartung.dashboard");
  exit;
}

csrf_check($_POST['csrf'] ?? null);

$wpId = (int)($_POST['wp_id'] ?? 0);
if ($wpId <= 0) {
  header("Location: {$base}/app.php?r=wartung.dashboard");
  exit;
}

$team = trim((string)($_POST['team_text'] ?? ''));
$bem  = trim((string)($_POST['bemerkung'] ?? ''));
$statusIn = trim((string)($_POST['status'] ?? 'ok'));
$createTicketRequested = !empty($_POST['create_ticket']) ? 1 : 0;

$messwert = null;
if (isset($_POST['messwert']) && $_POST['messwert'] !== '') {
  $mw = str_replace(',', '.', (string)$_POST['messwert']);
  if (!is_numeric($mw)) {
    header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&err=" . urlencode("Messwert ist nicht numerisch."));
    exit;
  }
  $messwert = (float)$mw;
}

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$actor = $u['anzeigename'] ?? $u['benutzername'] ?? 'user';

// Ticket-Recht prüfen
$canCreateTicket = user_can_edit($userId, 'stoerungstool', 'global', null);

$wp = db_one(
  "SELECT
      wp.*,
      a.id AS asset_id, a.code AS asset_code, a.name AS asset_name,
      COALESCE(rc.productive_hours, 0) AS productive_hours
   FROM wartungstool_wartungspunkt wp
   JOIN core_asset a ON a.id = wp.asset_id
   LEFT JOIN core_runtime_counter rc ON rc.asset_id = a.id
   WHERE wp.id=? AND wp.aktiv=1
   LIMIT 1",
  [$wpId]
);

if (!$wp) {
  header("Location: {$base}/app.php?r=wartung.dashboard");
  exit;
}

if ((int)$wp['messwert_pflicht'] === 1 && $messwert === null) {
  header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&err=" . urlencode("Messwert ist Pflicht."));
  exit;
}

$status = ($statusIn === 'abweichung') ? 'abweichung' : 'ok';
$outOfBounds = false;

if ($messwert !== null) {
  if ($wp['grenzwert_min'] !== null && $messwert < (float)$wp['grenzwert_min']) $outOfBounds = true;
  if ($wp['grenzwert_max'] !== null && $messwert > (float)$wp['grenzwert_max']) $outOfBounds = true;
  if ($outOfBounds) $status = 'abweichung';
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  session_boot();
  if ($team !== '') $_SESSION['wartung_team_text'] = $team;

  db_exec(
    "INSERT INTO wartungstool_protokoll (wartungspunkt_id, asset_id, datum, user_id, team_text, messwert, status, bemerkung)
     VALUES (?,?, NOW(), ?, ?, ?, ?, ?)",
    [
      (int)$wp['id'],
      (int)$wp['asset_id'],
      $userId ?: null,
      ($team !== '' ? $team : null),
      $messwert,
      $status,
      ($bem !== '' ? $bem : null)
    ]
  );
  $protId = (int)$pdo->lastInsertId();

  $oldWp = [
    'letzte_wartung' => $wp['letzte_wartung'],
    'datum' => $wp['datum'],
    'updated_at' => $wp['updated_at'] ?? null,
  ];

  if ($wp['intervall_typ'] === 'produktiv') {
    $cur = (float)$wp['productive_hours'];
    db_exec("UPDATE wartungstool_wartungspunkt SET letzte_wartung=?, updated_at=NOW() WHERE id=?", [$cur, $wpId]);
  } else {
    db_exec("UPDATE wartungstool_wartungspunkt SET datum=NOW(), updated_at=NOW() WHERE id=?", [$wpId]);
  }

  audit_log('wartungstool', 'protokoll', $protId, 'CREATE', null, [
    'wartungspunkt_id' => (int)$wp['id'],
    'asset_id' => (int)$wp['asset_id'],
    'status' => $status,
    'messwert' => $messwert,
    'team_text' => $team,
    'bemerkung' => $bem,
  ], $userId, $actor);

  $newWpRow = db_one("SELECT letzte_wartung, datum, updated_at FROM wartungstool_wartungspunkt WHERE id=?", [$wpId]);
  audit_log('wartungstool', 'wartungspunkt', (int)$wpId, 'UPDATE', $oldWp, $newWpRow, $userId, $actor);

  // Ticket nur wenn angefordert UND erlaubt
  if ($createTicketRequested === 1) {
    if (!$canCreateTicket) {
      // Marker in Protokoll, dass Ticket nicht erstellt wurde (Rechte)
      $oldProt = db_one("SELECT bemerkung FROM wartungstool_protokoll WHERE id=?", [$protId]);
      db_exec(
        "UPDATE wartungstool_protokoll
         SET bemerkung = TRIM(CONCAT(COALESCE(bemerkung,''), CASE WHEN bemerkung IS NULL OR bemerkung='' THEN '' ELSE ' ' END, '[#TICKET:DENIED]'))
         WHERE id=?",
        [$protId]
      );
      $newProt = db_one("SELECT bemerkung FROM wartungstool_protokoll WHERE id=?", [$protId]);
      audit_log('wartungstool', 'protokoll', $protId, 'UPDATE', $oldProt, $newProt, $userId, $actor);
    } else {
      $titel = "Wartung: " . ($wp['asset_code'] ? $wp['asset_code'].' ' : '') . $wp['asset_name'] . " – " . $wp['text_kurz'];

      $descLines = [];
      $descLines[] = "Erzeugt aus Wartungsprotokoll #{$protId}.";
      $descLines[] = "Anlage: " . ($wp['asset_code'] ? $wp['asset_code'].' – ' : '') . $wp['asset_name'];
      $descLines[] = "Wartungspunkt: " . $wp['text_kurz'];
      $descLines[] = "Status: " . $status;

      if ($messwert !== null) {
        $descLines[] = "Messwert: {$messwert}" . ($wp['einheit'] ? " {$wp['einheit']}" : "");
        $descLines[] = "Grenzen: " . ($wp['grenzwert_min'] !== null ? $wp['grenzwert_min'] : "—") . " bis " . ($wp['grenzwert_max'] !== null ? $wp['grenzwert_max'] : "—");
        if ($outOfBounds) $descLines[] = "Hinweis: Messwert außerhalb Grenzwerte.";
      }
      if ($team !== '') $descLines[] = "Team: {$team}";
      if ($bem !== '') $descLines[] = "Bemerkung: {$bem}";

      $beschreibung = implode("\n", $descLines);

      db_exec(
        "INSERT INTO stoerungstool_ticket
          (asset_id, titel, beschreibung, kategorie, prioritaet, status, gemeldet_von, kontakt, anonym, created_at)
         VALUES (?,?,?,?, 2, 'neu', ?, NULL, 0, NOW())",
        [(int)$wp['asset_id'], $titel, $beschreibung, 'Wartung', $actor]
      );
      $ticketId = (int)$pdo->lastInsertId();

      db_exec(
        "INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
         VALUES (?, NOW(), ?, ?, 'neu', NULL)",
        [$ticketId, $userId ?: null, "Ticket erzeugt aus Wartungsprotokoll #{$protId}"]
      );
      $ticketActionId = (int)$pdo->lastInsertId();

      audit_log('stoerungstool', 'ticket', $ticketId, 'CREATE', null, [
        'asset_id' => (int)$wp['asset_id'],
        'titel' => $titel,
        'kategorie' => 'Wartung',
        'source_protokoll_id' => $protId
      ], $userId, $actor);
      audit_log('stoerungstool', 'aktion', $ticketActionId, 'CREATE', null, [
        'ticket_id' => $ticketId,
        'text' => "Ticket erzeugt aus Wartungsprotokoll #{$protId}",
        'status_neu' => 'neu',
      ], $userId, $actor);

      $oldProt = db_one("SELECT bemerkung FROM wartungstool_protokoll WHERE id=?", [$protId]);
      db_exec(
        "UPDATE wartungstool_protokoll
         SET bemerkung = TRIM(CONCAT(
            COALESCE(bemerkung,''),
            CASE WHEN bemerkung IS NULL OR bemerkung='' THEN '' ELSE ' ' END,
            '[#TICKET:', ?, ']'
         ))
         WHERE id=?",
        [$ticketId, $protId]
      );
      $newProt = db_one("SELECT bemerkung FROM wartungstool_protokoll WHERE id=?", [$protId]);
      audit_log('wartungstool', 'protokoll', $protId, 'UPDATE', $oldProt, $newProt, $userId, $actor);
    }
  }

  $pdo->commit();
  header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&ok=1");
  exit;

} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  header("Location: {$base}/app.php?r=wartung.punkt&wp={$wpId}&err=" . urlencode("Speichern fehlgeschlagen: ".$e->getMessage()));
  exit;
}