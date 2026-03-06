<?php
declare(strict_types=1);

// ── Simple logger — writes to PHP error log (check /var/log/php*, Apache error_log, etc.) ──
function clog(string $msg): void {
    error_log('[wl-contact] ' . $msg);
}

// ── Load .env ────────────────────────────────────────────────────────────────
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        clog('.env file NOT found at ' . $envFile);
        return;
    }
    clog('.env loaded from ' . $envFile);
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key) putenv("{$key}={$value}");
    }
})();

$hcaptchaSecret = getenv('HCAPTCHA_SECRET');
$contactEmail   = getenv('CONTACT_EMAIL');
$allowedOrigin  = getenv('ALLOWED_ORIGIN');
$fromEmail      = getenv('FROM_EMAIL') ?: "noreply@{$_SERVER['HTTP_HOST']}";

clog("Config — to: {$contactEmail}, from: {$fromEmail}, origin: {$allowedOrigin}, captcha secret set: " . ($hcaptchaSecret ? 'yes' : 'NO'));

// ── CORS ─────────────────────────────────────────────────────────────────────
header("Access-Control-Allow-Origin: {$allowedOrigin}");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$name            = trim((string)($body['name']            ?? ''));
$email           = trim((string)($body['email']           ?? ''));
$subject         = trim((string)($body['subject']         ?? 'inne'));
$message         = trim((string)($body['message']         ?? ''));
$captchaResponse = trim((string)($body['captchaResponse'] ?? ''));

// ── Validate ─────────────────────────────────────────────────────────────────
if (!$name || !$email || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

if (!$captchaResponse) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing captcha']);
    exit;
}

// ── Verify hCaptcha ───────────────────────────────────────────────────────────
$ch = curl_init('https://hcaptcha.com/siteverify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => $hcaptchaSecret,
        'response' => $captchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
]);
$captchaResult = json_decode(curl_exec($ch), true);
curl_close($ch);

clog('hCaptcha result: ' . json_encode($captchaResult));

if (!($captchaResult['success'] ?? false)) {
    clog('hCaptcha FAILED — errors: ' . implode(', ', $captchaResult['error-codes'] ?? []));
    http_response_code(400);
    echo json_encode(['error' => 'Captcha verification failed']);
    exit;
}

clog('hCaptcha passed');

// ── Send email ────────────────────────────────────────────────────────────────
$safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$safeName    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
$safeEmail   = filter_var($email, FILTER_SANITIZE_EMAIL);

$emailSubject = "=?UTF-8?B?" . base64_encode("Kontakt — {$safeSubject}") . "?=";

$emailBody = implode("\n", [
    "Nowa wiadomość z formularza kontaktowego:",
    "",
    "Imię i nazwisko : {$safeName}",
    "E-mail          : {$safeEmail}",
    "Temat           : {$safeSubject}",
    "",
    "Wiadomość:",
    htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
]);

$headers = implode("\r\n", [
    "From: WL Beskidzki <{$fromEmail}>",
    "Reply-To: {$safeName} <{$safeEmail}>",
    "Content-Type: text/plain; charset=UTF-8",
    "Content-Transfer-Encoding: 8bit",
]);

clog("Calling mail() to: {$contactEmail}");
$sent = mail($contactEmail, $emailSubject, $emailBody, $headers);
clog('mail() returned: ' . ($sent ? 'TRUE' : 'FALSE'));

if (!$sent) {
    $lastError = error_get_last();
    clog('Last PHP error: ' . json_encode($lastError));
}

if ($sent) {
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send email']);
}
