<?php
/* =========================================================
 *  api/update_settings.php
 *  รองรับการอัปเดต 3 กลุ่ม field:
 *  (1) tanks         → tank_name, species, location, device_id, status
 *  (2) tank_settings → target_temp, min_temp, max_temp, trigger_ph*,
 *                       trigger_turbidity, trigger_water_low,
 *                       feeding_time, feeding_amount, auto_feed_status
 *  (3) sensor_configs→ การตั้งค่าฮาร์ดแวร์เซนเซอร์ (รับเป็น Array)
 * ========================================================= */
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError(405, 'POST only');

require_once '../db.php';

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data)                  jsonError(400, 'Invalid JSON');
if (empty($data['tank_id'])) jsonError(400, 'Missing tank_id');

$tank_id = (int)$data['tank_id'];
$actions = [];

// ฟิลด์ที่อนุญาตให้อัปเดต
$tankFields = ['tank_name', 'species', 'location', 'device_id', 'status'];
$settingFields = [
    'target_temp', 'min_temp', 'max_temp',
    'trigger_ph_high', 'trigger_ph_low',
    'trigger_turbidity', 'trigger_water_low',
    'feeding_time', 'feeding_amount', 'auto_feed_status',
];

// alias: frontend เดิมที่ยังส่ง trigger_ph มา → แปลงเป็น trigger_ph_high
if (isset($data['trigger_ph']) && !isset($data['trigger_ph_high'])) {
    $data['trigger_ph_high'] = $data['trigger_ph'];
}

// เชื่อมต่อฐานข้อมูล
try {
    $pdo = getConnection();
} catch (Exception $e) {
    jsonError(500, 'DB connect error');
}

// เช็คว่า tank มีอยู่จริงและยังไม่ถูกลบ
$stmt = $pdo->prepare("SELECT tank_id FROM tanks WHERE tank_id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $tank_id]);
if (!$stmt->fetch()) jsonError(404, 'ไม่พบ tank_id นี้ในระบบ');

try {
    $pdo->beginTransaction();

    /* ───── 1. อัปเดตตาราง tanks ───── */
    $updTanks  = [];
    $paramTanks = [':tank_id' => $tank_id];
    foreach ($tankFields as $f) {
        if (array_key_exists($f, $data)) {
            if ($f === 'status' && !in_array($data[$f], ['online', 'offline'], true)) {
                jsonError(400, 'status ต้องเป็น online หรือ offline');
            }
            $updTanks[]        = "$f = :$f";
            $paramTanks[":$f"] = $data[$f];
        }
    }
    if (!empty($updTanks)) {
        $sql = "UPDATE tanks SET " . implode(', ', $updTanks) . " WHERE tank_id = :tank_id";
        $pdo->prepare($sql)->execute($paramTanks);
        $actions[] = 'tank_updated';
    }

    /* ───── 2. อัปเดตตาราง tank_settings ───── */
    $updSets   = [];
    $paramSets = [':tank_id' => $tank_id];
    foreach ($settingFields as $f) {
        if (array_key_exists($f, $data)) {
            $updSets[]        = "$f = :$f";
            $paramSets[":$f"] = $data[$f];
        }
    }
    if (!empty($updSets)) {
        $check = $pdo->prepare("SELECT 1 FROM tank_settings WHERE tank_id = :tank_id");
        $check->execute([':tank_id' => $tank_id]);
        if ($check->fetchColumn()) {
            // UPDATE
            $sql = "UPDATE tank_settings SET " . implode(', ', $updSets) . " WHERE tank_id = :tank_id";
            $pdo->prepare($sql)->execute($paramSets);
            $actions[] = 'settings_update';
        } else {
            // INSERT (เฉพาะ field ที่ส่งมา ที่เหลือเป็น NULL/DEFAULT)
            $cols = ['tank_id'];
            $vals = [':tank_id'];
            foreach ($settingFields as $f) {
                if (array_key_exists($f, $data)) {
                    $cols[] = $f;
                    $vals[] = ":$f";
                }
            }
            $sql = "INSERT INTO tank_settings (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            $pdo->prepare($sql)->execute($paramSets);
            $actions[] = 'settings_insert';
        }
    }

    /* ───── 3. อัปเดตตาราง sensor_configs ───── */
    if (isset($data['sensor_configs']) && is_array($data['sensor_configs'])) {
        $stmtSensor = $pdo->prepare("
            INSERT INTO sensor_configs
                (tank_id, sensor_type, is_enabled, read_interval_sec, calibration_offset)
            VALUES
                (:tank_id, :sensor_type, :is_enabled, :read_interval_sec, :calibration_offset)
            ON DUPLICATE KEY UPDATE
                is_enabled         = VALUES(is_enabled),
                read_interval_sec  = VALUES(read_interval_sec),
                calibration_offset = VALUES(calibration_offset)
        ");

        foreach ($data['sensor_configs'] as $sc) {
            if (empty($sc['sensor_type'])) continue;
            $stmtSensor->execute([
                ':tank_id'            => $tank_id,
                ':sensor_type'        => $sc['sensor_type'],
                ':is_enabled'         => isset($sc['is_enabled'])         ? (int)$sc['is_enabled']         : 1,
                ':read_interval_sec'  => isset($sc['read_interval_sec'])  ? (int)$sc['read_interval_sec']  : 60,
                ':calibration_offset' => isset($sc['calibration_offset']) ? (float)$sc['calibration_offset'] : 0.00,
            ]);
        }
        $actions[] = 'sensor_configs_updated';
    }

    /* ───── 4. บันทึก event log ───── */
    @session_start();
    $changedBy   = $_SESSION['user_id'] ?? null;
    $changedKeys = array_keys(array_intersect_key(
        $data,
        array_flip(array_merge($tankFields, $settingFields, ['sensor_configs']))
    ));
    $detail = 'แก้ไข: ' . implode(', ', $changedKeys);
    $pdo->prepare("
        INSERT INTO event_logs
            (tank_id, category_id, event_type, severity, changed_by, detail)
        VALUES
            (:tank_id, 7, 'settings_updated', 'info', :changed_by, :detail)
    ")->execute([
        ':tank_id'    => $tank_id,
        ':changed_by' => $changedBy,
        ':detail'     => $detail,
    ]);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    jsonError(500, $e->getMessage());
}

/* ───── 5. ดึงข้อมูลล่าสุดเพื่อตอบกลับ ───── */
$stmt = $pdo->prepare("SELECT * FROM tanks WHERE tank_id = :id");
$stmt->execute([':id' => $tank_id]);
$tankRow = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM tank_settings WHERE tank_id = :id");
$stmt->execute([':id' => $tank_id]);
$settingsRow = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM sensor_configs WHERE tank_id = :id");
$stmt->execute([':id' => $tank_id]);
$sensorConfigsRow = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_end_clean();
echo json_encode([
    'success'        => true,
    'actions'        => $actions,
    'tank'           => $tankRow,
    'settings'       => $settingsRow,
    'sensor_configs' => $sensorConfigsRow,
], JSON_UNESCAPED_UNICODE);
