<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/_lib.php';
ai_auth_gate();

try {
    $pdo = ai_db();

    // Use MAX on indexed datetime cols (computed_at has an index; UpdateDateTime is indexed in active_items)
    $maxDelta = $pdo->query("SELECT MAX(computed_at) AS max_computed_at FROM eweb_inventory_deltas")->fetch();
    $maxItem  = $pdo->query("SELECT MAX(UpdateDateTime) AS max_update_datetime FROM eweb_active_items WHERE is_deleted = 0")->fetch();

    // Also include last daily aggregate day for movement table (fast, small)
    $maxDaily = $pdo->query("SELECT MAX(day) AS max_day FROM eweb_inventory_movement_daily")->fetch();

    $tzName = getenv('REPORT_TZ') ?: 'Australia/Melbourne';
    $tz = new DateTimeZone($tzName);
    $utc = new DateTimeZone('UTC');

    $maxComputedAt = $maxDelta['max_computed_at'] ?? null;
    $maxUpdateDt   = $maxItem['max_update_datetime'] ?? null;
    $maxDay        = $maxDaily['max_day'] ?? null;

    $asLocal = function ($dt) use ($tz, $utc) {
        if (!$dt) return null;
        $d = new DateTimeImmutable((string)$dt, $utc);
        return $d->setTimezone($tz)->format('Y-m-d H:i:s');
    };

    ai_json_out([
        'timezone' => $tzName,
        'deltas' => [
            'max_computed_at_utc' => $maxComputedAt,
            'max_computed_at_local' => $asLocal($maxComputedAt),
        ],
        'active_items' => [
            'max_update_datetime_utc' => $maxUpdateDt,
            'max_update_datetime_local' => $asLocal($maxUpdateDt),
        ],
        'movement_daily' => [
            'max_day_utc' => $maxDay,
        ],
    ], 200);

} catch (Throwable $e) {
    error_log('[data-freshness] ERROR: ' . $e->getMessage());
    ai_json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
