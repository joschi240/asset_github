<?php
// src/menu.php
require_once __DIR__ . '/db.php';

function load_menu_tree(): array {
  $items = db_all("
    SELECT id, parent_id, label, icon, modul, url, sort
    FROM core_menu
    WHERE aktiv=1
    ORDER BY (parent_id IS NULL) DESC, parent_id ASC, sort ASC, id ASC
  ");

  $byId = [];
  foreach ($items as $it) {
    $it['children'] = [];
    $byId[(int)$it['id']] = $it;
  }

  $tree = [];
  foreach ($byId as $id => $it) {
    if ($it['parent_id'] === null) {
      $tree[] = $it;
    } else {
      $pid = (int)$it['parent_id'];
      if (isset($byId[$pid])) $byId[$pid]['children'][] = $it;
      else $tree[] = $it;
    }
  }

  return $tree;
}