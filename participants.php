<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['organization_id'])) {
    header('Location: /login.php');
    exit;
}

$organizationId = (int) $_SESSION['organization_id'];
function ph($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function pRegistrationDate(?string $value): string {
    if (!$value) return '';
    try {
        // created_at is a TIMESTAMP from MySQL and this deployment stores it in UTC.
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d M Y, h:i A') . ' IST';
    } catch (Throwable $e) { return ''; }
}
function pCheckinDate(?string $value): string {
    if (!$value) return '';
    try { return (new DateTime($value, new DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i A') . ' IST'; }
    catch (Throwable $e) { return ''; }
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$eventFilter = (int) ($_GET['event_id'] ?? 0);
$allowedStatuses = ['', 'pending', 'confirmed', 'cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = '';

try {
    $eventsStmt = $pdo->prepare('SELECT id, event_name FROM events WHERE organization_id = :org ORDER BY created_at DESC, id DESC');
    $eventsStmt->execute([':org' => $organizationId]);
    $organizationEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $statsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END),0) AS pending,
                COALESCE(SUM(CASE WHEN r.status = 'confirmed' THEN 1 ELSE 0 END),0) AS confirmed,
                COALESCE(SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END),0) AS cancelled
         FROM event_registrations r
         WHERE r.organization_id = :org"
    );
    $statsStmt->execute([':org' => $organizationId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'pending'=>0,'confirmed'=>0,'cancelled'=>0];

    $where = ['r.organization_id = :org'];
    $params = [':org' => $organizationId];
    if ($eventFilter > 0) {
        $where[] = 'r.event_id = :event_id';
        $params[':event_id'] = $eventFilter;
    }
    if ($statusFilter !== '') {
        $where[] = 'r.status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($search !== '') {
        $where[] = '(r.participant_name LIKE :q OR r.email LIKE :q OR r.phone LIKE :q OR r.registration_code LIKE :q OR e.event_name LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    $sql = "SELECT r.id, r.event_id, r.participant_name, r.email, r.phone, r.age, r.gender,
                   r.city, r.race_distance, r.emergency_contact_name, r.emergency_contact_phone,
                   r.tshirt_size, r.registration_code, r.status, r.custom_answers, r.created_at, r.check_in_at,
                   e.event_name, e.event_date
            FROM event_registrations r
            INNER JOIN events e ON e.id = r.event_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.created_at DESC, r.id DESC LIMIT 500";
    $rowsStmt = $pdo->prepare($sql);
    $rowsStmt->execute($params);
    $participants = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('EventFlow participant list error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load participant registrations. Confirm the database update has been completed.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Participants | EventFlow</title>
<style>
:root{--p:#635bff;--ink:#182039;--muted:#727b91;--line:#e8ebf3;--bg:#f6f7fc;--white:#fff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px}a{color:inherit;text-decoration:none}
.top{height:74px;padding:0 max(4%,calc((100% - 1220px)/2));display:flex;align-items:center;justify-content:space-between;gap:15px;background:#fff;border-bottom:1px solid var(--line)}.brand{font-size:22px;font-weight:850;letter-spacing:-.7px}.brand span{color:var(--p)}.nav{display:flex;gap:15px;color:var(--p);font-size:12px;font-weight:800}
.wrap{width:min(1220px,92%);margin:30px auto 45px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.eyebrow{color:var(--p);font-size:10px;letter-spacing:1.5px;font-weight:850;text-transform:uppercase}h1{font-size:clamp(27px,4vw,36px);letter-spacing:-1px;margin:8px 0}.intro p{margin:0;color:var(--muted);font-size:13px;line-height:1.7}.btn{min-height:42px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:10px;background:#fff;font-size:12px;font-weight:800}.btn.primary{background:linear-gradient(110deg,#6d63ff,#5147e8);border-color:transparent;color:#fff;white-space:nowrap}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:21px}.stat{background:#fff;border:1px solid var(--line);border-radius:15px;padding:17px}.stat span{font-size:11px;color:var(--muted)}.stat strong{display:block;margin-top:8px;font-size:27px;letter-spacing:-.6px}.stat:nth-child(2) strong{color:#a36b11}.stat:nth-child(3) strong{color:#128253}.stat:nth-child(4) strong{color:#b44355}
.filters{padding:17px;background:#fff;border:1px solid var(--line);border-radius:15px;margin-bottom:17px}.filter-grid{display:grid;grid-template-columns:minmax(180px,1.5fr) minmax(160px,1fr) minmax(150px,.8fr) auto;gap:10px}input,select{width:100%;min-height:43px;padding:10px 12px;border:1px solid #dfe3ed;border-radius:9px;background:#fbfcff;color:var(--ink);font:inherit;font-size:12px;outline:none}input:focus,select:focus{border-color:var(--p);box-shadow:0 0 0 3px #635bff14}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden}.panelhead{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:17px 20px;border-bottom:1px solid var(--line)}.panelhead h2{font-size:15px;margin:0}.panelhead span{font-size:11px;color:var(--muted)}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:14px 15px;text-align:left;vertical-align:top;border-bottom:1px solid #eff1f6;font-size:11px}th{background:#fbfcff;color:#80899c;font-size:9px;letter-spacing:.7px;text-transform:uppercase}td strong{display:block;font-size:12px;line-height:1.5}td small{display:block;margin-top:4px;color:var(--muted);font-size:10px;line-height:1.5;overflow-wrap:anywhere}.badge{display:inline-flex;padding:6px 8px;border-radius:7px;font-size:9px;font-weight:850;text-transform:capitalize}.pending{background:#fff2d8;color:#96620b}.confirmed{background:#e7f8ef;color:#13794b}.cancelled{background:#fff0f2;color:#ac2e42}.details{margin-top:7px}.details summary{cursor:pointer;color:#554be1;font-weight:800;font-size:10px}.answer-list{margin-top:8px;max-width:280px;white-space:normal;line-height:1.6;color:#626b80}.empty{text-align:center;padding:44px 18px;color:var(--muted);font-size:12px;line-height:1.7}.empty strong{display:block;font-size:16px;color:var(--ink);margin-bottom:7px}.foot{text-align:center;padding:20px;color:#8c95a6;font-size:10px}
@media(max-width:760px){.intro{align-items:flex-start;flex-direction:column}.intro .btn{width:100%}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:1fr 1fr}.filter-grid .search{grid-column:1/-1}.filter-grid .filter-btn{grid-column:1/-1}.panelhead span{display:none}}
@media(max-width:480px){.top{height:64px}.brand{font-size:19px}.wrap{margin-top:22px}.stats{gap:8px}.stat{padding:12px}.stat strong{font-size:23px}.filter-grid{grid-template-columns:1fr}.filter-grid .search,.filter-grid .filter-btn{grid-column:auto}}
</style>
</head>
<body>
<header class="top"><a class="brand" href="/dashboard.php">Event<span>Flow</span></a><nav class="nav"><a href="/dashboard.php">Dashboard</a><a href="/my-events.php">My Events</a><a href="/attendance.php">Attendance</a></nav></header>
<main class="wrap">
  <section class="intro"><div><div class="eyebrow">Organization workspace</div><h1>Participants</h1><p>Registrations submitted through your marathon landing pages. Search participants and review attendance check-in time.</p></div><a class="btn primary" href="/my-events.php">View marathon events →</a></section>
  <section class="stats"><article class="stat"><span>Total registrations</span><strong><?= number_format((int)$stats['total']) ?></strong></article><article class="stat"><span>Pending</span><strong><?= number_format((int)$stats['pending']) ?></strong></article><article class="stat"><span>Confirmed</span><strong><?= number_format((int)$stats['confirmed']) ?></strong></article><article class="stat"><span>Cancelled</span><strong><?= number_format((int)$stats['cancelled']) ?></strong></article></section>
  <form class="filters" method="get" action=""><div class="filter-grid"><input class="search" type="search" name="q" value="<?= ph($search) ?>" placeholder="Search participant, email, phone, code or event"><select name="event_id"><option value="0">All events</option><?php foreach($organizationEvents as $ev): ?><option value="<?= (int)$ev['id'] ?>" <?= $eventFilter===(int)$ev['id']?'selected':'' ?>><?= ph($ev['event_name']) ?></option><?php endforeach; ?></select><select name="status"><option value="">All statuses</option><?php foreach(['pending','confirmed','cancelled'] as $s): ?><option value="<?= ph($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button class="btn primary filter-btn" type="submit">Apply filters</button></div></form>
  <section class="panel"><div class="panelhead"><h2>Participant registrations</h2><span>Showing up to 500 registrations belonging to your organization.</span></div>
  <?php if (!$participants): ?><div class="empty"><strong>No registrations found</strong>When a student or participant joins one of your published events, their registration will appear here.<br><a class="btn" style="margin-top:15px" href="/my-events.php">Open My Events</a></div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Participant</th><th>Event</th><th>Race</th><th>Contact</th><th>Registration status</th><th>Registration</th><th>Attendance</th><th>Custom answers</th></tr></thead><tbody>
  <?php foreach($participants as $r): ?><tr><td><strong><?= ph($r['participant_name']) ?></strong><small><?= ph($r['age'] !== null ? 'Age '.$r['age'] : '') ?><?= !empty($r['gender']) ? ' · '.ph($r['gender']) : '' ?><?= !empty($r['city']) ? '<br>'.ph($r['city']) : '' ?></small></td><td><strong><?= ph($r['event_name']) ?></strong><small><?= !empty($r['event_date']) ? ph(date('d M Y',strtotime((string)$r['event_date']))) : '' ?></small></td><td><strong><?= ph($r['race_distance']) ?></strong><?php if(!empty($r['tshirt_size'])): ?><small>T-shirt: <?= ph($r['tshirt_size']) ?></small><?php endif; ?></td><td><strong><?= ph($r['email']) ?></strong><small><?= ph($r['phone']) ?></small><?php if(!empty($r['emergency_contact_name']) || !empty($r['emergency_contact_phone'])): ?><small>Emergency: <?= ph(trim((string)$r['emergency_contact_name'].' '.(string)$r['emergency_contact_phone'])) ?></small><?php endif; ?></td><td><span class="badge <?= ph(in_array($r['status'],['pending','confirmed','cancelled'],true)?$r['status']:'pending') ?>"><?= ph($r['status']) ?></span></td><td><strong><?= ph($r['registration_code']) ?></strong><small><?= ph(pRegistrationDate(isset($r['created_at']) ? (string)$r['created_at'] : null)) ?></small></td><td><?php if(!empty($r['check_in_at'])): ?><span class="badge confirmed">PRESENT</span><small><?= ph(pCheckinDate((string)$r['check_in_at'])) ?></small><?php else: ?><span class="badge pending">Not checked in</span><?php endif; ?></td><td><?php $answers = json_decode((string)($r['custom_answers'] ?? ''),true); if(is_array($answers) && $answers): ?><details class="details"><summary>View answers (<?= count($answers) ?>)</summary><div class="answer-list"><?php foreach($answers as $answer): ?><div><strong><?= ph($answer['label'] ?? 'Question') ?></strong><br><?= nl2br(ph($answer['answer'] ?? '')) ?></div><?php endforeach; ?></div></details><?php else: ?><small>—</small><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></section>
  <div class="foot">© <?= date('Y') ?> EventFlow · Participant registrations</div>
</main>
</body></html>
