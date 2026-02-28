
<?php
// src/helpers.php
// Kürzt einen String auf $max Zeichen, normalisiert Whitespace. Guard für mehrfaches Definieren.
if (!function_exists('short_text')) {
  function short_text(string $s, int $max = 120): string {
    $s = preg_replace('/\s+/u', ' ', $s ?? '');
    $s = trim($s);
    if (mb_strlen($s) > $max) {
      return mb_substr($s, 0, $max - 1) . '…';
    }
    return $s;
  }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function e(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * DB Introspection (für Legacy/Neu-Kompatibilität)
 */
function db_table_exists(string $table): bool {
  $row = db_one(
    "SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ?
     LIMIT 1",
    [$table]
  );
  return (bool)$row;
}

function db_col_exists(string $table, string $col): bool {
  $row = db_one(
    "SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
     LIMIT 1",
    [$table, $col]
  );
  return (bool)$row;
}

/**
 * Permission-Check basiert auf core_permission:
 * - modul + objekt_typ müssen matchen
 * - objekt_id: Permission ist global (NULL) oder exakt passend
 * - darf_sehen = 1
 *
 * Sonderfälle:
 * - modul/objekt_typ leer => öffentlich sichtbar
 * - modul='*' => Admin wildcard
 */
function user_can_see(?int $userId, ?string $modul, ?string $objektTyp, ?int $objektId): bool {
  if (!$modul || !$objektTyp) return true;
  if (!$userId) return false;
  // Nutzt denselben Resolver wie user_can_flag
  return user_can_flag($userId, $modul, $objektTyp, $objektId, 'darf_sehen');
}

/**
 * Admin helper (optional)
 */
function has_any_user(): bool {
  return (bool)db_one("SELECT id FROM core_user LIMIT 1");
}

function is_admin_user(?int $userId): bool {
  if (!$userId) return false;
  return (bool)db_one(
    "SELECT 1 FROM core_permission WHERE user_id=? AND modul='*' AND darf_sehen=1 LIMIT 1",
    [$userId]
  );
}

function audit_json($value): ?string {
  if ($value === null) return null;
  $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
         | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
  $encoded = json_encode($value, $flags);
  if ($encoded === false) {
    error_log('audit_json: json_encode failed – ' . json_last_error_msg());
    return null;
  }
  return $encoded;
}

function audit_log(string $modul, string $entityType, int $entityId, string $action, $old = null, $new = null, ?int $actorUserId = null, ?string $actorText = null): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $oldJson = audit_json($old);
  $newJson = audit_json($new);

  db_exec(
    "INSERT INTO core_audit_log (modul, entity_type, entity_id, action, actor_user_id, actor_text, ip_addr, old_json, new_json)
     VALUES (?,?,?,?,?,?,?,?,?)",
    [$modul, $entityType, $entityId, $action, $actorUserId, $actorText, $ip, $oldJson, $newJson]
  );
}

/**
 * Ticket-Status: zentrale Badge-Daten (CSS-Klasse + Label)
 */
function badge_for_ticket_status(string $status): array {
  switch ($status) {
    case 'neu':        return ['cls'=>'ui-badge ui-badge--danger','label'=>'neu'];
    case 'angenommen': return ['cls'=>'ui-badge ui-badge--warn','label'=>'angenommen'];
    case 'in_arbeit':  return ['cls'=>'ui-badge ui-badge--warn','label'=>'in Arbeit'];
    case 'bestellt':   return ['cls'=>'ui-badge ui-badge--warn','label'=>'bestellt'];
    case 'erledigt':   return ['cls'=>'ui-badge ui-badge--ok','label'=>'erledigt'];
    case 'geschlossen':return ['cls'=>'ui-badge','label'=>'geschlossen'];
    default:           return ['cls'=>'','label'=>$status];
  }
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("Cannot create dir: $dir");
    }
  }
}

function handle_upload(array $file, string $targetDir): ?array {
  $cfg = app_cfg();
  if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;

  if ($file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload error: ' . (int)$file['error']);
  }
  if ($file['size'] > $cfg['upload']['max_bytes']) {
    throw new RuntimeException('File too large');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';

  if (!in_array($mime, $cfg['upload']['allowed_mimes'], true)) {
    throw new RuntimeException('Mime not allowed: ' . $mime);
  }

  ensure_dir($targetDir);

  $data = file_get_contents($file['tmp_name']);
  $sha256 = hash('sha256', $data);

  $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
  $safeExt = $ext ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '';
  $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . $safeExt;

  $path = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $stored;
  if (!move_uploaded_file($file['tmp_name'], $path)) {
    throw new RuntimeException('Cannot move upload');
  }

  return [
    'stored' => $stored,
    'original' => $file['name'],
    'mime' => $mime,
    'size' => (int)$file['size'],
    'sha256' => $sha256,
  ];
}

/**
 * Route Cache: route_key => Route-Daten
 */
function route_map_for_keys(array $keys): array {
  $keys = array_values(array_unique(array_filter($keys, fn($k) => (string)$k !== '')));
  if (!$keys) return [];

  if (!db_table_exists('core_route')) return [];

  $in = implode(',', array_fill(0, count($keys), '?'));
  $rows = db_all(
    "SELECT route_key, titel, file_path, modul, objekt_typ, objekt_id, require_login, aktiv
     FROM core_route
     WHERE route_key IN ($in) AND aktiv=1",
    $keys
  );

  $map = [];
  foreach ($rows as $r) {
    $map[$r['route_key']] = $r;
  }
  return $map;
}

/**
 * Menü laden – kompatibel:
 * - NEU: core_menu + core_menu_item (wenn vorhanden)
 * - LEGACY: core_menu als Menü-Items (id,parent_id,label,icon,modul,url,sort,aktiv, ggf. route_key)
 *
 * Output: Tree-Array, jedes Item enthält:
 * - label, icon, href, is_group, active, children[]
 */
function load_menu_tree(string $menuName = 'main'): array {
  $cfg  = app_cfg();
  $base = $cfg['app']['base_url'] ?? '';
  $currentRoute = (string)($_GET['r'] ?? '');
  $requestUri   = (string)($_SERVER['REQUEST_URI'] ?? '');

  $u = current_user();
  $userId = $u['id'] ?? null;

  $items = [];
  $mode = 'legacy';

  // NEU-Schema vorhanden?
  if (db_table_exists('core_menu_item') && db_table_exists('core_menu')) {
    $mode = 'new';
    $menu = db_one("SELECT id FROM core_menu WHERE name=? AND aktiv=1 LIMIT 1", [$menuName]);
    if (!$menu) return [];

    $items = db_all(
      "SELECT id, parent_id, label, icon, route_key, url, modul, objekt_typ, objekt_id, sort, aktiv
       FROM core_menu_item
       WHERE menu_id=? AND aktiv=1
       ORDER BY sort ASC, id ASC",
      [(int)$menu['id']]
    );
  } else {
    // LEGACY: core_menu selbst ist die Items-Tabelle
    if (!db_table_exists('core_menu')) return [];

    // Spalten-Check für Legacy
    $labelCol = db_col_exists('core_menu','label') ? 'label' : (db_col_exists('core_menu','name') ? 'name' : 'label');
    $hasRoute = db_col_exists('core_menu','route_key');
    $hasIcon  = db_col_exists('core_menu','icon');
    $hasUrl   = db_col_exists('core_menu','url');
    $hasModul = db_col_exists('core_menu','modul');
    $hasSort  = db_col_exists('core_menu','sort');
    $hasAktiv = db_col_exists('core_menu','aktiv');
    $hasParent= db_col_exists('core_menu','parent_id');

    // Optional: wenn Legacy mehrere Menüs kennt (Spalte menu/menu_name), filtern
    $menuFilterSql = "";
    $params = [];
    if (db_col_exists('core_menu','menu') ) {
      $menuFilterSql = " AND menu = ? ";
      $params[] = $menuName;
    } elseif (db_col_exists('core_menu','menu_name')) {
      $menuFilterSql = " AND menu_name = ? ";
      $params[] = $menuName;
    }

    $sql = "SELECT
              id" .
            ($hasParent ? ", parent_id" : ", NULL AS parent_id") .
            ", {$labelCol} AS label" .
            ($hasIcon  ? ", icon" : ", NULL AS icon") .
            ($hasRoute ? ", route_key" : ", NULL AS route_key") .
            ($hasUrl   ? ", url" : ", NULL AS url") .
            ($hasModul ? ", modul" : ", NULL AS modul") .
            (db_col_exists('core_menu','objekt_typ') ? ", objekt_typ" : ", NULL AS objekt_typ") .
            (db_col_exists('core_menu','objekt_id')  ? ", objekt_id"  : ", NULL AS objekt_id") .
            ($hasSort  ? ", `sort` AS sort" : ", 0 AS sort") .
            ($hasAktiv ? ", aktiv" : ", 1 AS aktiv") . "
            FROM core_menu
            WHERE 1=1 " .
            ($hasAktiv ? " AND aktiv=1 " : "") .
            $menuFilterSql . "
            ORDER BY " . ($hasSort ? "`sort` ASC, id ASC" : "id ASC");

    $items = db_all($sql, $params);
  }

  if (!$items) return [];

  // Route Infos bulk laden (damit permissions + href sauber sind)
  $routeKeys = [];
  foreach ($items as $it) {
    if (!empty($it['route_key'])) $routeKeys[] = (string)$it['route_key'];
  }
  $routeMap = route_map_for_keys($routeKeys);

  // Items vorbereiten + Sichtbarkeit berechnen (aber Parent später ggf. automatisch reinnehmen)
  $byId = [];
  foreach ($items as $it) {
    $id = (int)$it['id'];
    $parentId = !empty($it['parent_id']) ? (int)$it['parent_id'] : null;

    $routeKey = !empty($it['route_key']) ? (string)$it['route_key'] : null;
    $route = ($routeKey && isset($routeMap[$routeKey])) ? $routeMap[$routeKey] : null;

    // Permission-Daten: bevorzugt aus Route (wenn gesetzt)
    $permModul = $route['modul'] ?? ($it['modul'] ?? null);
    $permObjT  = $route['objekt_typ'] ?? ($it['objekt_typ'] ?? null);
    $permObjId = ($route && $route['objekt_id'] !== null)
      ? (int)$route['objekt_id']
      : (($it['objekt_id'] !== null) ? (int)$it['objekt_id'] : null);

    // require_login: aus Route (wenn vorhanden), sonst: wenn permModul/permObjT gesetzt => implizit intern
    $requiresLogin = ($route && isset($route['require_login']))
      ? ((int)$route['require_login'] === 1)
      : (bool)($permModul && $permObjT);

    // href bauen:
    $href = null;
    if ($routeKey) {
      $href = $base . '/app.php?r=' . urlencode($routeKey);
    } else {
      $url = $it['url'] ?? null;
      if ($url) {
        // absolut oder anchor?
        if (preg_match('~^(https?://)~i', $url) || str_starts_with($url, '#')) {
          $href = $url;
        } else {
          $href = $base . $url;
        }
      }
    }

    // Active:
    $active = false;
    if ($routeKey && $currentRoute === $routeKey) {
      $active = true;
    } elseif (!$routeKey && !empty($it['url'])) {
      // Legacy active: URI enthält url
      $active = (strpos($requestUri, (string)$it['url']) !== false);
    }

    // Sichtbarkeit: public immer, intern nur wenn login + permission passt
    $visible = true;
    if ($requiresLogin && !$userId) {
      $visible = false;
    } elseif ($permModul && $permObjT) {
      $visible = user_can_see($userId, $permModul, $permObjT, $permObjId);
    }

    $byId[$id] = [
      'id' => $id,
      'parent_id' => $parentId,
      'label' => (string)$it['label'],
      'icon' => $it['icon'] ?? null,
      'route_key' => $routeKey,
      'href' => $href,
      'visible' => $visible,
      'active' => $active,
      'branch_active' => false, // wird später gesetzt
      'children' => [],
    ];
  }

  // Tree bauen (erstmal komplett)
  foreach ($byId as $id => $node) {
    $pid = $node['parent_id'];
    if ($pid !== null && isset($byId[$pid])) {
      $byId[$pid]['children'][] = &$byId[$id];
    }
  }
  unset($node);

  // Root nodes sammeln
  $roots = [];
  foreach ($byId as $id => $node) {
    if ($node['parent_id'] === null || !isset($byId[$node['parent_id']])) {
      $roots[] = &$byId[$id];
    }
  }
  unset($node);

  // Rekursiv: branch_active + auto-include parent wenn child sichtbar
  $prune = function (&$node) use (&$prune): bool {
    $hasVisibleChild = false;
    $hasActiveChild = false;

    foreach ($node['children'] as $k => &$ch) {
      $childVisible = $prune($ch);
      if (!$childVisible) {
        unset($node['children'][$k]);
        continue;
      }
      $hasVisibleChild = true;
      if (!empty($ch['active']) || !empty($ch['branch_active'])) $hasActiveChild = true;
    }
    unset($ch);

    // Re-index children after unset
    $node['children'] = array_values($node['children']);

    $node['branch_active'] = $hasActiveChild;

    // Sichtbarkeit: wenn Node selbst sichtbar ODER mindestens ein sichtbares Child
    return (bool)($node['visible'] || $hasVisibleChild);
  };

  foreach ($roots as $k => &$r) {
    if (!$prune($r)) unset($roots[$k]);
  }
  unset($r);

  $roots = array_values($roots);

  // is_group: hat Children und keine href => Gruppe
  $markGroup = function (&$node) use (&$markGroup) {
    $node['is_group'] = (count($node['children']) > 0 && empty($node['href']));
    foreach ($node['children'] as &$ch) $markGroup($ch);
    unset($ch);
  };
  foreach ($roots as &$r) $markGroup($r);
  unset($r);

  return $roots;
}