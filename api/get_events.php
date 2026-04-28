<?php
// ===================================
// api/get_events.php
// ดึง event_logs พร้อม filter และ pagination
// GET params: tank_id, type, severity, limit, offset, hours
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

$tank_id  = isset($_GET['tank_id'])  && $_GET['tank_id']  !== '' ? (int)$_GET['tank_id']  : null;
$type     = isset($_GET['type'])     && $_GET['type']     !== '' ? trim($_GET['type'])     : null;
$severity = isset($_GET['severity']) && $_GET['severity'] !== '' ? trim($_GET['severity']) : null;
$limit    = isset($_GET['limit'])    ? min((int)$_GET['limit'],  500) : 100;
$offset   = isset($_GET['offset'])   ? max((int)$_GET['offset'],  0)  : 0;
$hours    = isset($_GET['hours'])    ? min((int)$_GET['hours'],  720) : null;

try {
    // Build WHERE
    $where  = [];
    $params = [];

    if ($tank_id) {
        $where[]             = 'e.tank_id = :tank_id';
        $params[':tank_id']  = $tank_id;
    }
    if ($type) {
        $where[]          = 'e.event_type = :type';
        $params[':type']  = $type;
    }
    if ($severity) {
        $where[]              = 'e.severity = :severity';
        $params[':severity']  = $severity;
    }
    if ($hours) {
        $where[] = "e.created_at >= NOW() - INTERVAL {$hours} HOUR";
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            e.event_id,
            e.tank_id,
            t.tank_name,
            e.event_type,
            e.severity,
            e.detail,
            e.sensor_value,
            e.threshold,
            ec.label_th   AS category_label,
            e.created_at
        FROM event_logs e
        LEFT JOIN tanks t             ON t.tank_id      = e.tank_id
        LEFT JOIN event_categories ec ON ec.category_id = e.category_id
        {$whereSQL}
        ORDER BY e.created_at DESC, e.event_id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();

    // Total count
    $sqlCount = "SELECT COUNT(*) FROM event_logs e LEFT JOIN tanks t ON t.tank_id = e.tank_id {$whereSQL}";
    $stmtC = $pdo->prepare($sqlCount);
    foreach ($params as $k => $v) $stmtC->bindValue($k, $v);
    $stmtC->execute();
    $totalCount = (int)$stmtC->fetchColumn();

    // Tank name map (รองรับทั้งมี/ไม่มี deleted_at)
    $hasDeletedAt = (bool)$pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'tanks'
          AND COLUMN_NAME  = 'deleted_at'
    ")->fetchColumn();

    $tankFilter = $hasDeletedAt ? "WHERE deleted_at IS NULL" : "";
    $tankNames  = [];
    foreach ($pdo->query("SELECT tank_id, tank_name FROM tanks {$tankFilter} ORDER BY tank_id")->fetchAll() as $r) {
        $tankNames[$r['tank_id']] = $r['tank_name'];
    }

    // Stats by type
    $sqlStats = "
        SELECT event_type, severity, COUNT(*) AS cnt
        FROM event_logs e
        {$whereSQL}
        GROUP BY event_type, severity
    ";
    $stmtStats = $pdo->prepare($sqlStats);
    foreach ($params as $k => $v) $stmtStats->bindValue($k, $v);
    $stmtStats->execute();
    $statRows = $stmtStats->fetchAll();

    $stats = ['total' => $totalCount, 'by_type' => [], 'by_severity' => []];
    foreach ($statRows as $r) {
        $stats['by_type'][$r['event_type']]   = ($stats['by_type'][$r['event_type']]   ?? 0) + (int)$r['cnt'];
        $stats['by_severity'][$r['severity']] = ($stats['by_severity'][$r['severity']] ?? 0) + (int)$r['cnt'];
    }

    ob_end_clean();
    echo json_encode([
        'success'    => true,
        'count'      => count($events),
        'total'      => $totalCount,
        'limit'      => $limit,
        'offset'     => $offset,
        'tank_names' => $tankNames,
        'stats'      => $stats,
        'events'     => $events,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    jsonError(500, $e->getMessage());
}
