<?php
/* =========================================================
 * api/simulate_device_error.php  v2
 * รับคำสั่งจำลองอุปกรณ์พัง/ปกติ แยกตามชิ้น
 * POST { tank_id, device_name, error_detail?, is_resolved? }
 * ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit; 
}

require_once '../db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['tank_id']) || empty($data['device_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing tank_id or device_name']);
    exit;
}

$tank_id      = (int)$data['tank_id'];
$device_name  = $data['device_name'];
$error_detail = $data['error_detail'] ?? 'Device connection lost';
$is_resolved  = !empty($data['is_resolved']);

$SENSOR_TYPES   = ['temperature', 'ph', 'turbidity', 'water_level'];
$ACTUATOR_TYPES = ['pump', 'feeder', 'cctv'];

// ชื่อที่อ่านได้ของแต่ละ device
$DEVICE_LABELS = [
    'temperature' => 'เซ็นเซอร์อุณหภูมิ',
    'ph'          => 'เซ็นเซอร์ pH',
    'turbidity'   => 'เซ็นเซอร์ความขุ่น',
    'water_level' => 'เซ็นเซอร์ระดับน้ำ',
    'pump'        => 'ปั๊มน้ำ',
    'feeder'      => 'เครื่องให้อาหาร',
    'cctv'        => 'กล้อง CCTV',
];
$device_label = $DEVICE_LABELS[$device_name] ?? $device_name;

try {
    $pdo = getConnection();
    $pdo->beginTransaction();

    // Lookup category_id สำหรับ hardware (fallback = 8 ถ้ายังไม่มี row)
    $hwCatId = (int)($pdo->query(
        "SELECT category_id FROM event_categories WHERE category_code='hardware' LIMIT 1"
    )->fetchColumn() ?: 8);

    if ($is_resolved) {
        if (in_array($device_name, $SENSOR_TYPES, true)) {
            $pdo->prepare("UPDATE sensor_configs SET status='normal' WHERE tank_id=? AND sensor_type=?")
                ->execute([$tank_id, $device_name]);
        } elseif (in_array($device_name, $ACTUATOR_TYPES, true)) {
            $pdo->prepare("UPDATE devices SET status='normal', error_msg=NULL WHERE tank_id=? AND device_type=?")
                ->execute([$tank_id, $device_name]);
        }

        $pdo->prepare("INSERT INTO event_logs (tank_id, category_id, event_type, severity, detail)
                       VALUES (?, ?, 'hardware_resolved', 'info', ?)")
            ->execute([$tank_id, $hwCatId, "✓ ซ่อมแซมแล้ว: {$device_label} (ตู้ #{$tank_id})"]);

    } else {
        if (in_array($device_name, $SENSOR_TYPES, true)) {
            $pdo->prepare("
                INSERT INTO sensor_configs (tank_id, sensor_type, status, is_enabled)
                VALUES (?, ?, 'error', 1)
                ON DUPLICATE KEY UPDATE status='error'
            ")->execute([$tank_id, $device_name]);
        } elseif (in_array($device_name, $ACTUATOR_TYPES, true)) {
            $pdo->prepare("
                INSERT INTO devices (tank_id, device_type, status, error_msg)
                VALUES (?, ?, 'error', ?)
                ON DUPLICATE KEY UPDATE status='error', error_msg=VALUES(error_msg)
            ")->execute([$tank_id, $device_name, $error_detail]);
        }

        $detail = "⚠ อุปกรณ์ขัดข้อง: {$device_label} (ตู้ #{$tank_id})";
        if ($error_detail && $error_detail !== 'Device connection lost') {
            $detail .= " — {$error_detail}";
        }

        $pdo->prepare("INSERT INTO event_logs (tank_id, category_id, event_type, severity, detail)
                       VALUES (?, ?, 'hardware_error', 'critical', ?)")
            ->execute([$tank_id, $hwCatId, $detail]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Simulated {$device_name} " . ($is_resolved ? 'recovery' : 'error')
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>