<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$asset  = (int)($_GET['asset_id'] ?? 0);

$params = [];
$where  = [];

if ($q !== '') {
    $where[] = "(t.titel LIKE ? OR t.beschreibung LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($status !== '') {
    $where[] = "t.status = ?";
    $params[] = $status;
}

if ($asset > 0) {
    $where[] = "t.asset_id = ?";
    $params[] = $asset;
}

$sql = "
    SELECT t.*, a.name AS asset_name
    FROM stoerungstool_ticket t
    LEFT JOIN core_asset a ON a.id = t.asset_id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.created_at DESC";

$tickets = db_all($sql, $params);
$assets  = db_all("SELECT id, name FROM core_asset ORDER BY name");
?>

<div class="ui-container">

    <div class="ui-page-header">
        <h1 class="ui-page-title">Störungen – Inbox</h1>
        <p class="ui-page-subtitle ui-muted">
            Übersicht aller Tickets mit Filter- und Suchfunktion.
        </p>
    </div>

    <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
        <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
            <input type="hidden" name="r" value="stoerung.inbox">

            <div class="ui-filterbar__group">
                <label for="q">Suche</label>
                <input id="q" name="q" value="<?= e($q) ?>" class="ui-input" placeholder="Titel oder Beschreibung">
            </div>

            <div class="ui-filterbar__group">
                <label for="status">Status</label>
                <select id="status" name="status" class="ui-input">
                    <option value="">Alle</option>
                    <?php
                    $statuses = ['neu','angenommen','in_arbeit','bestellt','erledigt','geschlossen'];
                    foreach ($statuses as $s):
                    ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                            <?= e($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ui-filterbar__group">
                <label for="asset_id">Anlage</label>
                <select id="asset_id" name="asset_id" class="ui-input">
                    <option value="">Alle</option>
                    <?php foreach ($assets as $a): ?>
                        <option value="<?= (int)$a['id'] ?>" <?= $asset === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= e($a['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ui-filterbar__actions">
                <button type="submit" class="ui-btn ui-btn--primary">Filtern</button>
                <a href="<?= e($base) ?>/app.php?r=stoerung.inbox" class="ui-btn ui-btn--ghost">Reset</a>
            </div>
        </form>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Titel</th>
                        <th>Anlage</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th class="ui-th-actions">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$tickets): ?>
                        <tr>
                            <td colspan="6" class="ui-muted">Keine Tickets gefunden.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td>#<?= (int)$t['id'] ?></td>
                            <td><?= e($t['titel']) ?></td>
                            <td><?= e($t['asset_name'] ?? '-') ?></td>
                            <td>
                                <?php
                                $badgeClass = 'ui-badge';
                                if (in_array($t['status'], ['neu','angenommen'])) {
                                    $badgeClass .= ' ui-badge--warn';
                                } elseif (in_array($t['status'], ['in_arbeit','bestellt'])) {
                                    $badgeClass .= ' ui-badge--danger';
                                } elseif (in_array($t['status'], ['erledigt','geschlossen'])) {
                                    $badgeClass .= ' ui-badge--ok';
                                }
                                ?>
                                <span class="<?= $badgeClass ?>">
                                    <?= e($t['status']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="ui-muted">
                                    <?= e($t['created_at']) ?>
                                </small>
                            </td>
                            <td class="ui-td-actions">
                                <a class="ui-btn ui-btn--sm ui-btn--primary"
                                   href="<?= e($base) ?>/app.php?r=stoerung.ticket&id=<?= (int)$t['id'] ?>">
                                    Öffnen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>