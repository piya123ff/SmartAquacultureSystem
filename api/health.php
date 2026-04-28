<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../db.php';

try {
    $pdo = getConnection();

    $tables = [
        'tanks',
        'tank_settings',
        'sensor_logs',
        'event_logs',
        'event_categories',
        'users'
    ];

    $optionalTables = [
        'sensor_configs',
        'devices'
    ];

    $exists = [];
    foreach (array_merge($tables, $optionalTables) as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $exists[$table] = (bool)$stmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'status'  => 'ok',
        'database'=> 'connected',
        'tables'  => $exists,
        'time'    => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => $e->getMessage(),
        'time'    => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>