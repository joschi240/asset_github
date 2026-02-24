<?php
require_once __DIR__ . '/src/auth.php';
logout();
$cfg = app_cfg();
header("Location: {$cfg['app']['base_url']}/login.php");
exit;