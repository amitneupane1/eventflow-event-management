<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['organization_id'])) {
    header('Location: /login.php');
    exit;
}

$organizationId = (int) $_SESSION['organization_id'];

function sh($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    $stmt = $pdo->prepare(
        "SELECT organization_name, subscription_status, created_at
         FROM organizations
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $organizationId]);
    $organization = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$organization) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('EventFlow subscription page error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load subscription details. Please try again later.');
}

$organizationName = (string) $organization['organization_name'];
$currentStatus = trim((string) $organization['subscription_status']);
if ($currentStatus === '') {
    $currentStatus = 'Not set';
}
$createdDate = !empty($organization['created_at'])
    ? date('d M Y', strtotime((string) $organization['created_at']))
    : 'Not available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscription | EventFlow</title>
<style>
:root{--primary:#635bff;--ink:#182039;--muted:#727b91;--border:#e8ebf3;--page:#f6f7fc}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}a{color:inherit;text-decoration:none}
.topbar{height:76px;padding:0 max(4%,calc((100% - 1160px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border-bottom:1px solid var(--border)}
.brand{font-size:22px;font-weight:800}.brand span{color:var(--primary)}.back{font-size:13px;color:var(--primary);font-weight:750}
.container{width:min(900px,92%);margin:35px auto}.eyebrow{color:var(--primary);font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase}
h1{margin:10px 0;font-size:clamp(26px,4vw,34px);letter-spacing:-1px}.intro{margin:0;color:var(--muted);font-size:14px;line-height:1.7}
.card{margin-top:24px;padding:clamp(22px,4vw,32px);border:1px solid var(--border);border-radius:18px;background:#fff;box-shadow:0 12px 35px #18203908}
.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding-bottom:22px;border-bottom:1px solid #edf0f5}.plan-label{color:var(--muted);font-size:12px}.plan-name{margin-top:7px;font-size:22px;font-weight:800}.status{display:inline-flex;padding:8px 12px;border-radius:20px;background:#f0efff;color:#5147e8;font-size:11px;font-weight:800;text-transform:capitalize;overflow-wrap:anywhere}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:22px}.item{padding:17px;border:1px solid var(--border);border-radius:13px}.item span{display:block;color:var(--muted);font-size:11px}.item strong{display:block;margin-top:7px;font-size:14px;overflow-wrap:anywhere}
.notice{margin-top:22px;padding:17px;border:1px solid #e3e1ff;border-radius:13px;background:#f8f7ff}.notice strong{display:block;font-size:13px}.notice p{margin:7px 0 0;color:#666d83;font-size:12px;line-height:1.8}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:23px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 17px;border-radius:11px;font-size:13px;font-weight:750}.primary{background:var(--primary);color:#fff}.secondary{border:1px solid var(--border);background:#fff;color:#5f6880}
.footer{margin-top:25px;color:#8b93a5;font-size:11px;text-align:center;line-height:1.7}
@media(max-width:560px){.topbar{height:65px;padding:0 4%}.brand{font-size:20px}.container{margin:24px auto}.grid{grid-template-columns:1fr}.card-head{flex-direction:column}.status{align-self:flex-start}}
</style>
</head>
<body>
<header class="topbar">
  <a class="brand" href="/dashboard.php">Event<span>Flow</span></a>
  <a class="back" href="/dashboard.php">← Back to Dashboard</a>
</header>
<main class="container">
  <div class="eyebrow">Organization Workspace</div>
  <h1>Subscription</h1>
  <p class="intro">Review the subscription status associated with your organization account.</p>

  <section class="card">
    <div class="card-head">
      <div>
        <div class="plan-label">Organization</div>
        <div class="plan-name"><?= sh($organizationName) ?></div>
      </div>
      <span class="status">Account status: <?= sh($currentStatus) ?></span>
    </div>

    <div class="grid">
      <div class="item">
        <span>Current subscription mode</span>
        <strong>Temporary access / setup stage</strong>
      </div>
      <div class="item">
        <span>Account created</span>
        <strong><?= sh($createdDate) ?></strong>
      </div>
    </div>

    <div class="notice">
      <strong>Online payment is not enabled yet</strong>
      <p>This page is currently informational only. No payment will be collected here and this page does not change your account's subscription status. Online payment, plan selection, renewals and payment verification can be added later.</p>
    </div>

    <div class="actions">
      <a class="button primary" href="/dashboard.php">Back to Dashboard</a>
      <a class="button secondary" href="/create-event.php">Create Marathon Event</a>
    </div>
  </section>

  <div class="footer">EventFlow subscription page · Payment integration will be added later.</div>
</main>
</body>
</html>
