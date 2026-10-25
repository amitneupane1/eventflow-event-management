<?php
session_start();
require_once __DIR__ . '/config.php';

function rh($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function rdate(?string $value): string {
    try { return $value ? (new DateTime($value))->format('D, d M Y · h:i A') : 'Date to be announced'; }
    catch (Throwable $e) { return 'Date to be announced'; }
}

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug === '' || strlen($slug) > 180 || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404); exit('Event not found.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.organization_id, e.event_name, e.description, e.event_date,
                e.venue, e.ticket_price, e.capacity, e.status, e.slug, e.distances,
                e.min_age, e.max_age, e.registration_fields,
                o.organization_name, o.logo_path
         FROM events e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.slug = :slug LIMIT 1"
    );
    $stmt->execute([':slug' => $slug]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) { http_response_code(404); exit('This event link is invalid or the event no longer exists.'); }
} catch (Throwable $e) {
    error_log('EventFlow registration event lookup: ' . $e->getMessage());
    http_response_code(500); exit('Unable to load the registration form right now.');
}

$distances = array_values(array_filter(array_map('trim', explode(',', (string) $event['distances']))));
$fieldConfig = json_decode((string) ($event['registration_fields'] ?? ''), true);
if (!is_array($fieldConfig)) $fieldConfig = [];
$optionalKeys = ['age','gender','city','emergency_contact_name','emergency_contact_phone','tshirt_size'];
foreach ($optionalKeys as $key) $fieldConfig[$key] = !empty($fieldConfig[$key]);
$fieldConfig['custom'] = isset($fieldConfig['custom']) && is_array($fieldConfig['custom']) ? array_values($fieldConfig['custom']) : [];
if ($event['min_age'] !== null || $event['max_age'] !== null) $fieldConfig['age'] = true;

if (empty($_SESSION['public_register_csrf'])) $_SESSION['public_register_csrf'] = bin2hex(random_bytes(32));
$errors = [];
$values = [
    'participant_name' => '', 'email' => '', 'phone' => '', 'age' => '',
    'gender' => '', 'city' => '', 'race_distance' => '',
    'emergency_contact_name' => '', 'emergency_contact_phone' => '', 'tshirt_size' => ''
];
$customValues = [];
$success = $_SESSION['registration_success'] ?? null;
unset($_SESSION['registration_success']);
$passEmailToken = (string) ($_SESSION['pass_email_token'] ?? '');

$capacityCount = 0;
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = :event_id AND status <> 'cancelled'");
    $c->execute([':event_id' => (int) $event['id']]);
    $capacityCount = (int) $c->fetchColumn();
} catch (Throwable $e) {
    error_log('EventFlow registration capacity check: ' . $e->getMessage());
}

$isPublished = strtolower((string) $event['status']) === 'published';
$isPast = strtotime((string) $event['event_date']) !== false && strtotime((string) $event['event_date']) < time();
$isFull = $capacityCount >= (int) $event['capacity'];
$canRegister = $isPublished && !$isPast && !$isFull;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    foreach ($values as $key => $_) $values[$key] = trim((string) ($_POST[$key] ?? ''));
    $postedCustom = $_POST['custom_answers'] ?? [];
    if (is_array($postedCustom)) {
        foreach ($fieldConfig['custom'] as $i => $field) $customValues[$i] = trim((string) ($postedCustom[$i] ?? ''));
    }

    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['public_register_csrf'], $token)) {
        $errors[] = 'This form has expired. Refresh the page and try again.';
    }
    if (!$canRegister) $errors[] = 'Registration is currently closed for this event.';
    if ($values['participant_name'] === '' || mb_strlen($values['participant_name']) > 150) $errors[] = 'Enter your name (maximum 150 characters).';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 190) $errors[] = 'Enter a valid email address.';
    if ($values['phone'] === '' || mb_strlen($values['phone']) > 30 || mb_strlen($values['phone']) < 7) $errors[] = 'Enter a valid phone number.';
    if (!in_array($values['race_distance'], $distances, true)) $errors[] = 'Choose one of the available race categories.';

    $age = null;
    if ($fieldConfig['age']) {
        if ($values['age'] === '') {
            if ($event['min_age'] !== null || $event['max_age'] !== null) $errors[] = 'Age is required for this event.';
        } else {
            $age = filter_var($values['age'], FILTER_VALIDATE_INT);
            if ($age === false || $age < 1 || $age > 120) $errors[] = 'Enter a valid age between 1 and 120.';
            elseif ($event['min_age'] !== null && $age < (int) $event['min_age']) $errors[] = 'You do not meet the minimum age requirement of ' . (int) $event['min_age'] . ' years.';
            elseif ($event['max_age'] !== null && $age > (int) $event['max_age']) $errors[] = 'You exceed the maximum age requirement of ' . (int) $event['max_age'] . ' years.';
        }
    }
    if (!$fieldConfig['age']) $age = null;

    foreach ($fieldConfig['custom'] as $i => $field) {
        $label = trim((string) ($field['label'] ?? 'Custom question'));
        $answer = trim((string) ($customValues[$i] ?? ''));
        $type = (string) ($field['type'] ?? 'text');
        if (!empty($field['required']) && $answer === '') $errors[] = 'Please answer: ' . $label;
        if ($answer !== '' && mb_strlen($answer) > 1000) $errors[] = $label . ' must be 1,000 characters or fewer.';
        if ($answer !== '' && $type === 'number' && !is_numeric($answer)) $errors[] = $label . ' must be a number.';
        if ($answer !== '' && $type === 'select' && !in_array($answer, (array) ($field['options'] ?? []), true)) $errors[] = 'Choose a valid option for: ' . $label;
    }

    foreach (['gender','city','emergency_contact_name','emergency_contact_phone','tshirt_size'] as $fieldKey) {
        if (!$fieldConfig[$fieldKey]) $values[$fieldKey] = '';
    }
    if ($fieldConfig['gender'] && $values['gender'] !== '' && !in_array($values['gender'], ['Woman','Man','Non-binary','Prefer not to say','Other'], true)) $errors[] = 'Choose a valid gender option.';
    if ($fieldConfig['tshirt_size'] && $values['tshirt_size'] !== '' && !in_array($values['tshirt_size'], ['XS','S','M','L','XL','XXL','XXXL'], true)) $errors[] = 'Choose a valid T-shirt size.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id, organization_id, capacity, status, event_date FROM events WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => (int) $event['id']]);
            $lockedEvent = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedEvent || strtolower((string) $lockedEvent['status']) !== 'published' || strtotime((string) $lockedEvent['event_date']) < time()) {
                throw new RuntimeException('Registration has closed for this event.');
            }
            $countLocked = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = :event_id AND status <> 'cancelled'");
            $countLocked->execute([':event_id' => (int) $event['id']]);
            if ((int) $countLocked->fetchColumn() >= (int) $lockedEvent['capacity']) {
                throw new RuntimeException('Sorry, this event has reached its registration capacity.');
            }

            $customToSave = [];
            foreach ($fieldConfig['custom'] as $i => $field) {
                $customToSave[] = [
                    'label' => (string) ($field['label'] ?? ''),
                    'answer' => (string) ($customValues[$i] ?? '')
                ];
            }
            $registrationCode = 'EF-' . strtoupper(bin2hex(random_bytes(6)));
            $insert = $pdo->prepare(
                "INSERT INTO event_registrations
                    (event_id, organization_id, participant_name, email, phone, age, gender,
                     city, race_distance, emergency_contact_name, emergency_contact_phone,
                     tshirt_size, registration_code, status, custom_answers)
                 VALUES
                    (:event_id, :organization_id, :participant_name, :email, :phone, :age, :gender,
                     :city, :race_distance, :emergency_contact_name, :emergency_contact_phone,
                     :tshirt_size, :registration_code, 'confirmed', :custom_answers)"
            );
            $insert->execute([
                ':event_id' => (int) $event['id'],
                ':organization_id' => (int) $lockedEvent['organization_id'],
                ':participant_name' => $values['participant_name'],
                ':email' => strtolower($values['email']),
                ':phone' => $values['phone'],
                ':age' => $age,
                ':gender' => $fieldConfig['gender'] && $values['gender'] !== '' ? $values['gender'] : null,
                ':city' => $fieldConfig['city'] && $values['city'] !== '' ? $values['city'] : null,
                ':race_distance' => $values['race_distance'],
                ':emergency_contact_name' => $fieldConfig['emergency_contact_name'] && $values['emergency_contact_name'] !== '' ? $values['emergency_contact_name'] : null,
                ':emergency_contact_phone' => $fieldConfig['emergency_contact_phone'] && $values['emergency_contact_phone'] !== '' ? $values['emergency_contact_phone'] : null,
                ':tshirt_size' => $fieldConfig['tshirt_size'] && $values['tshirt_size'] !== '' ? $values['tshirt_size'] : null,
                ':registration_code' => $registrationCode,
                ':custom_answers' => json_encode($customToSave, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ]);
            $pdo->commit();

            // The pass card is rendered from ticket.php itself, so the email
            // attachments match the View / Download design exactly.
            $_SESSION['pass_email_code'] = $registrationCode;
            $_SESSION['pass_email_token'] = bin2hex(random_bytes(32));

            $_SESSION['registration_success'] = [
                'code' => $registrationCode,
                'name' => $values['participant_name'],
                'event' => $event['event_name'],
                'status' => 'confirmed',
                'paid' => (float) $event['ticket_price'] > 0
            ];
            $_SESSION['public_register_csrf'] = bin2hex(random_bytes(32));
            header('Location: /register.php?slug=' . rawurlencode($slug));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException) $errors[] = $e->getMessage();
            else {
                error_log('EventFlow participant registration error: ' . $e->getMessage());
                $errors[] = 'We could not save your registration. Please try again later.';
            }
        }
    }
}

$logoUrl = !empty($event['logo_path']) ? '/' . ltrim((string) $event['logo_path'], '/') : null;
$price = (float) $event['ticket_price'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Race Registration · Join <?= rh($event['event_name']) ?> | EventFlow</title>
<style>
:root{--navy:#111a31;--navy2:#202d50;--purple:#6258f4;--purple2:#4d43dc;--lime:#d3f36b;--coral:#ff7867;--ink:#17213a;--muted:#69748b;--line:#e6eaf2;--bg:#f4f6fb;--white:#fff;}
*{box-sizing:border-box}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 6% 6%,#e9eaff 0,transparent 32%),radial-gradient(ellipse at 95% 45%,#e8f8f1 0,transparent 25%),var(--bg);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}button,input,select,textarea{font:inherit}
.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.94);backdrop-filter:blur(15px);border-bottom:1px solid rgba(222,227,239,.9);padding:12px max(4%,calc((100% - 1120px)/2));display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:70px}
.brand{display:flex;align-items:center;gap:11px;color:var(--ink);font-size:14px;font-weight:850;min-width:0}.brand span:last-child{overflow-wrap:anywhere}
.logo{width:42px;height:42px;flex:0 0 42px;border-radius:13px;background:#f0efff;overflow:hidden;display:grid;place-items:center;color:var(--purple);font-size:13px;font-weight:950;border:1px solid #e6e3ff;box-shadow:0 5px 15px #312b8110}.logo img{width:100%;height:100%;object-fit:contain;background:#fff}
.top a:last-child{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:800;color:#5148d9;padding:10px 13px;background:#f1f0ff;border:1px solid #e2e0ff;border-radius:11px;white-space:nowrap;transition:transform .2s,background .2s}.top a:last-child:hover{transform:translateY(-1px);background:#e9e7ff}
.wrap{width:min(850px,92%);margin:28px auto 54px;animation:rise .55s ease both}
.event-summary{position:relative;isolation:isolate;overflow:hidden;display:flex;align-items:center;gap:20px;min-height:175px;padding:30px;color:#fff;background:radial-gradient(circle at 87% 24%,#5049a0 0,transparent 33%),linear-gradient(123deg,#10172b 0%,#1b2948 58%,#332c7b 100%);border:1px solid #32365b;border-radius:25px;box-shadow:0 22px 50px #121a3525}
.event-summary:before{content:"";position:absolute;z-index:-1;left:0;top:0;bottom:0;width:6px;background:linear-gradient(var(--lime),var(--coral))}
.event-summary:after{content:"";position:absolute;z-index:-1;right:-40px;bottom:-86px;width:340px;height:175px;transform:rotate(-18deg);border-top:2px solid #ffffff25;border-bottom:2px solid #ffffff18;background:repeating-linear-gradient(90deg,transparent 0 29px,#ffffff08 29px 31px);pointer-events:none}
.summary-logo{width:76px;height:76px;flex:0 0 76px;border:3px solid #ffffff24;border-radius:21px;background:#fff;overflow:hidden;display:grid;place-items:center;color:var(--purple);font-size:17px;font-weight:950;box-shadow:0 12px 25px #0003}.summary-logo img{width:100%;height:100%;object-fit:contain;background:#fff}
.summary-kicker{display:flex;align-items:center;gap:8px;color:var(--lime);font-size:10px;font-weight:900;letter-spacing:1.8px;text-transform:uppercase}.summary-kicker:before{content:"";width:7px;height:7px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 5px #d3f36b20}
.summary-title{max-width:620px;font-size:clamp(23px,4vw,35px);font-weight:900;letter-spacing:-1.1px;line-height:1.14;margin:10px 0 13px;overflow-wrap:anywhere}
.summary-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;font-size:12px;line-height:1.7;color:#e1e7f9}.summary-meta br{display:none}
.head{margin:30px 2px 18px}.head:before{content:"YOUR RACE STARTS HERE";display:block;margin-bottom:8px;color:var(--purple);font-size:10px;font-weight:950;letter-spacing:1.8px}.head h1{margin:0 0 8px;font-size:clamp(28px,4vw,36px);line-height:1.1;letter-spacing:-1.4px;color:var(--navy)}.head p{max-width:650px;margin:0;color:var(--muted);font-size:13px;line-height:1.75}
.card{background:var(--white);border:1px solid #e4e8f1;border-radius:22px;overflow:hidden;box-shadow:0 18px 55px #1823440c}
.card-head{padding:21px 27px;border-bottom:1px solid #edf0f6;display:flex;justify-content:space-between;gap:14px;align-items:center;background:linear-gradient(90deg,#fff,#fcfcff)}.card-head h2{font-size:16px;letter-spacing:-.3px;margin:0;display:flex;align-items:center;gap:10px}.card-head h2:before{content:"01";display:grid;place-items:center;width:31px;height:31px;border-radius:10px;color:#4d43dc;background:#efeeff;font-size:10px;font-weight:950}.card-head span{font-size:10px;color:#4c7c39;font-weight:800;background:#f1f9e6;border:1px solid #e0efc5;padding:8px 10px;border-radius:9px;text-align:center}
.form{padding:27px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px 18px}.field{min-width:0}.full{grid-column:1/-1}label{display:flex;align-items:center;gap:5px;flex-wrap:wrap;font-size:12px;font-weight:850;margin-bottom:9px;color:#293550}label .optional, label span[style*="8992a4"]{font-size:10px!important;color:#7b8599!important;font-weight:600!important}
.control{display:block;width:100%;min-width:0;min-height:50px;padding:13px 14px;border:1px solid #dfe4ee;border-radius:12px;background:#fafbfe;font:inherit;font-size:13px;color:var(--ink);outline:none;transition:border-color .18s,box-shadow .18s,background .18s,transform .18s;appearance:auto}textarea.control{min-height:104px;resize:vertical;line-height:1.6}.control:hover{border-color:#c3c9dc}.control:focus{border-color:var(--purple);background:#fff;box-shadow:0 0 0 4px #6258f416}.control::placeholder{color:#a1a9b9}.hint{font-size:10px;color:#7f899d;line-height:1.65;margin-top:7px}
.alert{padding:15px 17px;border-radius:13px;font-size:12px;line-height:1.8;margin:19px 27px 0;overflow-wrap:anywhere}.error{background:#fff0f2;border:1px solid #f3cfd6;color:#a5263a}.error strong{display:inline-block;margin-bottom:3px}
.success{padding:clamp(28px,6vw,52px) 25px;text-align:center;background:radial-gradient(ellipse at top,#f1f0ff,transparent 55%),#fff}.successmark{width:66px;height:66px;display:grid;place-items:center;border-radius:21px;background:#e7f9ef;border:1px solid #c8efd9;color:#148253;font-size:30px;font-weight:950;margin:0 auto 19px;box-shadow:0 10px 22px #17804d10}.success h2{font-size:clamp(24px,4vw,31px);letter-spacing:-.8px;margin:0 0 10px}.success p{font-size:13px;color:var(--muted);line-height:1.8;margin:0 auto 20px;max-width:520px}.code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;display:inline-block;background:#f4f3ff;border:1px dashed #bcb6ff;border-radius:12px;padding:13px 17px;font-size:19px;font-weight:950;letter-spacing:1px;color:#3f36c7;max-width:100%;overflow-wrap:anywhere}.statusnote{padding:14px 15px;border-radius:12px;background:#fff7e9;color:#805719;border:1px solid #f1dfbb;text-align:left;font-size:12px;line-height:1.8;margin:20px auto;max-width:550px}.statusnote strong{font-weight:900}
.submitrow{margin-top:27px;padding-top:23px;border-top:1px solid #edf0f6;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}.submitrow p{font-size:11px;color:#7d879a;line-height:1.7;margin:0;max-width:440px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:52px;padding:0 24px;background:linear-gradient(105deg,#7268ff,#4d43dc);border:0;border-radius:13px;color:#fff;font-size:13px;font-weight:900;letter-spacing:.1px;cursor:pointer;box-shadow:0 10px 22px #554ce72a;transition:transform .2s,box-shadow .2s,filter .2s;white-space:nowrap}.btn:hover{transform:translateY(-2px);box-shadow:0 15px 27px #554ce73a;filter:saturate(1.08)}.btn:focus-visible{outline:3px solid #b7b1ff;outline-offset:3px}
.foot{display:flex;justify-content:center;gap:5px;flex-wrap:wrap;text-align:center;color:#8993a7;font-size:10px;line-height:1.8;margin-top:22px}.foot:before{content:"✓";color:#25845c;font-weight:950}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:640px){.top{min-height:63px;padding:10px 4%;gap:8px}.brand{gap:8px;font-size:12px}.logo{width:36px;height:36px;flex-basis:36px;border-radius:11px}.top a:last-child{font-size:10px;padding:9px 10px;gap:4px}.wrap{width:92%;margin:17px auto 32px}.event-summary{min-height:0;padding:21px 17px;gap:13px;border-radius:19px;align-items:flex-start}.summary-logo{width:56px;height:56px;flex-basis:56px;border-radius:15px;border-width:2px}.summary-kicker{font-size:9px;letter-spacing:1.2px}.summary-title{font-size:clamp(20px,6vw,27px);line-height:1.16;margin:8px 0 10px}.summary-meta{display:block;font-size:11px;line-height:1.75}.head{margin:23px 1px 15px}.head:before{font-size:9px;letter-spacing:1.4px}.head h1{font-size:28px;letter-spacing:-1px}.head p{font-size:12px}.card{border-radius:18px}.card-head{padding:16px;align-items:flex-start;flex-direction:column;gap:9px}.card-head h2{font-size:15px}.card-head span{font-size:10px}.form{padding:19px 16px}.grid{grid-template-columns:minmax(0,1fr);gap:17px}.full{grid-column:auto}.field{width:100%}.control{min-height:49px;font-size:16px;padding:12px 13px}textarea.control{min-height:100px}.hint{font-size:11px}.alert{margin:14px 15px 0;padding:13px;font-size:12px}.submitrow{flex-direction:column;align-items:stretch;gap:15px;margin-top:22px;padding-top:18px}.submitrow p{font-size:11px}.btn{width:100%;min-height:53px;font-size:13px}.success{padding:33px 18px}.successmark{width:59px;height:59px;border-radius:18px}.success h2{font-size:25px}.success p{font-size:12px}.code{font-size:16px}.statusnote{font-size:11px}.foot{padding:0 8px;font-size:10px}}
@media(max-width:360px){.brand{font-size:11px}.top a:last-child{font-size:9px;padding:8px}.summary-logo{width:47px;height:47px;flex-basis:47px}.summary-title{font-size:20px}.form{padding:16px 13px}.card-head{padding:15px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
</style></head><body>
<header class="top"><a class="brand" href="/marathon.php?slug=<?= rawurlencode($slug) ?>"><span class="logo"><?php if ($logoUrl): ?><img src="<?= rh($logoUrl) ?>" alt=""><?php else: ?>EF<?php endif; ?></span><span><?= rh($event['organization_name']) ?></span></a><a href="/marathon.php?slug=<?= rawurlencode($slug) ?>">← Back to event</a></header>
<main class="wrap"><section class="event-summary"><span class="summary-logo"><?php if ($logoUrl): ?><img src="<?= rh($logoUrl) ?>" alt=""><?php else: ?>EF<?php endif; ?></span><div><div class="summary-kicker">Participant registration</div><div class="summary-title"><?= rh($event['event_name']) ?></div><div class="summary-meta"><?= rh(rdate((string) $event['event_date'])) ?><br><?= rh($event['venue']) ?> · <?= $price <= 0 ? 'Free registration' : 'Fee: ₹' . number_format($price, 2) ?></div></div></section>
<div class="head"><h1>Join this event</h1><p>Fill in your details to register. Required fields are marked in the form. We will show your registration code after it is submitted.</p></div>
<section class="card">
<?php if ($success && is_array($success)): ?><div class="success"><div class="successmark">✓</div><h2>Registration submitted</h2><p>Thank you, <?= rh($success['name'] ?? '') ?>. Your registration for <strong><?= rh($success['event'] ?? $event['event_name']) ?></strong> is confirmed.</p><div class="code"><?= rh($success['code'] ?? '') ?></div><div class="statusnote" style="background:#eaf8f0;border-color:#ccebd9;color:#126b47"><strong>REGISTRATION STATUS: CONFIRMED</strong><br>Your registration is confirmed. Keep your unique registration number and participant pass ready for race day.<?php if (!empty($success['paid'])): ?><br><strong>Note:</strong> Online payment is not enabled yet, so no payment was collected through this page.<?php endif; ?></div><div class="statusnote" id="email-status" style="margin-top:14px;text-align:left"><strong>Preparing your participant pass email…</strong><br>We are creating the same pass design shown in View / Download and attaching it as a PDF and PNG. Please keep this page open for a moment.</div><?php if ($passEmailToken !== ''): ?><iframe id="pass-email-frame" title="Preparing participant pass email" data-src="/ticket.php?code=<?= rawurlencode((string)($success['code'] ?? '')) ?>&amp;email_once=1" style="position:fixed;left:-10000px;top:0;width:900px;height:1200px;border:0;opacity:0;pointer-events:none" aria-hidden="true" tabindex="-1"></iframe><?php endif; ?><a class="btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;margin-top:16px" href="/ticket.php?code=<?= rawurlencode((string)($success['code'] ?? '')) ?>">View / Download Participant Pass</a><br><a style="display:inline-block;margin-top:13px;color:#6258f4;font-size:13px;font-weight:800;text-decoration:none" href="/marathon.php?slug=<?= rawurlencode($slug) ?>">Back to event page →</a></div>
<?php else: ?>
<?php if ($errors): ?><div class="alert error"><strong>Please check the following:</strong><br><?= implode('<br>', array_map('rh', array_values(array_unique($errors)))) ?></div><?php endif; ?>
<?php if (!$canRegister): ?><div class="alert error"><?php if (!$isPublished): ?>This event is not published yet, so registrations are closed.<?php elseif ($isPast): ?>The event date has passed; registration is closed.<?php else: ?>This event has reached its registration capacity.<?php endif; ?></div><?php else: ?>
<div class="card-head"><h2>Participant details</h2><span>Capacity remaining: <?= max(0, (int) $event['capacity'] - $capacityCount) ?></span></div>
<form method="post" action="" class="form"><input type="hidden" name="csrf_token" value="<?= rh($_SESSION['public_register_csrf']) ?>"><div class="grid">
<div class="field"><label for="participant_name">Full name *</label><input class="control" id="participant_name" name="participant_name" maxlength="150" autocomplete="name" value="<?= rh($values['participant_name']) ?>" required></div>
<div class="field"><label for="email">Email address *</label><input class="control" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= rh($values['email']) ?>" required></div>
<div class="field"><label for="phone">Mobile / phone number *</label><input class="control" id="phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= rh($values['phone']) ?>" required></div>
<div class="field"><label for="race_distance">Race category *</label><select class="control" id="race_distance" name="race_distance" required><option value="">Choose a distance</option><?php foreach ($distances as $distance): ?><option value="<?= rh($distance) ?>" <?= $values['race_distance'] === $distance ? 'selected' : '' ?>><?= rh($distance) ?></option><?php endforeach; ?></select></div>
<?php if ($fieldConfig['age']): ?><div class="field"><label for="age">Age<?= ($event['min_age'] !== null || $event['max_age'] !== null) ? ' *' : ' (optional)' ?></label><input class="control" id="age" name="age" type="number" min="1" max="120" value="<?= rh($values['age']) ?>" <?= ($event['min_age'] !== null || $event['max_age'] !== null) ? 'required' : '' ?>><div class="hint"><?php if ($event['min_age'] !== null || $event['max_age'] !== null): ?>Allowed age: <?= $event['min_age'] !== null ? (int) $event['min_age'] : 'any' ?>–<?= $event['max_age'] !== null ? (int) $event['max_age'] : 'any' ?> years.<?php endif; ?></div></div><?php endif; ?>
<?php if ($fieldConfig['gender']): ?><div class="field"><label for="gender">Gender <span style="font-weight:500;color:#8992a4">(optional)</span></label><select class="control" id="gender" name="gender"><option value="">Select if applicable</option><?php foreach (['Woman','Man','Non-binary','Prefer not to say','Other'] as $choice): ?><option value="<?= rh($choice) ?>" <?= $values['gender'] === $choice ? 'selected' : '' ?>><?= rh($choice) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if ($fieldConfig['city']): ?><div class="field"><label for="city">City <span style="font-weight:500;color:#8992a4">(optional)</span></label><input class="control" id="city" name="city" maxlength="120" value="<?= rh($values['city']) ?>"></div><?php endif; ?>
<?php if ($fieldConfig['tshirt_size']): ?><div class="field"><label for="tshirt_size">T-shirt size <span style="font-weight:500;color:#8992a4">(optional)</span></label><select class="control" id="tshirt_size" name="tshirt_size"><option value="">Choose size</option><?php foreach (['XS','S','M','L','XL','XXL','XXXL'] as $size): ?><option value="<?= rh($size) ?>" <?= $values['tshirt_size'] === $size ? 'selected' : '' ?>><?= rh($size) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if ($fieldConfig['emergency_contact_name']): ?><div class="field"><label for="emergency_contact_name">Emergency contact name <span style="font-weight:500;color:#8992a4">(optional)</span></label><input class="control" id="emergency_contact_name" name="emergency_contact_name" maxlength="150" value="<?= rh($values['emergency_contact_name']) ?>"></div><?php endif; ?>
<?php if ($fieldConfig['emergency_contact_phone']): ?><div class="field"><label for="emergency_contact_phone">Emergency contact phone <span style="font-weight:500;color:#8992a4">(optional)</span></label><input class="control" id="emergency_contact_phone" name="emergency_contact_phone" type="tel" maxlength="30" value="<?= rh($values['emergency_contact_phone']) ?>"></div><?php endif; ?>
<?php foreach ($fieldConfig['custom'] as $i => $field): $label = (string) ($field['label'] ?? 'Additional question'); $type = in_array(($field['type'] ?? 'text'), ['text','number','textarea','select'], true) ? $field['type'] : 'text'; $required = !empty($field['required']); $value = (string) ($customValues[$i] ?? ''); ?><div class="field <?= $type === 'textarea' ? 'full' : '' ?>"><label for="custom_<?= (int) $i ?>"><?= rh($label) ?><?= $required ? ' *' : ' (optional)' ?></label><?php if ($type === 'textarea'): ?><textarea class="control" id="custom_<?= (int) $i ?>" name="custom_answers[<?= (int) $i ?>]" maxlength="1000" <?= $required ? 'required' : '' ?>><?= rh($value) ?></textarea><?php elseif ($type === 'select'): ?><select class="control" id="custom_<?= (int) $i ?>" name="custom_answers[<?= (int) $i ?>]" <?= $required ? 'required' : '' ?>><option value="">Choose an option</option><?php foreach ((array) ($field['options'] ?? []) as $option): ?><option value="<?= rh($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= rh($option) ?></option><?php endforeach; ?></select><?php else: ?><input class="control" id="custom_<?= (int) $i ?>" name="custom_answers[<?= (int) $i ?>]" type="<?= $type === 'number' ? 'number' : 'text' ?>" maxlength="1000" value="<?= rh($value) ?>" <?= $required ? 'required' : '' ?>><?php endif; ?></div><?php endforeach; ?>
</div><div class="submitrow"><p>Online payment is not enabled yet. Once you submit the form, your registration will be marked Confirmed and your participant pass will be prepared.</p><button class="btn" type="submit">Submit registration →</button></div></form>
<?php endif; ?><?php endif; ?></section><div class="foot">Your information is sent to <?= rh($event['organization_name']) ?> for this event registration.<br>Powered by EventFlow</div></main><?php if ($success && is_array($success) && $passEmailToken !== ''): ?><script>
window.EventFlowPassEmailToken = <?= json_encode($passEmailToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let passEmailStatusReceived = false;
window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.type !== 'eventflow-pass-email-result') return;
    const box = document.getElementById('email-status');
    if (!box) return;
    passEmailStatusReceived = true;
    if (event.data.sent) {
        box.style.background = '#eaf8f0'; box.style.borderColor = '#ccebd9'; box.style.color = '#126b47';
        box.innerHTML = '<strong>Confirmation email sent</strong><br>Your participant pass has been emailed as PDF and PNG attachments. Both attachments use the same card design as the View / Download page. Please check your inbox and spam folder.';
    } else {
        box.style.background = '#fff0f2'; box.style.borderColor = '#f3cfd6'; box.style.color = '#a5263a';
        box.innerHTML = '<strong>Your registration is confirmed, but email delivery did not complete.</strong><br>You can still open and download the correctly designed pass using the button below. The organizer may need to check the website mail configuration.';
    }
});
const passFrame = document.getElementById('pass-email-frame');
if (passFrame) passFrame.src = passFrame.dataset.src;
window.setTimeout(function () {
    if (passEmailStatusReceived) return;
    const box = document.getElementById('email-status');
    if (!box) return;
    box.style.background = '#fff0f2'; box.style.borderColor = '#f3cfd6'; box.style.color = '#a5263a';
    box.innerHTML = '<strong>Your registration is confirmed, but we could not finish email preparation.</strong><br>Your pass is still available using the View / Download button. Please ask the organizer to check the email setup if this repeats.';
}, 18000);
</script><?php endif; ?></body></html>
