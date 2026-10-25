
<?php
session_start();
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['organization_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['login_csrf'], $csrf)) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $message = 'Please enter a valid email address and password.';
    } elseif ($password === '' || strlen($password) > 72) {
        $message = 'Please enter a valid email address and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, organization_name, email, password_hash,
                        email_verified, subscription_status, subdomain
                 FROM organizations
                 WHERE email = :email
                 LIMIT 1"
            );

            $stmt->execute([':email' => $email]);
            $organization = $stmt->fetch();

            if ($organization && password_verify($password, $organization['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['organization_id'] = (int) $organization['id'];
                $_SESSION['organization_name'] = $organization['organization_name'];
                $_SESSION['organization_email'] = $organization['email'];

                // Status is available for future subscription restrictions.
                $_SESSION['subscription_status'] = $organization['subscription_status'];
                $_SESSION['email_verified'] = (int) $organization['email_verified'];

                $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $message = 'Incorrect email or password. Please try again.';
        } catch (PDOException $e) {
            error_log('Organization login failed: ' . $e->getMessage());
            $message = 'Login is temporarily unavailable. Please try again later.';
        }
    }
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In | EventFlow</title>
<meta name="description" content="Sign in to your EventFlow organization account.">

<style>
:root {
    --primary: #635bff;
    --ink: #182039;
    --muted: #727b91;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI",
                 Roboto, Arial, sans-serif;
    color: var(--ink);
    background: #f7f8ff;
}

a { color: inherit; text-decoration: none; }
button, input { font: inherit; }

.page {
    min-height: 100vh;
    overflow: hidden;
    position: relative;
    padding-bottom: 40px;
}

.glow {
    position: fixed;
    width: 330px;
    height: 330px;
    border-radius: 50%;
    filter: blur(85px);
    opacity: .24;
    pointer-events: none;
    animation: float 12s ease-in-out infinite alternate;
}

.glow.one { background: #8c82ff; top: -140px; left: -110px; }
.glow.two { background: #70d9e8; right: -140px; bottom: -130px; }

.navbar {
    position: relative;
    z-index: 1;
    width: min(1150px, 92%);
    height: 85px;
    margin: auto;
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
    color: white;
    background: linear-gradient(135deg, #776dff, #4c43e8);
    border-radius: 14px;
    box-shadow: 0 8px 22px #635bff38;
}

.brand-icon svg { width: 23px; height: 23px; }
.brand-name { color: var(--primary); }

.nav-note { font-size: 13px; color: var(--muted); }
.nav-note a { color: var(--primary); font-weight: 750; }

.layout {
    position: relative;
    z-index: 1;
    width: min(1020px, 92%);
    min-height: 620px;
    margin: 28px auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    overflow: hidden;
    background: white;
    border: 1px solid white;
    border-radius: 28px;
    box-shadow: 0 30px 90px #242e5b12;
    animation: enter .7s ease both;
}

.promo {
    padding: 52px 43px;
    color: white;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background:
        radial-gradient(circle at 90% 8%, #a59eff60, transparent 32%),
        radial-gradient(circle at 5% 100%, #30c5d660, transparent 36%),
        linear-gradient(145deg, #28225f, #4940b4 55%, #635bff);
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    width: fit-content;
    padding: 9px 13px;
    border: 1px solid #ffffff35;
    border-radius: 50px;
    background: #ffffff10;
    color: #f0efff;
    font-size: 11px;
    font-weight: 750;
    letter-spacing: 1px;
}

.dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #75f0c0;
    box-shadow: 0 0 12px #75f0c0;
}

.promo h1 {
    margin: 28px 0 16px;
    max-width: 400px;
    font-size: clamp(35px, 4vw, 48px);
    line-height: 1.12;
    letter-spacing: -1.8px;
}

.promo h1 span { color: #bcb7ff; }

.promo p {
    max-width: 370px;
    color: #e0defe;
    font-size: 14px;
    line-height: 1.9;
}

.features {
    display: grid;
    gap: 18px;
    margin-top: 30px;
}

.feature {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #f5f4ff;
    font-size: 13px;
}

.feature-icon {
    width: 37px;
    height: 37px;
    display: grid;
    place-items: center;
    border: 1px solid #ffffff1c;
    border-radius: 12px;
    background: #ffffff17;
    font-size: 17px;
}

.promo-footer {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #ffffff25;
    color: #d8d5ff;
    font-size: 12px;
}

.form-panel {
    padding: 48px clamp(26px, 4vw, 55px);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.step {
    color: var(--primary);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

h2 {
    margin: 12px 0 9px;
    font-size: 32px;
    letter-spacing: -1.1px;
}

.intro {
    margin: 0 0 28px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
}

.alert {
    margin: 0 0 20px;
    padding: 13px 14px;
    border: 1px solid #f8d4d8;
    border-radius: 12px;
    background: #fff0f1;
    color: #b22e40;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.field { margin-bottom: 19px; }

label {
    display: block;
    margin-bottom: 8px;
    color: #30384e;
    font-size: 12px;
    font-weight: 750;
}

.input-wrap { position: relative; }

input[type="email"],
input[type="password"],
input[type="text"] {
    width: 100%;
    height: 52px;
    padding: 0 14px;
    border: 1px solid #e0e4ee;
    border-radius: 12px;
    outline: none;
    background: #fbfcff;
    color: var(--ink);
    font-size: 13px;
    transition: .2s;
}

input::placeholder { color: #a0a7b7; }

input:focus {
    border-color: var(--primary);
    background: white;
    box-shadow: 0 0 0 4px #635bff14;
}

input.with-toggle { padding-right: 68px; }

.toggle {
    position: absolute;
    right: 9px;
    top: 50%;
    transform: translateY(-50%);
    padding: 8px;
    border: 0;
    background: transparent;
    color: var(--primary);
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}

.submit {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 53px;
    margin-top: 5px;
    border: 0;
    border-radius: 13px;
    color: white;
    background: linear-gradient(105deg, #6d63ff, #5147e8);
    box-shadow: 0 10px 23px #635bff30;
    font-size: 14px;
    font-weight: 750;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
}

.submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px #635bff40;
}

.submit svg { width: 18px; height: 18px; }

.form-footer {
    margin-top: 23px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
}

.form-footer a { color: var(--primary); font-weight: 800; }

.security {
    margin-top: 26px;
    text-align: center;
    color: #8b93a5;
    font-size: 11px;
}

@keyframes enter {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes float {
    from { transform: translate(0, 0); }
    to { transform: translate(25px, 30px); }
}

@media (max-width: 800px) {
    .layout { max-width: 570px; grid-template-columns: 1fr; }
    .promo { padding: 35px 30px; }
    .promo h1 { font-size: 38px; }
    .promo-footer { margin-top: 28px; }
    .form-panel { padding: 38px 30px; }
}

@media (max-width: 480px) {
    .navbar { height: 72px; }
    .brand { font-size: 20px; }
    .brand-icon { width: 37px; height: 37px; }
    .nav-note { font-size: 11px; }
    .layout { width: 94%; margin-top: 10px; border-radius: 22px; }
    .promo { padding: 29px 23px; }
    .promo h1 { font-size: 34px; }
    .form-panel { padding: 31px 23px; }
    h2 { font-size: 28px; }
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
    <div class="glow one"></div>
    <div class="glow two"></div>

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
            Event<span class="brand-name">Flow</span>
        </a>

        <div class="nav-note">
            New here? <a href="/signup.php">Create account</a>
        </div>
    </header>

    <main class="layout">
        <section class="promo">
            <div>
                <div class="eyebrow"><span class="dot"></span> WELCOME BACK</div>
                <h1>Your events.<br>Your team.<br><span>One place.</span></h1>
                <p>
                    Sign in to continue managing your organization's events,
                    participants and attendance from one workspace.
                </p>

                <div class="features">
                    <div class="feature">
                        <span class="feature-icon">▦</span>
                        <span>Keep your events organized</span>
                    </div>
                    <div class="feature">
                        <span class="feature-icon">♙</span>
                        <span>Manage your participants</span>
                    </div>
                    <div class="feature">
                        <span class="feature-icon">✓</span>
                        <span>Track attendance and reports</span>
                    </div>
                </div>
            </div>

            <div class="promo-footer">
                Everything your organization needs to stay on track.
            </div>
        </section>

        <section class="form-panel">
            <div class="step">ORGANIZATION WORKSPACE</div>
            <h2>Sign in to EventFlow</h2>
            <p class="intro">Enter your registered email and password to continue.</p>

            <?php if ($message !== ''): ?>
                <div class="alert" role="alert" aria-live="polite">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="on">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['login_csrf']) ?>">

                <div class="field">
                    <label for="email">Email address</label>
                    <div class="input-wrap">
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="190"
                            placeholder="you@organization.com"
                            value="<?= h($email) ?>"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input
                            class="with-toggle"
                            id="password"
                            name="password"
                            type="password"
                            maxlength="72"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button class="toggle" type="button"
                                id="togglePassword" aria-label="Show password">
                            SHOW
                        </button>
                    </div>
                </div>
                
                
                
                
                
<div style="text-align:right;margin:0 0 18px;font-size:13px;">
    <a href="/forgot-password.php"
       style="color:#635bff;font-weight:700;text-decoration:none;">
        Forgot password?
    </a>
</div>

                
                
                
                

                <button class="submit" type="submit">
                    Sign In to Your Account
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 6l6 6-6 6"></path>
                    </svg>
                </button>
            </form>

            <div class="form-footer">
                Don't have an account? <a href="/signup.php">Create one</a>
            </div>

            <div class="security">Your account credentials are checked securely.</div>
        </section>
    </main>
</div>

<script>
const toggle = document.getElementById('togglePassword');
const password = document.getElementById('password');

toggle.addEventListener('click', function () {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'HIDE' : 'SHOW';
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
</body>
</html>
