<?php
// ===================================
// api/get_latest.php  v2
// ดึงข้อมูลล่าสุดของตู้ปลาทุกตู้
// รวม sensor_configs + devices + offline detection
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

try {
    $sql = "
        SELECT
            t.tank_id,
            t.tank_name,
            t.species,
            t.location,
            t.status,

            s.log_id,
            s.recorded_at,
            s.temperature,
            s.ph_level,
            s.turbidity,
            s.water_level,

            ts.target_temp,
            ts.min_temp,
            ts.max_temp,
            ts.trigger_turbidity,
            ts.trigger_ph_high,
            ts.trigger_ph_low,
            ts.trigger_water_low,
            ts.auto_feed_status,
            ts.feeding_time,
            ts.feeding_amount

        FROM tanks t
        LEFT JOIN sensor_logs s
            ON s.log_id = (
                SELECT log_id FROM sensor_logs
                WHERE tank_id = t.tank_id
                ORDER BY recorded_at DESC
                LIMIT 1
            )
        LEFT JOIN tank_settings ts ON ts.tank_id = t.tank_id
    ";

    /* กรองตู้ที่ถูก soft-delete ออกเสมอ (รองรับทั้งมีและไม่มี deleted_at) */
    $hasDeletedAt = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tanks'
          AND COLUMN_NAME  = 'deleted_at'
    ")->fetchColumn();

    if ($tank_id) {
        if ($hasDeletedAt) {
            $sql .= " WHERE t.tank_id = :tank_id AND t.deleted_at IS NULL";
        } else {
            $sql .= " WHERE t.tank_id = :tank_id";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tank_id' => $tank_id]);
    } else {
        if ($hasDeletedAt) {
            $sql .= " WHERE t.deleted_at IS NULL ORDER BY t.tank_id ASC";
        } else {
            $sql .= " ORDER BY t.tank_id ASC";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    $rows   = $stmt->fetchAll();
    $result = [];

    foreach ($rows as $row) {
        // Recent alerts
        $stmtEvents = $pdo->prepare("
            SELECT event_type, severity, detail, created_at
            FROM event_logs
            WHERE tank_id = :tank_id
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmtEvents->execute([':tank_id' => $row['tank_id']]);
        $events = $stmtEvents->fetchAll();

        // ── Sensor configs (สถานะอุปกรณ์) ──
        $scMap = [];
        try {
            $stmtSC = $pdo->prepare("
                SELECT sensor_type, is_enabled, status, calibration_offset
                FROM sensor_configs WHERE tank_id = :tid
            ");
            $stmtSC->execute([':tid' => $row['tank_id']]);
            foreach ($stmtSC->fetchAll() as $sc) {
                $scMap[$sc['sensor_type']] = [
                    'enabled'            => (bool)$sc['is_enabled'],
                    'status'             => $sc['status'],
                    'calibration_offset' => (float)$sc['calibration_offset'],
                ];
            }
        } catch (PDOException $e) { /* sensor_configs ยังไม่มี — ข้ามได้ */ }

        // ── Actuator/Device status ──
        $devMap = [];
        try {
            $stmtDev = $pdo->prepare("
                SELECT device_type, status, error_msg, last_seen
                FROM devices WHERE tank_id = :tid
            ");
            $stmtDev->execute([':tid' => $row['tank_id']]);
            foreach ($stmtDev->fetchAll() as $dev) {
                $devMap[$dev['device_type']] = [
                    'status'    => $dev['status'],
                    'error_msg' => $dev['error_msg'],
                    'last_seen' => $dev['last_seen'],
                ];
            }
        } catch (PDOException $e) { /* devices ยังไม่มี — ข้ามได้ */ }

        // ── Offline detection: ถ้าข้อมูลหายไป > 10 นาที ถือว่า offline ──
        $offlineThresholdSec = 600;
        $isDataOffline = false;
        if ($row['recorded_at']) {
            $isDataOffline = (time() - strtotime($row['recorded_at'])) > $offlineThresholdSec;
        }

        $result[] = [
            'tank_id'      => (int)$row['tank_id'],
            'tank_name'    => $row['tank_name'],
            'species'      => $row['species'],
            'location'     => $row['location'],
            'status'       => $isDataOffline ? 'offline' : ($row['recorded_at'] ? 'online' : $row['status']),
            'data_offline' => $isDataOffline,

            'latest_sensor' => $row['log_id'] ? [
                'log_id'      => (int)$row['log_id'],
                'recorded_at' => $row['recorded_at'],
                'temperature' => (isset($scMap['temperature']) && $scMap['temperature']['status'] === 'error') ? null : (float)$row['temperature'],
                'ph_level'    => (isset($scMap['ph'])          && $scMap['ph']['status']          === 'error') ? null : (float)$row['ph_level'],
                'turbidity'   => (isset($scMap['turbidity'])   && $scMap['turbidity']['status']   === 'error') ? null : (float)$row['turbidity'],
                'water_level' => (isset($scMap['water_level']) && $scMap['water_level']['status'] === 'error') ? null : (float)$row['water_level'],
            ] : null,

            'settings' => $row['target_temp'] !== null ? [
                'target_temp'       => (float)$row['target_temp'],
                'min_temp'          => (float)$row['min_temp'],
                'max_temp'          => (float)$row['max_temp'],
                'trigger_turbidity' => (float)$row['trigger_turbidity'],
                'trigger_ph'        => (float)$row['trigger_ph_high'],
                'trigger_ph_low'    => (float)$row['trigger_ph_low'],
                'trigger_water_low' => (float)$row['trigger_water_low'],
                'auto_feed_status'  => $row['auto_feed_status'],
                'feeding_time'      => $row['feeding_time'],
                'feeding_amount'    => $row['feeding_amount'] !== null ? (float)$row['feeding_amount'] : null,
            ] : null,

            'sensor_configs' => $scMap,
            'devices'        => $devMap,
            'recent_alerts'  => $events,
        ];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'count'   => count($result),
        'data'    => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
