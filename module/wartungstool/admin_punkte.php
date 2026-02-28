<?php
// UI v2 INNER-VIEW TEMPLATE (copy/paste)
// - Wird via app.php geladen: daher KEIN render_header/render_footer hier!
// - Nutzt ausschließlich ui-* Klassen (UI v2)
// Referenz: docs/UI_V2_GUIDE.md

require_once __DIR__ . '/../../src/helpers.php';
require_login(); // oder weglassen, falls core_route.require_login=0

// Optional (für Schreibseiten / Admin):
// require_can_edit('wartungstool', 'global');

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';

$routeKey = 'dein.modul.route'; // <- anpassen (muss mit core_route.route_key matchen)

//
// Status/Flash (optional): nutze GET-Parameter, z.B. ?ok=1 / ?err=...
//
$ok  = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';

//
// Filter (Beispiel)
//
$q       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$mode    = isset($_GET['mode']) ? (string)$_GET['mode'] : 'offen';
$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;

// TODO: hier deine DB-Queries einfügen (db_all/db_one).
// $rows = db_all("SELECT ...", [...]);

?>
<div class="ui-container">

  <!-- Page Header -->
  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Seitentitel (UI v2)</h1>
    <p class="ui-page-subtitle ui-muted">Kurzbeschreibung / Kontext / Was kann man hier tun?</p>

    <?php if ($ok): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <span class="ui-badge ui-badge--ok"><?= e($ok) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
        <span class="ui-badge ui-badge--danger"><?= e($err) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Filterbar -->
  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="<?= e($routeKey) ?>">

      <div class="ui-filterbar__group">
        <label for="q">Suche</label>
        <input id="q" name="q" class="ui-input" placeholder="…"
               value="<?= e($q) ?>">
      </div>

      <div class="ui-filterbar__group">
        <label for="mode">Modus</label>
        <select id="mode" name="mode" class="ui-input">
          <option value="offen" <?= $mode==='offen'?'selected':'' ?>>Offen</option>
          <option value="alle"  <?= $mode==='alle'?'selected':''  ?>>Alle</option>
        </select>
      </div>

      <div class="ui-filterbar__group">
        <label for="asset_id">Asset</label>
        <select id="asset_id" name="asset_id" class="ui-input">
          <option value="0">— wählen —</option>
          <?php
          // Beispiel: Assets laden
          // $assets = db_all("SELECT id, code, name FROM core_asset ORDER BY code");
          // foreach ($assets as $a):
          ?>
          <?php /* ?>
            <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===$assetId)?'selected':'' ?>>
              <?= e($a['code']) ?> – <?= e($a['name']) ?>
            </option>
          <?php */ ?>
          <?php // endforeach; ?>
        </select>
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary" type="submit">Anwenden</button>
        <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=<?= e($routeKey) ?>">Reset</a>
      </div>
    </form>
  </div>

  <!-- Optional KPI Row -->
  <div class="ui-kpi-row" style="margin-bottom: var(--s-6);">
    <div class="ui-kpi ui-kpi--danger">
      <div class="ui-kpi__label">Überfällig</div>
      <div class="ui-kpi__value">0</div>
    </div>
    <div class="ui-kpi ui-kpi--warn">
      <div class="ui-kpi__label">Bald fällig</div>
      <div class="ui-kpi__value">0</div>
    </div>
    <div class="ui-kpi">
      <div class="ui-kpi__label">OK</div>
      <div class="ui-kpi__value">0</div>
    </div>
    <div class="ui-kpi">
      <div class="ui-kpi__label">Gesamt</div>
      <div class="ui-kpi__value">0</div>
    </div>
  </div>

  <!-- 2-column layout -->
  <div class="ui-grid" style="display:grid; grid-template-columns: 1.2fr 1fr; gap: var(--s-6); align-items:start;">

    <!-- Left: primary content -->
    <div class="ui-card">
      <h2>Hauptbereich</h2>
      <p class="small ui-muted">Hier kommt der Haupt-Flow rein: Tabelle, Formular, Listen…</p>

      <div class="ui-table-wrap" style="margin-top: var(--s-5);">
        <table class="ui-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Bezeichnung</th>
              <th>Status</th>
              <th class="ui-th-actions">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <?php /* foreach (($rows ?? []) as $r): */ ?>
            <?php /* ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['name']) ?></td>
                <td>
                  <span class="ui-badge ui-badge--ok">OK</span>
                </td>
                <td class="ui-td-actions">
                  <a class="ui-btn ui-btn--sm ui-btn--primary" href="<?= e($base) ?>/app.php?r=...&id=<?= (int)$r['id'] ?>">Öffnen</a>
                  <a class="ui-btn ui-btn--sm ui-btn--ghost" href="<?= e($base) ?>/app.php?r=...&id=<?= (int)$r['id'] ?>">Bearbeiten</a>
                </td>
              </tr>
            <?php */ ?>
            <?php /* endforeach; */ ?>

            <?php if (empty($rows ?? [])): ?>
              <tr>
                <td colspan="4" class="ui-muted">Keine Daten.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Optional collapsible -->
      <details style="margin-top: var(--s-6);">
        <summary style="cursor:pointer; font-weight:700;">Mehr anzeigen</summary>
        <div style="margin-top: var(--s-4);">
          <p class="small ui-muted">Sekundärinfos, Debug, “OK”-Liste, Historie, etc.</p>
        </div>
      </details>
    </div>

    <!-- Right: sidebar / secondary -->
    <aside class="ui-card">
      <h2>Sidebar / Kontext</h2>
      <p class="small ui-muted">Kontextinfos, Quick-Actions, Hilfe, Links, Audit, etc.</p>

      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top: var(--s-4);">
        <a class="ui-btn ui-btn--primary" href="<?= e($base) ?>/app.php?r=<?= e($routeKey) ?>&ok=Demo">Primary Action</a>
        <a class="ui-btn ui-btn--ghost" href="<?= e($base) ?>/app.php?r=<?= e($routeKey) ?>">Secondary</a>
      </div>

      <div style="margin-top: var(--s-6);">
        <h3 style="margin:0 0 var(--s-3) 0;">Status</h3>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <span class="ui-badge">neutral</span>
          <span class="ui-badge ui-badge--ok">ok</span>
          <span class="ui-badge ui-badge--warn">warn</span>
          <span class="ui-badge ui-badge--danger">danger</span>
        </div>
      </div>
    </aside>

  </div>
</div>