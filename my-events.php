<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['organization_id'])) { header('Location: /login.php'); exit; }
$organizationId = (int) $_SESSION['organization_id'];
if (empty($_SESSION['my_events_csrf'])) $_SESSION['my_events_csrf'] = bin2hex(random_bytes(32));
$flashMessage = $_SESSION['my_events_flash'] ?? '';
unset($_SESSION['my_events_flash']);
const EVENTFLOW_MY_EVENTS_BASE = 'https://event.amit-neupane.com.np';
function yeh($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['event_action'] ?? '');
    if (!hash_equals($_SESSION['my_events_csrf'], $token)) {
        $_SESSION['my_events_flash'] = 'This request expired. Refresh the page and try again.';
    } elseif ($eventId === false || $eventId === null || !in_array($action, ['publish', 'draft'], true)) {
        $_SESSION['my_events_flash'] = 'Invalid event action.';
    } else {
        try {
            $newStatus = $action === 'publish' ? 'published' : 'draft';
            $update = $pdo->prepare('UPDATE events SET status = :status WHERE id = :id AND organization_id = :organization_id');
            $update->execute([':status' => $newStatus, ':id' => $eventId, ':organization_id' => $organizationId]);
            $_SESSION['my_events_flash'] = $update->rowCount() >= 0 ? 'Event status updated to ' . $newStatus . '.' : 'Event status could not be updated.';
            $_SESSION['my_events_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            error_log('EventFlow update event status error: ' . $e->getMessage());
            $_SESSION['my_events_flash'] = 'Unable to update the event status. Please try again.';
        }
    }
    header('Location: /my-events.php');
    exit;
}
try {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.event_name, e.event_date, e.venue, e.ticket_price, e.capacity,
                e.status, e.slug, e.distances, COUNT(r.id) AS registration_count
         FROM events e
         LEFT JOIN event_registrations r ON r.event_id = e.id AND r.status <> 'cancelled'
         WHERE e.organization_id = :organization_id
         GROUP BY e.id, e.event_name, e.event_date, e.venue, e.ticket_price, e.capacity,
                  e.status, e.slug, e.distances
         ORDER BY e.created_at DESC, e.id DESC"
    );
    $stmt->execute([':organization_id' => $organizationId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('EventFlow my-events error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your events. Confirm that the event registration table and event columns have been added.');
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>My Events | EventFlow</title>
<style>
:root{--p:#5b55ee;--ink:#18213a;--muted:#6d7890;--line:#e6eaf2;--bg:#f6f7fc}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}a{text-decoration:none;color:inherit}.top{height:72px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 max(4%,calc((100% - 1160px)/2));gap:15px}.brand{font-weight:850;font-size:22px;letter-spacing:-.7px}.brand b{color:var(--p)}.navlinks{display:flex;gap:14px;font-size:12px;font-weight:800;color:var(--p)}.wrap{width:min(1160px,92%);margin:32px auto 50px}.intro{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:23px}.eyebrow{color:var(--p);font-size:10px;font-weight:850;letter-spacing:1.5px;text-transform:uppercase}h1{margin:9px 0;font-size:clamp(27px,4vw,37px);letter-spacing:-1.2px}.intro p{margin:0;color:var(--muted);font-size:13px;line-height:1.7}.primary{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border-radius:11px;background:linear-gradient(110deg,#6e65ff,#5046df);color:#fff;font-size:12px;font-weight:850;white-space:nowrap}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:20px}.stat{padding:17px;background:#fff;border:1px solid var(--line);border-radius:14px}.stat span{display:block;font-size:11px;color:var(--muted)}.stat strong{display:block;margin-top:7px;font-size:25px;letter-spacing:-.6px}.panel{background:#fff;border:1px solid var(--line);border-radius:17px;overflow:hidden;box-shadow:0 12px 35px #1a275207}.panelhead{padding:19px 22px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px}.panelhead h2{margin:0;font-size:15px}.panelhead span{font-size:11px;color:var(--muted)}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:15px 17px;border-bottom:1px solid #eff1f6;font-size:11px;vertical-align:top}th{color:#818a9d;background:#fbfcff;font-size:9px;letter-spacing:.7px;text-transform:uppercase}td strong{display:block;font-size:12px;line-height:1.5}td small{display:block;color:var(--muted);font-size:10px;line-height:1.6;margin-top:4px;overflow-wrap:anywhere}.badge{display:inline-flex;align-items:center;padding:6px 8px;border-radius:7px;text-transform:capitalize;font-size:9px;font-weight:850}.published{background:#e7f8ef;color:#13794b}.draft{background:#fff2d8;color:#95610d}.other{background:#edf3ff;color:#3465ad}.actions{display:flex;gap:7px;flex-wrap:wrap}.actions form{display:inline-flex;margin:0}.action{display:inline-flex;padding:8px 9px;border:1px solid #dedbff;background:#f8f7ff;color:#4e46ca;border-radius:8px;font-size:10px;font-weight:850}.action.copy{background:#fff;border-color:#e0e4ed;color:#4c566c;cursor:pointer}.action.publish{background:#eaf8f0;border-color:#cfecd9;color:#14784b}.action.unpublish{background:#fff5e4;border-color:#f0e0bf;color:#8f610d}.empty{text-align:center;padding:47px 18px}.empty strong{display:block;font-size:16px}.empty p{max-width:420px;margin:9px auto 18px;color:var(--muted);font-size:12px;line-height:1.7}.foot{text-align:center;color:#8c95a6;font-size:10px;padding:20px}@media(max-width:700px){.top{height:65px}.brand{font-size:19px}.wrap{margin-top:23px}.intro{align-items:flex-start;flex-direction:column}.primary{width:100%}.summary{gap:8px}.stat{padding:12px}.stat span{font-size:10px}.stat strong{font-size:22px}.panelhead{padding:16px}.panelhead span{display:none}}@media(max-width:420px){.summary{grid-template-columns:1fr}.stat{display:flex;align-items:center;justify-content:space-between}.stat strong{margin-top:0}}
</style></head><body>
<header class="top"><a class="brand" href="/dashboard.php">Event<b>Flow</b></a><nav class="navlinks"><a href="/dashboard.php">Dashboard</a><a href="/create-event.php">+ Create event</a></nav></header>
<main class="wrap"><div class="intro"><div><div class="eyebrow">Organization workspace</div><h1>My Events</h1><p>Manage your marathon landing pages and copy their shareable links to send to students and participants.</p></div><a class="primary" href="/create-event.php">+ Create new event</a></div>
<div class="summary"><div class="stat"><span>Total events</span><strong><?= number_format(count($events)) ?></strong></div><div class="stat"><span>Published events</span><strong><?= number_format(count(array_filter($events, static fn($e) => strtolower((string) $e['status']) === 'published'))) ?></strong></div><div class="stat"><span>Total registrations</span><strong><?= number_format(array_sum(array_map(static fn($e) => (int) $e['registration_count'], $events))) ?></strong></div></div>
<section class="panel"><div class="panelhead"><h2>All organization events</h2><span>Only events belonging to your organization are shown.</span></div><?php if ($flashMessage !== ''): ?><div style="margin:16px 18px;padding:12px 14px;border:1px solid #d8d6ff;background:#f7f6ff;color:#4d45c9;border-radius:10px;font-size:12px;line-height:1.6"><?= yeh($flashMessage) ?></div><?php endif; ?>
<?php if (!$events): ?><div class="empty"><strong>No events created yet</strong><p>Create your first marathon event. EventFlow will create its public landing page and a link you can share.</p><a class="primary" href="/create-event.php">Create your first event →</a></div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Event</th><th>Date & venue</th><th>Registrations</th><th>Status</th><th>Public link & actions</th></tr></thead><tbody>
<?php foreach ($events as $event): $url = EVENTFLOW_MY_EVENTS_BASE . '/marathon.php?slug=' . rawurlencode((string) $event['slug']); $status = strtolower((string) $event['status']); ?><tr><td><strong><?= yeh($event['event_name']) ?></strong><small><?= yeh($event['distances']) ?></small><small>Fee: <?= (float) $event['ticket_price'] <= 0 ? 'Free' : '₹' . number_format((float) $event['ticket_price'], 2) ?> · Capacity <?= number_format((int) $event['capacity']) ?></small></td><td><strong><?= yeh(date('d M Y, h:i A', strtotime((string) $event['event_date']))) ?></strong><small><?= yeh($event['venue']) ?></small></td><td><strong><?= number_format((int) $event['registration_count']) ?></strong><small>of <?= number_format((int) $event['capacity']) ?> places</small></td><td><span class="badge <?= $status === 'published' ? 'published' : ($status === 'draft' ? 'draft' : 'other') ?>"><?= yeh($status) ?></span><?php if ($status === 'draft'): ?><small>Join Now is closed until published.</small><?php endif; ?></td><td><div class="actions"><a class="action" href="<?= yeh($url) ?>" target="_blank" rel="noopener">Open landing page ↗</a><button class="action copy" type="button" data-url="<?= yeh($url) ?>">Copy link</button><form method="post" action=""><input type="hidden" name="csrf_token" value="<?= yeh($_SESSION['my_events_csrf']) ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><?php if ($status === 'draft'): ?><input type="hidden" name="event_action" value="publish"><button class="action publish" type="submit">Publish</button><?php elseif ($status === 'published'): ?><input type="hidden" name="event_action" value="draft"><button class="action unpublish" type="submit">Unpublish</button><?php endif; ?></form></div><small><?= yeh($url) ?></small></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section><div class="foot">© <?= date('Y') ?> EventFlow · Marathon management</div></main>
<script>document.querySelectorAll('.copy').forEach(button=>button.addEventListener('click',async()=>{const url=button.dataset.url;try{await navigator.clipboard.writeText(url);button.textContent='Copied!'}catch(e){window.prompt('Copy this event link:',url)}}));</script></body></html>
