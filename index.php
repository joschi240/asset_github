<?php
require_once __DIR__ . '/src/auth.php';

$cfg = app_cfg();
$r = urlencode((string)($cfg['app']['default_route'] ?? 'wartung.dashboard'));
header("Location: {$cfg['app']['base_url']}/app.php?r={$r}");
exit;