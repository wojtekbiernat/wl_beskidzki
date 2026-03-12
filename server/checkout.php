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

// Required .env variables:
//   STRIPE_SECRET_KEY    — Stripe secret API key (sk_live_... or sk_test_...)
//   ALLOWED_ORIGIN       — frontend origin, e.g. https://wbiernat.eu
//   CHECKOUT_SUCCESS_URL — e.g. https://wbiernat.eu/wl_beskidzki/przystap/?zamowienie=sukces
//   CHECKOUT_CANCEL_URL  — e.g. https://wbiernat.eu/wl_beskidzki/przystap/
//   STRIPE_PRICE_WL      — Stripe Price ID for Beskidzki Włóczykij
//   STRIPE_PRICE_KWS     — Stripe Price ID for Korona Woj. Śląskiego
//   STRIPE_PRICE_GGMF    — Stripe Price ID for Gł. Grzbiet Małej Fatry
//   STRIPE_PRICE_GGWF    — Stripe Price ID for Gł. Grzbiet Wielkiej Fatry
//   STRIPE_PRICE_GGNT    — Stripe Price ID for Gł. Grzbiet Niżnych Tatr
//   STRIPE_PRICE_KW      — Stripe Price ID for Korona Wzgórz i Gór Węgier

$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
$allowedOrigin   = getenv('ALLOWED_ORIGIN');
$successUrl      = getenv('CHECKOUT_SUCCESS_URL');
$cancelUrl       = getenv('CHECKOUT_CANCEL_URL');

// ── Allowed Stripe Price IDs (security allowlist) ────────────────────────────
// Only price IDs listed here will be accepted — prevents clients from injecting
// arbitrary products or prices into the checkout session.
$allowedPriceIds = array_values(array_filter([
    getenv('STRIPE_PRICE_WL'),
    getenv('STRIPE_PRICE_KWS'),
    getenv('STRIPE_PRICE_GGMF'),
    getenv('STRIPE_PRICE_GGWF'),
    getenv('STRIPE_PRICE_GGNT'),
    getenv('STRIPE_PRICE_KW'),
]));

// ── Validate required config ──────────────────────────────────────────────────
// Do this before sending any CORS headers so misconfiguration is obvious.
$missing = [];
if (!$stripeSecretKey) $missing[] = 'STRIPE_SECRET_KEY';
if (!$successUrl)      $missing[] = 'CHECKOUT_SUCCESS_URL';
if (!$cancelUrl)       $missing[] = 'CHECKOUT_CANCEL_URL';

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

// ── Fail early on missing config ─────────────────────────────────────────────
if (!empty($missing)) {
    http_response_code(500);
    echo json_encode(['error' => 'Brak konfiguracji serwera: ' . implode(', ', $missing)]);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body) || empty($body['items']) || !is_array($body['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid items']);
    exit;
}

// ── Validate & build line items ───────────────────────────────────────────────
$lineItems = [];

foreach ($body['items'] as $item) {
    $priceId  = trim((string)($item['price']    ?? ''));
    $quantity = max(1, min(99, (int)($item['quantity'] ?? 1)));

    if (!$priceId) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty price ID']);
        exit;
    }

    if (!in_array($priceId, $allowedPriceIds, true)) {
        http_response_code(400);
        echo json_encode(['error' => "Price ID not allowed: {$priceId}"]);
        exit;
    }

    $lineItems[] = ['price' => $priceId, 'quantity' => $quantity];
}

if (empty($lineItems)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid items']);
    exit;
}

// ── Build Stripe Checkout Session params ─────────────────────────────────────
$params = [
    'mode'        => 'payment',
    'success_url' => $successUrl,
    'cancel_url'  => $cancelUrl,
    // Collect shipping address; add or remove countries as needed
    'shipping_address_collection[allowed_countries][0]' => 'PL',
    'shipping_address_collection[allowed_countries][1]' => 'CZ',
    'shipping_address_collection[allowed_countries][2]' => 'SK',
    'shipping_address_collection[allowed_countries][3]' => 'UA',
];

foreach ($lineItems as $i => $item) {
    $params["line_items[{$i}][price]"]    = $item['price'];
    $params["line_items[{$i}][quantity]"] = $item['quantity'];
}

// ── Call Stripe API ───────────────────────────────────────────────────────────
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $stripeSecretKey,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);

$rawResponse = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Network error connecting to Stripe']);
    exit;
}

$stripe = json_decode($rawResponse, true);

if ($httpCode !== 200 || empty($stripe['url'])) {
    $errMsg = $stripe['error']['message'] ?? 'Stripe API error';
    http_response_code(502);
    echo json_encode(['error' => $errMsg]);
    exit;
}

http_response_code(200);
echo json_encode(['url' => $stripe['url']]);
