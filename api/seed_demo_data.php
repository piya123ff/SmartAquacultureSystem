<?php
/* =========================================================
 * api/seed_demo_data.php
 * Insert fresh normal sensor readings for all active tanks
 * เรียกตอน login — ให้ทุกตู้แสดง online ทันที
 * POST (no body required) | GET also accepted
 * ========================================================= */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }

require_once '../db.php';

try {
    $pdo = getConnection();

    // Fetch all active tanks with their settings
    $tanks = $pdo->query("
        SELECT t.tank_id, t.tank_name,
               COALESCE(ts.target_temp, 28)         AS target_temp,
               COALESCE(ts.target_ph,  7.2)         AS target_ph,
               COALESCE(ts.min_temp,   24)           AS min_temp,
               COALESCE(ts.max_temp,   32)           AS max_temp
        FROM tanks t
        LEFT JOIN tank_settings ts ON ts.tank_id = t.tank_id
        WHERE t.deleted_at IS NULL
        ORDER BY t.tank_id
    ")->fetchAll();

    if (empty($tanks)) {
        ob_end_clean();
        echo json_encode(['success' => true, 'seeded' => 0, 'message' => 'No active tanks found']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO sensor_logs (tank_id, temperature, ph_level, turbidity, water_level)
        VALUES (:tank_id, :temperature, :ph_level, :turbidity, :water_level)
    ");
    $stmtTs = $pdo->prepare("UPDATE tanks SET last_sensor_at = NOW() WHERE tank_id = :id");

    $seeded = 0;
    foreach ($tanks as $t) {
        // Generate realistic normal values ± small random noise
        $temp  = round($t['target_temp'] + (mt_rand(-20, 20) / 10), 1);  // ±2°C
        $ph    = round($t['target_ph']   + (mt_rand(-10, 10) / 100), 2); // ±0.1
        $turb  = round(18 + mt_rand(0, 12), 1);   // 18–30 NTU (clear water)
        $water = round(78 + mt_rand(0, 10), 0);    // 78–88%

        $stmt->execute([
            ':tank_id'     => (int)$t['tank_id'],
            ':temperature' => $temp,
            ':ph_level'    => $ph,
            ':turbidity'   => $turb,
            ':water_level' => $water,
        ]);
        try { $stmtTs->execute([':id' => (int)$t['tank_id']]); } catch (PDOException $e) {}

        // Mark tank as online
        try {
            $pdo->prepare("UPDATE tanks SET status='online' WHERE tank_id=:id")
                ->execute([':id' => (int)$t['tank_id']]);
        } catch (PDOException $e) {}

        $seeded++;
    }

    // Also reset all device/sensor statuses to normal (clear any stale errors)
    try {
        $pdo->exec("UPDATE sensor_configs SET status='normal' WHERE status='error'");
        $pdo->exec("UPDATE devices SET status='normal', error_msg=NULL WHERE status='error'");
    } catch (PDOException $e) { /* tables may not exist yet */ }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'seeded'  => $seeded,
        'message' => "Seeded normal data for {$seeded} tanks",
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
