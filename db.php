<?php
// ===================================
// db.php — ไฟล์เชื่อมต่อฐานข้อมูล
// วางไว้ที่ root ของโปรเจกต์
// ===================================

// ── Timezone fix ── ใช้เวลาไทย (Asia/Bangkok = UTC+7)
define('APP_TZ', 'Asia/Bangkok');
date_default_timezone_set(APP_TZ);

define('DB_HOST',    getenv('DB_HOST')     ?: 'db');
define('DB_NAME',    getenv('DB_NAME')     ?: 'smartaqua');
define('DB_USER',    getenv('DB_USER')     ?: 'user');
define('DB_PASS',    getenv('DB_PASSWORD') ?: 'password');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));

function getConnection(): PDO {
    $dsn = "mysql:host=" . DB_HOST
         . ";port="      . DB_PORT
         . ";dbname="    . DB_NAME
         . ";charset="   . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // ── Set MySQL session timezone to Asia/Bangkok (+07:00)
        // ทำให้ NOW(), CURRENT_TIMESTAMP, และค่า DATETIME ทั้งหมด อยู่ใน timezone ไทย
        $pdo->exec("SET time_zone = '+07:00'");
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'เชื่อมต่อฐานข้อมูลไม่ได้ กรุณาตรวจสอบ Docker container',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>