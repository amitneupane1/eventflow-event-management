<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['organization_id'])) {
    header('Location: /login.php');
    exit;
}

$organizationId = (int) $_SESSION['organization_id'];
const EVENTFLOW_PUBLIC_BASE = 'https://event.amit-neupane.com.np';

function eh($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cleanCustomFields($incoming): array
{
    if (!is_array($incoming)) {
        return [];
    }

    $result = [];
    $allowedTypes = ['text', 'number', 'textarea', 'select'];

    foreach (array_slice($incoming, 0, 8, true) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (mb_strlen($label) > 80) {
            throw new RuntimeException('Custom question labels must be 80 characters or fewer.');
        }

        $type = (string) ($item['type'] ?? 'text');
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $field = [
            'label' => $label,
            'type' => $type,
            'required' => !empty($item['required']),
            'options' => []
        ];

        if ($type === 'select') {
            $rawOptions = trim((string) ($item['options'] ?? ''));
            $options = array_values(array_unique(array_filter(array_map(
                static fn($value) => trim((string) $value),
                explode(',', $rawOptions)
            ), static fn($value) => $value !== '')));
            if (count($options) < 2 || count($options) > 20) {
                throw new RuntimeException('Each dropdown question needs 2 to 20 comma-separated options.');
            }
            foreach ($options as $option) {
                if (mb_strlen($option) > 70) {
                    throw new RuntimeException('Dropdown options must be 70 characters or fewer.');
                }
            }
            $field['options'] = $options;
        }

        $result[] = $field;
    }

    if (count($result) > 8) {
        throw new RuntimeException('You can add up to 8 custom questions per event.');
    }
    return $result;
}

try {
    $orgStmt = $pdo->prepare(
        'SELECT id, organization_name, logo_path, subscription_status FROM organizations WHERE id = :id LIMIT 1'
    );
    $orgStmt->execute([':id' => $organizationId]);
    $organization = $orgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$organization) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('EventFlow create-event organization lookup: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your organization. Please try again later.');
}

if (empty($_SESSION['event_form_csrf'])) {
    $_SESSION['event_form_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$success = $_SESSION['event_flash'] ?? '';
$createdSlug = $_SESSION['event_created_slug'] ?? '';
unset($_SESSION['event_flash'], $_SESSION['event_created_slug']);

$eventName = '';
$description = '';
$eventDateInput = '';
$venue = '';
$ticketPrice = '0.00';
$capacity = '';
$distances = '3K Fun Run, 5K, 10K, Half Marathon 21K, Full Marathon 42K';
$minAge = '';
$maxAge = '';
$status = 'published';
$showFields = [
    'age' => false,
    'gender' => false,
    'city' => false,
    'emergency_contact_name' => false,
    'emergency_contact_phone' => false,
    'tshirt_size' => false
];
$customFields = [];
$landingTemplate = 'track';
$landingCustomLayout = 'track';
$landingPrimaryColor = '#d9ff45';
$landingAccentColor = '#ff6e61';
$landingBackgroundColor = '#f7f6f1';
$templatePalettes = [
    'track' => ['primary' => '#d9ff45', 'accent' => '#ff6e61', 'background' => '#f7f6f1'],
    'city'  => ['primary' => '#72e4ff', 'accent' => '#b18cff', 'background' => '#f5f5fc'],
    'clean' => ['primary' => '#ff743b', 'accent' => '#2867e8', 'background' => '#fffaf4'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventName = trim((string) ($_POST['event_name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $eventDateInput = trim((string) ($_POST['event_date'] ?? ''));
    $venue = trim((string) ($_POST['venue'] ?? ''));
    $ticketPrice = trim((string) ($_POST['ticket_price'] ?? '0'));
    $capacity = trim((string) ($_POST['capacity'] ?? ''));
    $distances = trim((string) ($_POST['distances'] ?? ''));
    $minAge = trim((string) ($_POST['min_age'] ?? ''));
    $maxAge = trim((string) ($_POST['max_age'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'published');
    $landingTemplate = strtolower(trim((string) ($_POST['landing_template'] ?? 'track')));
    $landingCustomLayout = strtolower(trim((string) ($_POST['landing_custom_layout'] ?? 'track')));
    $landingPrimaryColor = strtolower(trim((string) ($_POST['landing_primary_color'] ?? '#d9ff45')));
    $landingAccentColor = strtolower(trim((string) ($_POST['landing_accent_color'] ?? '#ff6e61')));
    $landingBackgroundColor = strtolower(trim((string) ($_POST['landing_background_color'] ?? '#f7f6f1')));
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (!in_array($landingTemplate, ['track', 'city', 'clean', 'custom'], true)) {
        $landingTemplate = 'track';
    }
    if (!in_array($landingCustomLayout, ['track', 'city', 'clean'], true)) {
        $landingCustomLayout = 'track';
    }
    if ($landingTemplate !== 'custom') {
        $landingCustomLayout = $landingTemplate;
        $landingPrimaryColor = $templatePalettes[$landingTemplate]['primary'];
        $landingAccentColor = $templatePalettes[$landingTemplate]['accent'];
        $landingBackgroundColor = $templatePalettes[$landingTemplate]['background'];
    } else {
        foreach (['landingPrimaryColor', 'landingAccentColor', 'landingBackgroundColor'] as $colorVar) {
            if (!preg_match('/^#[0-9a-f]{6}$/i', $$colorVar)) {
                $message = 'Please choose valid custom design colours.';
                $$colorVar = '#d9ff45';
            }
        }
    }

    foreach ($showFields as $fieldKey => $_) {
        $showFields[$fieldKey] = isset($_POST['show_fields'][$fieldKey]) && $_POST['show_fields'][$fieldKey] === '1';
    }

    try {
        $customFields = cleanCustomFields($_POST['custom_fields'] ?? []);
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    $distanceList = array_values(array_unique(array_filter(array_map(
        static fn($item) => trim((string) $item),
        explode(',', $distances)
    ), static fn($item) => $item !== '')));
    $distances = implode(', ', $distanceList);

    if ($message !== '') {
        // A custom-field validation error was already recorded above.
    } elseif (!hash_equals($_SESSION['event_form_csrf'], $csrf)) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif ($message === '' && ($eventName === '' || mb_strlen($eventName) > 200)) {
        $message = 'Marathon name is required and must be within 200 characters.';
    } elseif ($message === '' && mb_strlen($description) > 60000) {
        $message = 'Description is too long. Please shorten it.';
    } elseif ($message === '' && ($venue === '' || mb_strlen($venue) > 255)) {
        $message = 'Start location is required and must be within 255 characters.';
    } elseif ($message === '' && (count($distanceList) < 1 || mb_strlen($distances) > 255 || count($distanceList) > 12)) {
        $message = 'Add between 1 and 12 race categories, within 255 combined characters.';
    } elseif ($message === '' && (($minAge === '') xor ($maxAge === ''))) {
        $message = 'Enter both minimum and maximum age, or leave both blank.';
    } else {
        $dateObject = DateTime::createFromFormat('!Y-m-d\TH:i', $eventDateInput);
        $dateErrors = DateTime::getLastErrors();
        $validDate = $dateObject !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $dateObject->format('Y-m-d\TH:i') === $eventDateInput;

        $priceValid = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $ticketPrice) === 1;
        $capacityValue = filter_var($capacity, FILTER_VALIDATE_INT);
        $minAgeValue = $minAge === '' ? null : filter_var($minAge, FILTER_VALIDATE_INT);
        $maxAgeValue = $maxAge === '' ? null : filter_var($maxAge, FILTER_VALIDATE_INT);

        if ($message === '' && !$validDate) {
            $message = 'Please enter a valid event date and start time.';
        } elseif ($message === '' && (!$priceValid || (float) $ticketPrice < 0 || (float) $ticketPrice > 99999999.99)) {
            $message = 'Enter a valid registration fee with up to 2 decimal places.';
        } elseif ($message === '' && ($capacityValue === false || $capacityValue < 1 || $capacityValue > 4294967295)) {
            $message = 'Maximum participants must be a whole number greater than zero.';
        } elseif ($message === '' && $minAge !== '' && ($minAgeValue === false || $minAgeValue < 1 || $minAgeValue > 120)) {
            $message = 'Minimum age must be between 1 and 120.';
        } elseif ($message === '' && $maxAge !== '' && ($maxAgeValue === false || $maxAgeValue < 1 || $maxAgeValue > 120)) {
            $message = 'Maximum age must be between 1 and 120.';
        } elseif ($message === '' && $minAgeValue !== null && $maxAgeValue !== null && $minAgeValue > $maxAgeValue) {
            $message = 'Minimum age cannot be greater than maximum age.';
        } elseif ($message === '' && !in_array($status, ['draft', 'published'], true)) {
            $message = 'Please select a valid event status.';
        } else {
            if ($minAgeValue !== null || $maxAgeValue !== null) {
                // If an age range exists, the landing page must collect age.
                $showFields['age'] = true;
            }

            try {
                if (empty($organization['logo_path'])) {
                    throw new RuntimeException('Please add your organization logo in the Organization Profile first. The logo on your profile is used on every event landing page.');
                }

                $baseSlug = strtolower($eventName);
                $baseSlug = preg_replace('/[^a-z0-9]+/', '-', $baseSlug) ?? '';
                $baseSlug = trim($baseSlug, '-');
                if ($baseSlug === '') {
                    $baseSlug = 'marathon';
                }
                $baseSlug = substr($baseSlug, 0, 120) . '-' . bin2hex(random_bytes(4));

                $registrationConfig = [
                    'age' => $showFields['age'],
                    'gender' => $showFields['gender'],
                    'city' => $showFields['city'],
                    'emergency_contact_name' => $showFields['emergency_contact_name'],
                    'emergency_contact_phone' => $showFields['emergency_contact_phone'],
                    'tshirt_size' => $showFields['tshirt_size'],
                    'custom' => $customFields
                ];

                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "INSERT INTO events
                        (organization_id, event_name, description, event_date, venue,
                         ticket_price, capacity, status, slug, banner_path, distances,
                         min_age, max_age, registration_fields,
                         landing_template, landing_primary_color, landing_accent_color,
                         landing_background_color, landing_custom_layout)
                     VALUES
                        (:organization_id, :event_name, :description, :event_date, :venue,
                         :ticket_price, :capacity, :status, :slug, NULL, :distances,
                         :min_age, :max_age, :registration_fields,
                         :landing_template, :landing_primary_color, :landing_accent_color,
                         :landing_background_color, :landing_custom_layout)"
                );
                $stmt->execute([
                    ':organization_id' => $organizationId,
                    ':event_name' => $eventName,
                    ':description' => $description !== '' ? $description : null,
                    ':event_date' => $dateObject->format('Y-m-d H:i:s'),
                    ':venue' => $venue,
                    ':ticket_price' => number_format((float) $ticketPrice, 2, '.', ''),
                    ':capacity' => $capacityValue,
                    ':status' => $status,
                    ':slug' => $baseSlug,
                    ':distances' => $distances,
                    ':min_age' => $minAgeValue,
                    ':max_age' => $maxAgeValue,
                    ':registration_fields' => json_encode($registrationConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':landing_template' => $landingTemplate,
                    ':landing_primary_color' => $landingPrimaryColor,
                    ':landing_accent_color' => $landingAccentColor,
                    ':landing_background_color' => $landingBackgroundColor,
                    ':landing_custom_layout' => $landingCustomLayout
                ]);
                $pdo->commit();

                $_SESSION['event_flash'] = 'Marathon event created successfully. Your shareable landing page link is ready below.';
                $_SESSION['event_created_slug'] = $baseSlug;
                $_SESSION['event_form_csrf'] = bin2hex(random_bytes(32));
                header('Location: /create-event.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('EventFlow marathon creation error: ' . $e->getMessage());
                $message = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to create the marathon. Please confirm the database columns were added and try again.';
            }
        }
    }
}

$shareUrl = $createdSlug !== '' ? EVENTFLOW_PUBLIC_BASE . '/marathon.php?slug=' . rawurlencode($createdSlug) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Marathon | EventFlow</title>
<style>
:root{--p:#5b55ee;--ink:#18213a;--muted:#6e7890;--line:#e5e9f2;--bg:#f5f7fc;--green:#126b4c;--red:#a82d40}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}a{color:var(--p);text-decoration:none}input,textarea,select,button{font:inherit}.top{height:72px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 max(4%,calc((100% - 1120px)/2));gap:12px}.brand{font-size:22px;font-weight:850;color:var(--ink);letter-spacing:-.7px}.brand b{color:var(--p)}.top a:last-child{font-size:12px;font-weight:800}.wrap{width:min(1080px,92%);margin:30px auto 48px}.intro{display:flex;justify-content:space-between;align-items:flex-end;gap:15px;margin-bottom:23px}.eyebrow{font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:1.4px;color:var(--p)}h1{font-size:clamp(27px,4vw,37px);letter-spacing:-1.2px;margin:10px 0 7px}.intro p{color:var(--muted);line-height:1.7;margin:0;max-width:700px}.tag{border:1px solid #dcdafe;background:#f0efff;color:#4d45ce;border-radius:11px;padding:11px 13px;font-size:11px;font-weight:800;white-space:nowrap}.card{border:1px solid var(--line);background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 16px 45px #1b275008}.head{padding:22px 27px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px}.head h2{margin:0;font-size:17px}.head p{margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.6}.body{padding:27px}.section{padding-bottom:24px;margin-bottom:24px;border-bottom:1px solid #eef0f6}.section:last-child{border:0;padding:0;margin:0}.secthead{display:flex;gap:12px;align-items:center;margin-bottom:17px}.ico{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:#f0efff;color:var(--p);font-size:17px}.secthead h3{font-size:14px;margin:0 0 4px}.secthead p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 20px}.field{min-width:0}.full{grid-column:1/-1}label{display:block;margin-bottom:8px;font-size:12px;font-weight:800;color:#34405a}.opt{font-size:10px;color:#9098a9;font-weight:500}.control{width:100%;min-height:46px;padding:12px;border:1px solid #dfe4ee;border-radius:10px;background:#fbfcff;outline:none;color:var(--ink)}textarea.control{min-height:110px;resize:vertical;line-height:1.65}.control:focus{border-color:var(--p);background:white;box-shadow:0 0 0 4px #5b55ee14}.hint{margin-top:7px;color:#8992a4;font-size:10px;line-height:1.6}.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.distbox{border:1px solid #dfe4ee;border-radius:12px;padding:12px;background:#fbfcff}.chips{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px}.chip{display:inline-flex;align-items:center;gap:8px;padding:7px 9px;border-radius:8px;background:#efefff;color:#4f46c8;font-size:11px;font-weight:800}.chip button{width:17px;height:17px;border:0;border-radius:50%;background:#dad7ff;color:#463fbd;cursor:pointer;font-weight:900;padding:0}.addrow{display:flex;gap:8px}.addrow input{flex:1;min-width:0;border:1px solid #dfe4ee;background:white;border-radius:9px;padding:10px}.small{border:1px solid #d7d3ff;border-radius:9px;background:#efefff;color:#4d45ce;font-weight:800;font-size:11px;padding:0 12px;cursor:pointer}.ages{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px;border:1px solid var(--line);border-radius:12px;background:#fafbff}.ages>p{grid-column:1/-1;font-size:11px;font-weight:850;letter-spacing:.5px;text-transform:uppercase;color:#46516a;margin:0}.age-summary{grid-column:1/-1;background:#efefff;color:#4d45ce;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:750}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.check{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--line);border-radius:11px;background:#fbfcff}.check input{accent-color:var(--p);width:16px;height:16px;margin-top:1px;flex:0 0 auto}.check strong{display:block;font-size:11px}.check small{display:block;margin-top:4px;color:var(--muted);font-size:10px;line-height:1.5}.custom-card{border:1px solid var(--line);border-radius:12px;padding:13px;margin-top:10px;background:#fcfcff}.custom-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px}.custom-top strong{font-size:11px}.remove{border:0;background:#fff0f2;color:#ad2d43;border-radius:7px;padding:6px 8px;font-size:10px;font-weight:800;cursor:pointer}.custom-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}.custom-grid .field label{font-size:10px}.reqcheck{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:650;margin-top:11px}.reqcheck input{accent-color:var(--p)}.alert{margin:18px 27px 0;padding:14px 15px;border-radius:11px;font-size:12px;line-height:1.7;overflow-wrap:anywhere}.error{background:#fff1f3;color:#a5263a;border:1px solid #f2d0d6}.success{background:#ebf9f1;color:#126b4b;border:1px solid #caead8}.sharebox{margin-top:12px;padding:13px;border:1px solid #bde7ce;border-radius:12px;background:#fff}.sharelabel{font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.8px}.shareurl{display:block;margin:8px 0;overflow-wrap:anywhere;color:#16583f;font-family:ui-monospace,Consolas,monospace;font-size:12px}.shareactions{display:flex;gap:9px;flex-wrap:wrap}.shareactions a,.shareactions button{display:inline-flex;align-items:center;justify-content:center;border:1px solid #cfe7d9;background:white;color:#176347;border-radius:8px;padding:8px 11px;font-size:11px;font-weight:800;cursor:pointer}.footactions{padding:19px 27px;background:#fbfcff;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.note{color:#858ea1;font-size:10px;line-height:1.6;max-width:520px}.buttons{display:flex;gap:9px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:43px;padding:0 16px;border-radius:10px;border:1px solid #e0e4ee;background:#fff;font-size:12px;font-weight:850}.btn.primary{background:linear-gradient(110deg,#6e65ff,#5046df);color:#fff;border-color:transparent}.logo-note{padding:12px 13px;background:#f8f8ff;border:1px solid #e3e1ff;border-radius:11px;font-size:11px;color:#59647d;line-height:1.65}.logo-note a{font-weight:800}.fineprint{text-align:center;margin-top:18px;color:#9299aa;font-size:10px}@media(max-width:700px){.wrap{margin-top:22px}.intro{align-items:flex-start;flex-direction:column}.tag{white-space:normal}.body{padding:18px}.head,.footactions{padding:18px}.grid,.row,.checks{grid-template-columns:1fr}.full{grid-column:auto}.ages{grid-template-columns:1fr}.ages>p,.age-summary{grid-column:auto}.alert{margin-left:18px;margin-right:18px}.footactions{align-items:flex-start;flex-direction:column;width:100%}.buttons{width:100%}.buttons .btn{flex:1}.custom-grid{grid-template-columns:1fr}}@media(max-width:430px){.top{height:64px}.brand{font-size:19px}.wrap{width:94%}.head{padding:15px}.body{padding:14px}.alert{margin-left:14px;margin-right:14px}.footactions{padding:14px}.addrow{flex-direction:column}.small{min-height:40px}.shareactions{flex-direction:column}.shareactions a,.shareactions button{width:100%}}

/* Landing page template selector */
.template-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:18px}
.landing-template-card{min-width:0;border:1px solid #e2e6ef;border-radius:16px;background:#fff;overflow:hidden;transition:border-color .2s,box-shadow .2s,transform .2s}
.landing-template-card.selected{border-color:#6258ed;box-shadow:0 0 0 3px #6258ed18,0 12px 24px #1a22500b;transform:translateY(-2px)}
.template-choice{display:block;cursor:pointer;margin:0;padding:0}
.template-choice input{position:absolute;opacity:0;pointer-events:none}
.template-art{height:145px;position:relative;overflow:hidden;padding:13px;isolation:isolate}
.template-art:after{content:"";position:absolute;inset:0;z-index:-1;opacity:.27;background-image:linear-gradient(rgba(255,255,255,.15) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.15) 1px,transparent 1px);background-size:20px 20px}
.template-art .mini-brand{display:flex;align-items:center;gap:6px;color:#fff;font-size:8px;font-weight:900;letter-spacing:.3px}
.template-art .mini-logo{width:16px;height:16px;border-radius:5px;background:var(--mini-accent);display:inline-block}
.template-art .mini-kicker{font-size:6px;color:var(--mini-primary);letter-spacing:1.3px;font-weight:900;margin-top:18px}
.template-art .mini-heading{max-width:180px;margin-top:6px;color:#fff;font-size:21px;font-weight:950;letter-spacing:-.8px;line-height:.95}
.template-art .mini-heading em{font-style:normal;color:var(--mini-primary)}
.template-art .mini-line{width:58%;height:4px;border-radius:8px;background:#fff;opacity:.32;margin-top:8px}
.template-art .mini-cta{display:inline-block;margin-top:10px;border-radius:20px;padding:5px 9px;background:var(--mini-primary);color:var(--mini-ink);font-size:7px;font-weight:950}
.art-track{--mini-primary:#d9ff45;--mini-accent:#ff6e61;--mini-ink:#111925;background:linear-gradient(135deg,#111925,#263b4d)}
.art-track:before{content:"";position:absolute;right:-18px;bottom:-48px;width:155px;height:102px;border:12px solid #d9ff454d;border-radius:50%;transform:rotate(-25deg)}
.art-city{--mini-primary:#72e4ff;--mini-accent:#b18cff;--mini-ink:#10152b;background:linear-gradient(135deg,#10152b,#30245e)}
.art-city:before{content:"";position:absolute;right:0;bottom:0;width:48%;height:65%;background:repeating-linear-gradient(90deg,#ffffff12 0 11px,transparent 11px 18px);clip-path:polygon(0 100%,0 42%,12% 42%,12% 18%,29% 18%,29% 58%,45% 58%,45% 0,64% 0,64% 36%,80% 36%,80% 22%,100% 22%,100% 100%)}
.art-clean{--mini-primary:#ff743b;--mini-accent:#2867e8;--mini-ink:#fff;background:linear-gradient(135deg,#fff5e9,#f4f7ff)}
.art-clean .mini-brand,.art-clean .mini-heading{color:#1d2a3b}.art-clean .mini-line{background:#1d2a3b;opacity:.19}.art-clean .mini-kicker{color:#d94e1c}.art-clean .mini-cta{color:#fff}
.template-card-info{padding:13px 13px 12px}
.template-card-title{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:900;margin:0 0 5px}
.template-card-desc{margin:0;color:#737d91;font-size:10px;line-height:1.55;min-height:32px}
.template-radio-mark{height:16px;width:16px;flex:0 0 16px;border:1.5px solid #c4cada;border-radius:50%;display:inline-grid;place-items:center}
.landing-template-card.selected .template-radio-mark{border-color:#6258ed;background:#6258ed;box-shadow:inset 0 0 0 4px white}
.template-actions{display:flex;gap:7px;margin-top:12px}
.template-actions button{border:1px solid #dcdfee;background:#fff;color:#4b54a4;border-radius:8px;padding:8px 9px;font-size:10px;font-weight:850;cursor:pointer}
.template-actions .select-template{background:#f0efff;border-color:#dcd8ff;color:#5146d9}
.custom-entry{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:14px 16px;margin-top:14px;border:1px solid #e2e6ef;border-radius:14px;background:linear-gradient(120deg,#fff,#faf9ff);transition:border-color .2s,box-shadow .2s}
.custom-entry.selected{border-color:#6258ed;box-shadow:0 0 0 3px #6258ed18}
.custom-entry-choice{display:flex;align-items:center;gap:10px;min-width:0;margin:0;cursor:pointer}
.custom-entry-copy strong{display:block;font-size:12px}.custom-entry-copy small{display:block;margin-top:4px;max-width:460px;color:#737d91;font-size:10px;line-height:1.55;font-weight:500}
.custom-entry .template-actions{margin:0;flex:0 0 auto}
.custom-design-panel{margin-top:16px;padding:17px;border:1px dashed #b7b3f5;border-radius:14px;background:#fafaff}
.custom-design-panel[hidden]{display:none}
.custom-design-title{font-size:13px;font-weight:900;margin-bottom:5px}
.custom-design-help{font-size:11px;color:#727b91;line-height:1.6;margin:0 0 15px}
.custom-design-grid{display:grid;grid-template-columns:1.2fr repeat(3,minmax(0,1fr));gap:12px}
.custom-design-grid label{font-size:10px}
.custom-design-grid input[type=color]{width:100%;height:43px;padding:4px;border:1px solid #dfe4ee;border-radius:9px;background:white;cursor:pointer}
.custom-preview-line{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:12px}
.custom-preview-line .hint{margin:0}
.template-modal[hidden]{display:none}
.template-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:18px}
.template-modal-backdrop{position:absolute;inset:0;background:rgba(13,20,42,.67);backdrop-filter:blur(6px)}
.template-modal-dialog{position:relative;width:min(760px,100%);max-height:94vh;overflow:auto;background:white;border-radius:20px;box-shadow:0 30px 100px #0004;padding:22px}
.template-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:16px}
.template-modal-head h3{margin:0;font-size:18px}
.template-modal-head p{margin:5px 0 0;color:#737d91;font-size:11px;line-height:1.6}
.modal-close{border:0;width:35px;height:35px;border-radius:10px;background:#f1f2f7;font-size:19px;cursor:pointer}
.preview-window{--pv-primary:#d9ff45;--pv-accent:#ff6e61;--pv-bg:#f7f6f1;overflow:hidden;border:1px solid #e7eaf1;border-radius:14px;background:var(--pv-bg);color:#17202b}
.preview-nav{display:flex;align-items:center;gap:8px;padding:13px 17px;background:rgba(255,255,255,.88);font-weight:850;font-size:10px}
.preview-logo{width:25px;height:25px;display:grid;place-items:center;border-radius:7px;background:var(--pv-primary);color:#101925;font-weight:950}
.preview-hero{padding:24px 22px 25px;position:relative;overflow:hidden;background:linear-gradient(130deg,#111925,#25374b);color:#fff;isolation:isolate}
.preview-hero:after{content:"";position:absolute;right:-40px;bottom:-85px;width:210px;height:150px;border:18px solid color-mix(in srgb,var(--pv-primary),transparent 65%);border-radius:50%;z-index:-1;transform:rotate(-22deg)}
.preview-hero .pv-kicker{font-size:8px;font-weight:900;letter-spacing:1.6px;color:var(--pv-primary)}
.preview-hero h4{font-size:clamp(28px,5vw,48px);line-height:.95;letter-spacing:-1.8px;margin:12px 0 8px;font-weight:950;max-width:480px}
.preview-hero p{max-width:420px;font-size:10px;line-height:1.7;color:#c5cedb;margin:0}
.preview-pill{display:inline-block;margin-top:15px;padding:9px 13px;border-radius:30px;background:var(--pv-primary);color:#111925;font-size:9px;font-weight:950}
.preview-details{padding:15px;display:grid;grid-template-columns:repeat(3,1fr);gap:9px;background:var(--pv-bg)}
.preview-detail{padding:12px;border-radius:10px;background:rgba(255,255,255,.88);border:1px solid #e6e8ef;font-size:9px;font-weight:850}
.preview-detail small{display:block;font-size:7px;font-weight:600;color:#7c8596;margin-bottom:5px}
.preview-window[data-theme="city"] .preview-hero{background:linear-gradient(130deg,#10152b,#362362)}
.preview-window[data-theme="city"] .preview-hero:after{border-color:color-mix(in srgb,var(--pv-primary),transparent 62%);border-style:dashed}
.preview-window[data-theme="clean"] .preview-hero{background:linear-gradient(130deg,var(--pv-bg),#fff 68%,#eef4ff);color:#192638;border-bottom:1px solid #e4e6eb}
.preview-window[data-theme="clean"] .preview-hero p{color:#687386}
.preview-window[data-theme="clean"] .preview-details{background:var(--pv-bg)}
.preview-window[data-theme="custom"] .preview-hero{background:linear-gradient(130deg,#111925,#26374b)}
.template-modal-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;margin-top:17px}
.template-modal-actions button{padding:11px 15px;border:1px solid #dfe3ed;border-radius:10px;background:#fff;font-size:11px;font-weight:850;cursor:pointer}
.template-modal-actions .use-template{color:#fff;background:#5b55ee;border-color:#5b55ee}
@media(max-width:800px){.template-grid{grid-template-columns:1fr}.template-art{height:160px}.custom-design-grid{grid-template-columns:1fr 1fr}.template-card-desc{min-height:0}.custom-entry{align-items:flex-start;flex-direction:column}.custom-entry .template-actions{width:100%}.custom-entry .template-actions button{flex:1}}
@media(max-width:520px){.custom-design-grid{grid-template-columns:1fr 1fr}.template-modal-dialog{padding:15px;border-radius:16px}.preview-hero{padding:19px 17px}.preview-details{grid-template-columns:1fr 1fr;padding:10px}.template-modal-actions button{flex:1}}

</style>
</head>
<body>
<header class="top"><a class="brand" href="/dashboard.php">Event<b>Flow</b></a><a href="/my-events.php">My Events →</a></header>
<main class="wrap">
    <div class="intro"><div><div class="eyebrow">Organization workspace</div><h1>Create a marathon event</h1><p>Make a unique landing page for each race. Your saved organization logo is used automatically—no event banner is needed. Add the questions you want runners to answer when they click Join Now.</p></div><div class="tag">✦ &nbsp; Landing page + registration</div></div>
    <section class="card">
        <div class="head"><div><h2>Marathon details</h2><p>Required fields are marked with *.</p></div></div>
        <?php if ($message !== ''): ?><div class="alert error" role="alert"><strong>Please check:</strong><br><?= eh($message) ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="alert success" role="status"><strong><?= eh($success) ?></strong><?php if ($createdSlug !== ''): ?><div class="sharebox"><div class="sharelabel">Share this marathon landing page</div><a class="shareurl" id="shareUrl" href="<?= eh($shareUrl) ?>"><?= eh($shareUrl) ?></a><div class="shareactions"><a href="<?= eh($shareUrl) ?>" target="_blank" rel="noopener">Open landing page ↗</a><button type="button" id="copyUrl">Copy event link</button><a href="/my-events.php">Manage all events</a></div></div><?php endif; ?></div><?php endif; ?>
        <form method="post" action="" id="eventForm">
            <input type="hidden" name="csrf_token" value="<?= eh($_SESSION['event_form_csrf']) ?>">
            <div class="body">
                <section class="section"><div class="secthead"><span class="ico">✎</span><span><h3>Event details</h3><p>These details will be displayed on the public event landing page.</p></span></div><div class="grid">
                    <div class="field full"><label for="event_name">Marathon / event name *</label><input class="control" id="event_name" name="event_name" maxlength="200" value="<?= eh($eventName) ?>" placeholder="e.g. Bengaluru City Marathon 2027" required></div>
                    <div class="field full"><label for="description">About this event <span class="opt">Optional</span></label><textarea class="control" id="description" name="description" maxlength="60000" placeholder="Tell participants about the race, route, facilities and rules..."><?= eh($description) ?></textarea></div>
                    <div class="field"><label for="event_date">Date and start time *</label><input class="control" id="event_date" name="event_date" type="datetime-local" value="<?= eh($eventDateInput) ?>" required></div>
                    <div class="field"><label for="venue">Start location / venue *</label><input class="control" id="venue" name="venue" maxlength="255" value="<?= eh($venue) ?>" placeholder="e.g. Cubbon Park, Bengaluru" required></div>
                    <div class="field"><label for="ticket_price">Registration fee *</label><input class="control" id="ticket_price" name="ticket_price" type="number" min="0" max="99999999.99" step="0.01" value="<?= eh($ticketPrice) ?>" required><div class="hint">Enter 0 for a free event. Payment collection is not enabled yet.</div></div>
                    <div class="field"><label for="capacity">Maximum participants *</label><input class="control" id="capacity" name="capacity" type="number" min="1" max="4294967295" step="1" value="<?= eh($capacity) ?>" placeholder="e.g. 1500" required></div>
                </div></section>
                <section class="section"><div class="secthead"><span class="ico">🏃</span><span><h3>Race categories and age range</h3><p>Choose the race categories and set age eligibility, if needed.</p></span></div><div class="grid">
                    <div class="field full"><label for="distanceNew">Race distances / categories *</label><div class="distbox"><div class="chips" id="distanceTags" aria-live="polite"></div><div class="addrow"><input id="distanceNew" maxlength="45" placeholder="Add a distance, e.g. Kids Run 2K"><button class="small" id="addDistance" type="button">+ Add category</button></div></div><input type="hidden" name="distances" id="distancesHidden" value="<?= eh($distances) ?>"><div class="hint">Add up to 12 categories. Participants choose one on the Join Now form.</div></div>
                    <div class="field full"><div class="ages"><p>Participant age eligibility</p><div class="field"><label for="min_age">Minimum age <span class="opt">Years</span></label><input class="control" id="min_age" name="min_age" type="number" min="1" max="120" value="<?= eh($minAge) ?>" placeholder="e.g. 18"></div><div class="field"><label for="max_age">Maximum age <span class="opt">Years</span></label><input class="control" id="max_age" name="max_age" type="number" min="1" max="120" value="<?= eh($maxAge) ?>" placeholder="e.g. 70"></div><div class="age-summary" id="ageSummary">Leave both blank for no age restriction.</div></div><div class="hint">If an age range is set, age is automatically added to the registration form and checked against this range.</div></div>
                </div></section>
                <section class="section"><div class="secthead"><span class="ico">◈</span><span><h3>Organization branding</h3><p>Every event automatically uses the logo and organization name saved in your Organization Profile.</p></span></div><div class="logo-note">No banner upload is required. The saved organization logo will appear at the top of each event landing page.<?php if (empty($organization['logo_path'])): ?> <strong>You need to add a logo first.</strong><?php endif; ?> <a href="/organization/">Open Organization Profile →</a></div></section>
                <section class="section" id="landingTemplates"><div class="secthead"><span class="ico">✦</span><span><h3>Choose your landing page design</h3><p>Select one of 3 ready-made marathon designs, preview it before choosing, or make your own colour variation.</p></span></div>
                    <div class="template-grid" id="templateGrid">
                        <div class="landing-template-card <?= $landingTemplate === 'track' ? 'selected' : '' ?>" data-template-card="track"><label class="template-choice"><input type="radio" name="landing_template" value="track" <?= $landingTemplate === 'track' ? 'checked' : '' ?>><div class="template-art art-track"><div class="mini-brand"><span class="mini-logo"></span><?= eh($organization['organization_name']) ?></div><div class="mini-kicker">THE START LINE IS CALLING</div><div class="mini-heading">OWN YOUR<br><em>FINISH.</em></div><div class="mini-line"></div><span class="mini-cta">JOIN THE RACE ↗</span></div><div class="template-card-info"><h4 class="template-card-title"><span class="template-radio-mark"></span>Track Bold</h4><p class="template-card-desc">Dark sports look with neon lime highlights and racing energy.</p></div></label><div class="template-actions"><button type="button" data-preview="track">Preview design ↗</button><button type="button" class="select-template" data-select="track">Use this</button></div></div>
                        <div class="landing-template-card <?= $landingTemplate === 'city' ? 'selected' : '' ?>" data-template-card="city"><label class="template-choice"><input type="radio" name="landing_template" value="city" <?= $landingTemplate === 'city' ? 'checked' : '' ?>><div class="template-art art-city"><div class="mini-brand"><span class="mini-logo"></span><?= eh($organization['organization_name']) ?></div><div class="mini-kicker">CITY LIGHTS · RACE NIGHT</div><div class="mini-heading">OWN THE<br><em>STREETS.</em></div><div class="mini-line"></div><span class="mini-cta">JOIN THE RACE ↗</span></div><div class="template-card-info"><h4 class="template-card-title"><span class="template-radio-mark"></span>City Lights</h4><p class="template-card-desc">Night-run style with electric cyan, violet and urban atmosphere.</p></div></label><div class="template-actions"><button type="button" data-preview="city">Preview design ↗</button><button type="button" class="select-template" data-select="city">Use this</button></div></div>
                        <div class="landing-template-card <?= $landingTemplate === 'clean' ? 'selected' : '' ?>" data-template-card="clean"><label class="template-choice"><input type="radio" name="landing_template" value="clean" <?= $landingTemplate === 'clean' ? 'checked' : '' ?>><div class="template-art art-clean"><div class="mini-brand"><span class="mini-logo"></span><?= eh($organization['organization_name']) ?></div><div class="mini-kicker">A FRESH START. A STRONG FINISH.</div><div class="mini-heading">YOUR NEXT<br><em>PERSONAL BEST.</em></div><div class="mini-line"></div><span class="mini-cta">REGISTER NOW ↗</span></div><div class="template-card-info"><h4 class="template-card-title"><span class="template-radio-mark"></span>Clean Race</h4><p class="template-card-desc">Bright editorial design with orange and blue accents.</p></div></label><div class="template-actions"><button type="button" data-preview="clean">Preview design ↗</button><button type="button" class="select-template" data-select="clean">Use this</button></div></div>
                    </div>
                    <div class="custom-entry <?= $landingTemplate === 'custom' ? 'selected' : '' ?>" data-template-card="custom">
                        <label class="template-choice custom-entry-choice"><input type="radio" name="landing_template" value="custom" <?= $landingTemplate === 'custom' ? 'checked' : '' ?>><span class="template-radio-mark"></span><span class="custom-entry-copy"><strong>Custom design</strong><small>Choose any base layout and adjust the highlight, accent and page background colours.</small></span></label>
                        <div class="template-actions"><button type="button" data-preview="custom">Preview custom ↗</button><button type="button" class="select-template" data-select="custom">Customize</button></div>
                    </div>
                    <div class="custom-design-panel" id="customDesignPanel" <?= $landingTemplate === 'custom' ? '' : 'hidden' ?>><div class="custom-design-title">✦ Make a custom design</div><p class="custom-design-help">Keep the layout you like and choose your own colours. Your selections will appear on this event's public landing page.</p><div class="custom-design-grid"><div class="field"><label for="landing_custom_layout">Base layout</label><select class="control" id="landing_custom_layout" name="landing_custom_layout"><option value="track" <?= $landingCustomLayout === 'track' ? 'selected' : '' ?>>Track Bold</option><option value="city" <?= $landingCustomLayout === 'city' ? 'selected' : '' ?>>City Lights</option><option value="clean" <?= $landingCustomLayout === 'clean' ? 'selected' : '' ?>>Clean Race</option></select></div><div class="field"><label for="landing_primary_color">Primary highlight</label><input id="landing_primary_color" name="landing_primary_color" type="color" value="<?= eh($landingPrimaryColor) ?>"></div><div class="field"><label for="landing_accent_color">Secondary accent</label><input id="landing_accent_color" name="landing_accent_color" type="color" value="<?= eh($landingAccentColor) ?>"></div><div class="field"><label for="landing_background_color">Page background</label><input id="landing_background_color" name="landing_background_color" type="color" value="<?= eh($landingBackgroundColor) ?>"></div></div><div class="custom-preview-line"><div class="hint">Tip: select a base layout first, then preview the colours.</div><button class="small" type="button" data-preview="custom">Preview custom design ↗</button></div></div>
                    <div class="hint" style="margin-top:13px">Each event saves its own design. You can choose a different design for every marathon. Existing events will continue using Track Bold unless you recreate them.</div>
                </section>
                <section class="section"><div class="secthead"><span class="ico">☷</span><span><h3>Customize the Join Now form</h3><p>Name, email, phone number and race distance are always included. Choose additional details you want to collect.</p></span></div>
                    <div class="checks">
                    <?php $options = ['age'=>['Age','Ask for participant age. Required when age limits are set.'],'gender'=>['Gender','Add a gender field.'],'city'=>['City','Ask which city the participant is from.'],'emergency_contact_name'=>['Emergency contact name','Collect an emergency contact person.'],'emergency_contact_phone'=>['Emergency contact phone','Collect an emergency contact phone number.'],'tshirt_size'=>['T-shirt size','Ask for T-shirt size.']]; foreach ($options as $key=>$info): ?>
                        <label class="check"><input type="checkbox" name="show_fields[<?= eh($key) ?>]" value="1" <?= !empty($showFields[$key]) ? 'checked' : '' ?> <?= $key === 'age' && ($minAge !== '' || $maxAge !== '') ? 'data-age-forced="1"' : '' ?>><span><strong><?= eh($info[0]) ?></strong><small><?= eh($info[1]) ?></small></span></label>
                    <?php endforeach; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:22px"><div><h3 style="font-size:13px;margin:0 0 5px">Custom questions</h3><div class="hint" style="margin:0">Add up to 8 questions, such as school / college name, team name or medical note.</div></div><button class="small" type="button" id="addCustom">+ Add question</button></div>
                    <div id="customFields"></div>
                    <div class="hint">For dropdown questions, separate choices with commas. Do not ask participants for sensitive information that you do not need.</div>
                </section>
                <section class="section"><div class="secthead"><span class="ico">◉</span><span><h3>Landing page status</h3><p>Published events can receive participant registrations.</p></span></div><div class="field"><label for="status">Status *</label><select class="control" id="status" name="status"><option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published — landing page and Join Now are live</option><option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft — landing page visible by link, registration closed</option></select><div class="hint">Payments are not active yet. This saves registrations but does not collect fees online.</div></div></section>
            </div>
            <div class="footactions"><div class="note">After saving, EventFlow will create a unique link for this event. You can copy it and share it with students or participants. The link will also remain available on My Events.</div><div class="buttons"><a class="btn" href="/dashboard.php">Cancel</a><button class="btn primary" type="submit">Create event & get link →</button></div></div>
        </form>
    </section>
    <p class="fineprint">© <?= date('Y') ?> EventFlow · Organization event management</p>
</main>
<div class="template-modal" id="templateModal" hidden>
    <div class="template-modal-backdrop" data-close-modal></div>
    <section class="template-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="templateModalTitle">
        <div class="template-modal-head"><div><h3 id="templateModalTitle">Landing page preview</h3><p>Preview only. Select “Use this template” to choose the design for this marathon.</p></div><button type="button" class="modal-close" data-close-modal aria-label="Close preview">×</button></div>
        <div class="preview-window" id="previewWindow" data-theme="track">
            <div class="preview-nav"><span class="preview-logo"><?= eh(function_exists('mb_substr') ? mb_strtoupper(mb_substr((string) $organization['organization_name'], 0, 1)) : strtoupper(substr((string) $organization['organization_name'], 0, 1))) ?></span><span><?= eh($organization['organization_name']) ?></span></div>
            <div class="preview-hero"><div class="pv-kicker" id="previewKicker">THE START LINE IS CALLING</div><h4 id="previewEventName">YOUR MARATHON<br>OWN YOUR FINISH.</h4><p>Every runner has a reason. Bring yours to the start line.</p><span class="preview-pill">JOIN THE RACE ↗</span></div>
            <div class="preview-details"><div class="preview-detail"><small>RACE DATE</small>Race day, 2027</div><div class="preview-detail"><small>START LOCATION</small>Your event venue</div><div class="preview-detail"><small>RACE CATEGORIES</small>5K · 10K · 21K</div></div>
        </div>
        <div class="template-modal-actions"><button type="button" data-close-modal>Close preview</button><button type="button" id="usePreviewTemplate" class="use-template">Use this template ✓</button></div>
    </section>
</div>
<script>
const distanceTags = document.getElementById('distanceTags');
const distanceNew = document.getElementById('distanceNew');
const distancesHidden = document.getElementById('distancesHidden');
let distances = (distancesHidden.value || '').split(',').map(v => v.trim()).filter(Boolean);
function renderDistances() {
    distanceTags.innerHTML = '';
    distances.forEach((distance, index) => {
        const tag = document.createElement('span'); tag.className = 'chip';
        const text = document.createElement('span'); text.textContent = distance;
        const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', 'Remove ' + distance);
        remove.addEventListener('click', () => { distances.splice(index, 1); renderDistances(); });
        tag.append(text, remove); distanceTags.appendChild(tag);
    });
    distancesHidden.value = distances.join(', ');
}
function addDistance() {
    const value = distanceNew.value.trim();
    if (!value) return;
    if (distances.length >= 12) { alert('Maximum 12 categories allowed.'); return; }
    if (value.length > 45) { alert('Category name must be 45 characters or fewer.'); return; }
    if (distances.some(d => d.toLowerCase() === value.toLowerCase())) { alert('That category is already added.'); return; }
    distances.push(value); distanceNew.value = ''; renderDistances();
}
document.getElementById('addDistance').addEventListener('click', addDistance);
distanceNew.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addDistance(); } });
renderDistances();

const minAge = document.getElementById('min_age');
const maxAge = document.getElementById('max_age');
const ageSummary = document.getElementById('ageSummary');
const ageCheckbox = document.querySelector('input[name="show_fields[age]"]');
function updateAgeSummary() {
    if (minAge.value && maxAge.value) ageSummary.textContent = 'Eligible participants: ' + minAge.value + ' to ' + maxAge.value + ' years. Age will be required.';
    else { ageSummary.textContent = 'Leave both blank for no age restriction.'; }
    if (minAge.value || maxAge.value) { ageCheckbox.checked = true; ageCheckbox.disabled = true; }
    else { ageCheckbox.disabled = false; }
}
minAge.addEventListener('input', updateAgeSummary); maxAge.addEventListener('input', updateAgeSummary); updateAgeSummary();

const customContainer = document.getElementById('customFields');
let customIndex = 0;
function addCustomField(data = {}) {
    if (customContainer.children.length >= 8) { alert('Maximum 8 custom questions allowed.'); return; }
    const i = customIndex++;
    const card = document.createElement('div'); card.className = 'custom-card';
    card.innerHTML = `<div class="custom-top"><strong>Custom question ${customContainer.children.length + 1}</strong><button type="button" class="remove">Remove</button></div>
      <div class="custom-grid"><div class="field"><label>Question label</label><input class="control custom-label" name="custom_fields[${i}][label]" maxlength="80" placeholder="e.g. School / college name"></div>
      <div class="field"><label>Answer type</label><select class="control custom-type" name="custom_fields[${i}][type]"><option value="text">Short text</option><option value="number">Number</option><option value="textarea">Long answer</option><option value="select">Dropdown</option></select></div>
      <div class="field custom-options" style="display:none;grid-column:1/-1"><label>Dropdown choices (comma-separated)</label><input class="control custom-option-values" name="custom_fields[${i}][options]" placeholder="Student, Teacher, Other"></div></div>
      <label class="reqcheck"><input type="checkbox" name="custom_fields[${i}][required]" value="1"> Make this question required</label>`;
    const type = card.querySelector('.custom-type'); const opt = card.querySelector('.custom-options');
    type.addEventListener('change', () => { opt.style.display = type.value === 'select' ? 'block' : 'none'; });
    card.querySelector('.remove').addEventListener('click', () => { card.remove(); Array.from(customContainer.children).forEach((child, idx) => child.querySelector('.custom-top strong').textContent = 'Custom question ' + (idx + 1)); });
    if (data.label) card.querySelector('.custom-label').value = data.label;
    if (data.type) { type.value = data.type; type.dispatchEvent(new Event('change')); }
    if (data.options) card.querySelector('.custom-option-values').value = Array.isArray(data.options) ? data.options.join(', ') : data.options;
    if (data.required) card.querySelector('.reqcheck input').checked = true;
    customContainer.appendChild(card);
}
document.getElementById('addCustom').addEventListener('click', () => addCustomField());
<?php foreach ($customFields as $customField): ?>
addCustomField(<?= json_encode($customField, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>);
<?php endforeach; ?>

const copyBtn = document.getElementById('copyUrl');
if (copyBtn) copyBtn.addEventListener('click', async () => {
    const text = document.getElementById('shareUrl').href;
    try { await navigator.clipboard.writeText(text); copyBtn.textContent = 'Copied!'; }
    catch (e) { window.prompt('Copy this event link:', text); }
});
</script>

<script>
(function () {
    const form = document.getElementById('eventForm');
    const radios = Array.from(document.querySelectorAll('input[name="landing_template"]'));
    const cards = Array.from(document.querySelectorAll('[data-template-card]'));
    const customPanel = document.getElementById('customDesignPanel');
    const layoutInput = document.getElementById('landing_custom_layout');
    const primaryInput = document.getElementById('landing_primary_color');
    const accentInput = document.getElementById('landing_accent_color');
    const backgroundInput = document.getElementById('landing_background_color');
    const modal = document.getElementById('templateModal');
    const previewWindow = document.getElementById('previewWindow');
    const previewTitle = document.getElementById('templateModalTitle');
    const previewKicker = document.getElementById('previewKicker');
    const previewEventName = document.getElementById('previewEventName');
    const previewUse = document.getElementById('usePreviewTemplate');
    if (!form || !modal) return;

    const palettes = {
      track: { primary:'#d9ff45', accent:'#ff6e61', background:'#f7f6f1', layout:'track', title:'Track Bold', kicker:'THE START LINE IS CALLING' },
      city: { primary:'#72e4ff', accent:'#b18cff', background:'#f5f5fc', layout:'city', title:'City Lights', kicker:'CITY LIGHTS · RACE NIGHT' },
      clean: { primary:'#ff743b', accent:'#2867e8', background:'#fffaf4', layout:'clean', title:'Clean Race', kicker:'A FRESH START. A STRONG FINISH.' }
    };
    const selected = () => document.querySelector('input[name="landing_template"]:checked')?.value || 'track';
    const activeEventName = () => (document.getElementById('event_name')?.value || '').trim() || 'YOUR MARATHON';
    let previewSelection = 'track';

    function customColors() {
      return {
        primary: primaryInput?.value || palettes.track.primary,
        accent: accentInput?.value || palettes.track.accent,
        background: backgroundInput?.value || palettes.track.background,
        layout: layoutInput?.value || 'track',
        title: 'Custom design', kicker: 'YOUR RACE. YOUR DESIGN.'
      };
    }
    function themeConfig(key) { return key === 'custom' ? customColors() : (palettes[key] || palettes.track); }
    function updateSelection() {
      const val = selected();
      cards.forEach(card => card.classList.toggle('selected', card.dataset.templateCard === val));
      customPanel.hidden = val !== 'custom';
    }
    function fillPreview(key) {
      const p = themeConfig(key);
      previewSelection = key;
      previewWindow.dataset.theme = p.layout || key;
      previewWindow.style.setProperty('--pv-primary', p.primary);
      previewWindow.style.setProperty('--pv-accent', p.accent);
      previewWindow.style.setProperty('--pv-bg', p.background);
      previewTitle.textContent = p.title + ' · Preview';
      previewKicker.textContent = p.kicker;
      const name = activeEventName();
      previewEventName.innerHTML = '';
      const first = document.createElement('span'); first.textContent = name;
      const lineBreak = document.createElement('br');
      const second = document.createElement('span'); second.textContent = key === 'city' || (key === 'custom' && p.layout === 'city') ? 'OWN THE STREETS.' : key === 'clean' || (key === 'custom' && p.layout === 'clean') ? 'YOUR PERSONAL BEST.' : 'OWN YOUR FINISH.';
      previewEventName.append(first, lineBreak, second);
      if (key === 'custom') previewWindow.dataset.theme = p.layout;
    }
    function openPreview(key) {
      fillPreview(key);
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
    }
    function closePreview() {
      modal.hidden = true;
      document.body.style.overflow = '';
    }
    radios.forEach(radio => radio.addEventListener('change', () => { updateSelection(); }));
    document.querySelectorAll('[data-select]').forEach(btn => btn.addEventListener('click', () => {
      const radio = radios.find(item => item.value === btn.dataset.select);
      if (radio) { radio.checked = true; updateSelection(); }
    }));
    document.querySelectorAll('[data-preview]').forEach(btn => btn.addEventListener('click', () => {
      const key = btn.dataset.preview || selected();
      openPreview(key);
    }));
    document.querySelectorAll('[data-close-modal]').forEach(btn => btn.addEventListener('click', closePreview));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) closePreview(); });
    previewUse.addEventListener('click', () => {
      const radio = radios.find(item => item.value === previewSelection);
      if (radio) { radio.checked = true; updateSelection(); }
      closePreview();
    });
    ['event_name','landing_custom_layout','landing_primary_color','landing_accent_color','landing_background_color'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', () => {
        if (!modal.hidden) fillPreview(previewSelection);
      });
      document.getElementById(id)?.addEventListener('change', () => {
        if (!modal.hidden) fillPreview(previewSelection);
      });
    });
    updateSelection();
})();
</script>

</body>
</html>
