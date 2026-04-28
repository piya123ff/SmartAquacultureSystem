<?php
// ===================================
// api/get_device_status.php
// ดึงสถานะ sensor_configs + devices ตาม tank_id
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

$tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : null;
$where   = $tank_id ? 'WHERE tank_id = :tid' : '';

try {
    $sensorConfigs = [];
    $devices       = [];

    // sensor_configs อาจยังไม่มี (ถ้า migration 003 ยังไม่รัน)
    try {
        $sensors = $pdo->prepare("
            SELECT tank_id, sensor_type, is_enabled, status, calibration_offset
            FROM sensor_configs
            {$where}
            ORDER BY tank_id
        ");
        $sensors->execute($tank_id ? [':tid' => $tank_id] : []);
        $sensorConfigs = $sensors->fetchAll();
    } catch (PDOException $e) {
        $sensorConfigs = [];
    }

    // devices อาจยังไม่มี (ถ้า migration 003 ยังไม่รัน)
    try {
        $devStmt = $pdo->prepare("
            SELECT tank_id, device_type, status, error_msg, last_seen
            FROM devices
            {$where}
            ORDER BY tank_id
        ");
        $devStmt->execute($tank_id ? [':tid' => $tank_id] : []);
        $devices = $devStmt->fetchAll();
    } catch (PDOException $e) {
        $devices = [];
    }

    ob_end_clean();
    echo json_encode([
        'success'        => true,
        'sensor_configs' => $sensorConfigs,
        'devices'        => $devices,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
