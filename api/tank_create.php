<?php
/* =========================================================
 *  api/tank_create.php
 *  เพิ่มตู้ปลาใหม่ + สร้าง tank_settings ตาม preset
 *
 *  BODY (JSON):
 *  {
 *    "tank_name": "Tank F1 - Tilapia",      (required)
 *    "species":   "Tilapia",                (optional)
 *    "location":  "Zone F",                 (optional)
 *    "device_id": "ESP32-F1",               (optional)
 *    "status":    "online"|"offline",       (optional, default=online)
 *    "preset":    "default"|"tilapia"|"shrimp"|"catfish"|"blank"
 *                                           (optional, default=default)
 *  }
 * ========================================================= */
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean(); http_response_code(200); exit;
}

function jsonError($code, $msg) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError(405, 'POST only');

require_once '../db.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) jsonError(400, 'Invalid JSON');

$name    = trim($data['tank_name'] ?? '');
$species = trim($data['species']   ?? '');
$loc     = trim($data['location']  ?? '');
$dev     = trim($data['device_id'] ?? '');
$status  = $data['status'] ?? 'online';
$preset  = $data['preset'] ?? 'default';

if ($name === '') jsonError(400, 'กรุณาระบุ tank_name');
if (!in_array($status, ['online', 'offline'], true)) $status = 'online';

/* ── preset ค่า default ต่อชนิดปลา ── */
$PRESETS = [
    'default' => [
        'target_temp'=>28, 'min_temp'=>24, 'max_temp'=>32,
        'trigger_ph_high'=>8.5, 'trigger_ph_low'=>6.5,
        'trigger_turbidity'=>50, 'trigger_water_low'=>30,
        'feeding_time'=>'08:00:00', 'feeding_amount'=>250,
        'auto_feed_status'=>'off',
    ],
    'tilapia' => [
        'target_temp'=>28, 'min_temp'=>25, 'max_temp'=>33,
        'trigger_ph_high'=>8.5, 'trigger_ph_low'=>6.5,
        'trigger_turbidity'=>50, 'trigger_water_low'=>30,
        'feeding_time'=>'07:00:00', 'feeding_amount'=>550,
        'auto_feed_status'=>'on',
    ],
    'shrimp' => [
        'target_temp'=>28, 'min_temp'=>26, 'max_temp'=>31,
        'trigger_ph_high'=>8.0, 'trigger_ph_low'=>7.5,
        'trigger_turbidity'=>40, 'trigger_water_low'=>40,
        'feeding_time'=>'06:00:00', 'feeding_amount'=>160,
        'auto_feed_status'=>'on',
    ],
    'catfish' => [
        'target_temp'=>29, 'min_temp'=>24, 'max_temp'=>34,
        'trigger_ph_high'=>8.5, 'trigger_ph_low'=>6.0,
        'trigger_turbidity'=>60, 'trigger_water_low'=>25,
        'feeding_time'=>'08:30:00', 'feeding_amount'=>300,
        'auto_feed_status'=>'off',
    ],
    'blank' => [],
];
$settings = $PRESETS[$preset] ?? $PRESETS['default'];

try { $pdo = getConnection(); }
catch (Exception $e) { jsonError(500, 'DB connect error'); }

$pdo->beginTransaction();
try {
    /* 1) INSERT tank */
    $stmt = $pdo->prepare("
        INSERT INTO tanks (tank_name, species, location, device_id, status)
        VALUES (:n, :s, :l, :d, :st)
    ");
    $stmt->execute([
        ':n'  => $name,
        ':s'  => $species ?: null,
        ':l'  => $loc     ?: null,
        ':d'  => $dev     ?: null,
        ':st' => $status,
    ]);
    $newId = (int)$pdo->lastInsertId();

    /* 2) INSERT tank_settings (ถ้าไม่ใช่ preset = blank) */
    if (!empty($settings)) {
        $cols = ['tank_id'];
        $vals = [':tank_id'];
        $ins  = [':tank_id' => $newId];
        foreach ($settings as $k => $v) {
            $cols[] = $k;
            $vals[] = ":$k";
            $ins[":$k"] = $v;
        }
        $sql = "INSERT INTO tank_settings (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $pdo->prepare($sql)->execute($ins);
    }

    /* 3) event log */
    @session_start();
    $changedBy = $_SESSION['user_id'] ?? null;
    $pdo->prepare("
        INSERT INTO event_logs (tank_id, category_id, event_type, severity, changed_by, detail)
        VALUES (:id, 8, 'tank_created', 'info', :by, :dt)
    ")->execute([
        ':id' => $newId,
        ':by' => $changedBy,
        ':dt' => "สร้างตู้ใหม่ \"$name\" (preset=$preset)",
    ]);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    jsonError(500, $e->getMessage());
}

/* fetch full info กลับไปให้ frontend */
$stmt = $pdo->prepare("SELECT * FROM tanks WHERE tank_id = :id");
$stmt->execute([':id' => $newId]);
$tank = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM tank_settings WHERE tank_id = :id");
$stmt->execute([':id' => $newId]);
$sets = $stmt->fetch(PDO::FETCH_ASSOC);

ob_end_clean();
echo json_encode([
    'success'  => true,
    'message'  => "เพิ่มตู้ \"$name\" สำเร็จ",
    'tank_id'  => $newId,
    'tank'     => $tank,
    'settings' => $sets,
], JSON_UNESCAPED_UNICODE);
