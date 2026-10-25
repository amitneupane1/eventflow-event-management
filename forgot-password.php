
<?php

use PHPMailer\PHPMailer\PHPMailer;

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-config.php';

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

if (empty($_SESSION['forgot_password_csrf'])) {
    $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';
$email = '';

function fp_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim(
        is_string($_POST['email'] ?? null)
            ? $_POST['email']
            : ''
    ));

    $csrf = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    if (!hash_equals($_SESSION['forgot_password_csrf'], $csrf)) {

        $message = 'Your session expired. Please refresh and try again.';
        $messageType = 'error';

    } else {

        // Keep the same message whether the account exists or not.
        $message = 'If a verified account exists for this email, a password reset link will be sent. Please check your Inbox and Spam folder.';
        $messageType = 'success';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190) {

            try {
                $stmt = $pdo->prepare(
                    "SELECT id, organization_name, email
                     FROM organizations
                     WHERE email = :email
                       AND email_verified = 1
                     LIMIT 1"
                );

                $stmt->execute([':email' => $email]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($account) {

                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $update = $pdo->prepare(
                        "UPDATE organizations
                         SET password_reset_token_hash = :token_hash,
                             password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                         WHERE id = :id
                           AND email_verified = 1"
                    );

                    $update->execute([
                        ':token_hash' => $tokenHash,
                        ':id' => $account['id']
                    ]);

                    if ($update->rowCount() === 1) {

                        try {
                            $resetUrl = rtrim(EVENTFLOW_BASE_URL, '/')
                                . '/reset-password.php?token='
                                . rawurlencode($token);

                            $safeName = htmlspecialchars(
                                (string)$account['organization_name'],
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            );

                            $safeUrl = htmlspecialchars(
                                $resetUrl,
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            );

                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host = 'smtp.gmail.com';
                            $mail->SMTPAuth = true;
                            $mail->Username = EVENTFLOW_SMTP_USERNAME;
                            $mail->Password = EVENTFLOW_SMTP_APP_PASSWORD;
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = 587;
                            $mail->CharSet = 'UTF-8';

                            $mail->setFrom(
                                EVENTFLOW_SMTP_USERNAME,
                                'EventFlow'
                            );

                            $mail->addAddress(
                                $account['email'],
                                $account['organization_name']
                            );

                            $mail->isHTML(true);
                            $mail->Subject = 'Reset your EventFlow password';

                            $mail->Body = '
                            <!DOCTYPE html>
                            <html lang="en">
                            <head>
                                <meta charset="UTF-8">
                                <meta name="viewport" content="width=device-width, initial-scale=1">
                            </head>
                            <body style="margin:0;padding:25px;background:#f4f7fb;font-family:Arial,sans-serif;color:#182039;">
                                <div style="max-width:540px;margin:20px auto;background:#ffffff;padding:32px;border-radius:16px;">
                                    <h2 style="color:#635bff;">Reset your password</h2>
                                    <p>Hello ' . $safeName . ',</p>
                                    <p>We received a request to reset your EventFlow account password.</p>
                                    <p style="margin:28px 0;">
                                        <a href="' . $safeUrl . '"
                                           style="display:inline-block;padding:14px 22px;background:#635bff;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">
                                            Reset Password
                                        </a>
                                    </p>
                                    <p>This link expires in one hour and can only be used once.</p>
                                    <p>If you did not request this reset, you can ignore this email. Your password will not change unless the link is used.</p>
                                    <p>Regards,<br>EventFlow Team</p>
                                </div>
                            </body>
                            </html>';

                            $mail->AltBody =
                                "Reset your EventFlow password using this link:\n"
                                . $resetUrl
                                . "\n\nThis link expires in one hour.";

                            $mail->send();

                        } catch (Throwable $mailError) {
                            error_log(
                                'EventFlow password reset email error: '
                                . $mailError->getMessage()
                            );

                            // Invalidate a link that could not be sent.
                            $clear = $pdo->prepare(
                                "UPDATE organizations
                                 SET password_reset_token_hash = NULL,
                                     password_reset_expires_at = NULL
                                 WHERE id = :id
                                   AND password_reset_token_hash = :token_hash"
                            );

                            $clear->execute([
                                ':id' => $account['id'],
                                ':token_hash' => $tokenHash
                            ]);
                        }
                    }
                }

            } catch (Throwable $e) {
                error_log('EventFlow forgot-password error: ' . $e->getMessage());
                // Keep the public message generic.
            }
        }

        $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
        $email = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password | EventFlow</title>
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
input{width:100%;height:50px;border:1px solid #e0e4ee;border-radius:11px;padding:0 14px;font-size:14px;outline:none}
input:focus{border-color:#635bff;box-shadow:0 0 0 4px #635bff14}
button{width:100%;height:52px;margin-top:18px;background:linear-gradient(105deg,#6d63ff,#5147e8);color:#fff;font-weight:750;border:0;border-radius:12px;cursor:pointer}
.message{font-size:13px;line-height:1.6;padding:13px;border-radius:10px;margin-bottom:20px;overflow-wrap:anywhere}
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

    <div class="eyebrow">ACCOUNT RECOVERY</div>
    <h1>Forgot password?</h1>
    <p class="description">Enter the email address linked to your EventFlow account. If the account is verified, we'll send you a secure password reset link.</p>

    <?php if ($message !== ''): ?>
        <div class="message <?= fp_h($messageType) ?>" role="status">
            <?= fp_h($message) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= fp_h($_SESSION['forgot_password_csrf']) ?>">

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" maxlength="190"
               placeholder="you@organization.com" autocomplete="email"
               value="<?= fp_h($email) ?>" required>

        <button type="submit">Send Reset Link</button>
    </form>

    <div class="back">
        Remember your password? <a href="/login.php">Back to login</a>
    </div>
</div>
</body>
</html>
