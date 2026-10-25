<?php
/**
 * EventFlow professional participant registration email with PNG/PDF pass attachments.
 * Requires PHP mail() and GD. Email failure never cancels a saved registration.
 */

if (!function_exists('efMailEscape')) {
    function efMailEscape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('efFindFont')) {
    function efFindFont(bool $bold = false): ?string {
        $candidates = $bold ? [
            __DIR__ . '/assets/fonts/DejaVuSans-Bold.ttf',
            __DIR__ . '/fonts/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'
        ] : [
            __DIR__ . '/assets/fonts/DejaVuSans.ttf',
            __DIR__ . '/fonts/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'
        ];
        foreach ($candidates as $font) if (is_file($font) && is_readable($font)) return $font;
        return null;
    }
}

if (!function_exists('efDrawText')) {
    function efDrawText($im, string $text, int $x, int $y, int $size, int $color, bool $bold = false): void {
        $text = trim($text);
        if ($text === '') return;
        $font = efFindFont($bold);
        if ($font && function_exists('imagettftext')) {
            imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
        } else {
            // Low-dependency fallback if no TTF font is installed.
            $safe = preg_replace('/[^\x20-\x7E]/', '?', $text);
            imagestring($im, $size >= 27 ? 5 : ($size >= 17 ? 4 : 3), $x, max(0, $y - 15), $safe, $color);
        }
    }
}

if (!function_exists('efFetchBinary')) {
    function efFetchBinary(string $url, int $timeout = 8): ?string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'EventFlow/1.0 participant-pass',
            ]);
            $data = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return is_string($data) && $status >= 200 && $status < 300 ? $data : null;
        }
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'user_agent' => 'EventFlow/1.0 participant-pass'], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $data = @file_get_contents($url, false, $context);
        return is_string($data) ? $data : null;
    }
}

if (!function_exists('efResolveLocalLogo')) {
    function efResolveLocalLogo(?string $logoPath): ?array {
        if (!$logoPath) return null;
        $relative = ltrim(str_replace('\\', '/', $logoPath), '/');
        if ($relative === '' || str_contains($relative, '..') || preg_match('~^https?://~i', $relative)) return null;
        $roots = array_unique([__DIR__, (string)($_SERVER['DOCUMENT_ROOT'] ?? '')]);
        foreach ($roots as $root) {
            if ($root === '') continue;
            $candidate = realpath(rtrim($root, '/') . '/' . $relative);
            $rootReal = realpath($root);
            if (!$candidate || !$rootReal || !is_file($candidate) || !is_readable($candidate)) continue;
            if (strpos($candidate, $rootReal . DIRECTORY_SEPARATOR) !== 0 && $candidate !== $rootReal) continue;
            $bytes = @file_get_contents($candidate);
            if (!is_string($bytes)) continue;
            $info = @getimagesizefromstring($bytes);
            if (!$info || empty($info['mime'])) continue;
            if (!in_array($info['mime'], ['image/png','image/jpeg','image/gif','image/webp'], true)) continue;
            return ['path' => $candidate, 'bytes' => $bytes, 'mime' => $info['mime']];
        }
        return null;
    }
}

if (!function_exists('efDrawRoundedBox')) {
    function efDrawRoundedBox($im, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void {
        imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }
}

if (!function_exists('efWrapWords')) {
    function efWrapWords(string $text, int $maxChars): array {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') return [''];
        return explode("\n", wordwrap($text, $maxChars, "\n", true));
    }
}

if (!function_exists('efBuildPassImage')) {
    function efBuildPassImage(array $event, array $person, string $code, string $ticketUrl, ?array $logoInfo, string $pngPath, string $jpgPath): bool {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng') || !function_exists('imagejpeg')) return false;
        $w = 1200; $h = 760;
        $im = imagecreatetruecolor($w, $h);
        if (!$im) return false;
        if (function_exists('imageantialias')) @imageantialias($im, true);
        $navy = imagecolorallocate($im, 17, 26, 49);
        $navy2 = imagecolorallocate($im, 29, 43, 76);
        $purple = imagecolorallocate($im, 88, 78, 235);
        $lime = imagecolorallocate($im, 211, 243, 107);
        $ink = imagecolorallocate($im, 26, 37, 62);
        $muted = imagecolorallocate($im, 103, 116, 140);
        $light = imagecolorallocate($im, 245, 247, 252);
        $line = imagecolorallocate($im, 222, 228, 239);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $light);
        // Deep racing-style header and lime accent track.
        imagefilledrectangle($im, 0, 0, $w, 214, $navy);
        imagefilledrectangle($im, 0, 0, $w, 12, $lime);
        for ($i = 0; $i < 8; $i++) {
            $x = 800 + ($i * 65);
            imageline($im, $x, 12, $x + 125, 214, $navy2);
        }
        efDrawText($im, 'OFFICIAL PARTICIPANT PASS', 48, 57, 20, $lime, true);
        efDrawText($im, 'EVENTFLOW RACE SERIES', 48, 91, 13, $white, true);

        // Organization logo, with text fallback.
        $logoBoxX = 48; $logoBoxY = 115; $logoBox = 68;
        efDrawRoundedBox($im, $logoBoxX, $logoBoxY, $logoBoxX + $logoBox, $logoBoxY + $logoBox, 14, $white);
        $logoUsed = false;
        if ($logoInfo && function_exists('imagecreatefromstring')) {
            $logoIm = @imagecreatefromstring($logoInfo['bytes']);
            if ($logoIm) {
                $lw = imagesx($logoIm); $lh = imagesy($logoIm);
                if ($lw > 0 && $lh > 0) {
                    $scale = min(58 / $lw, 58 / $lh);
                    $dw = max(1, (int)round($lw * $scale)); $dh = max(1, (int)round($lh * $scale));
                    imagecopyresampled($im, $logoIm, $logoBoxX + (int)(($logoBox - $dw)/2), $logoBoxY + (int)(($logoBox - $dh)/2), 0, 0, $dw, $dh, $lw, $lh);
                    $logoUsed = true;
                }
                imagedestroy($logoIm);
            }
        }
        if (!$logoUsed) efDrawText($im, 'EF', $logoBoxX + 16, $logoBoxY + 44, 28, $purple, true);

        $orgLines = efWrapWords((string)($event['organization_name'] ?? 'Event Organizer'), 28);
        efDrawText($im, mb_substr($orgLines[0], 0, 34), 139, 144, 18, $white, true);
        $eventNameLines = efWrapWords((string)($event['event_name'] ?? 'Marathon Event'), 37);
        efDrawText($im, mb_substr($eventNameLines[0], 0, 40), 139, 181, 24, $lime, true);
        if (isset($eventNameLines[1])) efDrawText($im, mb_substr($eventNameLines[1], 0, 40), 139, 207, 17, $lime, true);

        // Content panel.
        efDrawRoundedBox($im, 34, 238, 1166, 652, 24, $white);
        efDrawText($im, 'PARTICIPANT DETAILS', 65, 282, 15, $purple, true);
        efDrawText($im, 'FULL NAME', 65, 326, 11, $muted, true);
        $nameLines = efWrapWords((string)($person['participant_name'] ?? ''), 34);
        efDrawText($im, mb_substr($nameLines[0], 0, 40), 65, 358, 24, $ink, true);
        efDrawText($im, 'MOBILE NUMBER', 65, 402, 11, $muted, true);
        efDrawText($im, mb_substr((string)($person['phone'] ?? ''), 0, 35), 65, 429, 18, $ink, true);
        efDrawText($im, 'RACE CATEGORY', 65, 470, 11, $muted, true);
        efDrawText($im, mb_substr((string)($person['race_distance'] ?? ''), 0, 35), 65, 497, 18, $ink, true);
        efDrawText($im, 'EVENT DATE & TIME', 65, 538, 11, $muted, true);
        try { $dt = new DateTime((string)($event['event_date'] ?? ''), new DateTimeZone('Asia/Kolkata')); $dt->setTimezone(new DateTimeZone('Asia/Kolkata')); $when = $dt->format('d M Y, h:i A') . ' IST'; } catch (Throwable $e) { $when = 'To be announced'; }
        efDrawText($im, mb_substr($when, 0, 42), 65, 566, 16, $ink, true);
        efDrawText($im, 'VENUE', 65, 604, 11, $muted, true);
        $venueLines = efWrapWords((string)($event['venue'] ?? ''), 42);
        efDrawText($im, mb_substr($venueLines[0], 0, 45), 65, 628, 14, $ink, true);

        // QR block, generated by QRServer without credentials. If unavailable, show a clear fallback.
        efDrawRoundedBox($im, 835, 270, 1125, 575, 18, $light);
        $qrData = efFetchBinary('https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&margin=2&data=' . rawurlencode($ticketUrl), 7);
        $qrOk = false;
        if ($qrData && function_exists('imagecreatefromstring')) {
            $qrIm = @imagecreatefromstring($qrData);
            if ($qrIm) {
                imagecopyresampled($im, $qrIm, 875, 300, 0, 0, 210, 210, imagesx($qrIm), imagesy($qrIm));
                imagedestroy($qrIm); $qrOk = true;
            }
        }
        if (!$qrOk) {
            imagerectangle($im, 875, 300, 1085, 510, $line);
            efDrawText($im, 'QR CODE', 930, 400, 18, $purple, true);
            efDrawText($im, 'Use the online pass', 895, 430, 12, $muted, false);
        }
        efDrawText($im, 'SCAN AT CHECK-IN', 885, 542, 13, $ink, true);
        efDrawText($im, 'Registration number', 885, 572, 11, $muted, true);
        efDrawText($im, $code, 885, 598, 16, $purple, true);
        efDrawText($im, 'Keep this pass for race day.', 885, 626, 10, $muted, false);

        // Bottom stripe.
        imagefilledrectangle($im, 0, 682, $w, $h, $navy);
        efDrawText($im, 'POWERED BY EVENTFLOW', 42, 727, 13, $lime, true);
        efDrawText($im, 'Please present your participant pass at the event entrance.', 360, 727, 12, $white, false);
        $okPng = @imagepng($im, $pngPath, 7);
        $okJpg = @imagejpeg($im, $jpgPath, 91);
        imagedestroy($im);
        return $okPng && $okJpg && is_file($pngPath) && is_file($jpgPath);
    }
}

if (!function_exists('efBuildPdfFromJpeg')) {
    function efBuildPdfFromJpeg(string $jpegPath, string $pdfPath, int $pixelWidth = 1200, int $pixelHeight = 760): bool {
        $jpg = @file_get_contents($jpegPath);
        if (!is_string($jpg) || $jpg === '') return false;
        // One-page, standards-compliant PDF containing the pass image.
        $pageW = 595; $pageH = 842; $drawW = 535; $drawH = $drawW * $pixelHeight / $pixelWidth;
        $x = ($pageW - $drawW) / 2; $y = ($pageH - $drawH) / 2;
        $content = "q\n" . sprintf('%.2f 0 0 %.2f %.2f %.2f cm', $drawW, $drawH, $x, $y) . "\n/PassImage Do\nQ\n";
        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /XObject << /PassImage 4 0 R >> >> /Contents 5 0 R >>";
        $objects[] = "<< /Type /XObject /Subtype /Image /Width {$pixelWidth} /Height {$pixelHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpg) . " >>\nstream\n" . $jpg . "\nendstream";
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return @file_put_contents($pdfPath, $pdf) !== false;
    }
}

if (!function_exists('efBuildProfessionalEmail')) {
    function efBuildProfessionalEmail(array $event, array $person, string $code, string $ticketUrl, ?array $logoInfo, bool $withAttachments = true): string {
        $name = efMailEscape($person['participant_name'] ?? 'Participant');
        $eventName = efMailEscape($event['event_name'] ?? 'Your event');
        $orgName = efMailEscape($event['organization_name'] ?? 'Event organizer');
        $phone = efMailEscape($person['phone'] ?? 'Not provided');
        $distance = efMailEscape($person['race_distance'] ?? 'Not provided');
        $venue = efMailEscape($event['venue'] ?? 'To be announced');
        try { $date = new DateTime((string)($event['event_date'] ?? ''), new DateTimeZone('Asia/Kolkata')); $date->setTimezone(new DateTimeZone('Asia/Kolkata')); $dateText = $date->format('D, d M Y · h:i A') . ' IST'; } catch (Throwable $e) { $dateText = 'Date to be announced'; }
        $dateText = efMailEscape($dateText);
        $logoTag = '<div style="font-size:22px;font-weight:800;color:#5148d9;letter-spacing:-.4px">' . $orgName . '</div>';
        if ($logoInfo) $logoTag = '<img src="cid:organizationlogo" alt="' . $orgName . ' logo" style="max-width:92px;max-height:72px;display:block;border:0;outline:none;text-decoration:none;margin:0 auto 12px;background:#ffffff;border-radius:12px;padding:6px">' . $logoTag;
        $url = efMailEscape($ticketUrl);
        $attachmentNote = $withAttachments
            ? 'Your participant pass is attached to this email as a PDF and a PNG image.'
            : 'You can open and download your participant pass using the secure button below.';
        $attachmentList = $withAttachments
            ? '<p style="text-align:center;color:#7c859b;font-size:11px;line-height:1.7;margin:10px 0 20px">Attachments: Participant-Pass.pdf and Participant-Pass.png</p>'
            : '<p style="text-align:center;color:#7c859b;font-size:11px;line-height:1.7;margin:10px 0 20px">Your pass is available from the secure link above.</p>';
        return '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f2f4f9;font-family:Arial,Helvetica,sans-serif;color:#18223b"><div style="display:none;max-height:0;overflow:hidden;opacity:0">Your official participant pass for ' . $eventName . ' is attached.</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f4f9;padding:28px 10px"><tr><td align="center"><table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:20px;overflow:hidden"><tr><td style="background:#111a31;padding:28px 30px 24px;text-align:center;border-top:6px solid #d3f36b">' . $logoTag . '<div style="color:#d3f36b;font-size:11px;font-weight:800;letter-spacing:2px;margin-top:10px">OFFICIAL PARTICIPANT CONFIRMATION</div><h1 style="font-size:27px;line-height:1.2;color:#ffffff;margin:13px 0 8px">You’re on the start list!</h1><p style="font-size:14px;line-height:1.7;color:#d5dbec;margin:0">Your registration is confirmed.</p></td></tr><tr><td style="padding:29px 30px 10px"><p style="margin:0 0 10px;font-size:16px">Hello <strong>' . $name . '</strong>,</p><p style="margin:0;color:#66718a;font-size:14px;line-height:1.75">Thank you for registering for <strong style="color:#18223b">' . $eventName . '</strong>, organized by ' . $orgName . '. Your registration is marked <strong style="color:#168052">CONFIRMED</strong>. ' . efMailEscape($attachmentNote) . ' Online payment is not enabled on this page, so no payment was collected through this registration form.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:22px 0;background:#f7f8fc;border:1px solid #e5e9f1;border-radius:14px"><tr><td style="padding:18px 20px"><div style="font-size:10px;color:#6e7790;font-weight:800;letter-spacing:1.5px">YOUR EVENT DETAILS</div><div style="font-size:20px;font-weight:800;color:#18223b;margin:9px 0 14px">' . $eventName . '</div><div style="font-size:13px;line-height:1.9;color:#4e5b76"><strong>Date &amp; time:</strong> ' . $dateText . '<br><strong>Venue:</strong> ' . $venue . '<br><strong>Race category:</strong> ' . $distance . '<br><strong>Mobile:</strong> ' . $phone . '</div></td></tr></table><div style="font-size:10px;font-weight:800;letter-spacing:1.5px;color:#6e7790">UNIQUE REGISTRATION NUMBER</div><div style="display:inline-block;margin-top:8px;padding:12px 16px;background:#f0efff;border:1px dashed #b8b1ff;border-radius:10px;color:#453bc4;font-family:Consolas,monospace;font-size:20px;font-weight:800;letter-spacing:1px">' . efMailEscape($code) . '</div><p style="margin:20px 0 0;color:#66718a;font-size:13px;line-height:1.7">Keep your QR pass ready on race day. The organizer can scan it at the check-in desk. Please do not share your personal pass publicly.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px auto 8px"><tr><td align="center" bgcolor="#5148d9" style="border-radius:10px"><a href="' . $url . '" style="display:inline-block;padding:15px 25px;color:#ffffff;font-weight:800;font-size:14px;text-decoration:none;border-radius:10px">View / Download Participant Pass&nbsp; →</a></td></tr></table>' . $attachmentList . '</td></tr><tr><td style="background:#111a31;padding:18px 28px;text-align:center"><p style="margin:0;color:#d5dbec;font-size:11px;line-height:1.7">This is an automated registration email from ' . $orgName . '.<br><span style="color:#d3f36b;font-weight:800">Powered by EventFlow</span></p></td></tr></table><p style="max-width:600px;color:#9aa3b6;font-size:10px;line-height:1.6;text-align:center;margin:16px auto 0">If you did not register for this event, please contact the organizer.</p></td></tr></table></body></html>';
    }
}

if (!function_exists('efSendParticipantPassEmail')) {
    function efSendParticipantPassEmail(array $event, array $person, string $registrationCode): array {
        if (!function_exists('mail')) return ['sent' => false, 'attachments' => false, 'reason' => 'php_mail_disabled'];
        $to = strtolower(trim((string)($person['email'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['sent' => false, 'reason' => 'invalid_recipient'];
        $ticketUrl = 'https://event.amit-neupane.com.np/ticket.php?code=' . rawurlencode($registrationCode);
        $logoInfo = efResolveLocalLogo(isset($event['logo_path']) ? (string)$event['logo_path'] : null);
        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'eventflow-pass-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) return ['sent' => false, 'reason' => 'temp_dir_failed'];
        $pngPath = $tmpDir . DIRECTORY_SEPARATOR . 'Participant-Pass.png';
        $jpgPath = $tmpDir . DIRECTORY_SEPARATOR . 'pass.jpg';
        $pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'Participant-Pass.pdf';
        $attachments = [];
        try {
            $built = efBuildPassImage($event, $person, $registrationCode, $ticketUrl, $logoInfo, $pngPath, $jpgPath);
            if ($built && efBuildPdfFromJpeg($jpgPath, $pdfPath)) {
                $attachments[] = ['path' => $pdfPath, 'name' => 'EventFlow-Participant-Pass-' . $registrationCode . '.pdf', 'mime' => 'application/pdf'];
                $attachments[] = ['path' => $pngPath, 'name' => 'EventFlow-Participant-Pass-' . $registrationCode . '.png', 'mime' => 'image/png'];
            }
            $html = efBuildProfessionalEmail($event, $person, $registrationCode, $ticketUrl, $logoInfo, count($attachments) === 2);
            $mixed = 'efm_' . bin2hex(random_bytes(12));
            $related = 'efr_' . bin2hex(random_bytes(12));
            $subjectText = 'Registration received: ' . (string)($event['event_name'] ?? 'Your event') . ' — ' . $registrationCode;
            $subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
            $fromAddress = 'no-reply@event.amit-neupane.com.np';
            $fromName = 'EventFlow';
            $headers = [
                'MIME-Version: 1.0',
                'From: ' . $fromName . ' <' . $fromAddress . '>',
                'Reply-To: ' . $fromAddress,
                'X-Mailer: EventFlow Registration',
                'Content-Type: multipart/mixed; boundary="' . $mixed . '"'
            ];
            $body = '--' . $mixed . "\r\nContent-Type: multipart/related; boundary=\"{$related}\"\r\n\r\n";
            $body .= '--' . $related . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
            if ($logoInfo) {
                $ext = $logoInfo['mime'] === 'image/jpeg' ? 'jpg' : ($logoInfo['mime'] === 'image/gif' ? 'gif' : ($logoInfo['mime'] === 'image/webp' ? 'webp' : 'png'));
                $body .= '--' . $related . "\r\nContent-Type: {$logoInfo['mime']}; name=\"organization-logo.{$ext}\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <organizationlogo>\r\nContent-Disposition: inline; filename=\"organization-logo.{$ext}\"\r\n\r\n" . chunk_split(base64_encode($logoInfo['bytes']), 76, "\r\n") . "\r\n";
            }
            $body .= '--' . $related . "--\r\n";
            foreach ($attachments as $att) {
                $bytes = @file_get_contents($att['path']);
                if (!is_string($bytes)) continue;
                $body .= '--' . $mixed . "\r\nContent-Type: {$att['mime']}; name=\"{$att['name']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$att['name']}\"\r\n\r\n" . chunk_split(base64_encode($bytes), 76, "\r\n") . "\r\n";
            }
            $body .= '--' . $mixed . "--\r\n";
            $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
            if (!$attachments) return ['sent' => (bool)$sent, 'attachments' => false, 'reason' => $sent ? 'gd_or_pdf_unavailable' : 'mail_failed'];
            return ['sent' => (bool)$sent, 'attachments' => true, 'reason' => $sent ? 'accepted_by_mail_server' : 'mail_failed'];
        } catch (Throwable $e) {
            error_log('EventFlow participant email error: ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'exception'];
        } finally {
            foreach ([$pngPath, $jpgPath, $pdfPath] as $file) if (is_file($file)) @unlink($file);
            if (is_dir($tmpDir)) @rmdir($tmpDir);
        }
    }
}
