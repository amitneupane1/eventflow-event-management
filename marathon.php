<?php
session_start();
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Kolkata');

function mh($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mdate(?string $value): string
{
    if (!$value) {
        return 'Date to be announced';
    }

    try {
        $date = new DateTime($value, new DateTimeZone('Asia/Kolkata'));
        return $date->format('D, d M Y · h:i A') . ' IST';
    } catch (Throwable $e) {
        return 'Date to be announced';
    }
}

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '' || strlen($slug) > 180 || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    exit('Event not found.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.organization_id, e.event_name, e.description, e.event_date,
                e.venue, e.ticket_price, e.capacity, e.status, e.slug, e.distances,
                e.min_age, e.max_age, e.registration_fields,
                e.landing_template, e.landing_primary_color, e.landing_accent_color,
                e.landing_background_color, e.landing_custom_layout,
                o.organization_name, o.logo_path
         FROM events e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.slug = :slug
         LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        exit('This event link is invalid or the event no longer exists.');
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_registrations
         WHERE event_id = :event_id AND status <> 'cancelled'"
    );
    $countStmt->execute([':event_id' => (int) $event['id']]);
    $registrationCount = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('EventFlow public landing page error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load this event right now. Please try again later.');
}

$distances = array_values(array_filter(array_map('trim', explode(',', (string) ($event['distances'] ?? '')))));
$rawLogo = trim((string) ($event['logo_path'] ?? ''));
$logoUrl = null;
if ($rawLogo !== '') {
    $logoUrl = preg_match('#^https?://#i', $rawLogo)
        ? $rawLogo
        : '/' . ltrim($rawLogo, '/');
}
$price = (float) $event['ticket_price'];
$capacity = max(0, (int) $event['capacity']);
$registrationCount = max(0, $registrationCount);
$remainingSlots = max(0, $capacity - $registrationCount);
$fillPercent = $capacity > 0 ? min(100, (int) round(($registrationCount / $capacity) * 100)) : 0;
$isPublished = strtolower((string) $event['status']) === 'published';
$eventTimestamp = strtotime((string) $event['event_date']);
$isPast = $eventTimestamp !== false && $eventTimestamp < time();
$isFull = $registrationCount >= $capacity;
$canJoin = $isPublished && !$isPast && !$isFull;
$registrationUrl = '/register.php?slug=' . rawurlencode((string) $event['slug']);
$registrationFields = json_decode((string) ($event['registration_fields'] ?? ''), true);
if (!is_array($registrationFields)) {
    $registrationFields = [];
}
$eventName = (string) $event['event_name'];
$organizationName = (string) $event['organization_name'];
$description = trim((string) ($event['description'] ?? ''));
$description = $description !== '' ? $description : 'A day to challenge yourself, celebrate movement and cross the finish line with an incredible running community.';
$initials = function_exists('mb_substr') ? mb_strtoupper(mb_substr($organizationName, 0, 1)) : strtoupper(substr($organizationName, 0, 1));
$landingTemplate = strtolower((string) ($event['landing_template'] ?? 'track'));
if (!in_array($landingTemplate, ['track', 'city', 'clean', 'custom'], true)) {
    $landingTemplate = 'track';
}
$pageTemplate = $landingTemplate === 'custom'
    ? strtolower((string) ($event['landing_custom_layout'] ?? 'track'))
    : $landingTemplate;
if (!in_array($pageTemplate, ['track', 'city', 'clean'], true)) {
    $pageTemplate = 'track';
}
$isCustomTemplate = $landingTemplate === 'custom';
$customPageStyle = '';
if ($isCustomTemplate) {
    $primaryColor = strtolower((string) ($event['landing_primary_color'] ?? '#d9ff45'));
    $accentColor = strtolower((string) ($event['landing_accent_color'] ?? '#ff6e61'));
    $backgroundColor = strtolower((string) ($event['landing_background_color'] ?? '#f7f6f1'));
    if (!preg_match('/^#[0-9a-f]{6}$/', $primaryColor)) $primaryColor = '#d9ff45';
    if (!preg_match('/^#[0-9a-f]{6}$/', $accentColor)) $accentColor = '#ff6e61';
    if (!preg_match('/^#[0-9a-f]{6}$/', $backgroundColor)) $backgroundColor = '#f7f6f1';
    $customPageStyle = '--lime:' . $primaryColor . ';--coral:' . $accentColor . ';--purple:' . $accentColor . ';--paper:' . $backgroundColor . ';';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= mh($eventName) ?> | <?= mh($organizationName) ?></title>
<meta name="description" content="Register for <?= mh($eventName) ?> organised by <?= mh($organizationName) ?>. Find race information, event date, venue and registration details.">
<meta name="theme-color" content="#111925">
<meta property="og:title" content="<?= mh($eventName) ?>">
<meta property="og:description" content="<?= mh($organizationName) ?> · <?= mh($event['venue']) ?> · <?= mh(mdate((string) $event['event_date'])) ?>">
<style>
:root{
  --night:#111925;--night-2:#172333;--paper:#f7f6f1;--white:#fffefa;
  --ink:#17202b;--muted:#697381;--line:#e4e5df;--lime:#d9ff45;
  --coral:#ff6e61;--purple:#6159ef;--radius:22px;
  --shadow:0 22px 55px rgba(18,28,41,.11)
}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:90px}
body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.5}
a{color:inherit;text-decoration:none}
button,a{-webkit-tap-highlight-color:transparent}
button:focus-visible,a:focus-visible{outline:3px solid var(--coral);outline-offset:4px}
.wrap{width:min(1190px,91.5%);margin-inline:auto}
.nav{height:82px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:12px;min-width:0}
.brand-logo{height:47px;width:47px;display:grid;place-items:center;flex:0 0 47px;border-radius:15px;overflow:hidden;background:var(--lime);color:var(--night);font-size:17px;font-weight:950;letter-spacing:-1px}
.brand-logo img{width:100%;height:100%;object-fit:contain;background:#fff}
.brand-text{min-width:0}
.brand-name{display:block;max-width:310px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:850;font-size:14px;letter-spacing:-.2px}
.brand-caption{display:block;margin-top:2px;color:#76808b;font-size:10px;font-weight:650;letter-spacing:.8px;text-transform:uppercase}
.nav-right{display:flex;align-items:center;gap:16px}
.nav-date{color:#707a86;font-size:11px;font-weight:750;text-align:right}
.nav-cta,.cta{display:inline-flex;align-items:center;justify-content:center;gap:12px;min-height:47px;padding:0 19px;background:var(--lime);color:var(--night);border:0;border-radius:999px;font-size:12px;font-weight:900;box-shadow:0 7px 0 rgba(217,255,69,.12);transition:transform .22s,box-shadow .22s,background .22s}
.nav-cta:hover,.cta:hover{transform:translateY(-3px);box-shadow:0 11px 24px rgba(217,255,69,.18)}
.nav-cta .arrow,.cta .arrow{font-size:18px;line-height:1}
.hero{position:relative;isolation:isolate;overflow:hidden;background:var(--night);color:#fff;border-radius:0 0 32px 32px}
.hero:before{content:"";position:absolute;inset:0;z-index:-2;background:radial-gradient(ellipse at 78% 35%,rgba(217,255,69,.12),transparent 31%),radial-gradient(ellipse at 4% 92%,rgba(255,110,97,.13),transparent 34%),linear-gradient(135deg,#101722 0%,#182333 55%,#101722 100%)}
.hero:after{content:"";position:absolute;z-index:-1;inset:0;opacity:.18;background-image:linear-gradient(rgba(255,255,255,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.12) 1px,transparent 1px);background-size:44px 44px;mask-image:linear-gradient(90deg,transparent,#000 65%)}
.hero-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:30px;align-items:center;min-height:590px;padding:63px 0 67px}
.kicker{display:inline-flex;align-items:center;gap:9px;padding:8px 12px;border:1px solid rgba(217,255,69,.29);border-radius:999px;background:rgba(217,255,69,.07);color:var(--lime);font-size:10px;font-weight:900;letter-spacing:1.2px;text-transform:uppercase}
.kicker-dot{height:7px;width:7px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 5px rgba(217,255,69,.12);animation:pulse 1.8s infinite}
.hero h1{max-width:730px;margin:24px 0 19px;font-size:clamp(42px,6.1vw,78px);line-height:.99;letter-spacing:-4.1px;font-weight:950;text-wrap:balance;overflow-wrap:anywhere}
.hero h1 .highlight{color:var(--lime);display:inline}
.hero-copy{max-width:610px;margin:0;color:#bbc3cc;font-size:14px;line-height:1.9;white-space:normal;overflow-wrap:anywhere}
.hero-copy p{margin:0}
.hero-actions{display:flex;align-items:center;gap:17px;flex-wrap:wrap;margin-top:29px}
.cta{min-height:57px;padding:0 24px;font-size:13px}
.cta-disabled{background:#4a5360;color:#c4cad0;box-shadow:none;cursor:not-allowed}
.hero-note{max-width:245px;color:#a6b0bc;font-size:10px;line-height:1.7}
.hero-note strong{color:#fff}
.hero-bottom{display:flex;align-items:center;gap:12px;margin-top:38px;color:#9ea9b6;font-size:10px;font-weight:750;letter-spacing:1px;text-transform:uppercase}
.hero-bottom:before{content:"";width:34px;height:2px;background:var(--coral)}
.visual{position:relative;min-height:430px;display:grid;place-items:center;isolation:isolate}
.visual-halo{position:absolute;width:345px;height:345px;border-radius:50%;background:rgba(217,255,69,.09);filter:blur(35px);animation:halo 6s ease-in-out infinite alternate}
.track-svg{position:absolute;width:min(100%,540px);height:auto;overflow:visible;filter:drop-shadow(0 18px 36px rgba(0,0,0,.16));transform:rotate(-9deg);animation:trackFloat 7s ease-in-out infinite}
.track-line{fill:none;stroke:rgba(255,255,255,.18);stroke-width:2.2}
.track-dash{fill:none;stroke:var(--lime);stroke-width:2.5;stroke-dasharray:12 13;stroke-linecap:round;animation:dash 13s linear infinite}
.runner{position:absolute;width:175px;height:190px;right:17%;top:19%;filter:drop-shadow(0 18px 15px rgba(0,0,0,.16));animation:runnerBob .48s ease-in-out infinite alternate;transform-origin:center}
.runner .body{stroke:var(--lime);stroke-width:17;stroke-linecap:round;stroke-linejoin:round;fill:none}
.runner .accent{stroke:var(--coral);stroke-width:15;stroke-linecap:round;stroke-linejoin:round;fill:none}
.runner .head{fill:var(--lime)}
.runner .arm-a{transform-origin:76px 68px;animation:armA .48s ease-in-out infinite alternate}
.runner .arm-b{transform-origin:82px 71px;animation:armB .48s ease-in-out infinite alternate}
.runner .leg-a{transform-origin:85px 112px;animation:legA .48s ease-in-out infinite alternate}
.runner .leg-b{transform-origin:85px 112px;animation:legB .48s ease-in-out infinite alternate}
.visual-tag{position:absolute;z-index:2;display:flex;align-items:center;gap:9px;padding:12px 15px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.07);box-shadow:0 15px 35px rgba(0,0,0,.12);backdrop-filter:blur(12px);animation:tagFloat 4.2s ease-in-out infinite}
.visual-tag strong{display:block;font-size:12px;color:#fff;line-height:1.35}
.visual-tag small{display:block;color:#aeb9c5;font-size:9px;margin-top:3px}
.visual-tag .tag-icon{height:34px;width:34px;display:grid;place-items:center;border-radius:10px;background:var(--lime);color:var(--night);font-size:18px;font-weight:950}
.tag-start{left:0;top:15%}.tag-finish{right:0;bottom:12%;animation-delay:-2s}
.visual-number{position:absolute;left:9%;bottom:6%;color:rgba(255,255,255,.055);font-size:118px;line-height:1;font-weight:950;letter-spacing:-10px;user-select:none}
.ticker{overflow:hidden;background:var(--lime);color:var(--night);border-bottom:1px solid rgba(17,25,37,.12)}
.ticker-track{display:flex;width:max-content;align-items:center;gap:27px;padding:13px 0;animation:ticker 26s linear infinite;font-size:10px;font-weight:950;letter-spacing:1.5px;text-transform:uppercase}
.ticker-track span{display:flex;align-items:center;gap:27px;white-space:nowrap}
.ticker-track b{font-size:15px;color:#e7574d}
.content{padding:76px 0}
.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:25px;margin-bottom:25px}
.section-eyebrow{color:#6c7890;font-size:10px;font-weight:900;letter-spacing:1.5px;text-transform:uppercase}
.section-heading h2{margin:8px 0 0;font-size:clamp(29px,4vw,43px);line-height:1.04;letter-spacing:-1.8px;font-weight:950}
.section-side{max-width:310px;color:#727b86;font-size:12px;line-height:1.8}
.facts-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}
.fact{min-width:0;min-height:165px;padding:20px;border:1px solid #e7e5de;border-radius:19px;background:var(--white);transition:transform .2s,border-color .2s,box-shadow .2s;animation:reveal .65s both}
.fact:nth-child(2){animation-delay:.08s}.fact:nth-child(3){animation-delay:.16s}.fact:nth-child(4){animation-delay:.24s}
.fact:hover{transform:translateY(-5px);border-color:#d0d5bd;box-shadow:0 18px 32px rgba(24,34,45,.07)}
.fact-icon{display:grid;place-items:center;width:41px;height:41px;border-radius:13px;background:#f0efea;color:#111925;font-size:20px}
.fact-label{margin-top:20px;color:#79818b;font-size:9px;font-weight:900;letter-spacing:1.1px;text-transform:uppercase}
.fact-value{margin-top:7px;font-size:14px;font-weight:900;line-height:1.45;overflow-wrap:anywhere}
.fact-sub{margin-top:4px;color:#828a94;font-size:10px;line-height:1.55}
.challenge{display:grid;grid-template-columns:minmax(0,.72fr) minmax(0,1.28fr);gap:45px;align-items:start;margin-top:80px}
.challenge h2{margin:8px 0 12px;font-size:clamp(28px,4vw,42px);line-height:1.05;letter-spacing:-1.6px}
.challenge p{margin:0;color:#707987;font-size:12px;line-height:1.9}
.distance-area{padding:25px;border:1px solid #e6e4dc;border-radius:20px;background:var(--white)}
.distance-top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:17px}
.distance-top strong{font-size:13px}.distance-top span{font-size:10px;color:#8a919a}
.distance-list{display:flex;flex-wrap:wrap;gap:9px}
.distance-pill{display:inline-flex;align-items:center;gap:8px;padding:12px 15px;border:1px solid #e5e4dd;border-radius:999px;background:#fbfaf6;font-size:11px;font-weight:900;transition:background .2s,border-color .2s,transform .2s}
.distance-pill:before{content:"";width:7px;height:7px;border-radius:50%;background:var(--coral)}
.distance-pill:hover{transform:translateY(-2px);border-color:var(--lime);background:#f8ffe1}
.empty-distance{font-size:12px;color:#7d8793;line-height:1.7}
.age-badge{display:inline-flex;align-items:center;gap:7px;margin-top:17px;padding:9px 12px;background:#f1f0e9;border-radius:9px;color:#596372;font-size:10px;font-weight:800}
.slots{margin-top:18px;padding-top:17px;border-top:1px solid #eceae3}
.slots-row{display:flex;justify-content:space-between;gap:12px;margin-bottom:9px;font-size:10px;color:#78818c}
.slots-row strong{color:var(--ink)}
.progress{height:7px;border-radius:99px;background:#e9e8e1;overflow:hidden}
.progress-fill{height:100%;width:<?= (int) $fillPercent ?>%;border-radius:99px;background:linear-gradient(90deg,var(--lime),#a6d315);animation:progressIn 1.2s ease-out both}
.cta-panel{position:relative;isolation:isolate;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:28px;padding:43px;border-radius:25px;background:var(--night);color:#fff;margin-top:78px}
.cta-panel:after{content:"RUN";position:absolute;z-index:-1;right:3%;bottom:-40px;color:rgba(255,255,255,.045);font-size:clamp(100px,18vw,220px);font-weight:950;letter-spacing:-13px;line-height:1}
.cta-copy{max-width:610px}.cta-copy .section-eyebrow{color:var(--lime)}
.cta-panel h2{margin:10px 0 10px;font-size:clamp(26px,4vw,43px);letter-spacing:-1.6px;line-height:1.07}
.cta-panel p{margin:0;color:#b8c0c9;font-size:12px;line-height:1.8}
.cta-panel .cta{flex-shrink:0;z-index:1}
.status-notice{width:min(1190px,91.5%);margin:20px auto 0;padding:14px 17px;border-radius:13px;font-size:12px;line-height:1.7}
.status-notice.warn{background:#fff1db;border:1px solid #f0d4a5;color:#815b1b}
.status-notice.info{background:#ebf0ff;border:1px solid #d3dcff;color:#4559ac}
.footer{border-top:1px solid #e4e3dc;padding:26px 0 32px;color:#7c8490;font-size:10px}
.footer-inner{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap}
.footer-brand{font-weight:900;color:#2d3744}.footer-brand span{color:#7a858f;font-weight:500}
@keyframes pulse{0%,100%{box-shadow:0 0 0 5px rgba(217,255,69,.08)}50%{box-shadow:0 0 0 9px rgba(217,255,69,.02)}}
@keyframes halo{from{transform:scale(.92);opacity:.65}to{transform:scale(1.08);opacity:1}}
@keyframes trackFloat{0%,100%{translate:0 0}50%{translate:0 -10px}}
@keyframes dash{to{stroke-dashoffset:-500}}
@keyframes runnerBob{from{translate:0 0;rotate:-1deg}to{translate:0 -7px;rotate:1deg}}
@keyframes armA{from{transform:rotate(20deg)}to{transform:rotate(-28deg)}}
@keyframes armB{from{transform:rotate(-25deg)}to{transform:rotate(20deg)}}
@keyframes legA{from{transform:rotate(-30deg)}to{transform:rotate(24deg)}}
@keyframes legB{from{transform:rotate(27deg)}to{transform:rotate(-24deg)}}
@keyframes tagFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes ticker{to{transform:translateX(-50%)}}
@keyframes reveal{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes progressIn{from{width:0}to{width:<?= (int) $fillPercent ?>%}}
@media(max-width:950px){.hero-grid{grid-template-columns:minmax(0,1fr) minmax(300px,.8fr);gap:10px;min-height:540px}.hero h1{font-size:clamp(40px,6.3vw,65px);letter-spacing:-2.8px}.visual{min-height:355px}.runner{right:13%;top:21%;transform:scale(.88)}.track-svg{width:115%}.facts-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.challenge{gap:25px}}
@media(max-width:720px){.nav{height:72px}.nav-date{display:none}.hero{border-radius:0 0 23px 23px}.hero-grid{grid-template-columns:1fr;gap:0;padding:46px 0 38px}.hero h1{font-size:clamp(44px,10vw,68px);letter-spacing:-2.4px}.hero-copy{font-size:13px}.hero-actions{margin-top:24px}.hero-bottom{margin-top:25px}.visual{min-height:320px;margin-top:10px}.track-svg{width:min(100%,480px)}.runner{width:145px;height:160px;right:24%;top:20%}.tag-start{left:2%;top:10%}.tag-finish{right:1%;bottom:8%}.visual-number{left:12%;bottom:0;font-size:95px}.content{padding:52px 0}.section-heading{align-items:flex-start;flex-direction:column;gap:10px}.section-side{max-width:500px}.challenge{grid-template-columns:1fr;gap:20px;margin-top:58px}.cta-panel{align-items:flex-start;flex-direction:column;padding:29px;margin-top:55px}.cta-panel .cta{width:100%}}
@media(max-width:460px){.wrap{width:90%}.brand-logo{width:40px;height:40px;flex-basis:40px;border-radius:12px}.brand-name{max-width:185px;font-size:12px}.brand-caption{font-size:8px}.nav-cta{min-height:40px;padding:0 14px;font-size:11px}.hero-grid{padding-top:36px}.hero h1{font-size:42px;letter-spacing:-2px}.visual{min-height:270px;margin-top:14px}.runner{width:125px;height:140px;right:23%;top:20%}.tag-start{top:7%;left:0}.tag-finish{bottom:5%;right:0}.visual-tag{padding:9px 10px}.visual-tag .tag-icon{height:29px;width:29px}.visual-tag strong{font-size:10px}.visual-tag small{font-size:8px}.facts-grid{gap:9px}.fact{min-height:148px;padding:15px;border-radius:16px}.fact-value{font-size:12px}.fact-label{font-size:8px}.fact-icon{height:36px;width:36px}.content{padding:42px 0}.distance-area{padding:18px}.distance-pill{font-size:10px;padding:10px 12px}.cta-panel{padding:23px;border-radius:19px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
</style>

<style>
/* Landing page themes selected for this individual event */
body.theme-city{--night:#10152b;--night-2:#1b2240;--paper:#f5f5fc;--white:#fff;--ink:#17182c;--muted:#686c89;--line:#e1e2f4;--lime:#72e4ff;--coral:#b18cff;--purple:#7260ff}
body.theme-city .hero:before{background:radial-gradient(ellipse at 78% 35%,rgba(114,228,255,.18),transparent 31%),radial-gradient(ellipse at 4% 92%,rgba(177,140,255,.22),transparent 34%),linear-gradient(135deg,#10152b 0%,#30245e 55%,#11152d 100%)}
body.theme-city .ticker{background:var(--coral);color:#10152b}
body.theme-city .fact-icon{background:#edebff;color:#3e368f}
body.theme-city .distance-pill{background:#f7f5ff;border-color:#e6e0ff}
body.theme-clean{--night:#fff7ed;--night-2:#fff;--paper:#fffaf4;--white:#fff;--ink:#1c2535;--muted:#677386;--line:#ebdfd2;--lime:#ff743b;--coral:#2867e8;--purple:#5d51d9}
body.theme-clean .hero{background:#fff7ed;color:#1c2535;border-bottom:1px solid #eedfce}
body.theme-clean .hero:before{background:radial-gradient(ellipse at 80% 30%,rgba(255,116,59,.12),transparent 34%),radial-gradient(ellipse at 4% 88%,rgba(40,103,232,.08),transparent 35%),linear-gradient(130deg,#fff7ed 0%,#fff 58%,#f1f5ff 100%)}
body.theme-clean .hero:after{opacity:.1;background-image:linear-gradient(rgba(35,48,66,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(35,48,66,.12) 1px,transparent 1px)}
body.theme-clean .kicker{background:#fff;border-color:#ffd3c2;color:#d94e1c}
body.theme-clean .kicker-dot{background:var(--coral);box-shadow:0 0 0 5px rgba(40,103,232,.1)}
body.theme-clean .hero h1{color:#1c2535}
body.theme-clean .hero h1 .highlight{color:#e65f28}
body.theme-clean .hero-copy{color:#626e7d}
body.theme-clean .hero-note{color:#6e7888}
body.theme-clean .hero-note strong{color:#1c2535}
body.theme-clean .hero-bottom{color:#6e7888}
body.theme-clean .hero-bottom:before{background:var(--coral)}
body.theme-clean .visual-halo{background:rgba(255,116,59,.12)}
body.theme-clean .runner .body,body.theme-clean .runner .head{stroke:var(--lime);fill:var(--lime)}
body.theme-clean .runner .accent{stroke:var(--coral)}
body.theme-clean .visual-tag{background:rgba(255,255,255,.88);border-color:#e1e5ee;box-shadow:0 15px 35px rgba(25,38,56,.1)}
body.theme-clean .visual-tag strong{color:#1c2535}
body.theme-clean .visual-tag small{color:#697486}
body.theme-clean .visual-number{color:rgba(28,37,53,.07)}
body.theme-clean .ticker{background:var(--lime);color:#fff}
body.theme-clean .ticker-track b{color:var(--coral)}
body.theme-clean .fact,body.theme-clean .distance-area{border-color:#ebdfd2}
body.theme-custom .hero:before{background:radial-gradient(ellipse at 78% 35%,color-mix(in srgb,var(--lime),transparent 82%),transparent 31%),radial-gradient(ellipse at 4% 92%,color-mix(in srgb,var(--coral),transparent 84%),transparent 34%),linear-gradient(135deg,var(--night) 0%,var(--night-2) 55%,var(--night) 100%)}
body.theme-custom.theme-clean .hero:before{background:radial-gradient(ellipse at 80% 30%,color-mix(in srgb,var(--lime),transparent 88%),transparent 34%),radial-gradient(ellipse at 4% 88%,color-mix(in srgb,var(--coral),transparent 90%),transparent 35%),linear-gradient(130deg,var(--paper) 0%,#fff 58%,color-mix(in srgb,var(--paper),#eef4ff 45%) 100%)}
body.theme-custom .ticker{background:var(--lime)}
body.theme-custom .fact-icon{background:color-mix(in srgb,var(--lime),white 80%)}
@media(prefers-reduced-motion:reduce){body .ticker-track,body .runner,body .track-svg,body .visual-tag,body .visual-halo{animation:none!important}}
</style>

</head>
<body class="theme-<?= mh($pageTemplate) ?><?= $isCustomTemplate ? ' theme-custom' : '' ?>"<?= $customPageStyle !== '' ? ' style="' . mh($customPageStyle) . '"' : '' ?>>
<header class="wrap nav">
  <a class="brand" href="/" aria-label="EventFlow home">
    <span class="brand-logo"><?php if ($logoUrl): ?><img src="<?= mh($logoUrl) ?>" alt="<?= mh($organizationName) ?> logo"><?php else: ?><?= mh($initials) ?><?php endif; ?></span>
    <span class="brand-text"><span class="brand-name"><?= mh($organizationName) ?></span><span class="brand-caption">Presents · <?= mh($eventName) ?></span></span>
  </a>
  <div class="nav-right"><span class="nav-date"><?= mh(mdate((string) $event['event_date'])) ?></span><?php if ($canJoin): ?><a class="nav-cta" href="<?= mh($registrationUrl) ?>">JOIN THE RACE <span class="arrow">↗</span></a><?php else: ?><span class="nav-cta" style="background:#e6e6e0;box-shadow:none;color:#727a82">REGISTRATION CLOSED</span><?php endif; ?></div>
</header>

<?php if (!$isPublished): ?><div class="status-notice info"><strong>Preview link:</strong> This event is currently a draft. The public landing page is viewable, but registration is closed until the organization publishes it.</div><?php elseif ($isPast): ?><div class="status-notice warn">This race date has passed, so new registrations are closed.</div><?php elseif ($isFull): ?><div class="status-notice warn">All registration places are currently filled. Please contact the organizer for updates.</div><?php endif; ?>

<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-copy-wrap">
      <div class="kicker"><span class="kicker-dot"></span> THE START LINE IS CALLING</div>
      <h1><?= mh($eventName) ?><br><span class="highlight">Own your finish.</span></h1>
      <div class="hero-copy"><?= nl2br(mh($description)) ?></div>
      <div class="hero-actions">
        <?php if ($canJoin): ?><a class="cta" href="<?= mh($registrationUrl) ?>">Join the race <span class="arrow">↗</span></a><?php else: ?><span class="cta cta-disabled">Registration unavailable</span><?php endif; ?>
        <span class="hero-note"><strong>Every runner has a reason.</strong><br>Bring yours to the start line.</span>
      </div>
      <div class="hero-bottom">One course. Your pace. Your finish line.</div>
    </div>
    <div class="visual" aria-label="Animated running track illustration" role="img">
      <div class="visual-halo"></div>
      <svg class="track-svg" viewBox="0 0 520 420" aria-hidden="true">
        <defs><linearGradient id="trackGlow" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#d9ff45" stop-opacity=".36"/><stop offset="1" stop-color="#ff6e61" stop-opacity=".13"/></linearGradient></defs>
        <ellipse cx="260" cy="210" rx="218" ry="133" fill="none" stroke="url(#trackGlow)" stroke-width="48" opacity=".35"/>
        <ellipse class="track-line" cx="260" cy="210" rx="236" ry="150"/>
        <ellipse class="track-line" cx="260" cy="210" rx="219" ry="133"/>
        <ellipse class="track-line" cx="260" cy="210" rx="202" ry="116"/>
        <ellipse class="track-line" cx="260" cy="210" rx="185" ry="99"/>
        <ellipse class="track-dash" cx="260" cy="210" rx="168" ry="82"/>
        <path d="M72 147 l-13 -5 M443 272 l14 5" stroke="#ff6e61" stroke-width="5" stroke-linecap="round"/>
        <circle cx="90" cy="105" r="5" fill="#d9ff45"><animate attributeName="opacity" values=".3;1;.3" dur="2.2s" repeatCount="indefinite"/></circle>
        <circle cx="438" cy="321" r="7" fill="#ff6e61"><animate attributeName="opacity" values="1;.4;1" dur="1.8s" repeatCount="indefinite"/></circle>
        <circle cx="420" cy="85" r="3" fill="#ffffff" opacity=".7"/>
      </svg>
      <svg class="runner" viewBox="0 0 180 200" aria-hidden="true">
        <g class="leg-a"><path class="body" d="M86 111 L63 139 L48 167"/><path class="accent" d="M49 167 L29 170"/></g>
        <g class="leg-b"><path class="body" d="M89 111 L111 136 L134 145"/><path class="body" d="M134 145 L149 163"/></g>
        <path class="body" d="M86 69 L78 101 L91 116"/>
        <g class="arm-a"><path class="accent" d="M78 74 L55 91 L40 78"/></g>
        <g class="arm-b"><path class="body" d="M86 73 L108 87 L122 69"/></g>
        <circle class="head" cx="95" cy="47" r="16"/>
        <path d="M80 45 Q92 22 111 39 L107 31 Q91 21 80 34 Z" fill="#ff6e61"/>
        <path d="M75 69 L96 70 L105 90 L83 99 L70 83 Z" fill="#d9ff45"/>
      </svg>
      <div class="visual-tag tag-start"><span class="tag-icon">↗</span><span><strong>START STRONG</strong><small>Take the first step</small></span></div>
      <div class="visual-tag tag-finish"><span class="tag-icon" style="background:var(--coral);color:#fff">✓</span><span><strong>FINISH PROUD</strong><small>Make it your moment</small></span></div>
      <div class="visual-number" aria-hidden="true">RUN</div>
    </div>
  </div>
</section>

<div class="ticker" aria-hidden="true"><div class="ticker-track"><span>RUN YOUR RACE <b>✳</b> CHASE YOUR PERSONAL BEST <b>✳</b> FEEL THE ENERGY <b>✳</b> OWN YOUR FINISH <b>✳</b></span><span>RUN YOUR RACE <b>✳</b> CHASE YOUR PERSONAL BEST <b>✳</b> FEEL THE ENERGY <b>✳</b> OWN YOUR FINISH <b>✳</b></span></div></div>

<main class="wrap content">
  <div class="section-heading"><div><div class="section-eyebrow">Your race day at a glance</div><h2>Know before<br>you go.</h2></div><div class="section-side">Plan your race, bring your energy and get ready for the day. Here are the details you need before joining <?= mh($eventName) ?>.</div></div>
  <div class="facts-grid">
    <article class="fact"><div class="fact-icon">◷</div><div class="fact-label">Race date & time</div><div class="fact-value"><?= mh(mdate((string) $event['event_date'])) ?></div><div class="fact-sub">Please arrive early on race day.</div></article>
    <article class="fact"><div class="fact-icon">⌖</div><div class="fact-label">Start location</div><div class="fact-value"><?= mh($event['venue']) ?></div><div class="fact-sub">Check the venue before travelling.</div></article>
    <article class="fact"><div class="fact-icon">₹</div><div class="fact-label">Entry fee</div><div class="fact-value"><?= $price <= 0 ? 'Free entry' : '₹' . number_format($price, 2) ?></div><div class="fact-sub"><?= $price <= 0 ? 'No ticket fee is listed.' : 'Payment arrangements are managed by the organiser.' ?></div></article>
    <article class="fact"><div class="fact-icon">♧</div><div class="fact-label">Places remaining</div><div class="fact-value"><?= number_format($remainingSlots) ?> <span style="font-size:11px;color:#77818c;font-weight:750">/ <?= number_format($capacity) ?></span></div><div class="fact-sub"><?= number_format($registrationCount) ?> participant<?= $registrationCount === 1 ? '' : 's' ?> registered so far</div></article>
  </div>

  <section class="challenge">
    <div><div class="section-eyebrow">Pick your distance</div><h2>Choose your<br>challenge.</h2><p>Whether you are starting out or ready to push your limit, check the race categories offered for this event.</p>
      <?php if ($event['min_age'] !== null || $event['max_age'] !== null): ?><div class="age-badge">◉ Age eligibility: <?= $event['min_age'] !== null ? (int) $event['min_age'] . '+' : 'Any age' ?><?= $event['max_age'] !== null ? ' · Up to ' . (int) $event['max_age'] : '' ?> years</div><?php endif; ?>
    </div>
    <div class="distance-area"><div class="distance-top"><strong>Race categories</strong><span><?= count($distances) ?> option<?= count($distances) === 1 ? '' : 's' ?></span></div>
      <?php if ($distances): ?><div class="distance-list"><?php foreach ($distances as $distance): ?><span class="distance-pill"><?= mh($distance) ?></span><?php endforeach; ?></div><?php else: ?><div class="empty-distance">Race categories will be announced by the organiser. Check back for updates.</div><?php endif; ?>
      <div class="slots"><div class="slots-row"><span>Registration places filled</span><strong><?= $fillPercent ?>%</strong></div><div class="progress"><div class="progress-fill"></div></div></div>
    </div>
  </section>

  <section class="cta-panel"><div class="cta-copy"><div class="section-eyebrow">Ready when you are</div><h2>Make this finish line yours.</h2><p>Join the event and fill in the participant registration form. Your next race starts with one click.</p></div><?php if ($canJoin): ?><a class="cta" href="<?= mh($registrationUrl) ?>">JOIN NOW <span class="arrow">↗</span></a><?php else: ?><span class="cta cta-disabled">Registration closed</span><?php endif; ?></section>
</main>

<footer class="footer"><div class="wrap footer-inner"><div class="footer-brand"><?= mh($organizationName) ?> <span>· Event organiser</span></div><div>Powered by <strong>EventFlow</strong> · © <?= date('Y') ?></div></div></footer>
</body>
</html>
