# UI v2 Guide (Design System & Seiten-Patterns)

Stand: 2026-02-28 (aktualisiert; ursprünglich: 2026-02-27)  
Ziel: Einheitliches UI für alle Module (Wartung / Störung / Admin) als Grundlage für weitere Seiten.

Dieses UI ist **Desktop-first** (typische 2026-Monitore), aber so gebaut, dass ein späterer Mobile/Tablet-Step möglich ist:
- Layouts nutzen Grid/Flex
- Tabellen liegen in `.ui-table-wrap` (horizontal scroll möglich)
- Komponenten sind wiederverwendbar (Cards, Badges, Buttons, Filterbar, KPI)

### Wichtig: `main.css` ist veraltet (Legacy)

- **Bitte nichts an `main.css` ändern.**
- Neue/umgebaute Seiten nutzen ausschließlich das **UI-v2 Template** (Klassen mit Prefix `ui-…`).
- Alt-Seiten werden schrittweise migriert. Während der Migration gilt:
  - Neue Komponenten/Seiten: **UI-v2**
  - Bestehende Alt-Seiten: bleiben bis zur Umstellung unverändert

**Migrationsstatus (repo-verifiziert, Stand 2026-02-28):**
(Quelle: `docs/PRIJECT_CONTEXT_v2.md`, Abschnitt „Migrationsstatus")

| Seite | Status |
|---|---|
| `wartung.dashboard` (`module/wartungstool/dashboard.php`) | ✅ UI v2 |
| `wartung.uebersicht` (`module/wartungstool/uebersicht.php`) | ✅ UI v2 |
| `wartung.admin_punkte` (`module/wartungstool/admin_punkte.php`) | ✅ UI v2 |
| `wartung.punkt` (`module/wartungstool/punkt.php`) | ⚠️ UI v2 TODO |

---

## 1) Grundlayout

Jede INNER-VIEW startet typischerweise so:

```php
<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();
// optional: require_can_edit(...)
$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';
?>
<div class="ui-container">
  <!-- ... -->
</div>
```

### Page Header (Standard)

```html
<div class="ui-page-header">
  <h1 class="ui-page-title">Titel</h1>
  <p class="ui-page-subtitle ui-muted">Kurzbeschreibung / Kontext</p>

  <!-- Optional: Statuschips -->
  <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
    <span class="ui-badge ui-badge--ok">Gespeichert</span>
    <span class="ui-badge ui-badge--danger">Fehlertext</span>
  </div>
</div>
```

---

## 2) Layout-Bausteine

### 2.1 Card
`ui-card` ist der Standard-Container für Inhalte.

```html
<div class="ui-card">
  <h2>Card Titel</h2>
  <p class="small ui-muted">Text…</p>
</div>
```

**Regel:** Alles „sichtbar zusammengehörige“ gehört in eine Card.

---

### 2.2 Grid (2-Spalten Desktop)
Desktop-first: wir nutzen Grid für Seiten mit „links Hauptarbeit, rechts Kontext/Listen“.

```html
<div class="ui-grid" style="display:grid; grid-template-columns: 1.1fr 1.4fr; gap: var(--s-6); align-items:start;">
  <div><!-- links --></div>
  <div><!-- rechts --></div>
</div>
```

**Best practice:**
- Links: Form / KPIs / Primary Actions
- Rechts: Listen / Tabellen / Audit / Insights

---

### 2.3 Filterbar (Form-Header)
`ui-filterbar` + `ui-filterbar__form` für Filter/Select/Controls.

```html
<div class="ui-card ui-filterbar">
  <form class="ui-filterbar__form">
    <div class="ui-filterbar__group">
      <label for="x">Label</label>
      <select id="x" name="x"></select>
    </div>
    <div class="ui-filterbar__actions">
      <button class="ui-btn ui-btn--primary">Filter anwenden</button>
    </div>
  </form>
</div>
```

**Regel:** Filterbar ist die „Steuerzentrale“ der Seite.

---

### 2.4 KPI Row (Clickables möglich)
Für Dashboards/Übersichten: KPI-Boxen als schnelle Navigation.

```html
<div class="ui-kpi-row">
  <a class="ui-kpi ui-kpi--danger" href="#">
    <div class="ui-kpi__label">Überfällig</div>
    <div class="ui-kpi__value">3</div>
  </a>
  <div class="ui-kpi">
    <div class="ui-kpi__label">Gesamt</div>
    <div class="ui-kpi__value">42</div>
  </div>
</div>
```

---

## 3) Komponenten (UI v2)

### 3.1 Buttons
- Primary: `ui-btn ui-btn--primary`
- Ghost/Secondary: `ui-btn ui-btn--ghost`
- Small: `ui-btn ui-btn--sm`

Beispiele:

```html
<button class="ui-btn ui-btn--primary">Speichern</button>
<a class="ui-btn ui-btn--ghost" href="#">Abbrechen</a>
<a class="ui-btn ui-btn--sm ui-btn--primary" href="#">Öffnen</a>
```

---

### 3.2 Badges (Status)
- Neutral: `ui-badge`
- OK: `ui-badge ui-badge--ok`
- Warn: `ui-badge ui-badge--warn`
- Danger: `ui-badge ui-badge--danger`

Beispiele:

```html
<span class="ui-badge ui-badge--ok">aktiv</span>
<span class="ui-badge ui-badge--warn">Bald fällig</span>
<span class="ui-badge ui-badge--danger">Überfällig</span>
```

---

### 3.3 Inputs
- Input: `ui-input`
- Textarea: `ui-input` (gleiches Styling)

```html
<label for="name">Name</label>
<input id="name" class="ui-input" name="name">

<label for="desc">Beschreibung</label>
<textarea id="desc" class="ui-input" name="desc"></textarea>
```

---

### 3.4 Tabellen
**Wichtig:** Tabellen immer in `.ui-table-wrap`, damit bei schmaler View kein Layout bricht.

```html
<div class="ui-table-wrap">
  <table class="ui-table">
    <thead>...</thead>
    <tbody>...</tbody>
  </table>
</div>
```

Actions-Spalte:
- Header: `ui-th-actions`
- Cells: `ui-td-actions`

---

### 3.5 Links
- Standard: `ui-link`

```html
<a class="ui-link" href="#">Wartungspunkt öffnen</a>
```

---

### 3.6 Collapsible Sections
Für „Optional/Secondary“ Inhalte (z.B. OK-Liste, Details, Historien):

```html
<details>
  <summary style="cursor:pointer; font-weight:700;">Mehr anzeigen</summary>
  <div style="margin-top: var(--s-4);">...</div>
</details>
```

---

## 4) Seiten-Pattern Vorlagen

### 4.1 Admin CRUD (Beispiel: Wartungspunkte)
Pattern:
- oben: Page Header + Statuschips
- Filterbar: Asset auswählen
- 2-Spalten:
  - Links: Create/Update Form + Imports/Tools
  - Rechts: Listen + Audit

Genau so ist `wartung.admin_punkte` gebaut.

---

### 4.2 Dashboard (Beispiel: Wartung Dashboard)
Pattern:
- Page Header + Quick Links
- KPI row (clickable Filter)
- 2-Spalten:
  - Links: Haupttabellen (kritisch + weitere einklappbar)
  - Rechts: Insights (Trend, Top up/down)

---

### 4.3 Übersicht pro Asset (Beispiel: Wartung Übersicht)
Pattern:
- Page Header
- KPI row (Überfällig/Bald/OK/Gesamt)
- Filterbar (Asset + Mode)
- Tabelle (Offene oder Alle)
- Optional: OK-Liste in `<details>`

---

## 5) Style-Regeln (konkret)

1) **Keine „alten“ Klassen** (`card`, `table`, `btn`, `badge` etc.) in neuen/überarbeiteten Seiten.  
   Nur `ui-*` verwenden.

2) **Tabellen nur mit Wrap** (`ui-table-wrap`).  
   Sonst „zerschießt“ es bei enger Breite oder langen Texten.

3) **Inline-Styles nur für Layout** (Grid Columns, kleine flex alignments).  
   Farben/Abstände grundsätzlich über CSS Klassen.

4) **Actions immer rechts** (Buttons in eigener Actions-Spalte).  
   Macht Listen scanbar.

5) **Sekundärinfos klein + muted** (`small ui-muted`).  
   Dadurch bleibt die Seite “ruhig”.

---

## 6) “Copy-Paste” Mini-Template (Neue Seite starten)

```php
<?php
require_once __DIR__ . '/../../src/helpers.php';
require_login();

$cfg  = app_cfg();
$base = $cfg['app']['base_url'] ?? '';
?>

<div class="ui-container">

  <div class="ui-page-header" style="margin: 0 0 var(--s-5) 0;">
    <h1 class="ui-page-title">Titel</h1>
    <p class="ui-page-subtitle ui-muted">Kurzbeschreibung…</p>
  </div>

  <div class="ui-card ui-filterbar" style="margin-bottom: var(--s-6);">
    <form method="get" action="<?= e($base) ?>/app.php" class="ui-filterbar__form">
      <input type="hidden" name="r" value="route.key">

      <div class="ui-filterbar__group">
        <label for="x">Filter</label>
        <input id="x" name="x" class="ui-input" placeholder="...">
      </div>

      <div class="ui-filterbar__actions">
        <button class="ui-btn ui-btn--primary" type="submit">Anwenden</button>
      </div>
    </form>
  </div>

  <div class="ui-grid" style="display:grid; grid-template-columns: 1.2fr 1fr; gap: var(--s-6); align-items:start;">
    <div class="ui-card">
      <h2>Hauptteil</h2>
      ...
    </div>

    <aside class="ui-card">
      <h2>Sidebar / Kontext</h2>
      ...
    </aside>
  </div>

</div>
```
