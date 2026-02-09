<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'time_utc' => gmdate('c'),
  'sapi' => PHP_SAPI,
]);
