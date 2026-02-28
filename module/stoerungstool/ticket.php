<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$actor  = $u['anzeigename'] ?? $u['benutzername'] ?? 'user';
$canEdit   = user_can_edit($userId, 'stoerungstool', 'global', null);
$canDelete = user_can_delete($userId, 'stoerungstool', 'global', null);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: {$base}/app.php?r=stoerung.inbox");
    exit;
}

$ok = (int)($_GET['ok'] ?? 0);
$err = trim((string)($_GET['err'] ?? ''));

$statuses = ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if (!$canEdit) {
            throw new RuntimeException('Keine Bearbeitungsrechte.');
        }

        if ($action === 'set_status') {
            $newStatus = trim((string)($_POST['status'] ?? ''));
            if (!in_array($newStatus, $statuses, true)) {
                throw new RuntimeException('Ungültiger Status.');
            }

            db_exec("UPDATE stoerungstool_ticket SET status=? WHERE id=?", [$newStatus, $id]);
            db_exec(
                "INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
                 VALUES (?, NOW(), ?, ?, ?, NULL)",
                [$id, $userId ?: null, 'Status geändert', $newStatus]
            );
            $actionId = (int)db()->lastInsertId();
            audit_log('stoerungstool', 'ticket', $id, 'STATUS', null, ['status' => $newStatus], $userId, $actor);
            audit_log('stoerungstool', 'aktion', $actionId, 'CREATE', null, [
                'ticket_id' => $id,
                'text' => 'Status geändert',
                'status_neu' => $newStatus,
            ], $userId, $actor);

        } elseif ($action === 'assign') {
            $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
            $assigned = ($assignedUserId > 0) ? $assignedUserId : null;

            db_exec("UPDATE stoerungstool_ticket SET assigned_user_id=? WHERE id=?", [$assigned, $id]);
            $assignText = $assigned ? ('Zugewiesen an User #' . $assigned) : 'Zuweisung entfernt';
            db_exec(
                "INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
                 VALUES (?, NOW(), ?, ?, NULL, NULL)",
                [$id, $userId ?: null, $assignText]
            );
            $actionId = (int)db()->lastInsertId();
            audit_log('stoerungstool', 'ticket', $id, 'UPDATE', null, ['assigned_user_id' => $assigned], $userId, $actor);
            audit_log('stoerungstool', 'aktion', $actionId, 'CREATE', null, [
                'ticket_id' => $id,
                'text' => $assignText,
                'status_neu' => null,
            ], $userId, $actor);

        } elseif ($action === 'add_action') {
            $text = trim((string)($_POST['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('Aktionstext fehlt.');
            }

            db_exec(
                "INSERT INTO stoerungstool_aktion (ticket_id, datum, user_id, text, status_neu, arbeitszeit_min)
                 VALUES (?, NOW(), ?, ?, NULL, NULL)",
                [$id, $userId ?: null, $text]
            );
            $actionId = (int)db()->lastInsertId();
            audit_log('stoerungstool', 'aktion', $actionId, 'CREATE', null, [
                'ticket_id' => $id,
                'text' => $text,
                'status_neu' => null,
            ], $userId, $actor);

        } elseif ($action === 'upload_doc') {
            if (empty($_FILES['file']) || !isset($_FILES['file']['error']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload fehlgeschlagen.');
            }
            $baseDir = $cfg['upload']['base_dir'] ?? (__DIR__ . '/../../uploads');
            $relDir = 'stoerungstool/tickets/' . $id;
            $absDir = rtrim($baseDir, '/\\') . '/' . $relDir;
            ensure_dir($absDir);

            $up = handle_upload($_FILES['file'], $absDir);
            if (!$up) {
                throw new RuntimeException('Keine Datei hochgeladen.');
            }

            $relPath = $relDir . '/' . $up['stored'];

            db_exec(
                "INSERT INTO core_dokument
                 (modul, referenz_typ, referenz_id, dateiname, originalname, mime, size_bytes, sha256, hochgeladen_am, hochgeladen_von_user_id)
                 VALUES ('stoerungstool','ticket',?,?,?,?,?,?,NOW(),?)",
                [$id, $relPath, (string)$up['original'], (string)$up['mime'], (int)$up['size'], (string)$up['sha256'], $userId ?: null]
            );
            $dokId = (int)db()->lastInsertId();
            audit_log('stoerungstool', 'dokument', $dokId, 'CREATE', null, ['referenz_typ' => 'ticket', 'referenz_id' => $id, 'dateiname' => $relPath], $userId, $actor);

        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }

        header("Location: {$base}/app.php?r=stoerung.ticket&id={$id}&ok=1");
        exit;
    } catch (Throwable $e) {
        header("Location: {$base}/app.php?r=stoerung.ticket&id={$id}&err=" . urlencode($e->getMessage()));
        exit;
    }
}

$ticket = db_one("
    SELECT t.*, a.name AS asset_name
    FROM stoerungstool_ticket t
    LEFT JOIN core_asset a ON a.id = t.asset_id
    WHERE t.id = ?
", [$id]);

if (!$ticket) {
    header("Location: {$base}/app.php?r=stoerung.inbox");
    exit;
}

$actions = db_all("
    SELECT a.*, COALESCE(u.anzeigename, u.benutzername) AS user_name
    FROM stoerungstool_aktion a
    LEFT JOIN core_user u ON u.id = a.user_id
    WHERE a.ticket_id = ?
    ORDER BY a.datum DESC, a.id DESC
", [$id]);

$users = db_all("SELECT id, COALESCE(anzeigename, benutzername) AS display_name FROM core_user WHERE aktiv=1 ORDER BY COALESCE(anzeigename, benutzername)");

$docs = db_all(
    "SELECT * FROM core_dokument
     WHERE modul='stoerungstool' AND referenz_typ='ticket' AND referenz_id=?
     ORDER BY hochgeladen_am DESC, id DESC",
    [$id]
);

$statusClass = 'ui-badge';
if (in_array($ticket['status'], ['neu','angenommen'])) {
    $statusClass .= ' ui-badge--warn';
} elseif (in_array($ticket['status'], ['in_arbeit','bestellt'])) {
    $statusClass .= ' ui-badge--danger';
} elseif (in_array($ticket['status'], ['erledigt','geschlossen'])) {
    $statusClass .= ' ui-badge--ok';
}
?>

<div class="ui-container">

    <div class="ui-page-header">
        <h1 class="ui-page-title">
            Ticket #<?= (int)$ticket['id'] ?> – <?= e($ticket['titel']) ?>
        </h1>
        <p class="ui-page-subtitle ui-muted">
            <a class="ui-link" href="<?= e($base) ?>/app.php?r=stoerung.inbox">← zurück zur Inbox</a>
            <span class="ui-muted">·</span>
            <?= e($ticket['asset_name'] ?? 'Keine Anlage zugeordnet') ?>
        </p>
        <div style="margin-top:10px;">
            <span class="<?= $statusClass ?>">
                <?= e($ticket['status']) ?>
            </span>
            <?php if (!$canEdit): ?><span class="ui-badge" style="margin-left:8px;">Nur Lesen</span><?php endif; ?>
        </div>

        <?php if ($ok || $err !== ''): ?>
          <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
            <?php if ($ok): ?><span class="ui-badge ui-badge--ok">Gespeichert</span><?php endif; ?>
            <?php if ($err !== ''): ?><span class="ui-badge ui-badge--danger"><?= e($err) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
    </div>

    <div class="ui-grid" style="display:grid; grid-template-columns: 1.3fr 1fr; gap: var(--s-6); align-items:start;">

        <div>

            <div class="ui-card" style="margin-bottom: var(--s-6);">
                <h2>Beschreibung</h2>
                <p><?= nl2br(e($ticket['beschreibung'])) ?></p>
            </div>

            <div class="ui-card">
                <h2>Aktionen</h2>

                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_action">

                        <label for="text">Neue Aktion</label>
                        <textarea id="text" name="text" class="ui-input" required></textarea>

                        <div style="margin-top: var(--s-4); display:flex; gap: var(--s-3);">
                            <button class="ui-btn ui-btn--primary">Speichern</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="small ui-muted">Keine Bearbeitungsrechte für neue Aktionen.</p>
                <?php endif; ?>

                <div class="ui-table-wrap" style="margin-top: var(--s-6);">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Benutzer</th>
                                <th>Text</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($actions as $a): ?>
                                <tr>
                                    <td><small class="ui-muted"><?= e($a['datum']) ?></small></td>
                                    <td><?= e($a['user_name'] ?? 'System') ?></td>
                                    <td><?= nl2br(e($a['text'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$actions): ?>
                                <tr>
                                    <td colspan="3" class="ui-muted">Keine Aktionen vorhanden.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <aside>

            <div class="ui-card" style="margin-bottom: var(--s-6);">
                <h2>Status ändern</h2>

                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_status">

                        <select name="status" class="ui-input">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= e($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>>
                                    <?= e($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div style="margin-top: var(--s-4);">
                            <button class="ui-btn ui-btn--primary">Status speichern</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="small ui-muted">Keine Bearbeitungsrechte.</p>
                <?php endif; ?>
            </div>

            <div class="ui-card">
                <h2>Zuweisung</h2>

                <?php if ($canEdit): ?>
                    <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign">

                        <select name="assigned_user_id" class="ui-input">
                            <option value="">Nicht zugewiesen</option>
                            <?php foreach ($users as $usr): ?>
                                <option value="<?= (int)$usr['id'] ?>"
                                    <?= (int)$ticket['assigned_user_id'] === (int)$usr['id'] ? 'selected' : '' ?>>
                                    <?= e($usr['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div style="margin-top: var(--s-4);">
                            <button class="ui-btn ui-btn--primary">Speichern</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="small ui-muted">Keine Bearbeitungsrechte.</p>
                <?php endif; ?>
            </div>

            <div class="ui-card" style="margin-top: var(--s-6);">
                <h2>Dokumente</h2>

                <?php if ($canEdit): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>" style="margin-bottom: var(--s-4);">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_doc">

                        <label for="ticket_doc_file">Datei (jpg/png/webp/pdf)</label>
                        <input id="ticket_doc_file" class="ui-input" type="file" name="file" required>

                        <div style="margin-top: var(--s-4);">
                            <button class="ui-btn ui-btn--primary">Hochladen</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if (!$docs): ?>
                    <p class="small ui-muted">Keine Dokumente.</p>
                <?php else: ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Datei</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($docs as $d): ?>
                                    <tr>
                                        <td><small class="ui-muted"><?= e((string)$d['hochgeladen_am']) ?></small></td>
                                        <td>
                                            <a class="ui-link" href="<?= e($base) ?>/uploads/<?= e((string)$d['dateiname']) ?>" target="_blank" rel="noopener">
                                                <?= e((string)($d['originalname'] ?: $d['dateiname'])) ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </aside>

    </div>

</div>