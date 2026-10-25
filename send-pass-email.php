<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email-pass.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function efJsonResponse(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    efJsonResponse(405, ['sent' => false, 'message' => 'Method not allowed.']);
}

// Same-origin browser request plus a one-time session token set immediately
// after successful registration. Never accept an email address from the browser.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$expectedOrigin = 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'event.amit-neupane.com.np');
if ($origin !== '' && !hash_equals($expectedOrigin, $origin)) {
    efJsonResponse(403, ['sent' => false, 'message' => 'Invalid request origin.']);
}

$code = strtoupper(trim((string) ($_POST['code'] ?? '')));
$token = (string) ($_POST['token'] ?? '');
$sessionToken = (string) ($_SESSION['pass_email_token'] ?? '');
$sessionCode = strtoupper((string) ($_SESSION['pass_email_code'] ?? ''));
if (!preg_match('/^EF-[A-F0-9]{12}$/', $code)
    || $sessionToken === ''
    || $sessionCode !== $code
    || $token === ''
    || !hash_equals($sessionToken, $token)) {
    efJsonResponse(403, ['sent' => false, 'message' => 'Email pass authorization expired.']);
}

if (!isset($_FILES['pass_png'], $_FILES['pass_pdf'])
    || (int) $_FILES['pass_png']['error'] !== UPLOAD_ERR_OK
    || (int) $_FILES['pass_pdf']['error'] !== UPLOAD_ERR_OK) {
    efJsonResponse(400, ['sent' => false, 'message' => 'Pass image or PDF could not be generated.']);
}

$pngFile = $_FILES['pass_png'];
$pdfFile = $_FILES['pass_pdf'];
$maxBytes = 8 * 1024 * 1024;
if (!is_uploaded_file((string) $pngFile['tmp_name']) || !is_uploaded_file((string) $pdfFile['tmp_name'])
    || (int) $pngFile['size'] < 100 || (int) $pngFile['size'] > $maxBytes
    || (int) $pdfFile['size'] < 100 || (int) $pdfFile['size'] > $maxBytes) {
    efJsonResponse(400, ['sent' => false, 'message' => 'Generated pass files are invalid or too large.']);
}

$pngBytes = @file_get_contents((string) $pngFile['tmp_name']);
$pdfBytes = @file_get_contents((string) $pdfFile['tmp_name']);
$pngInfo = $pngBytes !== false ? @getimagesizefromstring($pngBytes) : false;
if (!is_string($pngBytes) || !$pngInfo || ($pngInfo['mime'] ?? '') !== 'image/png'
    || !is_string($pdfBytes) || substr($pdfBytes, 0, 5) !== '%PDF-') {
    efJsonResponse(400, ['sent' => false, 'message' => 'The generated pass attachment format is invalid.']);
}

try {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.event_id, r.organization_id, r.participant_name, r.email,
                r.phone, r.race_distance, r.registration_code, r.status,
                e.event_name, e.event_date, e.venue, e.slug, e.ticket_price,
                o.organization_name, o.logo_path
         FROM event_registrations r
         INNER JOIN events e ON e.id = r.event_id
         INNER JOIN organizations o ON o.id = r.organization_id
         WHERE r.registration_code = :code LIMIT 1"
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) efJsonResponse(404, ['sent' => false, 'message' => 'Registration was not found.']);
} catch (Throwable $e) {
    error_log('EventFlow attachment email lookup error: ' . $e->getMessage());
    efJsonResponse(500, ['sent' => false, 'message' => 'Unable to prepare the confirmation email.']);
}

$recipient = strtolower(trim((string) $row['email']));
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    efJsonResponse(400, ['sent' => false, 'message' => 'The registration email address is invalid.']);
}

// Consume the one-time token so this browser callback cannot send duplicates.
unset($_SESSION['pass_email_token'], $_SESSION['pass_email_code']);

$ticketUrl = 'https://event.amit-neupane.com.np/ticket.php?code=' . rawurlencode($code);
$logoInfo = efResolveLocalLogo(!empty($row['logo_path']) ? (string) $row['logo_path'] : null);
$person = [
    'participant_name' => (string) $row['participant_name'],
    'email' => $recipient,
    'phone' => (string) $row['phone'],
    'race_distance' => (string) $row['race_distance']
];
$html = efBuildProfessionalEmail($row, $person, $code, $ticketUrl, $logoInfo, true);

$boundaryMixed = 'efm_' . bin2hex(random_bytes(12));
$boundaryRelated = 'efr_' . bin2hex(random_bytes(12));
$subjectText = 'Registration confirmed: ' . (string) $row['event_name'] . ' — ' . $code;
$subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
$fromAddress = 'no-reply@event.amit-neupane.com.np';
$headers = [
    'MIME-Version: 1.0',
    'From: EventFlow <' . $fromAddress . '>',
    'Reply-To: ' . $fromAddress,
    'X-Mailer: EventFlow Participant Pass',
    'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"'
];
$body = '--' . $boundaryMixed . "\r\n";
$body .= 'Content-Type: multipart/related; boundary="' . $boundaryRelated . '"' . "\r\n\r\n";
$body .= '--' . $boundaryRelated . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
$body .= chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
if ($logoInfo && isset($logoInfo['bytes'], $logoInfo['mime'])) {
    $mime = (string) $logoInfo['mime'];
    $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/gif' ? 'gif' : ($mime === 'image/webp' ? 'webp' : 'png'));
    $body .= '--' . $boundaryRelated . "\r\nContent-Type: {$mime}; name=\"organization-logo.{$ext}\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <organizationlogo>\r\nContent-Disposition: inline; filename=\"organization-logo.{$ext}\"\r\n\r\n";
    $body .= chunk_split(base64_encode((string) $logoInfo['bytes']), 76, "\r\n") . "\r\n";
}
$body .= '--' . $boundaryRelated . "--\r\n";

$attachments = [
    ['name' => 'EventFlow-Participant-Pass-' . $code . '.pdf', 'mime' => 'application/pdf', 'bytes' => $pdfBytes],
    ['name' => 'EventFlow-Participant-Pass-' . $code . '.png', 'mime' => 'image/png', 'bytes' => $pngBytes],
];
foreach ($attachments as $attachment) {
    $body .= '--' . $boundaryMixed . "\r\nContent-Type: {$attachment['mime']}; name=\"{$attachment['name']}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($attachment['bytes']), 76, "\r\n") . "\r\n";
}
$body .= '--' . $boundaryMixed . "--\r\n";

$sent = function_exists('mail') && @mail($recipient, $subject, $body, implode("\r\n", $headers));
if (!$sent) {
    error_log('EventFlow participant pass email could not be accepted by PHP mail() for registration ' . $code);
    efJsonResponse(502, ['sent' => false, 'message' => 'The website mail service did not accept the email.']);
}

efJsonResponse(200, ['sent' => true, 'attachments' => true]);
