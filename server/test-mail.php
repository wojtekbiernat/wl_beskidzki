<?php
/**
 * One-off mail diagnostics script.
 * Upload to your server, visit it once, then DELETE IT.
 *
 * Usage: https://korunahor.cz/wl_beskidzki/test-mail.php?key=test123
 */

// Simple access key — change this to anything before uploading
define('ACCESS_KEY', 'test123');

if (($_GET['key'] ?? '') !== ACCESS_KEY) {
    http_response_code(403);
    die('Forbidden — add ?key=ACCESS_KEY to the URL');
}

header('Content-Type: text/plain; charset=UTF-8');

$to = 'biernat.wojtek@gmail.com'; // where to send the test

echo "=== WL Beskidzki — Mail Diagnostics ===\n\n";

// 1. PHP mail config
echo "--- PHP mail config ---\n";
echo "sendmail_path : " . ini_get('sendmail_path') . "\n";
echo "SMTP          : " . ini_get('SMTP') . "\n";
echo "smtp_port     : " . ini_get('smtp_port') . "\n";
echo "PHP version   : " . PHP_VERSION . "\n";
echo "Server host   : " . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n\n";

// 2. .env check
echo "--- .env ---\n";
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    echo ".env found at: {$envFile}\n";
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key] = array_map('trim', explode('=', $line, 2));
        echo "  {$key} = [set]\n"; // print key names only, not values
    }
} else {
    echo ".env NOT FOUND at: {$envFile}\n";
}
echo "\n";

// 3. Send test email
echo "--- Sending test email to: {$to} ---\n";
$subject = '=?UTF-8?B?' . base64_encode('WL Beskidzki — test maila') . '?=';
$body    = "To jest testowy email wysłany ze skryptu diagnostycznego.\n\nCzas: " . date('Y-m-d H:i:s');
$headers = implode("\r\n", [
    'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'korunahor.cz'),
    'Content-Type: text/plain; charset=UTF-8',
]);

$result = mail($to, $subject, $body, $headers);
echo "mail() returned: " . ($result ? "TRUE — check your inbox (and spam!)" : "FALSE — mail() is broken") . "\n";

$lastError = error_get_last();
if ($lastError) {
    echo "Last PHP error: " . json_encode($lastError) . "\n";
}

echo "\n=== Done. DELETE this file from the server now! ===\n";
