<?php
declare(strict_types=1);

/**
 * Aggregates eweb_inventory_deltas into eweb_inventory_movement_daily
 * for yesterday + today (UTC).
 */

require __DIR__ . '/../../src/Bootstrap.php';

use App\Support\Db;

$pdo = Db::pdo();

// Recompute yesterday and today (UTC days)
$days = [
    (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d'),
    (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d'),
];

$sql = "
INSERT INTO eweb_inventory_movement_daily
  (day, sku, delta_sum, abs_sum, neg_abs_sum, pos_sum, events_count)
SELECT
  DATE(computed_at) AS day,
  sku,
  SUM(delta) AS delta_sum,
  SUM(ABS(delta)) AS abs_sum,
  SUM(CASE WHEN delta < 0 THEN ABS(delta) ELSE 0 END) AS neg_abs_sum,
  SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) AS pos_sum,
  COUNT(*) AS events_count
FROM eweb_inventory_deltas
WHERE DATE(computed_at) = :day
GROUP BY DATE(computed_at), sku
ON DUPLICATE KEY UPDATE
  delta_sum = VALUES(delta_sum),
  abs_sum = VALUES(abs_sum),
  neg_abs_sum = VALUES(neg_abs_sum),
  pos_sum = VALUES(pos_sum),
  events_count = VALUES(events_count);
";

$stmt = $pdo->prepare($sql);

foreach ($days as $day) {
    $stmt->execute([':day' => $day]);
    echo "[movement-daily] aggregated {$day}\n";
}
