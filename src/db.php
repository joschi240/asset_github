<?php
// src/db.php

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = require __DIR__ . '/config.php';
  $db = $cfg['db'];

  $dsn = sprintf(
    "mysql:host=%s;dbname=%s;charset=%s",
    $db['host'],
    $db['name'],
    $db['charset']
  );

  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}

function db_one(string $sql, array $params = []) {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st->fetch();
}

function db_all(string $sql, array $params = []): array {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function db_exec(string $sql, array $params = []): int {
  $st = db()->prepare($sql);
  $st->execute($params);
  return $st->rowCount();
}