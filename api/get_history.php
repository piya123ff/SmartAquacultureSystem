<?php
// ===================================
// api/get_history.php
// ดึง sensor_logs + event_logs ตาม tank_id และช่วงเวลา
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

$tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : 1;
$hours   = isset($_GET['hours'])   ? (int)$_GET['hours']   : 24;
$limit   = isset($_GET['limit'])   ? (int)$_GET['limit']   : 200;

try {
    $stmt = $pdo->prepare("
        SELECT log_id, tank_id, recorded_at, temperature, ph_level, turbidity, water_level
        FROM sensor_logs
        WHERE tank_id = :tank_id
          AND recorded_at >= NOW() - INTERVAL :hours HOUR
        ORDER BY recorded_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':tank_id', $tank_id, PDO::PARAM_INT);
    $stmt->bindValue(':hours',   $hours,   PDO::PARAM_INT);
    $stmt->bindValue(':limit',   $limit,   PDO::PARAM_INT);
    $stmt->execute();
    $logs = array_reverse($stmt->fetchAll());

    $ev = $pdo->prepare("
        SELECT event_id, tank_id, event_type, severity, detail AS event_detail, created_at
        FROM event_logs
        WHERE tank_id = :tank_id
          AND created_at >= NOW() - INTERVAL :hours HOUR
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $ev->bindValue(':tank_id', $tank_id, PDO::PARAM_INT);
    $ev->bindValue(':hours',   $hours,   PDO::PARAM_INT);
    $ev->execute();
    $events = $ev->fetchAll();

    $st = $pdo->prepare("
        SELECT target_temp, min_temp, max_temp, trigger_turbidity,
               trigger_ph_high, trigger_ph_low, trigger_water_low
        FROM tank_settings
        WHERE tank_id = :tank_id
        LIMIT 1
    ");
    $st->execute([':tank_id' => $tank_id]);
    $settings = $st->fetch();

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'tank_id'  => $tank_id,
        'hours'    => $hours,
        'logs'     => $logs,
        'events'   => $events,
        'settings' => $settings,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
