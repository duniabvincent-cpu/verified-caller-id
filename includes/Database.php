<?php
function getDatabase() {
    static $db = null;
    if ($db !== null) return $db;

    $config = require __DIR__ . '/../config.php';
    $cfg = $config['db'];

    $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'];

    try {
        $db = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        die('Database error: ' . $e->getMessage());
    }

    return $db;
}

function normalizePhone($phone) {
    return preg_replace('/[^\d+]/', '', trim((string)$phone));
}

function generateCode() {
    return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($token ?? ''));
}

function genCsrf() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function setFlash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
}

function getFlash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function sendVerificationSms($phone, $code) {
    $config = require __DIR__ . '/../config.php';
    $twilio = $config['twilio'];

    if (!empty($twilio['enabled'])) {
        $sid = $twilio['sid'];
        $token = $twilio['token'];
        $from = $twilio['phone'];

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
        $payload = [
            'From' => $from,
            'To' => $phone,
            'Body' => 'Your Verified Caller ID code is: ' . $code . '. Valid for 15 minutes.'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true];
        }

        return ['success' => false];
    }

    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents(
        $dir . '/verification.log',
        date('Y-m-d H:i:s') . " | TO: {$phone} | CODE: {$code}" . PHP_EOL,
        FILE_APPEND
    );

    return ['success' => true, 'method' => 'demo'];
}
