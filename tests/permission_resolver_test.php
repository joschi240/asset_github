<?php
/**
 * tests/permission_resolver_test.php
 *
 * CLI Smoke-Test für die Permission-Resolver-Logik (kein DB-Zugriff).
 * Simuliert core_permission-Einträge als PHP-Array und prüft,
 * ob Wildcard-Rechte korrekt greifen.
 *
 * Ausführen: php tests/permission_resolver_test.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Stub: repliziert die SQL-Logik aus user_can_flag (src/auth.php) rein in PHP
// ---------------------------------------------------------------------------

/**
 * @param  array<array{user_id:int,modul:string,objekt_typ:string,objekt_id:int|null,darf_sehen:int,darf_aendern:int,darf_loeschen:int}> $permissions
 */
function resolve_flag(
    array $permissions,
    int   $userId,
    string $modul,
    string $objektTyp,
    ?int  $objektId,
    string $flagCol
): bool {
    $val = 0;
    foreach ($permissions as $p) {
        if ((int)$p['user_id'] !== $userId) continue;

        $pObjId = ($p['objekt_id'] !== null) ? (int)$p['objekt_id'] : null;

        // Matching-Priorität (identisch zur SQL-OR-Logik in user_can_flag):
        // 1. Exakt: (modul, objekt_typ, objekt_id oder global)
        $exactModul    = ($p['modul'] === $modul);
        $exactObjTyp   = ($p['objekt_typ'] === $objektTyp);
        $objIdMatch    = ($pObjId === null || $pObjId === $objektId);
        $match1 = $exactModul && $exactObjTyp && $objIdMatch;

        // 2. Modul + objekt_typ='global' (objekt_id NULL)
        $match2 = ($p['modul'] === $modul) && ($p['objekt_typ'] === 'global') && ($pObjId === null);

        // 3+4. Wildcard: modul='*' AND objekt_typ IN ('*','global') AND objekt_id NULL
        $match3 = ($p['modul'] === '*') && in_array($p['objekt_typ'], ['*', 'global'], true) && ($pObjId === null);

        if ($match1 || $match2 || $match3) {
            $val = max($val, (int)$p[$flagCol]);
        }
    }
    return $val === 1;
}

/**
 * @param  array<array{user_id:int,modul:string,objekt_typ:string,objekt_id:int|null,darf_sehen:int,darf_aendern:int,darf_loeschen:int}> $permissions
 */
function resolve_can_see(
    array $permissions,
    int $userId,
    string $modul,
    string $objektTyp,
    ?int  $objektId
): bool {
    // Admin wildcard shortcut (identisch zu user_can_see in helpers.php)
    foreach ($permissions as $p) {
        if ((int)$p['user_id'] !== $userId) continue;
        if ($p['modul'] === '*' && in_array($p['objekt_typ'], ['*', 'global'], true) && (int)$p['darf_sehen'] === 1) {
            return true;
        }
    }
    // Non-admin path: exact + module-global fallback + wildcard (all three OR branches)
    return resolve_flag($permissions, $userId, $modul, $objektTyp, $objektId, 'darf_sehen');
}

// ---------------------------------------------------------------------------
// Test-Hilfsfunktionen
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_true(bool $result, string $label): void {
    global $passed, $failed;
    if ($result) {
        echo "  [PASS] $label\n";
        $passed++;
    } else {
        echo "  [FAIL] $label\n";
        $failed++;
    }
}

function assert_false(bool $result, string $label): void {
    assert_true(!$result, $label);
}

// ---------------------------------------------------------------------------
// Testdaten
// ---------------------------------------------------------------------------

/** Admin: modul='*', objekt_typ='*', darf_sehen=1, darf_aendern=1, darf_loeschen=1 */
$adminWildcard = [
    ['user_id'=>1, 'modul'=>'*', 'objekt_typ'=>'*', 'objekt_id'=>null,
     'darf_sehen'=>1, 'darf_aendern'=>1, 'darf_loeschen'=>1],
];

/** Admin mit modul='*', objekt_typ='global' (alternative Schreibweise) */
$adminGlobal = [
    ['user_id'=>1, 'modul'=>'*', 'objekt_typ'=>'global', 'objekt_id'=>null,
     'darf_sehen'=>1, 'darf_aendern'=>1, 'darf_loeschen'=>1],
];

/** Normaler User: nur sehen für wartungstool/global */
$normalUser = [
    ['user_id'=>2, 'modul'=>'wartungstool', 'objekt_typ'=>'global', 'objekt_id'=>null,
     'darf_sehen'=>1, 'darf_aendern'=>0, 'darf_loeschen'=>0],
];

/** Spezifischer User: darf bestimmtes Objekt ändern */
$specificUser = [
    ['user_id'=>3, 'modul'=>'stoerungstool', 'objekt_typ'=>'ticket', 'objekt_id'=>42,
     'darf_sehen'=>1, 'darf_aendern'=>1, 'darf_loeschen'=>0],
];

/** Leere Rechte */
$noPerms = [];

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "\n=== 1) Admin Wildcard (modul='*', objekt_typ='*') ===\n";
assert_true (resolve_flag($adminWildcard, 1, 'wartungstool',  'global', null, 'darf_aendern'),  'admin darf_aendern auf wartungstool/global');
assert_true (resolve_flag($adminWildcard, 1, 'stoerungstool', 'global', null, 'darf_aendern'),  'admin darf_aendern auf stoerungstool/global');
assert_true (resolve_flag($adminWildcard, 1, 'stoerungstool', 'ticket', 42,   'darf_aendern'),  'admin darf_aendern auf spezifisches Ticket');
assert_true (resolve_flag($adminWildcard, 1, 'admin',         'users',  null, 'darf_loeschen'), 'admin darf_loeschen auf admin/users');
assert_true (resolve_can_see($adminWildcard, 1, 'wartungstool', 'global', null),                'admin user_can_see wartungstool');

echo "\n=== 2) Admin Global (modul='*', objekt_typ='global') ===\n";
assert_true (resolve_flag($adminGlobal, 1, 'wartungstool',  'global', null, 'darf_aendern'),  'admin/global darf_aendern auf wartungstool/global');
assert_true (resolve_flag($adminGlobal, 1, 'stoerungstool', 'global', null, 'darf_aendern'),  'admin/global darf_aendern auf stoerungstool/global');
assert_true (resolve_can_see($adminGlobal, 1, 'stoerungstool', 'global', null),               'admin/global user_can_see stoerungstool');

echo "\n=== 3) Normaler User: nur sehen, kein ändern ===\n";
assert_true (resolve_flag($normalUser, 2, 'wartungstool', 'global', null, 'darf_sehen'),   'normalUser darf_sehen wartungstool/global');
assert_false(resolve_flag($normalUser, 2, 'wartungstool', 'global', null, 'darf_aendern'), 'normalUser darf_aendern wartungstool/global – soll false sein');
assert_false(resolve_flag($normalUser, 2, 'stoerungstool','global', null, 'darf_sehen'),   'normalUser darf_sehen stoerungstool – kein Eintrag, soll false sein');

echo "\n=== 4) Spezifischer Objekt-Scope ===\n";
assert_true (resolve_flag($specificUser, 3, 'stoerungstool', 'ticket', 42,  'darf_aendern'),  'specificUser darf_aendern Ticket #42');
assert_false(resolve_flag($specificUser, 3, 'stoerungstool', 'ticket', 99,  'darf_aendern'),  'specificUser darf_aendern Ticket #99 – soll false sein');
assert_false(resolve_flag($specificUser, 3, 'stoerungstool', 'ticket', 42,  'darf_loeschen'), 'specificUser darf_loeschen Ticket #42 – soll false sein');

echo "\n=== 5) Keine Rechte ===\n";
assert_false(resolve_flag($noPerms, 1, 'wartungstool', 'global', null, 'darf_sehen'),   'leere Rechte – darf_sehen soll false sein');
assert_false(resolve_flag($noPerms, 1, 'wartungstool', 'global', null, 'darf_aendern'), 'leere Rechte – darf_aendern soll false sein');

echo "\n=== 6) Kein Wildcard-Bleed auf anderen User ===\n";
assert_false(resolve_flag($adminWildcard, 2, 'wartungstool', 'global', null, 'darf_aendern'), 'User 2 hat kein Wildcard – soll false sein');

echo "\n=== 7) Fallback modul+global (objekt_typ nicht global, aber Eintrag mit global vorhanden) ===\n";
$fallbackGlobal = [
    ['user_id'=>4, 'modul'=>'wartungstool', 'objekt_typ'=>'global', 'objekt_id'=>null,
     'darf_sehen'=>1, 'darf_aendern'=>1, 'darf_loeschen'=>0],
];
assert_true (resolve_flag($fallbackGlobal, 4, 'wartungstool', 'dashboard', null, 'darf_sehen'),  'Fallback: modul+global matcht auf dashboard');
assert_true (resolve_flag($fallbackGlobal, 4, 'wartungstool', 'dashboard', null, 'darf_aendern'),'Fallback: modul+global ändern');

// ---------------------------------------------------------------------------
// Ergebnis
// ---------------------------------------------------------------------------

echo "\n=== 8) SQL-Placeholder-Count Safety (? vs params) ===\n";

// Prüft, ob substr_count($sql,'?') === count($params) für die Queries aus den Resolver-Funktionen.
// Schützt vor Silent-Bugs durch falsch gezählte PDO-Parameter.

$sqlChecks = [
  [
    'label' => 'user_can_flag (auth.php) – 5 Platzhalter',
    'sql'   => "SELECT MAX(darf_aendern) AS ok
       FROM core_permission
       WHERE user_id=?
         AND (
           (modul=? AND objekt_typ=? AND (objekt_id IS NULL OR objekt_id=?))
           OR (modul=? AND objekt_typ='global' AND objekt_id IS NULL)
           OR (modul='*' AND objekt_typ IN ('*','global') AND objekt_id IS NULL)
         )",
    'params' => [1, 'wartungstool', 'global', null, 'wartungstool'],
  ],
  [
    'label'  => 'user_can_see non-admin (helpers.php) – 5 Platzhalter',
    'sql'    => "SELECT 1
     FROM core_permission
     WHERE user_id=?
       AND (
         (modul=? AND objekt_typ=? AND darf_sehen=1 AND (objekt_id IS NULL OR objekt_id = ?))
         OR (modul=? AND objekt_typ='global' AND darf_sehen=1 AND objekt_id IS NULL)
         OR (modul='*' AND objekt_typ IN ('*','global') AND darf_sehen=1 AND objekt_id IS NULL)
       )
     LIMIT 1",
    'params' => [1, 'stoerungstool', 'global', null, 'stoerungstool'],
  ],
  [
    'label'  => 'user_can_see admin-shortcut (helpers.php) – 1 Platzhalter',
    'sql'    => "SELECT 1 FROM core_permission
     WHERE user_id=? AND modul='*' AND objekt_typ IN ('*','global') AND darf_sehen=1
     LIMIT 1",
    'params' => [1],
  ],
  [
    'label'  => 'is_admin_user (helpers.php) – 1 Platzhalter',
    'sql'    => "SELECT 1 FROM core_permission WHERE user_id=? AND modul='*' AND objekt_typ IN ('*','global') AND darf_sehen=1 LIMIT 1",
    'params' => [1],
  ],
];

foreach ($sqlChecks as $chk) {
  $countQ = substr_count($chk['sql'], '?');
  $countP = count($chk['params']);
  assert_true($countQ === $countP, $chk['label'] . " ({$countQ} ? = {$countP} params)");
}

echo "\n----------------------------------------\n";
echo "Ergebnis: $passed bestanden, $failed fehlgeschlagen\n";
echo "----------------------------------------\n\n";

exit($failed > 0 ? 1 : 0);
