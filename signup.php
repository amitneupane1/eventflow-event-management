
<?php

use PHPMailer\PHPMailer\PHPMailer;

session_start();

$message = '';
$messageType = '';
$setupError = '';

$organizationName = '';
$email = '';
$phone = '';

/*
|--------------------------------------------------------------------------
| Check required files before including them
|--------------------------------------------------------------------------
*/

$requiredFiles = [
    'config.php' => __DIR__ . '/config.php',
    'mail-config.php' => __DIR__ . '/mail-config.php',
    'PHPMailer Exception.php' => __DIR__ . '/PHPMailer/src/Exception.php',
    'PHPMailer PHPMailer.php' => __DIR__ . '/PHPMailer/src/PHPMailer.php',
    'PHPMailer SMTP.php' => __DIR__ . '/PHPMailer/src/SMTP.php'
];

foreach ($requiredFiles as $label => $path) {
    if (!is_file($path)) {
        $setupError = 'Setup error: Required file is missing: ' . $label;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Load configuration
|--------------------------------------------------------------------------
*/

if ($setupError === '') {
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/mail-config.php';

        require_once __DIR__ . '/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/src/SMTP.php';

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new RuntimeException('PDO connection is unavailable.');
        }

        if (
            !defined('EVENTFLOW_SMTP_USERNAME') ||
            !defined('EVENTFLOW_SMTP_APP_PASSWORD') ||
            !defined('EVENTFLOW_BASE_URL')
        ) {
            throw new RuntimeException('Mail configuration is incomplete.');
        }

    } catch (Throwable $e) {
        error_log('EventFlow setup error: ' . $e->getMessage());

        $setupError = 'Website setup error. Please check config.php, mail-config.php and the PHPMailer files.';
    }
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function h($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function sendEventFlowVerificationEmail(
    string $recipientEmail,
    string $organizationName,
    string $token
): void {
    $verificationUrl = rtrim(EVENTFLOW_BASE_URL, '/')
        . '/verify-email.php?token='
        . rawurlencode($token);

    $safeName = htmlspecialchars(
        $organizationName,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $safeUrl = htmlspecialchars(
        $verificationUrl,
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
        $recipientEmail,
        $organizationName
    );

    $mail->isHTML(true);
    $mail->Subject = 'Verify your EventFlow email address';

    $mail->Body = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verify your email</title>
    </head>
    <body style="margin:0;padding:25px;background:#f4f7fb;font-family:Arial,sans-serif;color:#182039;">
        <div style="max-width:560px;margin:20px auto;background:#ffffff;padding:32px;border-radius:16px;">
            <h2 style="color:#635bff;">Welcome to EventFlow!</h2>

            <p>Hello ' . $safeName . ',</p>

            <p>Thank you for creating your EventFlow organization account.</p>

            <p>Please verify your email address by clicking the button below.</p>

            <p style="margin:28px 0;">
                <a href="' . $safeUrl . '"
                   style="display:inline-block;padding:14px 22px;background:#635bff;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">
                    Verify Email Address
                </a>
            </p>

            <p>If the button does not work, copy and paste this link into your browser:</p>

            <p style="overflow-wrap:anywhere;">' . $safeUrl . '</p>

            <p>This verification link expires in 24 hours.</p>

            <p>If you did not create this account, you can ignore this email.</p>

            <p>Regards,<br>EventFlow Team</p>
        </div>
    </body>
    </html>';

    $mail->AltBody =
        "Welcome to EventFlow!\n\n"
        . "Hello " . $organizationName . ",\n\n"
        . "Verify your email address using this link:\n"
        . $verificationUrl . "\n\n"
        . "This verification link expires in 24 hours.\n";

    $mail->send();
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Process signup form
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $setupError === ''
) {
    $organizationName = trim(
        is_string($_POST['organization_name'] ?? null)
            ? $_POST['organization_name']
            : ''
    );

    $email = strtolower(
        trim(
            is_string($_POST['email'] ?? null)
                ? $_POST['email']
                : ''
        )
    );

    $phone = trim(
        is_string($_POST['phone'] ?? null)
            ? $_POST['phone']
            : ''
    );

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    $confirmPassword = is_string($_POST['confirm_password'] ?? null)
        ? $_POST['confirm_password']
        : '';

    $csrf = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : '';

    if (!hash_equals($_SESSION['signup_csrf'], $csrf)) {
        $message = 'Your session expired. Please refresh and try again.';
        $messageType = 'error';

    } elseif (
        strlen($organizationName) < 2 ||
        strlen($organizationName) > 150
    ) {
        $message = 'Organization name must be between 2 and 150 characters.';
        $messageType = 'error';

    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190
    ) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';

    } elseif (strlen($phone) > 30) {
        $message = 'Phone number is too long.';
        $messageType = 'error';

    } elseif (
        strlen($password) < 8 ||
        strlen($password) > 72
    ) {
        $message = 'Password must contain between 8 and 72 characters.';
        $messageType = 'error';

    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';

    } else {
        try {
            /*
             * Check if this email already has an account.
             */
            $lookup = $pdo->prepare(
                "SELECT id, organization_name, email_verified
                 FROM organizations
                 WHERE email = :email
                 LIMIT 1"
            );

            $lookup->execute([
                ':email' => $email
            ]);

            $existing = $lookup->fetch(PDO::FETCH_ASSOC);

            if (
                $existing &&
                (int) $existing['email_verified'] === 1
            ) {
                $message = 'This email is already registered. Please sign in or use another email.';
                $messageType = 'error';

            } else {
                /*
                 * Create a new verification token.
                 * Store only the hash in the database.
                 */
                $verificationToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $verificationToken);

                $expiresAt = date(
                    'Y-m-d H:i:s',
                    time() + (24 * 60 * 60)
                );

                if ($existing) {
                    /*
                     * An unverified account exists.
                     * Refresh its verification token without
                     * changing its password or organization name.
                     */
                    $organizationId = (int) $existing['id'];

                    $verificationName =
                        (string) $existing['organization_name'];

                    $update = $pdo->prepare(
                        "UPDATE organizations
                         SET email_verification_token_hash = :token_hash,
                             email_verification_expires_at = :expires_at
                         WHERE id = :id
                           AND email_verified = 0"
                    );

                    $update->execute([
                        ':token_hash' => $tokenHash,
                        ':expires_at' => $expiresAt,
                        ':id' => $organizationId
                    ]);

                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Could not update verification token.'
                        );
                    }

                } else {
                    /*
                     * Create a new organization account.
                     */
                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $insert = $pdo->prepare(
                        "INSERT INTO organizations
                        (
                            organization_name,
                            email,
                            password_hash,
                            phone,
                            email_verified,
                            subscription_status,
                            email_verification_token_hash,
                            email_verification_expires_at
                        )
                        VALUES
                        (
                            :organization_name,
                            :email,
                            :password_hash,
                            :phone,
                            0,
                            'inactive',
                            :token_hash,
                            :expires_at
                        )"
                    );

                    $insert->execute([
                        ':organization_name' => $organizationName,
                        ':email' => $email,
                        ':password_hash' => $passwordHash,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':token_hash' => $tokenHash,
                        ':expires_at' => $expiresAt
                    ]);

                    $organizationId = (int) $pdo->lastInsertId();
                    $verificationName = $organizationName;
                }

                /*
                 * Send the verification email.
                 */
                try {
                    sendEventFlowVerificationEmail(
                        $email,
                        $verificationName,
                        $verificationToken
                    );

                    $message = 'Registration successful! A verification link has been sent to your email. Check your Inbox and Spam folder. The link expires in 24 hours.';
                    $messageType = 'success';

                    $organizationName = '';
                    $email = '';
                    $phone = '';

                    $_SESSION['signup_csrf'] =
                        bin2hex(random_bytes(32));

                } catch (Throwable $mailError) {
                    error_log(
                        'EventFlow verification email error: '
                        . $mailError->getMessage()
                    );

                    $message = 'Your account has been saved, but the verification email could not be sent. Please try submitting the form again to get a fresh verification link.';
                    $messageType = 'error';
                }
            }

        } catch (PDOException $e) {
            error_log(
                'EventFlow signup database error: '
                . $e->getMessage()
            );

            if ($e->getCode() === '23000') {
                $message = 'This email is already registered. Please sign in or use another email.';
            } else {
                $message = 'Registration could not be completed. Please check your database setup and try again.';
            }

            $messageType = 'error';

        } catch (Throwable $e) {
            error_log(
                'EventFlow signup error: '
                . $e->getMessage()
            );

            $message = 'Registration could not be completed. Please try again later.';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Organization Account | EventFlow</title>
<meta name="description" content="Create your EventFlow organization account and manage events, participants and attendance.">

<style>
:root {
    --primary: #635bff;
    --ink: #182039;
    --muted: #727b91;
    --success: #137a49;
    --danger: #c13745;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    color: var(--ink);
    background: #f7f8ff;
}

button, input {
    font: inherit;
}

a {
    color: inherit;
    text-decoration: none;
}

.page {
    min-height: 100vh;
    overflow: hidden;
    position: relative;
}

.ambient {
    position: fixed;
    width: 360px;
    height: 360px;
    border-radius: 50%;
    filter: blur(85px);
    opacity: .22;
    pointer-events: none;
}

.ambient.one {
    background: #9188ff;
    top: -150px;
    left: -120px;
}

.ambient.two {
    background: #70d9e8;
    right: -150px;
    bottom: -140px;
}

.navbar {
    position: relative;
    z-index: 2;
    width: min(1240px, 92%);
    margin: auto;
    height: 86px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    display: inline-flex;
    align-items: center;
    gap: 11px;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -.8px;
}

.brand-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    color: #fff;
    background: linear-gradient(135deg, #776dff, #4c43e8);
    border-radius: 14px;
}

.brand-icon svg {
    width: 23px;
    height: 23px;
}

.brand-flow {
    color: var(--primary);
}

.nav-login {
    color: #555e74;
    font-size: 14px;
}

.nav-login a {
    color: var(--primary);
    font-weight: 750;
    margin-left: 5px;
}

.layout {
    position: relative;
    z-index: 1;
    width: min(1120px, 92%);
    margin: 25px auto 65px;
    display: grid;
    grid-template-columns: .92fr 1.08fr;
    background: #fff;
    border-radius: 30px;
    overflow: hidden;
    box-shadow: 0 30px 90px #242e5b12;
    animation: pageEnter .5s ease both;
}

.showcase {
    padding: 54px 43px;
    color: #fff;
    background:
        radial-gradient(circle at 90% 8%, #a59eff60 0, transparent 32%),
        radial-gradient(circle at 5% 100%, #30c5d660 0, transparent 36%),
        linear-gradient(145deg, #28225f, #4940b4 53%, #635bff);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 700px;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 9px 13px;
    border: 1px solid #ffffff35;
    border-radius: 100px;
    background: #ffffff10;
    color: #f0efff;
    font-size: 11px;
    font-weight: 750;
    letter-spacing: 1.2px;
}

.eyebrow-dot {
    width: 7px;
    height: 7px;
    background: #75f0c0;
    border-radius: 50%;
}

.showcase h1 {
    margin: 28px 0 18px;
    font-size: clamp(34px, 3.6vw, 47px);
    line-height: 1.12;
    letter-spacing: -1.8px;
}

.showcase h1 span {
    color: #bcb7ff;
}

.showcase-copy {
    color: #e0defe;
    font-size: 15px;
    line-height: 1.85;
    max-width: 390px;
}

.feature-list {
    display: grid;
    gap: 17px;
    margin-top: 32px;
}

.feature {
    display: flex;
    align-items: center;
    gap: 13px;
    color: #f5f4ff;
    font-size: 14px;
}

.feature-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #ffffff17;
    border: 1px solid #ffffff1c;
}

.feature-icon svg {
    width: 19px;
    height: 19px;
}

.showcase-bottom {
    margin-top: 50px;
    padding-top: 22px;
    border-top: 1px solid #ffffff25;
    color: #d8d5ff;
    font-size: 12px;
    line-height: 1.7;
}

.form-panel {
    padding: 48px clamp(25px, 4vw, 55px);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-heading {
    margin-bottom: 28px;
}

.step-label {
    color: var(--primary);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
}

.form-heading h2 {
    font-size: 31px;
    letter-spacing: -1.1px;
    margin: 11px 0 9px;
}

.form-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.7;
}

.message {
    margin: 0 0 20px;
    padding: 14px 15px;
    border-radius: 13px;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.message.success {
    color: var(--success);
    background: #e9f8ef;
    border: 1px solid #c8ecd6;
}

.message.error {
    color: var(--danger);
    background: #fff0f1;
    border: 1px solid #f8d4d8;
}

.message.setup {
    color: #8a5100;
    background: #fff8e8;
    border: 1px solid #f0d9a6;
}

.field {
    margin-bottom: 17px;
}

.field label {
    display: block;
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 750;
    color: #30384e;
}

.optional {
    color: #9299aa;
    font-weight: 500;
}

.input-wrap {
    position: relative;
}

.input-wrap input {
    width: 100%;
    height: 51px;
    padding: 0 15px;
    color: var(--ink);
    background: #fbfcff;
    border: 1px solid #e0e4ee;
    border-radius: 12px;
    outline: none;
    font-size: 13px;
    transition: border-color .2s, box-shadow .2s;
}

.input-wrap input::placeholder {
    color: #a0a7b7;
}

.input-wrap input:focus {
    background: #fff;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px #635bff14;
}

.input-wrap input.with-toggle {
    padding-right: 70px;
}

.toggle-password {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: var(--primary);
    cursor: pointer;
    font-size: 11px;
    font-weight: 800;
    padding: 8px;
}

.strength {
    display: flex;
    gap: 5px;
    margin-top: 9px;
}

.strength span {
    height: 4px;
    flex: 1;
    border-radius: 10px;
    background: #e7e9f0;
}

.strength-text, .match-text {
    display: block;
    margin-top: 7px;
    font-size: 11px;
    color: var(--muted);
}

.terms {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 8px 0 20px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.7;
}

.terms input {
    margin-top: 3px;
    accent-color: var(--primary);
}

.submit-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 53px;
    border: 0;
    border-radius: 13px;
    color: #fff;
    background: linear-gradient(105deg, #6d63ff, #5147e8);
    box-shadow: 0 10px 23px #635bff30;
    font-weight: 750;
    font-size: 14px;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px #635bff40;
}

.submit-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
    transform: none;
}

.submit-btn svg {
    width: 18px;
    height: 18px;
}

.form-footer {
    margin-top: 23px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
}

.form-footer a {
    color: var(--primary);
    font-weight: 800;
}

.security-note {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    margin-top: 25px;
    color: #8b93a5;
    font-size: 11px;
}

.security-note svg {
    width: 15px;
    height: 15px;
}

@keyframes pageEnter {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 850px) {
    .layout {
        grid-template-columns: 1fr;
        max-width: 590px;
        margin-top: 10px;
    }

    .showcase {
        min-height: auto;
        padding: 38px 32px;
    }

    .showcase h1 {
        max-width: 450px;
        font-size: 38px;
    }

    .feature-list {
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .showcase-bottom {
        margin-top: 30px;
    }

    .form-panel {
        padding: 38px 32px;
    }
}

@media (max-width: 480px) {
    .navbar {
        height: 72px;
    }

    .brand {
        font-size: 20px;
    }

    .brand-icon {
        width: 37px;
        height: 37px;
    }

    .nav-login {
        font-size: 12px;
    }

    .layout {
        width: 94%;
        border-radius: 22px;
        margin-bottom: 30px;
    }

    .showcase {
        padding: 30px 23px;
    }

    .showcase h1 {
        font-size: 34px;
    }

    .showcase-copy {
        font-size: 13px;
    }

    .feature-list {
        grid-template-columns: 1fr;
    }

    .form-panel {
        padding: 31px 23px;
    }

    .form-heading h2 {
        font-size: 27px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }
}
</style>
</head>

<body>
<div class="page">
    <div class="ambient one"></div>
    <div class="ambient two"></div>

    <header class="navbar">
        <a class="brand" href="/" aria-label="EventFlow home">
            <span class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="5" width="18" height="16" rx="3"></rect>
                    <path d="M16 3v4M8 3v4M3 10h18"></path>
                    <path d="m9 15 2 2 4-4"></path>
                </svg>
            </span>
            Event<span class="brand-flow">Flow</span>
        </a>

        <div class="nav-login">
            Already a member?
            <a href="/login.php">Sign in</a>
        </div>
    </header>

    <main class="layout">
        <section class="showcase">
            <div>
                <div class="eyebrow">
                    <span class="eyebrow-dot"></span>
                    YOUR EVENTS, SIMPLIFIED
                </div>

                <h1>Bring your events <span>to life.</span></h1>

                <p class="showcase-copy">
                    One place for your organization to plan events,
                    manage participants, track attendance and stay organized.
                    Spend less time managing and more time making moments count.
                </p>

                <div class="feature-list">
                    <div class="feature">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                                <path d="M16 2v4M8 2v4M3 10h18"></path>
                            </svg>
                        </span>
                        <span>Simple event management</span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="10" cy="7" r="4"></circle>
                                <path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <span>Participant management</span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19V5M4 19h17"></path>
                                <rect x="7" y="11" width="3" height="5" rx="1"></rect>
                                <rect x="13" y="7" width="3" height="9" rx="1"></rect>
                                <rect x="19" y="3" width="2" height="13" rx="1"></rect>
                            </svg>
                        </span>
                        <span>Attendance and reports</span>
                    </div>

                    <div class="feature">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3 20 7v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4Z"></path>
                                <path d="m9 12 2 2 4-4"></path>
                            </svg>
                        </span>
                        <span>Your organization workspace</span>
                    </div>
                </div>
            </div>

            <div class="showcase-bottom">
                A simpler way to organize your next big event.<br>
                Built for teams, clubs and organizations.
            </div>
        </section>

        <section class="form-panel">
            <div class="form-heading">
                <span class="step-label">GET STARTED · STEP 01</span>
                <h2>Create your account</h2>
                <p>Enter your organization details to get started.</p>
            </div>

            <?php if ($setupError !== ''): ?>
                <div class="message setup" role="alert">
                    <?= h($setupError) ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="message <?= h($messageType) ?>" role="status" aria-live="polite">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="signupForm" autocomplete="on">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['signup_csrf']) ?>">

                <div class="field">
                    <label for="organization_name">Organization name *</label>
                    <div class="input-wrap">
                        <input
                            id="organization_name"
                            name="organization_name"
                            type="text"
                            maxlength="150"
                            placeholder="e.g. Sunrise Sports Club"
                            required
                            value="<?= h($organizationName) ?>"
                            autocomplete="organization"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="email">Work email address *</label>
                    <div class="input-wrap">
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="190"
                            placeholder="you@organization.com"
                            required
                            value="<?= h($email) ?>"
                            autocomplete="email"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="phone">
                        Mobile number <span class="optional">(optional)</span>
                    </label>
                    <div class="input-wrap">
                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            maxlength="30"
                            placeholder="Enter your phone number"
                            value="<?= h($phone) ?>"
                            autocomplete="tel"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Create password *</label>
                    <div class="input-wrap">
                        <input
                            class="with-toggle"
                            id="password"
                            name="password"
                            type="password"
                            minlength="8"
                            maxlength="72"
                            placeholder="At least 8 characters"
                            required
                            autocomplete="new-password"
                        >
                        <button class="toggle-password" type="button"
                                data-target="password">SHOW</button>
                    </div>
                    <div class="strength" aria-hidden="true">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <small class="strength-text" id="strengthText">
                        Use 8 or more characters for your password.
                    </small>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm password *</label>
                    <div class="input-wrap">
                        <input
                            class="with-toggle"
                            id="confirm_password"
                            name="confirm_password"
                            type="password"
                            minlength="8"
                            maxlength="72"
                            placeholder="Enter the password again"
                            required
                            autocomplete="new-password"
                        >
                        <button class="toggle-password" type="button"
                                data-target="confirm_password">SHOW</button>
                    </div>
                    <small class="match-text" id="matchText" aria-live="polite"></small>
                </div>

                <label class="terms">
                    <input type="checkbox" id="terms" required>
                    <span>
                        I confirm that the information provided is correct and
                        I agree to use this platform responsibly.
                    </span>
                </label>

                <button class="submit-btn" type="submit"
                        <?= $setupError !== '' ? 'disabled' : '' ?>>
                    Create Organization Account
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"></path>
                    </svg>
                </button>
            </form>

            <div class="form-footer">
                Already have an account?
                <a href="/login.php">Sign in here</a>
            </div>

            <div class="security-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                </svg>
                Your password is securely hashed before storage.
            </div>
        </section>
    </main>
</div>

<script>
(function () {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthBars = document.querySelectorAll('.strength span');
    const strengthText = document.getElementById('strengthText');
    const matchText = document.getElementById('matchText');
    const form = document.getElementById('signupForm');

    document.querySelectorAll('.toggle-password').forEach(function (button) {
        button.addEventListener('click', function () {
            const field = document.getElementById(button.dataset.target);
            const show = field.type === 'password';

            field.type = show ? 'text' : 'password';
            button.textContent = show ? 'HIDE' : 'SHOW';
        });
    });

    function updateStrength() {
        const value = password.value;
        let score = 0;

        if (value.length >= 8) score++;
        if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
        if (/[0-9]/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;

        const labels = [
            'Use 8 or more characters for your password.',
            'Weak password — add more variety.',
            'Fair password — add numbers or symbols.',
            'Good password — almost there.',
            'Strong password.'
        ];

        strengthBars.forEach(function (bar, index) {
            if (index >= score) {
                bar.style.background = '#e7e9f0';
            } else if (score <= 1) {
                bar.style.background = '#e35c68';
            } else if (score === 2) {
                bar.style.background = '#e7a33e';
            } else if (score === 3) {
                bar.style.background = '#6a83f7';
            } else {
                bar.style.background = '#22aa70';
            }
        });

        strengthText.textContent = labels[score];
    }

    function checkMatch() {
        if (!confirmPassword.value) {
            matchText.textContent = '';
            confirmPassword.setCustomValidity('');
            return;
        }

        if (password.value === confirmPassword.value) {
            matchText.textContent = 'Passwords match.';
            matchText.style.color = '#137a49';
            confirmPassword.setCustomValidity('');
        } else {
            matchText.textContent = 'Passwords do not match.';
            matchText.style.color = '#c13745';
            confirmPassword.setCustomValidity('Passwords do not match.');
        }
    }

    password.addEventListener('input', function () {
        updateStrength();
        checkMatch();
    });

    confirmPassword.addEventListener('input', checkMatch);

    form.addEventListener('submit', function (event) {
        checkMatch();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    updateStrength();
})();
</script>
</body>
</html>
