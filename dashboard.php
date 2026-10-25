<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['organization_id'])) {
    header('Location: /login.php');
    exit;
}

$organizationId = (int) $_SESSION['organization_id'];

function dh($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboardIcon(string $name): string
{
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check' => '<path d="M12 3 20 7v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4Z"/><path d="m9 12 2 2 4-4"/>',
        'chart' => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6" rx="1"/><rect x="13" y="7" width="3" height="10" rx="1"/><rect x="19" y="4" width="2" height="13" rx="1"/>',
        'building' => '<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 10h1M14 10h1M9 14h1M14 14h1M10 21v-4h4v4"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.6-1.4-2.4L7.3 15a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.6 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 2Z"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 0 1 4.8 1c0 2-2.4 2-2.4 4"/><path d="M12 17h.01"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
        'arrow' => '<path d="M7 17 17 7M7 7h10v10"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 10a2 2 0 0 0-2 2M18 14a2 2 0 0 0 2-2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'ticket' => '<path d="M2 9a3 3 0 0 0 0 6v3h20v-3a3 3 0 0 1 0-6V6H2v3Z"/><path d="M13 6v2M13 11v2M13 16v2"/>'
    ];
    $path = $icons[$name] ?? $icons['help'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, organization_name, email, phone, subscription_status, created_at
         FROM organizations WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $organizationId]);
    $organization = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$organization) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: /login.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('EventFlow dashboard organization query: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your dashboard. Please try again later.');
}

$organizationName = (string) $organization['organization_name'];
$organizationEmail = (string) $organization['email'];
$subscriptionStatus = (string) ($organization['subscription_status'] ?? 'inactive');
$initial = function_exists('mb_substr')
    ? mb_substr($organizationName, 0, 1, 'UTF-8')
    : substr($organizationName, 0, 1);

// Dashboard values are scoped strictly to the logged-in organization.
$eventTotal = 0;
$eventPublished = 0;
$eventDrafts = 0;
$totalRegistrations = 0;
$paidRegistrations = 0;
$pendingRegistrations = 0;
$revenue = 0.0;
$paymentTrackingReady = false;
$recentEvents = [];

try {
    $eventStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END), 0) AS published,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS drafts
         FROM events WHERE organization_id = :organization_id"
    );
    $eventStmt->execute([':organization_id' => $organizationId]);
    $eventStats = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $eventTotal = (int) ($eventStats['total'] ?? 0);
    $eventPublished = (int) ($eventStats['published'] ?? 0);
    $eventDrafts = (int) ($eventStats['drafts'] ?? 0);

    $recentStmt = $pdo->prepare(
        'SELECT id, event_name, event_date, venue, status, slug
         FROM events WHERE organization_id = :organization_id
         ORDER BY created_at DESC, id DESC LIMIT 5'
    );
    $recentStmt->execute([':organization_id' => $organizationId]);
    $recentEvents = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $eventError) {
    error_log('EventFlow dashboard event stats: ' . $eventError->getMessage());
}

try {
    // The registration table exists after setup. Keep page functional if it is unavailable.
    $regStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending
         FROM event_registrations WHERE organization_id = :organization_id"
    );
    $regStmt->execute([':organization_id' => $organizationId]);
    $regStats = $regStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalRegistrations = (int) ($regStats['total'] ?? 0);
    $pendingRegistrations = (int) ($regStats['pending'] ?? 0);

    $paymentStatusColumn = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'payment_status'")->fetch(PDO::FETCH_ASSOC);
    $amountPaidColumn = $pdo->query("SHOW COLUMNS FROM event_registrations LIKE 'amount_paid'")->fetch(PDO::FETCH_ASSOC);

    if ($paymentStatusColumn && $amountPaidColumn) {
        $paymentTrackingReady = true;
        $paidStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS payment_pending,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END), 0) AS revenue
             FROM event_registrations WHERE organization_id = :organization_id"
        );
        $paidStmt->execute([':organization_id' => $organizationId]);
        $paidStats = $paidStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $paidRegistrations = (int) ($paidStats['paid'] ?? 0);
        $pendingRegistrations = (int) ($paidStats['payment_pending'] ?? 0);
        $revenue = (float) ($paidStats['revenue'] ?? 0);
    }
} catch (Throwable $registrationError) {
    error_log('EventFlow dashboard registration stats: ' . $registrationError->getMessage());
}

$modules = [
    ['title' => 'Marathon Events', 'description' => 'View, manage and share your event landing pages.', 'icon' => 'calendar', 'color' => 'purple', 'href' => '/my-events.php', 'available' => true, 'meta' => $eventTotal . ' events · ' . $eventPublished . ' published'],
    ['title' => 'Participants', 'description' => 'View student registrations and their custom form answers.', 'icon' => 'users', 'color' => 'green', 'href' => '/participants.php', 'available' => true, 'meta' => 'View registrations'],
    ['title' => 'Attendance', 'description' => 'Scan participant QR codes or enter registration numbers to mark present.', 'icon' => 'check', 'color' => 'orange', 'href' => '/attendance.php', 'available' => true, 'meta' => 'QR scan + manual check-in'],
    ['title' => 'Reports', 'description' => 'Review registrations, attendance and event performance.', 'icon' => 'chart', 'color' => 'blue', 'href' => '/reports.php', 'available' => false, 'meta' => 'Reports and analytics'],
    ['title' => 'Organization', 'description' => 'Manage your company profile and brand information.', 'icon' => 'building', 'color' => 'blue', 'href' => '/organization/', 'available' => true, 'meta' => 'Profile and company logo'],
    ['title' => 'Subscription', 'description' => 'View your current plan and future payment options.', 'icon' => 'card', 'color' => 'green', 'href' => '/subscription.php', 'available' => true, 'meta' => 'Online payment later'],
    ['title' => 'Settings', 'description' => 'Manage account preferences and security.', 'icon' => 'settings', 'color' => 'purple', 'href' => '/settings.php', 'available' => false, 'meta' => 'Account settings'],
    ['title' => 'Help & Support', 'description' => 'Get help with setting up and managing your marathon.', 'icon' => 'help', 'color' => 'orange', 'href' => '/support.php', 'available' => false, 'meta' => 'Support centre']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Organization Dashboard | EventFlow</title>
<meta name="description" content="Manage your marathon events, participants and registrations in EventFlow.">
<style>
:root{--primary:#635bff;--primary-dark:#4c43e8;--ink:#182039;--muted:#727b91;--border:#e8ebf3;--page:#f6f7fc;--white:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--page);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px}a{color:inherit;text-decoration:none}svg{width:21px;height:21px;flex-shrink:0}.app{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:250px;background:#fff;border-right:1px solid var(--border);padding:25px 17px;display:flex;flex-direction:column;z-index:20}.brand{display:flex;align-items:center;gap:10px;padding:0 9px;margin-bottom:34px;font-size:23px;font-weight:850;letter-spacing:-.8px}.brand-icon{width:41px;height:41px;display:grid;place-items:center;color:white;background:linear-gradient(135deg,#776dff,#4c43e8);border-radius:13px;box-shadow:0 8px 20px #635bff30}.brand-icon svg{width:23px;height:23px}.brand-flow{color:var(--primary)}.nav-label{padding:0 12px;margin:0 0 12px;color:#969db0;font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase}.nav-list{display:grid;gap:5px}.nav-link{min-height:43px;padding:10px 12px;display:flex;align-items:center;gap:12px;color:#626b80;border-radius:11px;font-size:13px;font-weight:650;transition:background .2s,color .2s}.nav-link svg{width:19px;height:19px}.nav-link:hover,.nav-link.active{color:var(--primary);background:#f0efff}.nav-link.disabled{color:#9aa1b1;cursor:default}.sidebar-bottom{margin-top:auto}.plan-box{padding:15px;border:1px solid #e8e6ff;background:linear-gradient(145deg,#f7f6ff,#fff);border-radius:15px;margin-bottom:16px}.plan-title{font-size:12px;font-weight:800;margin-bottom:6px}.plan-text{color:var(--muted);font-size:11px;line-height:1.6}.plan-status{display:inline-block;margin-top:10px;padding:5px 8px;color:#6a55c7;background:#eeeaff;border-radius:20px;font-weight:800;font-size:10px;text-transform:capitalize}.logout{display:flex;align-items:center;gap:10px;padding:12px;color:#c13745;font-weight:700;font-size:13px;border-radius:10px}.logout:hover{background:#fff0f1}.main{margin-left:250px;min-height:100vh}.topbar{height:76px;padding:0 34px;display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border-bottom:1px solid var(--border)}.topbar-label{font-size:11px;color:var(--muted);letter-spacing:.7px}.topbar-label strong{display:block;color:var(--ink);margin-top:5px;font-size:15px;letter-spacing:0}.top-actions{display:flex;align-items:center;gap:13px}.top-link{width:38px;height:38px;display:grid;place-items:center;background:#f7f8fc;color:#647087;border:1px solid var(--border);border-radius:12px}.avatar{width:39px;height:39px;display:grid;place-items:center;border-radius:13px;background:#ebe9ff;color:var(--primary);font-size:13px;font-weight:800;text-transform:uppercase}.content{width:min(1450px,100%);margin:0 auto;padding:30px 34px 38px}.welcome{display:flex;justify-content:space-between;align-items:center;gap:22px;margin-bottom:25px}.eyebrow{margin-bottom:9px;color:var(--primary);font-size:10px;font-weight:850;letter-spacing:1.6px;text-transform:uppercase}h1{margin:0;font-size:clamp(27px,2.6vw,35px);line-height:1.2;letter-spacing:-1.2px}.welcome p{margin:10px 0 0;color:var(--muted);font-size:13px;line-height:1.6}.welcome-actions{display:flex;gap:10px;flex-wrap:wrap}.btn{min-height:42px;padding:0 15px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--border);border-radius:11px;background:#fff;color:#535d73;font-size:12px;font-weight:800}.btn.primary{background:linear-gradient(110deg,#6d63ff,#5147e8);border-color:transparent;color:#fff;box-shadow:0 9px 22px #635bff2b}.btn svg{width:16px;height:16px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:33px}.metric{position:relative;overflow:hidden;min-width:0;padding:20px 19px;background:#fff;border:1px solid var(--border);border-radius:17px;box-shadow:0 6px 22px #202c5005}.metric:after{content:"";position:absolute;width:90px;height:90px;right:-28px;top:-36px;border-radius:50%;background:var(--bubble,#f0efff);opacity:.7}.metric-top{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:8px}.metric-label{color:#70798e;font-size:12px;font-weight:700}.metric-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:var(--icon-bg,#f0efff);color:var(--icon-color,#635bff)}.metric-icon svg{width:20px;height:20px}.metric-value{position:relative;z-index:1;margin-top:15px;font-size:clamp(26px,2.4vw,33px);font-weight:850;letter-spacing:-1px;line-height:1.15;overflow-wrap:anywhere}.metric-foot{position:relative;z-index:1;margin-top:8px;color:#8b93a5;font-size:11px;line-height:1.5}.metric.purple{--bubble:#e9e6ff;--icon-bg:#f0efff;--icon-color:#635bff}.metric.green{--bubble:#ddf8ed;--icon-bg:#e7f9f2;--icon-color:#148466}.metric.orange{--bubble:#ffebcf;--icon-bg:#fff3df;--icon-color:#bb7619}.metric.blue{--bubble:#e0eeff;--icon-bg:#eaf3ff;--icon-color:#3176cf}.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:16px}.section-heading h2{margin:0;font-size:19px;letter-spacing:-.5px}.section-heading p{margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.5}.link-quiet{font-size:12px;font-weight:800;color:var(--primary)}.module-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.module-card{position:relative;display:block;min-width:0;min-height:182px;padding:19px;background:#fff;border:1px solid var(--border);border-radius:16px;transition:transform .18s,border-color .18s,box-shadow .18s}.module-card.available:hover{transform:translateY(-3px);border-color:#c9c4ff;box-shadow:0 14px 28px #4c43e812}.module-card.unavailable{background:#fbfcff}.module-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:13px;margin-bottom:15px}.module-icon svg{width:21px;height:21px}.module-icon.purple{color:#635bff;background:#f0efff}.module-icon.green{color:#168a72;background:#e6f8f2}.module-icon.orange{color:#c27b19;background:#fff3df}.module-icon.blue{color:#3176cf;background:#eaf3ff}.module-title-row{display:flex;align-items:center;justify-content:space-between;gap:8px}.module-title{margin:0;font-size:13px;font-weight:850}.module-arrow{color:var(--primary);font-size:17px;flex-shrink:0}.module-description{margin:8px 0 0;color:var(--muted);font-size:11px;line-height:1.65;min-height:36px}.module-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:13px}.mini-pill{display:inline-flex;align-items:center;padding:5px 7px;background:#f4f5fa;border-radius:7px;color:#667087;font-size:9px;font-weight:800}.coming-soon{display:inline-flex;margin-top:12px;padding:5px 8px;color:#747d91;background:#f0f2f7;border-radius:7px;font-size:9px;font-weight:850;letter-spacing:.4px;text-transform:uppercase}.lower-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(250px,1fr);gap:15px;margin-top:27px}.panel{min-width:0;background:#fff;border:1px solid var(--border);border-radius:17px;padding:20px}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}.panel-heading h2{margin:0;font-size:16px;letter-spacing:-.3px}.panel-heading p{margin:5px 0 0;color:var(--muted);font-size:11px}.event-list{display:grid}.event-row{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(100px,.9fr) auto;align-items:center;gap:12px;padding:13px 0;border-top:1px solid #eff1f6}.event-row:first-child{border-top:0}.event-name{min-width:0;font-size:12px;font-weight:800;overflow-wrap:anywhere}.event-sub{margin-top:5px;color:#8991a4;font-size:10px;line-height:1.4;overflow-wrap:anywhere}.event-date{color:#626b80;font-size:11px;line-height:1.5}.status-badge{display:inline-flex;padding:6px 8px;border-radius:7px;font-size:9px;font-weight:850;text-transform:capitalize}.status-badge.published{color:#137a49;background:#e9f8ef}.status-badge.draft{color:#9a640d;background:#fff0d1}.status-badge.completed{color:#3176cf;background:#eaf3ff}.status-badge.cancelled{color:#b22e40;background:#fff0f1}.empty-state{padding:23px 12px;text-align:center;color:var(--muted);font-size:12px;line-height:1.7}.empty-state strong{display:block;margin-bottom:5px;color:var(--ink);font-size:13px}.quick-list{display:grid;gap:10px}.quick-item{display:flex;align-items:center;gap:11px;padding:12px;border:1px solid #edf0f6;border-radius:12px;transition:border-color .18s,background .18s}.quick-item:hover{border-color:#d6d2ff;background:#fcfbff}.quick-icon{width:36px;height:36px;flex-shrink:0;display:grid;place-items:center;background:#f0efff;color:var(--primary);border-radius:10px}.quick-icon svg{width:18px;height:18px}.quick-text{min-width:0;flex:1}.quick-text strong{display:block;font-size:12px}.quick-text span{display:block;margin-top:4px;color:var(--muted);font-size:10px;line-height:1.4}.quick-arrow{color:#939bb0}.note{margin-top:13px;padding:12px;border-radius:11px;background:#f8f9fd;color:#7c8599;font-size:10px;line-height:1.6}.panel-footer{margin-top:25px;padding-top:17px;border-top:1px solid var(--border);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;color:#8991a4;font-size:10px}
@media(max-width:1250px){.sidebar{width:220px}.main{margin-left:220px}.content{padding-left:24px;padding-right:24px}.topbar{padding:0 24px}.module-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.metrics{gap:10px}.metric{padding:16px}}
@media(max-width:900px){.sidebar{width:68px;padding:22px 9px}.brand{justify-content:center;padding:0;font-size:0;margin-bottom:30px}.brand-icon{flex-shrink:0}.nav-label,.nav-link span,.plan-box,.logout span{display:none}.nav-link,.logout{justify-content:center;padding:12px 8px}.nav-link svg,.logout svg{width:21px;height:21px}.main{margin-left:68px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.module-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.lower-grid{grid-template-columns:1fr}}
@media(max-width:560px){.topbar{height:65px;padding:0 14px}.topbar-label{font-size:9px}.topbar-label strong{font-size:13px}.content{padding:22px 13px 25px}.welcome{align-items:flex-start;flex-direction:column;margin-bottom:21px}.welcome-actions{width:100%}.welcome-actions .btn{flex:1}.metrics{gap:9px;margin-bottom:27px}.metric{padding:13px;border-radius:14px}.metric-value{font-size:26px}.metric-label{font-size:10px}.metric-icon{width:32px;height:32px}.module-grid{gap:9px}.module-card{min-height:185px;padding:13px;border-radius:13px}.module-icon{width:36px;height:36px;margin-bottom:11px}.module-description{font-size:10px}.lower-grid{margin-top:23px}.panel{padding:15px}.event-row{grid-template-columns:minmax(0,1fr) auto;gap:8px}.event-date{grid-column:1}.event-row .status-badge{grid-column:2;grid-row:1}.section-heading h2{font-size:17px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition-duration:.01ms!important}}
</style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <a class="brand" href="/dashboard.php" aria-label="EventFlow dashboard">
            <span class="brand-icon"><?= dashboardIcon('calendar') ?></span>
            Event<span class="brand-flow">Flow</span>
        </a>
        <div class="nav-label">Workspace</div>
        <nav class="nav-list">
            <a class="nav-link active" href="/dashboard.php"><?= dashboardIcon('dashboard') ?><span>Dashboard</span></a>
            <a class="nav-link" href="/my-events.php"><?= dashboardIcon('calendar') ?><span>Marathon Events</span></a>
            <a class="nav-link" href="/participants.php"><?= dashboardIcon('users') ?><span>Participants</span></a>
            <a class="nav-link" href="/attendance.php"><?= dashboardIcon('check') ?><span>Attendance</span></a>
            <div class="nav-link disabled" title="Reports will be connected next"><?= dashboardIcon('chart') ?><span>Reports</span></div>
        </nav>
        <div class="nav-label" style="margin-top:28px">Account</div>
        <nav class="nav-list">
            <a class="nav-link" href="/organization/"><?= dashboardIcon('building') ?><span>Organization Profile</span></a>
            <a class="nav-link" href="/subscription.php"><?= dashboardIcon('card') ?><span>Subscription</span></a>
            <div class="nav-link disabled" title="Settings will be connected next"><?= dashboardIcon('settings') ?><span>Settings</span></div>
            <div class="nav-link disabled" title="Help and support will be connected next"><?= dashboardIcon('help') ?><span>Help &amp; Support</span></div>
        </nav>
        <div class="sidebar-bottom">
            <div class="plan-box">
                <div class="plan-title">Your workspace</div>
                <div class="plan-text">Create marathon events, manage your organization and review registration activity from one place.</div>
            </div>
            <a class="logout" href="/logout.php"><?= dashboardIcon('logout') ?><span>Log out</span></a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-label">ORGANIZATION WORKSPACE<strong>Marathon Dashboard</strong></div>
            <div class="top-actions">
                <a class="top-link" href="/organization/" title="Organization profile" aria-label="Organization profile"><?= dashboardIcon('building') ?></a>
                <div class="avatar" title="<?= dh($organizationName) ?>"><?= dh($initial) ?></div>
            </div>
        </header>

        <main class="content">
            <section class="welcome">
                <div>
                    <div class="eyebrow">MARATHON MANAGEMENT OVERVIEW</div>
                    <h1>Welcome back, <?= dh($organizationName) ?>!</h1>
                    <p>Track registrations, review payments and manage all your marathon events from one workspace.</p>
                </div>
                <div class="welcome-actions">
                    <a class="btn" href="/organization/"><?= dashboardIcon('building') ?> Organization profile</a>
                    <a class="btn primary" href="/create-event.php"><?= dashboardIcon('calendar') ?> Create Marathon</a>
                </div>
            </section>

            <section class="metrics" aria-label="Registration overview">
                <article class="metric purple">
                    <div class="metric-top"><span class="metric-label">Total Registrations</span><span class="metric-icon"><?= dashboardIcon('ticket') ?></span></div>
                    <div class="metric-value"><?= number_format($totalRegistrations) ?></div>
                    <div class="metric-foot">Across all your marathon events</div>
                </article>
                <article class="metric green">
                    <div class="metric-top"><span class="metric-label">Paid</span><span class="metric-icon"><?= dashboardIcon('check') ?></span></div>
                    <div class="metric-value"><?= number_format($paidRegistrations) ?></div>
                    <div class="metric-foot"><?= $paymentTrackingReady ? 'Registrations marked as paid' : 'Payment tracking will be connected later' ?></div>
                </article>
                <article class="metric orange">
                    <div class="metric-top"><span class="metric-label">Pending</span><span class="metric-icon"><?= dashboardIcon('clock') ?></span></div>
                    <div class="metric-value"><?= number_format($pendingRegistrations) ?></div>
                    <div class="metric-foot"><?= $paymentTrackingReady ? 'Awaiting payment' : 'Registrations awaiting confirmation' ?></div>
                </article>
                <article class="metric blue">
                    <div class="metric-top"><span class="metric-label">Revenue</span><span class="metric-icon"><?= dashboardIcon('cash') ?></span></div>
                    <div class="metric-value"><?= number_format($revenue, 2) ?></div>
                    <div class="metric-foot"><?= $paymentTrackingReady ? 'Recorded payments only' : 'Will calculate after payment tracking is enabled' ?></div>
                </article>
            </section>

            <section>
                <div class="section-heading">
                    <div><h2>Your workspace</h2><p>All your organization and marathon tools in one place.</p></div>
                </div>
                <div class="module-grid">
                    <?php foreach ($modules as $module): ?>
                        <?php if ($module['available']): ?>
                            <a class="module-card available" href="<?= dh($module['href']) ?>">
                        <?php else: ?>
                            <div class="module-card unavailable" aria-disabled="true">
                        <?php endif; ?>
                            <div class="module-icon <?= dh($module['color']) ?>"><?= dashboardIcon($module['icon']) ?></div>
                            <div class="module-title-row"><h3 class="module-title"><?= dh($module['title']) ?></h3><?php if ($module['available']): ?><span class="module-arrow" aria-hidden="true">↗</span><?php endif; ?></div>
                            <p class="module-description"><?= dh($module['description']) ?></p>
                            <div class="module-meta">
                                <?php if (!empty($module['meta'])): ?><span class="mini-pill"><?= dh($module['meta']) ?></span><?php endif; ?>
                                <?php if (!$module['available']): ?><span class="coming-soon">Coming soon</span><?php endif; ?>
                            </div>
                        <?php if ($module['available']): ?></a><?php else: ?></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="lower-grid">
                <div class="panel">
                    <div class="panel-heading">
                        <div><h2>Recent Marathon Events</h2><p>Your latest created events</p></div>
                        <a class="link-quiet" href="/my-events.php">View all events →</a>
                    </div>
                    <?php if (empty($recentEvents)): ?>
                        <div class="empty-state"><strong>No marathon events yet</strong>Create your first marathon event to start building its landing page.</div>
                    <?php else: ?>
                        <div class="event-list">
                            <?php foreach ($recentEvents as $event): ?>
                                <?php $eventStatus = strtolower((string) ($event['status'] ?? 'draft')); ?>
                                <div class="event-row">
                                    <?php $publicEventUrl = 'https://event.amit-neupane.com.np/marathon.php?slug=' . rawurlencode((string) ($event['slug'] ?? '')); ?>
                                    <div><div class="event-name"><?= dh($event['event_name'] ?? 'Untitled event') ?></div><div class="event-sub"><?= dh($event['venue'] ?? 'Venue not set') ?></div><?php if (!empty($event['slug'])): ?><div class="event-sub"><a class="link-quiet" href="<?= dh($publicEventUrl) ?>" target="_blank" rel="noopener">Open landing page ↗</a></div><?php endif; ?></div>
                                    <div class="event-date"><?= !empty($event['event_date']) ? dh(date('d M Y, h:i A', strtotime((string) $event['event_date']))) : 'Date not set' ?></div>
                                    <span class="status-badge <?= in_array($eventStatus, ['published','draft','completed','cancelled'], true) ? dh($eventStatus) : 'draft' ?>"><?= dh($eventStatus) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-heading"><div><h2>Quick Actions</h2><p>Common tasks for your workspace</p></div></div>
                    <div class="quick-list">
                        <a class="quick-item" href="/create-event.php"><span class="quick-icon"><?= dashboardIcon('calendar') ?></span><span class="quick-text"><strong>Create a marathon</strong><span>Add event details, race distances and registration questions.</span></span><span class="quick-arrow">↗</span></a>
                        <a class="quick-item" href="/my-events.php"><span class="quick-icon"><?= dashboardIcon('ticket') ?></span><span class="quick-text"><strong>My event landing pages</strong><span>Open or copy public links to share with participants.</span></span><span class="quick-arrow">↗</span></a>
                        <a class="quick-item" href="/participants.php"><span class="quick-icon"><?= dashboardIcon('users') ?></span><span class="quick-text"><strong>View participants</strong><span>See student registrations and custom form answers.</span></span><span class="quick-arrow">↗</span></a>
                        <a class="quick-item" href="/organization/"><span class="quick-icon"><?= dashboardIcon('building') ?></span><span class="quick-text"><strong>Update organization profile</strong><span>Maintain your company details and logo.</span></span><span class="quick-arrow">↗</span></a>
                        <a class="quick-item" href="/subscription.php"><span class="quick-icon"><?= dashboardIcon('card') ?></span><span class="quick-text"><strong>View subscription</strong><span>Payment setup can be added later.</span></span><span class="quick-arrow">↗</span></a>
                    </div>
                    <?php if (!$paymentTrackingReady): ?>
                        <div class="note">Paid and Revenue stay at 0 until payment tracking is added. No real payment is being collected on this dashboard yet.</div>
                    <?php endif; ?>
                </div>
            </section>

            <footer class="panel-footer"><span>© <?= date('Y') ?> EventFlow. All rights reserved.</span><span>Marathon organization workspace</span></footer>
        </main>
    </div>
</div>
</body>
</html>
