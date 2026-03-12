<?php
declare(strict_types=1);

// ── Load .env ────────────────────────────────────────────────────────────────
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key) putenv("{$key}={$value}");
    }
})();

$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
$fromEmail     = getenv('FROM_EMAIL') ?: "noreply@{$_SERVER['HTTP_HOST']}";
$wpRoot        = getenv('WP_ROOT') ?: dirname(__DIR__);

// CONTACT_EMAIL supports multiple addresses separated by commas
$contactEmail = array_filter(array_map('trim', explode(',', getenv('CONTACT_EMAIL') ?: '')));

// ── Bootstrap WordPress so we can use wp_mail() ───────────────────────────────
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$wpLoad = rtrim($wpRoot, '/') . '/wp-load.php';
if (file_exists($wpLoad)) require_once $wpLoad;

// ── Read raw payload BEFORE any output or parsing ────────────────────────────
// Must be raw — Stripe signature is computed against the exact bytes received.
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify Stripe webhook signature ──────────────────────────────────────────
// Implemented manually — no Stripe SDK required.
// Docs: https://docs.stripe.com/webhooks#verify-official-libraries
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool
{
    $timestamp  = null;
    $signatures = [];

    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2) + [1 => ''];
        if ($k === 't')  $timestamp    = (int)$v;
        if ($k === 'v1') $signatures[] = $v;
    }

    if ($timestamp === null || empty($signatures)) return false;

    // Reject events older than 5 minutes (replay attack protection)
    if (abs(time() - $timestamp) > 300) return false;

    $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }

    return false;
}

if (!verifyStripeSignature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    echo 'Invalid signature';
    exit;
}

// ── Parse event ───────────────────────────────────────────────────────────────
$event = json_decode($payload, true);
$type  = $event['type'] ?? '';

// Only act on completed checkouts — acknowledge everything else silently.
if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── Extract order details ─────────────────────────────────────────────────────
$session  = $event['data']['object'];
$customer = $session['customer_details'] ?? [];
$address  = $customer['address'] ?? [];

$customerName    = $customer['name']        ?? '—';
$customerEmail   = $customer['email']       ?? '—';
$orderId         = $session['id']           ?? '—';
$paymentIntentId = $session['payment_intent'] ?? null;
$amountTotal     = number_format((int)($session['amount_total'] ?? 0) / 100, 2, ',', ' ');
$currency        = strtoupper($session['currency'] ?? 'PLN');
$paymentStatus   = $session['payment_status'] ?? '—';

$addressLine = implode(', ', array_filter([
    $address['line1']       ?? '',
    $address['line2']       ?? '',
    $address['postal_code'] ?? '',
    $address['city']        ?? '',
    $address['country']     ?? '',
])) ?: '—';

// ── Send notification email ───────────────────────────────────────────────────
$emailSubject = "Nowe zamówienie Beskidzki Włóczykij — {$customerName}";

$emailBody = implode("\n", [
    "Nowe zamówienie zrealizowane przez Stripe:",
    str_repeat("─", 40),
    "",
    "ID sesji        : {$orderId}",
    "Status płatności: {$paymentStatus}",
    "",
    "Klient          : {$customerName}",
    "E-mail          : {$customerEmail}",
    "Adres dostawy   : {$addressLine}",
    "",
    "Kwota           : {$amountTotal} {$currency}",
    "",
    str_repeat("─", 40),
    "Stripe Dashboard:",
    $paymentIntentId
        ? "https://dashboard.stripe.com/payments/{$paymentIntentId}"
        : "https://dashboard.stripe.com/checkout/sessions/{$orderId}",
]);

$headers = [
    "From: Beskidzki Włóczykij <{$fromEmail}>",
    "Content-Type: text/plain; charset=UTF-8",
];

if (function_exists('wp_mail')) {
    wp_mail($contactEmail, $emailSubject, $emailBody, $headers);
} else {
    mail(implode(',', $contactEmail), $emailSubject, $emailBody, implode("\r\n", $headers));
}

// Always return 200 — if we return anything else Stripe will retry.
http_response_code(200);
echo 'OK';
