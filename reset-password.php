
<?php

session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['reset_password_csrf'])) {
    $_SESSION['reset_password_csrf'] = bin2hex(random_bytes(32));
}

function rp_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (is_string($_POST['token'] ?? null) ? $_POST['token'] : '')
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');

$password = '';
$confirmPassword = '';
$message = '';
$messageType = '';
$resetComplete = false;
$tokenHash = '';
$organizationId = null;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);

    try {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM organizations
             WHERE password_reset_token_hash = :token_hash
               AND password_reset_expires_at > NOW()
               AND email_verified = 1
             LIMIT 1"
        );

        $stmt->execute([':token_hash' => $tokenHash]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $organizationId = (int)$account['id'];
        }
    } catch (Throwable $e) {
        error_log('EventFlow reset-password lookup error: ' . $e->getMessage());
    }
}

$tokenValid = $organizationId !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    $confirmPassword = is_string($_POST['confirm_password'] ?? null)
        ? $_POST['confirm_password']
        : '';

    if (!hash_equals($_SESSION['reset_password_csrf'], $csrf)) {
        $message = 'Your session expired. Please refresh the page and try again.';
        $messageType = 'error';

    } elseif (!$tokenValid) {
        $message = 'This password reset link is invalid or expired. Please request a new link.';
        $messageType = 'error';

    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $message = 'Your password must contain between 8 and 72 characters.';
        $messageType = 'error';

    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';

    } else {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare(
                "UPDATE organizations
                 SET password_hash = :password_hash,
                     password_reset_token_hash = NULL,
                     password_reset_expires_at = NULL
                 WHERE id = :id
                   AND password_reset_token_hash = :token_hash
                   AND password_reset_expires_at > NOW()
                   AND email_verified = 1"
            );

            $update->execute([
                ':password_hash' => $passwordHash,
                ':id' => $organizationId,
                ':token_hash' => $tokenHash
            ]);

            if ($update->rowCount() === 1) {
                $resetComplete = true;
                $tokenValid = false;
                $message = 'Your password has been changed successfully. You can now log in using your new password.';
                $messageType = 'success';

                $_SESSION['reset_password_csrf'] = bin2hex(random_bytes(32));
            } else {
                $tokenValid = false;
                $message = 'This password reset link is invalid or expired. Please request a new link.';
                $messageType = 'error';
            }

        } catch (Throwable $e) {
            error_log('EventFlow password update error: ' . $e->getMessage());
            $message = 'We could not update your password right now. Please try again later.';
            $messageType = 'error';
        }
    }
}

if (!$tokenValid && !$resetComplete && $message === '') {
    $message = 'This password reset link is invalid or expired. Please request a new link.';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Password | EventFlow</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:linear-gradient(135deg,#f7f8ff,#edf3ff);color:#182039}
.card{width:100%;max-width:440px;background:#fff;padding:36px;border-radius:24px;box-shadow:0 24px 70px #242e5b14;border:1px solid #fff}
.brand{display:flex;align-items:center;gap:10px;font-size:23px;font-weight:800;letter-spacing:-.6px;margin-bottom:34px}
.logo{height:42px;width:42px;display:grid;place-items:center;border-radius:14px;background:linear-gradient(135deg,#776dff,#4c43e8);color:white}
.logo svg{width:23px;height:23px}
.brand span:last-child{color:#635bff}
.eyebrow{font-size:11px;letter-spacing:1.5px;font-weight:800;color:#635bff}
h1{font-size:30px;letter-spacing:-1px;margin:10px 0}
.description{font-size:14px;line-height:1.7;color:#727b91;margin-bottom:25px}
label{display:block;font-size:12px;font-weight:750;margin-bottom:8px}
.field{margin-bottom:18px}
input{width:100%;height:50px;border:1px solid #e0e4ee;border-radius:11px;padding:0 14px;font-size:14px;outline:none}
input:focus{border-color:#635bff;box-shadow:0 0 0 4px #635bff14}
button{width:100%;height:52px;margin-top:6px;background:linear-gradient(105deg,#6d63ff,#5147e8);color:#fff;font-weight:750;border:0;border-radius:12px;cursor:pointer}
.message{font-size:13px;line-height:1.6;padding:13px;border-radius:10px;margin:18px 0;overflow-wrap:anywhere}
.success{background:#e9f8ef;color:#137a49;border:1px solid #c8ecd6}
.error{background:#fff0f1;color:#c13745;border:1px solid #f8d4d8}
.back{text-align:center;margin-top:23px;font-size:13px;color:#727b91}
a{color:#635bff;text-decoration:none;font-weight:750}
@media(max-width:480px){.card{padding:26px 22px;border-radius:20px}h1{font-size:27px}}
</style>
</head>
<body>
<div class="card">
    <div class="brand">
        <span class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="16" rx="3"></rect>
                <path d="M16 3v4M8 3v4M3 10h18"></path>
            </svg>
        </span>
        Event<span>Flow</span>
    </div>

    <div class="eyebrow">ACCOUNT SECURITY</div>
    <h1>Set a new password</h1>
    <p class="description">Choose a new password for your EventFlow account.</p>

    <?php if ($message !== ''): ?>
        <div class="message <?= rp_h($messageType) ?>" role="status">
            <?= rp_h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($tokenValid && !$resetComplete): ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= rp_h($_SESSION['reset_password_csrf']) ?>">
        <input type="hidden" name="token" value="<?= rp_h($token) ?>">

        <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password"
                   minlength="8" maxlength="72"
                   autocomplete="new-password" required
                   placeholder="At least 8 characters">
        </div>

        <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password"
                   type="password" minlength="8" maxlength="72"
                   autocomplete="new-password" required
                   placeholder="Enter the password again">
        </div>

        <button type="submit">Save New Password</button>
    </form>
    <?php endif; ?>

    <div class="back">
        <a href="/login.php">Back to login</a>
        &nbsp;·&nbsp;
        <a href="/forgot-password.php">Request another link</a>
    </div>
</div>
</body>
</html>
