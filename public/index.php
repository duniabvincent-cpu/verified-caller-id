<?php
session_start();

require_once __DIR__ . '/../includes/Database.php';

$db = getDatabase();
$csrf = genCsrf();
$flash = getFlash();

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        setFlash('error', 'Security validation failed.');
        header('Location: /');
        exit;
    }

    if (isset($_POST['register'])) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $phone = normalizePhone($_POST['phone'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Invalid email address.');
            header('Location: /');
            exit;
        }

        if (strlen($password) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
            header('Location: /');
            exit;
        }

        if (strlen($phone) < 7) {
            setFlash('error', 'Invalid phone number.');
            header('Location: /');
            exit;
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            setFlash('error', 'Email already registered.');
            header('Location: /');
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('INSERT INTO users (email, password_hash, phone_number, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$email, $hash, $phone]);

        $_SESSION['user_id'] = $db->lastInsertId();
        $_SESSION['user_email'] = $email;

        setFlash('success', 'Account created. Verify your phone number.');
        header('Location: /');
        exit;
    }

    if (isset($_POST['login'])) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($password, $u['password_hash'])) {
            setFlash('error', 'Invalid email or password.');
            header('Location: /');
            exit;
        }

        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user_email'] = $u['email'];

        setFlash('success', 'Welcome back.');
        header('Location: /');
        exit;
    }

    if (isset($_POST['logout'])) {
        session_unset();
        session_destroy();
        header('Location: /');
        exit;
    }

    if (isset($_POST['send_code'])) {
        if (!$user) {
            setFlash('error', 'Please log in first.');
            header('Location: /');
            exit;
        }

        $phone = normalizePhone($_POST['phone'] ?? '');
        if (strlen($phone) < 7) {
            setFlash('error', 'Enter a valid phone number.');
            header('Location: /');
            exit;
        }

        $code = generateCode();
        $expires = date('Y-m-d H:i:s', time() + 900);

        $stmt = $db->prepare('INSERT INTO verification_codes (user_id, phone_number, code, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$user['id'], $phone, $code, $expires]);

        $result = sendVerificationSms($phone, $code);
        if (!$result['success']) {
            setFlash('error', 'Could not send verification code.');
            header('Location: /');
            exit;
        }

        $_SESSION['pending_phone'] = $phone;
        $_SESSION['pending_code'] = $code;
        $_SESSION['verification_expires'] = $expires;

        setFlash('success', 'Verification code sent to ' . $phone . '.');
        header('Location: /');
        exit;
    }

    if (isset($_POST['verify_code'])) {
        if (!$user) {
            setFlash('error', 'Please log in first.');
            header('Location: /');
            exit;
        }

        $phone = $_SESSION['pending_phone'] ?? '';
        $storedCode = $_SESSION['pending_code'] ?? '';
        $entered = trim($_POST['code'] ?? '');

        if (empty($phone) || empty($storedCode)) {
            setFlash('error', 'No verification request is active.');
            header('Location: /');
            exit;
        }

        if ($entered !== $storedCode) {
            setFlash('error', 'The code is incorrect.');
            header('Location: /');
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM verification_codes WHERE user_id = ? AND phone_number = ? AND code = ? AND verified_at IS NULL ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$user['id'], $phone, $storedCode]);
        $verification = $stmt->fetch();

        if (!$verification) {
            setFlash('error', 'Verification code not found or already used.');
            header('Location: /');
            exit;
        }

        if (strtotime($verification['expires_at']) < time()) {
            setFlash('error', 'The verification code has expired.');
            header('Location: /');
            exit;
        }

        $stmt = $db->prepare('UPDATE verification_codes SET verified_at = NOW() WHERE id = ?');
        $stmt->execute([$verification['id']]);

        $stmt = $db->prepare('INSERT INTO verified_numbers (user_id, phone_number, status, verified_at) VALUES (?, ?, "active", NOW()) ON DUPLICATE KEY UPDATE status = "active", verified_at = NOW()');
        $stmt->execute([$user['id'], $phone]);

        $stmt = $db->prepare('INSERT INTO verification_logs (user_id, event_type, phone_number, status, created_at) VALUES (?, "number_verified", ?, "success", NOW())');
        $stmt->execute([$user['id'], $phone]);

        $_SESSION['verified_phone'] = $phone;
        unset($_SESSION['pending_phone'], $_SESSION['pending_code'], $_SESSION['verification_expires']);

        setFlash('success', 'Phone number verified successfully.');
        header('Location: /');
        exit;
    }

    if (isset($_POST['place_call'])) {
        if (!$user) {
            setFlash('error', 'Please log in first.');
            header('Location: /');
            exit;
        }

        $destination = normalizePhone($_POST['destination'] ?? '');
        $spoof = normalizePhone($_POST['spoof'] ?? '');
        $verifiedPhone = $_SESSION['verified_phone'] ?? null;

        if (strlen($destination) < 7) {
            setFlash('error', 'Enter a valid destination number.');
            header('Location: /');
            exit;
        }

        if (!$verifiedPhone) {
            setFlash('error', 'You must verify a caller ID before making a call.');
            header('Location: /');
            exit;
        }

        $status = 'sent';
        $attemptedSpoof = null;

        if (!empty($spoof) && $spoof !== $verifiedPhone) {
            $status = 'blocked';
            $attemptedSpoof = $spoof;
        }

        $stmt = $db->prepare('INSERT INTO outbound_calls (user_id, destination, caller_id, attempted_spoofing, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$user['id'], $destination, $verifiedPhone, $attemptedSpoof, $status]);

        if ($status === 'sent') {
            setFlash('success', 'Call placed successfully using verified caller ID ' . $verifiedPhone . '.');
        } else {
            setFlash('error', 'Call rejected. Spoofed caller ID blocked. Only ' . $verifiedPhone . ' is allowed.');
        }

        header('Location: /');
        exit;
    }
}

$verifiedPhone = $_SESSION['verified_phone'] ?? null;
if (!$verifiedPhone && $user) {
    $stmt = $db->prepare('SELECT phone_number FROM verified_numbers WHERE user_id = ? AND status = "active" ORDER BY verified_at DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        $_SESSION['verified_phone'] = $row['phone_number'];
        $verifiedPhone = $row['phone_number'];
    }
}

$callHistory = [];
if ($user) {
    $stmt = $db->prepare('SELECT * FROM outbound_calls WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$user['id']]);
    $callHistory = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verified Caller ID</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="container">
    <header class="header">
      <div class="brand">
        <div class="brand-icon">✓</div>
        <div class="brand-text">
          <h1>Verified Caller ID</h1>
          <p>Carrier-grade number trust</p>
        </div>
      </div>

      <?php if ($user): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" name="logout" class="btn btn-ghost">Log out</button>
        </form>
      <?php else: ?>
        <div class="badge">Secure deployment</div>
      <?php endif; ?>
    </header>

    <?php if ($flash): ?>
      <div class="alert <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$user): ?>
      <div class="hero">
        <section class="hero-left">
          <div class="eyebrow">Carrier trust layer</div>
          <h1>Verify before you display.</h1>
          <p>Only a verified number may be displayed as caller ID. Any spoofed attempt is blocked by the platform.</p>
          <ul class="features">
            <li>Trusted number registration</li>
            <li>Verification code challenge</li>
            <li>Outbound caller ID lock</li>
            <li>Call audit trail</li>
          </ul>
        </section>

        <div class="card card-auth">
          <h2>Create account</h2>
          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="field">
              <label>Email</label>
              <input type="email" name="email" required>
            </div>
            <div class="field">
              <label>Password</label>
              <input type="password" name="password" minlength="8" required>
            </div>
            <div class="field">
              <label>Phone number</label>
              <input type="tel" name="phone" placeholder="+254 712 345 678" required>
            </div>
            <button type="submit" name="register" class="btn btn-primary">Create account</button>
          </form>

          <div class="divider"><span>or</span></div>

          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="field">
              <label>Email</label>
              <input type="email" name="email" required>
            </div>
            <div class="field">
              <label>Password</label>
              <input type="password" name="password" required>
            </div>
            <button type="submit" name="login" class="btn btn-secondary">Log in</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="dashboard">
        <div class="card verify-panel">
          <div class="panel-head">
            <div>
              <div class="eyebrow">Ownership verification</div>
              <h2>Register your number</h2>
            </div>
          </div>

          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="field-row">
              <div class="field grow">
                <label>Phone number</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>" required>
              </div>
              <button type="submit" name="send_code" class="btn btn-primary">Send code</button>
            </div>
          </form>

          <?php if (!empty($_SESSION['pending_phone'])): ?>
            <form method="post" class="form">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <div class="field">
                <label>Enter the 6-digit code</label>
                <input type="text" name="code" maxlength="6" placeholder="••••••" required>
              </div>
              <button type="submit" name="verify_code" class="btn btn-secondary">Verify ownership</button>
            </form>
          <?php endif; ?>

          <div class="verified-badge <?= $verifiedPhone ? 'show' : '' ?>">
            <strong>Verified:</strong>
            <span><?= htmlspecialchars($verifiedPhone ?? 'Not verified') ?></span>
          </div>
        </div>

        <div class="card dialer-panel">
          <div class="panel-head">
            <div>
              <div class="eyebrow">Outbound dialer</div>
              <h2>Place a call</h2>
            </div>
          </div>

          <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="field">
              <label>Number to call</label>
              <input type="tel" name="destination" placeholder="+1 800 555 0199" required>
            </div>
            <div class="field">
              <label>Verified caller ID</label>
              <input type="text" value="<?= htmlspecialchars($verifiedPhone ?? '') ?>" readonly>
            </div>
            <div class="field">
              <label>Attempt custom caller ID</label>
              <input type="tel" name="spoof" placeholder="+1 800 555 0100">
            </div>
            <button type="submit" name="place_call" class="btn btn-primary" <?= $verifiedPhone ? '' : 'disabled' ?>>Place call</button>
          </form>
        </div>

        <div class="card log-panel">
          <div class="panel-head">
            <div>
              <div class="eyebrow">Carrier event log</div>
              <h2>Recent activity</h2>
            </div>
          </div>

          <div class="call-log">
            <?php if (empty($callHistory)): ?>
              <div class="empty-log">No calls placed yet.</div>
            <?php else: ?>
              <?php foreach ($callHistory as $entry): ?>
                <div class="log-entry">
                  <span class="log-time"><?= htmlspecialchars(date('H:i:s', strtotime($entry['created_at']))) ?></span>
                  <span class="log-status <?= htmlspecialchars($entry['status']) ?>"><?= strtoupper(htmlspecialchars($entry['status'])) ?></span>
                  <span class="log-text">
                    <?php
                    if ($entry['status'] === 'sent') {
                        echo 'Call to ' . htmlspecialchars($entry['destination']) . ' · verified ID ' . htmlspecialchars($entry['caller_id']);
                    } else {
                        echo 'Rejected spoof attempt · caller ID ' . htmlspecialchars($entry['attempted_spoofing'] ?? 'unknown') . ' · destination ' . htmlspecialchars($entry['destination']);
                    }
                    ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="/assets/app.js"></script>
</body>
</html>
