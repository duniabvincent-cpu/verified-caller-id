<?php
session_start();

$step = $_GET['step'] ?? 1;
$configPath = __DIR__ . '/config.php';

if (file_exists($configPath)) {
    $config = require $configPath;
    if (!empty($config['db']['name']) && !empty($config['db']['user'])) {
        header('Location: /');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 1) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');

    try {
        $pdo = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $_SESSION['db_cfg'] = [
            'host' => $dbHost,
            'user' => $dbUser,
            'pass' => $dbPass,
            'name' => $dbName,
        ];

        header('Location: install.php?step=2');
        exit;
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 2) {
    $cfg = $_SESSION['db_cfg'] ?? null;
    if (!$cfg) {
        header('Location: install.php?step=1');
        exit;
    }

    try {
        $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
        $db = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        $sqlParts = array_filter(array_map('trim', explode(';', $schema)));

        foreach ($sqlParts as $sql) {
            if (!empty($sql)) {
                $db->exec($sql);
            }
        }

        header('Location: install.php?step=3');
        exit;
    } catch (Exception $e) {
        $error = 'Schema error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 3) {
    $cfg = $_SESSION['db_cfg'] ?? null;
    if (!$cfg) {
        header('Location: install.php?step=1');
        exit;
    }

    $twilioEnabled = isset($_POST['twilio_enabled']) ? true : false;
    $twilioSid = trim($_POST['twilio_sid'] ?? '');
    $twilioToken = trim($_POST['twilio_token'] ?? '');
    $twilioPhone = trim($_POST['twilio_phone'] ?? '+15555550100');

    $content = "<?php\nreturn [\n    'db' => [\n        'host' => '" . addslashes($cfg['host']) . "',\n        'user' => '" . addslashes($cfg['user']) . "',\n        'pass' => '" . addslashes($cfg['pass']) . "',\n        'name' => '" . addslashes($cfg['name']) . "',\n        'charset' => 'utf8mb4',\n    ],\n    'twilio' => [\n        'enabled' => " . ($twilioEnabled ? 'true' : 'false') . ",\n        'sid' => '" . addslashes($twilioSid) . "',\n        'token' => '" . addslashes($twilioToken) . "',\n        'phone' => '" . addslashes($twilioPhone) . "',\n    ],\n];\n";

    if (file_put_contents($configPath, $content) === false) {
        $error = 'Could not write config.php. Check permissions.';
    } else {
        header('Location: /');
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install Verified Caller ID</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f3f6fb; margin: 0; padding: 30px; }
        .box { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 18px; box-shadow: 0 20px 40px rgba(17,33,67,0.08); }
        h1 { margin-top: 0; }
        label { display: block; font-weight: 700; margin-bottom: 8px; color: #13233a; }
        input { width: 100%; padding: 12px 14px; margin-bottom: 18px; border-radius: 12px; border: 1px solid #dfe7f1; }
        button { background: #1d6feb; color: white; border: none; padding: 12px 18px; border-radius: 12px; font-weight: 700; cursor: pointer; }
        .alert { background: #fce9e6; color: #b42318; border: 1px solid rgba(180,35,24,0.1); padding: 12px 16px; border-radius: 12px; margin-bottom: 18px; }
        .small { color: #5c6b82; font-size: 0.9rem; }
        .row { display: flex; gap: 12px; align-items: center; }
        .row input[type='checkbox'] { width: 18px; height: 18px; margin: 0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Verified Caller ID Setup</h1>

        <?php if (isset($error)): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <form method="post">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required>

                <label>Database User</label>
                <input type="text" name="db_user" placeholder="your_db_user" required>

                <label>Database Password</label>
                <input type="password" name="db_pass" required>

                <label>Database Name</label>
                <input type="text" name="db_name" placeholder="your_db_name" required>

                <button type="submit">Next</button>
            </form>
        <?php elseif ($step == 2): ?>
            <p class="small">This imports the database tables required for the app.</p>
            <form method="post">
                <button type="submit">Import Schema</button>
            </form>
        <?php elseif ($step == 3): ?>
            <form method="post">
                <div class="row">
                    <input type="checkbox" name="twilio_enabled" id="twilio_enabled">
                    <label for="twilio_enabled" style="margin:0;">Enable Twilio SMS</label>
                </div>

                <label>Twilio SID</label>
                <input type="text" name="twilio_sid" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">

                <label>Twilio Auth Token</label>
                <input type="password" name="twilio_token" placeholder="your_auth_token">

                <label>Twilio Phone Number</label>
                <input type="text" name="twilio_phone" value="+15555550100">

                <button type="submit">Finish Setup</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
