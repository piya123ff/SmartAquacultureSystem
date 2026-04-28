<?php
// ===================================
// api/auth.php
// POST { username, password } → { success, token, user }
// + Rate Limit: block ถ้า fail > 5 ครั้ง ใน 15 นาทีจาก IP เดียวกัน
// ===================================

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะ POST เท่านั้น'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../db.php';

/* ──────────────────────────────────────────────
 *  Rate Limit config
 * ────────────────────────────────────────────── */
const RL_MAX_ATTEMPTS   = 5;     // ล็อก ถ้าพลาด >= 5 ครั้ง
const RL_WINDOW_MINUTES = 15;    // ในช่วง 15 นาที
const RL_BLOCK_MINUTES  = 15;    // ให้รอ 15 นาทีค่อยลองใหม่

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ username และ password'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $username = trim($input['username']);
    $password = trim($input['password']);

    $conn = getConnection();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    /* ──────────────────────────────────────────
     * Check rate limit ก่อนทำอะไรกับ password
     * นับ failed attempts จาก IP นี้ในช่วง window
     * ────────────────────────────────────────── */
    /* หมายเหตุ: INTERVAL N MINUTE bind ไม่ได้ใน real-prepare mode
     *          RL_WINDOW_MINUTES เป็น const ที่เราควบคุมเอง → ปลอดภัยที่ inject
     */
    $winMin = (int)RL_WINDOW_MINUTES;
    $rl = $conn->prepare("
        SELECT COUNT(*) AS cnt,
               MAX(created_at) AS last_fail
        FROM login_logs
        WHERE ip_address = :ip
          AND status = 'failed'
          AND created_at >= (NOW() - INTERVAL {$winMin} MINUTE)
    ");
    $rl->bindValue(':ip',  $ip_address);
    $rl->execute();
    $rlRow = $rl->fetch(PDO::FETCH_ASSOC);
    $failCount = (int)($rlRow['cnt'] ?? 0);

    if ($failCount >= RL_MAX_ATTEMPTS) {
        // คำนวณวินาทีที่ต้องรอ
        $lastFailTs = strtotime($rlRow['last_fail'] ?? 'now');
        $waitSec = max(0, ($lastFailTs + RL_BLOCK_MINUTES * 60) - time());
        http_response_code(429);
        echo json_encode([
            'success'    => false,
            'message'    => "คุณกรอกรหัสผิดเกิน " . RL_MAX_ATTEMPTS .
                            " ครั้ง กรุณารออีก " . ceil($waitSec/60) . " นาที",
            'retry_in'   => $waitSec,
            'error_code' => 'rate_limited',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ────────────── ตรวจสอบ user ────────────── */
    $stmt = $conn->prepare("
        SELECT user_id, username, password_hash, display_name, role, is_active
        FROM users
        WHERE username = ?
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // User not found
    if (!$user) {
        $conn->prepare("INSERT INTO login_logs (username, status, ip_address) VALUES (?, 'failed', ?)")
             ->execute([$username, $ip_address]);
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            'attempts_remaining' => max(0, RL_MAX_ATTEMPTS - ($failCount + 1)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Inactive
    if (!$user['is_active']) {
        $conn->prepare("INSERT INTO login_logs (user_id, username, status, ip_address) VALUES (?, ?, 'failed', ?)")
             ->execute([$user['user_id'], $username, $ip_address]);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'บัญชีผู้ใช้ถูกปิดใช้งาน'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Wrong password
    if (!password_verify($password, $user['password_hash'])) {
        $conn->prepare("INSERT INTO login_logs (user_id, username, status, ip_address) VALUES (?, ?, 'failed', ?)")
             ->execute([$user['user_id'], $username, $ip_address]);
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            'attempts_remaining' => max(0, RL_MAX_ATTEMPTS - ($failCount + 1)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // === Admin only ===
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'เข้าถึงได้เฉพาะผู้ดูแลระบบเท่านั้น'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Generate token
    $token        = bin2hex(random_bytes(32));
    $tokenExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $conn->prepare("UPDATE users SET session_token=?, token_expires_at=?, last_login=NOW() WHERE user_id=?")
         ->execute([$token, $tokenExpires, $user['user_id']]);

    $conn->prepare("INSERT INTO login_logs (user_id, username, status, ip_address) VALUES (?, ?, 'success', ?)")
         ->execute([$user['user_id'], $username, $ip_address]);

    // Set PHP session so get_user.php works
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['full_name']  = $user['display_name'] ?? $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['token']      = $token;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'เข้าสู่ระบบสำเร็จ',
        'token'   => $token,
        'user'    => [
            'user_id'      => (int)$user['user_id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในระบบฐานข้อมูล'], JSON_UNESCAPED_UNICODE);
    error_log('Auth Error: ' . $e->getMessage());
}
