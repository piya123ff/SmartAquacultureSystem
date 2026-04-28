<?php
// ===================================
// api/get_stats.php
// Statistics API — AVG/MIN/MAX per tank per period
// ===================================
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function jsonError($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../db.php';

try {
    $pdo = getConnection();
} catch (Exception $e) {
    jsonError(500, 'DB connect error');
}

$tank_id   = isset($_GET['tank_id'])   && $_GET['tank_id'] !== '' ? (int)$_GET['tank_id'] : null;
$period    = isset($_GET['period'])    ? trim($_GET['period']) : '7d';
$all_tanks = isset($_GET['all_tanks']) && $_GET['all_tanks'] == '1';

function periodToInterval(string $period, int $hours = 24): string {
    switch ($period) {
        case 'today':  return '24 HOUR';
        case '7d':     return '7 DAY';
        case '30d':    return '30 DAY';
        case 'custom': return "{$hours} HOUR";
        default:       return '7 DAY';
    }
}

$customHours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$interval    = periodToInterval($period, $customHours);

function getStatsForTank(PDO $pdo, int $tank_id, string $interval): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)           AS sample_count,
            ROUND(AVG(temperature), 2) AS avg_temp,
            ROUND(MIN(temperature), 2) AS min_temp,
            ROUND(MAX(temperature), 2) AS max_temp,
            ROUND(AVG(ph_level),    2) AS avg_ph,
            ROUND(MIN(ph_level),    2) AS min_ph,
            ROUND(MAX(ph_level),    2) AS max_ph,
            ROUND(AVG(turbidity),   2) AS avg_turbidity,
            ROUND(MIN(turbidity),   2) AS min_turbidity,
            ROUND(MAX(turbidity),   2) AS max_turbidity,
            ROUND(AVG(water_level), 2) AS avg_water,
            ROUND(MIN(water_level), 2) AS min_water,
            ROUND(MAX(water_level), 2) AS max_water,
            MIN(recorded_at) AS first_record,
            MAX(recorded_at) AS last_record
        FROM sensor_logs
        WHERE tank_id    = :tank_id
          AND recorded_at >= NOW() - INTERVAL {$interval}
    ");
    $stmt->execute([':tank_id' => $tank_id]);
    $row = $stmt->fetch();

    $stmtA = $pdo->prepare("
        SELECT COUNT(*) AS alert_count
        FROM event_logs
        WHERE tank_id    = :tank_id
          AND created_at >= NOW() - INTERVAL {$interval}
          AND severity IN ('critical','warning')
    ");
    $stmtA->execute([':tank_id' => $tank_id]);
    $alertCount = (int)$stmtA->fetchColumn();

    $stmtT = $pdo->prepare("
        SELECT
            DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00') AS hour_bucket,
            ROUND(AVG(temperature), 2) AS avg_temp,
            ROUND(AVG(ph_level),    2) AS avg_ph,
            ROUND(AVG(turbidity),   2) AS avg_turbidity
        FROM sensor_logs
        WHERE tank_id    = :tank_id
          AND recorded_at >= NOW() - INTERVAL 24 HOUR
        GROUP BY hour_bucket
        ORDER BY hour_bucket ASC
    ");
    $stmtT->execute([':tank_id' => $tank_id]);
    $trend = $stmtT->fetchAll();

    return [
        'sample_count' => (int)($row['sample_count'] ?? 0),
        'alert_count'  => $alertCount,
        'temperature'  => ['avg' => (float)($row['avg_temp'] ?? 0), 'min' => (float)($row['min_temp'] ?? 0), 'max' => (float)($row['max_temp'] ?? 0)],
        'ph_level'     => ['avg' => (float)($row['avg_ph']   ?? 0), 'min' => (float)($row['min_ph']   ?? 0), 'max' => (float)($row['max_ph']   ?? 0)],
        'turbidity'    => ['avg' => (float)($row['avg_turbidity'] ?? 0), 'min' => (float)($row['min_turbidity'] ?? 0), 'max' => (float)($row['max_turbidity'] ?? 0)],
        'water_level'  => ['avg' => (float)($row['avg_water'] ?? 0), 'min' => (float)($row['min_water'] ?? 0), 'max' => (float)($row['max_water'] ?? 0)],
        'first_record' => $row['first_record'] ?? null,
        'last_record'  => $row['last_record']  ?? null,
        'hourly_trend' => $trend,
    ];
}

try {
    // ตรวจว่า deleted_at มีอยู่หรือยัง (ก่อนรัน migration)
    $hasDeletedAt = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tanks'
          AND COLUMN_NAME  = 'deleted_at'
    ")->fetchColumn();

    $deletedFilter = $hasDeletedAt ? "WHERE deleted_at IS NULL" : "";

    $result = [];

    if ($all_tanks || !$tank_id) {
        $tanks = $pdo->query("SELECT tank_id, tank_name, species, location FROM tanks {$deletedFilter} ORDER BY tank_id")->fetchAll();
        foreach ($tanks as $t) {
            $stats = getStatsForTank($pdo, (int)$t['tank_id'], $interval);
            $result[] = array_merge([
                'tank_id'   => (int)$t['tank_id'],
                'tank_name' => $t['tank_name'],
                'species'   => $t['species'],
                'location'  => $t['location'],
                'period'    => $period,
                'interval'  => $interval,
            ], $stats);
        }
    } else {
        $whereClause = $hasDeletedAt
            ? "WHERE tank_id = :id AND deleted_at IS NULL"
            : "WHERE tank_id = :id";
        $tStmt = $pdo->prepare("SELECT tank_id, tank_name, species, location FROM tanks {$whereClause} LIMIT 1");
        $tStmt->execute([':id' => $tank_id]);
        $t = $tStmt->fetch();

        if (!$t) {
            jsonError(404, "ไม่พบตู้ปลา ID: {$tank_id}");
        }

        $stats = getStatsForTank($pdo, $tank_id, $interval);
        $result[] = array_merge([
            'tank_id'   => (int)$t['tank_id'],
            'tank_name' => $t['tank_name'],
            'species'   => $t['species'],
            'location'  => $t['location'],
            'period'    => $period,
            'interval'  => $interval,
        ], $stats);
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'period'  => $period,
        'count'   => count($result),
        'data'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
