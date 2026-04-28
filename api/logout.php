<?php
// ===================================
// api/logout.php — Logout & Destroy Session
// ===================================

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

echo json_encode(['success' => true, 'message' => 'ออกจากระบบสำเร็จ'], JSON_UNESCAPED_UNICODE);
?>