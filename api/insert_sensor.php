<?php
// ===================================
// api/insert_sensor.php
// รับค่าเซนเซอร์จาก IoT Device (POST)
// ===================================
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

function jsonError($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'รองรับเฉพาะ POST เท่านั้น');
}

require_once '../db.php';

$raw  = file_get_contents('php://input');
$data = !empty($raw) ? json_decode($raw, true) : $_POST;

$tank_id     = isset($data['tank_id'])     ? (int)   $data['tank_id']     : null;
$temperature = isset($data['temperature']) ? (float) $data['temperature'] : null;
$ph_level    = isset($data['ph_level'])    ? (float) $data['ph_level']    : null;
$turbidity   = isset($data['turbidity'])   ? (float) $data['turbidity']   : null;
$water_level = isset($data['water_level']) ? (float) $data['water_level'] : null;

if (!$tank_id || $temperature === null || $ph_level === null) {
    jsonError(400, 'ข้อมูลไม่ครบ ต้องส่ง: tank_id, temperature, ph_level');
}

try {
    $pdo = getConnection();
} catch (Exception $e) {
    jsonError(500, 'DB connect error');
}

function saveAlert(PDO $pdo, int $tank_id, int $category_id, string $event_type, string $severity, $sensor_value, $threshold_value, string $detail): void {
    $recent = $pdo->prepare("
        SELECT COUNT(*) FROM event_logs
        WHERE tank_id = ? AND event_type = ? AND created_at >= NOW() - INTERVAL 5 MINUTE
    ");
    $recent->execute([$tank_id, $event_type]);
    if ((int)$recent->fetchColumn() > 0) {
        return;
    }

    $pdo->prepare("
        INSERT INTO event_logs (
            tank_id, category_id, event_type, severity,
            sensor_value, threshold, detail
        ) VALUES (
            :tank_id, :category_id, :event_type, :severity,
            :sensor_value, :threshold, :detail
        )
    ")->execute([
        ':tank_id'       => $tank_id,
        ':category_id'   => $category_id,
        ':event_type'    => $event_type,
        ':severity'      => $severity,
        ':sensor_value'  => $sensor_value,
        ':threshold'     => $threshold_value,
        ':detail'        => $detail,
    ]);
}

try {
    $pdo->prepare("
        INSERT INTO sensor_logs (tank_id, temperature, ph_level, turbidity, water_level)
        VALUES (:tank_id, :temperature, :ph_level, :turbidity, :water_level)
    ")->execute([
        ':tank_id'     => $tank_id,
        ':temperature' => $temperature,
        ':ph_level'    => $ph_level,
        ':turbidity'   => $turbidity,
        ':water_level' => $water_level,
    ]);

    $log_id = $pdo->lastInsertId();

    // อัปเดตเวลา sensor ล่าสุด (last_sensor_at อาจยังไม่มีถ้า migration 003 ยังไม่รัน)
    try {
        $pdo->prepare("UPDATE tanks SET last_sensor_at = NOW() WHERE tank_id = :id")
            ->execute([':id' => $tank_id]);
    } catch (PDOException $e) { /* ข้ามถ้า column ยังไม่มี */ }

    $stmt = $pdo->prepare("SELECT * FROM tank_settings WHERE tank_id = :tank_id LIMIT 1");
    $stmt->execute([':tank_id' => $tank_id]);
    $settings = $stmt->fetch();

    $alerts = [];

    if ($settings) {
        if ($temperature > $settings['max_temp']) {
            $detail = "อุณหภูมิสูงเกินกำหนด: {$temperature}°C (สูงสุด: {$settings['max_temp']}°C)";
            saveAlert($pdo, $tank_id, 1, 'alert_temp_high', 'critical', $temperature, $settings['max_temp'], $detail);
            $alerts[] = $detail;
        }
        if ($temperature < $settings['min_temp']) {
            $detail = "อุณหภูมิต่ำเกินกำหนด: {$temperature}°C (ต่ำสุด: {$settings['min_temp']}°C)";
            saveAlert($pdo, $tank_id, 1, 'alert_temp_low', 'critical', $temperature, $settings['min_temp'], $detail);
            $alerts[] = $detail;
        }
        if ($turbidity !== null && $turbidity > $settings['trigger_turbidity']) {
            $detail = "ความขุ่นสูงเกินกำหนด: {$turbidity} NTU (กำหนดไว้: {$settings['trigger_turbidity']} NTU)";
            saveAlert($pdo, $tank_id, 3, 'alert_turbidity', 'warning', $turbidity, $settings['trigger_turbidity'], $detail);
            $alerts[] = $detail;
        }
        if ($ph_level > $settings['trigger_ph_high']) {
            $detail = "pH สูงเกินกำหนด: {$ph_level} (กำหนดไว้: {$settings['trigger_ph_high']})";
            saveAlert($pdo, $tank_id, 2, 'alert_ph_high', 'critical', $ph_level, $settings['trigger_ph_high'], $detail);
            $alerts[] = $detail;
        }
        if ($ph_level < $settings['trigger_ph_low']) {
            $detail = "pH ต่ำเกินกำหนด: {$ph_level} (กำหนดไว้: {$settings['trigger_ph_low']})";
            saveAlert($pdo, $tank_id, 2, 'alert_ph_low', 'warning', $ph_level, $settings['trigger_ph_low'], $detail);
            $alerts[] = $detail;
        }
        if ($water_level !== null && $water_level < $settings['trigger_water_low']) {
            $detail = "ระดับน้ำต่ำเกินกำหนด: {$water_level}% (กำหนดไว้: {$settings['trigger_water_low']}%)";
            saveAlert($pdo, $tank_id, 4, 'alert_water_low', 'warning', $water_level, $settings['trigger_water_low'], $detail);
            $alerts[] = $detail;
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกข้อมูลสำเร็จ',
        'log_id'  => (int)$log_id,
        'alerts'  => $alerts,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
