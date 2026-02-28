<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Ungültige Ticket-ID');
}

$ticket = db_one("
    SELECT t.*, a.name AS asset_name
    FROM stoerungstool_ticket t
    LEFT JOIN core_asset a ON a.id = t.asset_id
    WHERE t.id = ?
", [$id]);

if (!$ticket) {
    http_response_code(404);
    exit('Ticket nicht gefunden');
}

$actions = db_all("
    SELECT a.*, u.name AS user_name
    FROM stoerungstool_aktion a
    LEFT JOIN core_user u ON u.id = a.user_id
    WHERE a.ticket_id = ?
    ORDER BY a.created_at DESC
", [$id]);

$users = db_all("SELECT id, name FROM core_user WHERE aktiv=1 ORDER BY name");

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
            <?= e($ticket['asset_name'] ?? 'Keine Anlage zugeordnet') ?>
        </p>
        <div style="margin-top:10px;">
            <span class="<?= $statusClass ?>">
                <?= e($ticket['status']) ?>
            </span>
        </div>
    </div>

    <div class="ui-grid" style="display:grid; grid-template-columns: 1.3fr 1fr; gap: var(--s-6); align-items:start;">

        <div>

            <div class="ui-card" style="margin-bottom: var(--s-6);">
                <h2>Beschreibung</h2>
                <p><?= nl2br(e($ticket['beschreibung'])) ?></p>
            </div>

            <div class="ui-card">
                <h2>Aktionen</h2>

                <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                    <?= csrf_token() ?>
                    <input type="hidden" name="action" value="add_action">

                    <label for="text">Neue Aktion</label>
                    <textarea id="text" name="text" class="ui-input" required></textarea>

                    <div style="margin-top: var(--s-4); display:flex; gap: var(--s-3);">
                        <button class="ui-btn ui-btn--primary">Speichern</button>
                    </div>
                </form>

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
                                    <td><small class="ui-muted"><?= e($a['created_at']) ?></small></td>
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

                <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                    <?= csrf_token() ?>
                    <input type="hidden" name="action" value="set_status">

                    <select name="status" class="ui-input">
                        <?php
                        $statuses = ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'];
                        foreach ($statuses as $s):
                        ?>
                            <option value="<?= e($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>>
                                <?= e($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-top: var(--s-4);">
                        <button class="ui-btn ui-btn--primary">Status speichern</button>
                    </div>
                </form>
            </div>

            <div class="ui-card">
                <h2>Zuweisung</h2>

                <form method="post" action="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= $id ?>">
                    <?= csrf_token() ?>
                    <input type="hidden" name="action" value="assign">

                    <select name="assigned_user_id" class="ui-input">
                        <option value="">Nicht zugewiesen</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"
                                <?= (int)$ticket['assigned_user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-top: var(--s-4);">
                        <button class="ui-btn ui-btn--primary">Speichern</button>
                    </div>
                </form>
            </div>

        </aside>

    </div>

</div>