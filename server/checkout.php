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
//
// Shipping rate IDs — create in Stripe Dashboard → Products → Shipping rates:
//   STRIPE_SHIP_PACZKOMAT_SMALL — InPost Paczkomat, 1–3 books  (12,50 zł)
//   STRIPE_SHIP_PACZKOMAT_LARGE — InPost Paczkomat, 4–10 books (17,50 zł)
//   STRIPE_SHIP_KURIER_SMALL    — Kurier (DPD),     1–3 books  ( 16,00 zł)
//   STRIPE_SHIP_KURIER_LARGE    — Kurier (DPD),     4–10 books (21,00 zł)
//   STRIPE_SHIP_FREE            — Gratis,           11+ books  ( 0,00 zł)

$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
$allowedOrigin   = getenv('ALLOWED_ORIGIN');
$successUrl      = getenv('CHECKOUT_SUCCESS_URL');
$cancelUrl       = getenv('CHECKOUT_CANCEL_URL');

// ── Product code → Stripe Price ID map ───────────────────────────────────────
// Frontend sends product codes ('WL', 'KWS', …) — Price IDs live only in .env.
// To add/remove a product: edit .env. No frontend changes needed.
$productPriceMap = array_filter([
    'WL'   => getenv('STRIPE_PRICE_WL'),
    'KWS'  => getenv('STRIPE_PRICE_KWS'),
    'GGMF' => getenv('STRIPE_PRICE_GGMF'),
    'GGWF' => getenv('STRIPE_PRICE_GGWF'),
    'GGNT' => getenv('STRIPE_PRICE_GGNT'),
    'KW'   => getenv('STRIPE_PRICE_KW'),
]);

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
    $productCode = strtoupper(trim((string)($item['product'] ?? '')));
    $quantity    = max(1, min(99, (int)($item['quantity']  ?? 1)));

    if (!$productCode || !array_key_exists($productCode, $productPriceMap)) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown product: {$productCode}"]);
        exit;
    }

    $lineItems[] = ['price' => $productPriceMap[$productCode], 'quantity' => $quantity];
}

if (empty($lineItems)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid items']);
    exit;
}

// ── Consent flags (passed as Stripe metadata for webhook logging) ─────────────
$newsletterConsent = !empty($body['newsletter']) ? 'true' : 'false';
$regulaminAccepted = !empty($body['regulamin'])  ? 'true' : 'false';
$consentTimestamp  = date('c'); // ISO 8601 — when the user clicked checkout

// ── Shipping / delivery details ───────────────────────────────────────────────
$shippingMethod   = ($body['shipping_method'] ?? '') === 'paczkomat' ? 'paczkomat' : 'adres';
$paczkomatId      = substr(trim((string)($body['paczkomat_id']      ?? '')), 0, 100);
$paczkomatAddress = substr(trim((string)($body['paczkomat_address'] ?? '')), 0, 200);

// ── Shipping rate selection ───────────────────────────────────────────────────
// Count total books across all validated line items
$totalBooks = 0;
foreach ($lineItems as $item) {
    $totalBooks += $item['quantity'];
}

// Determine price tier
if ($totalBooks > 5) {
    $tier = 'free';
} elseif ($totalBooks >= 4) {
    $tier = 'large';
} else {
    $tier = 'small';
}

// Map method + tier → Stripe shipping rate ID (set in .env)
$shippingRateMap = [
    'paczkomat' => [
        'small'  => getenv('STRIPE_SHIP_PACZKOMAT_SMALL'),   // 12,50 zł
        'large'  => getenv('STRIPE_SHIP_PACZKOMAT_LARGE'),   // 17,50 zł
        'free'   => getenv('STRIPE_SHIP_FREE'),               //  0,00 zł
    ],
    'adres' => [
        'small'  => getenv('STRIPE_SHIP_KURIER_SMALL'),      //  16,00 zł
        'large'  => getenv('STRIPE_SHIP_KURIER_LARGE'),      // 21,00 zł
        'free'   => getenv('STRIPE_SHIP_FREE'),               //  0,00 zł
    ],
];

$shippingRateId = $shippingRateMap[$shippingMethod][$tier] ?? '';

// ── Build Stripe Checkout Session params ─────────────────────────────────────
$params = [
    'mode'        => 'payment',
    'success_url' => $successUrl,
    'cancel_url'  => $cancelUrl,
    // Consent evidence — readable in Stripe Dashboard and available in webhook
    'metadata[newsletter_consent]' => $newsletterConsent,
    'metadata[regulamin_accepted]' => $regulaminAccepted,
    'metadata[consent_timestamp]'  => $consentTimestamp,
    'metadata[shipping_method]'    => $shippingMethod,
    'metadata[paczkomat_id]'       => $paczkomatId,
    'metadata[paczkomat_address]'  => $paczkomatAddress,
];

// Always collect shipping address (required for legal/return purposes)
$params['shipping_address_collection[allowed_countries][0]'] = 'PL';
$params['shipping_address_collection[allowed_countries][1]'] = 'CZ';
$params['shipping_address_collection[allowed_countries][2]'] = 'SK';
$params['shipping_address_collection[allowed_countries][3]'] = 'UA';

// For paczkomat orders, clarify why an address is being asked
if ($shippingMethod === 'paczkomat') {
    $params['custom_text[shipping_address][message]'] =
        'Paczka zostanie dostarczona do wybranego paczkomatu. '
        . 'Podaj swój adres zamieszkania — jest wymagany do celów prawnych '
        . 'oraz obsługi ewentualnych zwrotów.';
}

// Attach shipping rate (pre-calculated from item count + delivery method)
if ($shippingRateId) {
    $params['shipping_options[0][shipping_rate]'] = $shippingRateId;
}

foreach ($lineItems as $i => $item) {
    $params["line_items[{$i}][price]"]    = $item['price'];
    $params["line_items[{$i}][quantity]"] = $item['quantity'];
    // Quantity locked — user selects count on the website, not in Stripe checkout
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
