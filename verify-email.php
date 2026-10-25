
<?php

require_once __DIR__ . '/config.php';

function showMessage($title, $message, $status = 200)
{
    http_response_code($status);

    echo '<!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' - EventFlow</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f4f7fb;
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                padding: 20px;
                box-sizing: border-box;
            }
            .card {
                background: white;
                max-width: 460px;
                width: 100%;
                padding: 32px;
                border-radius: 16px;
                box-sizing: border-box;
                box-shadow: 0 8px 30px rgba(0,0,0,.08);
                text-align: center;
            }
            h1 { color: #172554; font-size: 25px; }
            p { color: #475569; line-height: 1.7; }
            a {
                display: inline-block;
                margin-top: 12px;
                padding: 12px 22px;
                background: #2563eb;
                color: white;
                text-decoration: none;
                border-radius: 8px;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
            <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <a href="/login.php">Go to Login</a>
        </div>
    </body>
    </html>';

    exit;
}

$token = $_GET['token'] ?? '';

if (
    !is_string($token) ||
    !preg_match('/^[a-f0-9]{64}$/', $token)
) {
    showMessage(
        'Invalid Link',
        'This verification link is invalid or incomplete.',
        400
    );
}

$tokenHash = hash('sha256', $token);

try {
    $stmt = $pdo->prepare(
        'SELECT id, email_verification_expires_at
         FROM organizations
         WHERE email_verification_token_hash = :token_hash
         LIMIT 1'
    );

    $stmt->execute(['token_hash' => $tokenHash]);
    $organization = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$organization) {
        showMessage(
            'Invalid Link',
            'This link is invalid, already used, or no longer available.',
            400
        );
    }

    $expiresAt = strtotime(
        (string) $organization['email_verification_expires_at']
    );

    if (!$expiresAt || $expiresAt < time()) {
        showMessage(
            'Link Expired',
            'This verification link has expired. A new verification link will be needed.'
        );
    }

    $update = $pdo->prepare(
        'UPDATE organizations
         SET email_verified = 1,
             email_verification_token_hash = NULL,
             email_verification_expires_at = NULL
         WHERE id = :id
           AND email_verification_token_hash = :token_hash'
    );

    $update->execute([
        'id' => $organization['id'],
        'token_hash' => $tokenHash
    ]);

    if ($update->rowCount() !== 1) {
        showMessage(
            'Verification Failed',
            'The link could not be confirmed. Please try again.',
            400
        );
    }

    showMessage(
        'Email Verified!',
        'Your email has been verified successfully. You can now log in.'
    );

} catch (PDOException $e) {
    error_log('EventFlow email verification database error: ' . $e->getMessage());

    showMessage(
        'Something Went Wrong',
        'We could not verify your email right now. Please try again later.',
        500
    );
}
