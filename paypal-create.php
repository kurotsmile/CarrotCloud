<?php
session_start();

require_once __DIR__ . '/../CarrotHome/config/database.php';
require_once __DIR__ . '/../CarrotHome/includes/functions.php';
require_once __DIR__ . '/../CarrotHome/includes/paypal_config.php';
require_once __DIR__ . '/../CarrotAdmin/includes/schema.php';

if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

admin_ensure_cloud_tables($pdo);

$paypalConfig = paypal_config_from_db($pdo, 'cloud');
$planId = (int) ($_GET['plan'] ?? 0);

if ($planId <= 0 || empty($paypalConfig['enabled'])) {
    http_response_code(400);
    exit('Invalid payment request.');
}

if (empty($paypalConfig['client_id']) || empty($paypalConfig['client_secret'])) {
    http_response_code(500);
    exit('PayPal config is missing.');
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    exit('PHP cURL extension is required for PayPal.');
}

$user = null;
if (!empty($_SESSION['home_user_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['home_user_id']]);
    $user = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare('SELECT * FROM cloud WHERE id = ? AND status = "active" LIMIT 1');
$stmt->execute([$planId]);
$plan = $stmt->fetch();
if (!$plan) {
    http_response_code(404);
    exit('Cloud plan is not available.');
}

$planPrice = (float) ($plan['price'] ?? 0);
if ($planPrice <= 0) {
    $planPrice = (float) ($paypalConfig['amount'] ?? 0);
}
if ($planPrice <= 0) {
    http_response_code(400);
    exit('Cloud plan price is not configured.');
}

$amount = number_format($planPrice, 2, '.', '');
$currency = strtoupper(trim((string) ($plan['currency'] ?? '')));
if ($currency === '') {
    $currency = strtoupper(trim((string) ($paypalConfig['currency'] ?? 'USD'))) ?: 'USD';
}

$baseApi = !empty($paypalConfig['sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
$auth = base64_encode($paypalConfig['client_id'] . ':' . $paypalConfig['client_secret']);

$ch = curl_init($baseApi . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$tokenResponse = curl_exec($ch);
$tokenStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokenData = json_decode((string) $tokenResponse, true);
if ($tokenStatus >= 300 || empty($tokenData['access_token'])) {
    http_response_code(502);
    exit('Unable to connect to PayPal.');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'cloud.carrot28.com';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/CarrotCloud/paypal-create.php'), '/\\');
$basePath = ($basePath === '' || $basePath === '.') ? '' : $basePath;
$returnUrl = $scheme . '://' . $host . $basePath . '/paypal-return.php?plan=' . urlencode((string) $planId);
$cancelUrl = $scheme . '://' . $host . $basePath . '/index.php';

$payload = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id' => 'cloud-plan-' . $planId,
        'description' => 'CarrotCloud plan: ' . (string) $plan['name'],
        'amount' => [
            'currency_code' => $currency,
            'value' => $amount,
        ],
    ]],
    'application_context' => [
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'user_action' => 'PAY_NOW',
    ],
];

$ch = curl_init($baseApi . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $tokenData['access_token'],
        'Content-Type: application/json',
    ],
]);
$orderResponse = curl_exec($ch);
$orderStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$orderData = json_decode((string) $orderResponse, true);
if ($orderStatus >= 300 || empty($orderData['id']) || empty($orderData['links'])) {
    http_response_code(502);
    exit('Unable to create PayPal order.');
}

$_SESSION['paypal_cloud_orders'][$orderData['id']] = $planId;

$userEmail = $user ? (string) ($user['email'] ?? '') : '';
$userId = $user ? (int) ($user['id'] ?? 0) : null;
$guestUser = 'guest:' . substr(hash('sha256', session_id() ?: (string) $orderData['id']), 0, 24);
$subscriptionUser = $userEmail !== '' ? $userEmail : ($userId ? (string) $userId : $guestUser);
$stmt = $pdo->prepare('
    INSERT INTO cloud_subscription
        (cloud_id, user, user_id, payer_email, provider, provider_order_id, amount, currency, paypal_payload, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        user = VALUES(user),
        user_id = VALUES(user_id),
        payer_email = VALUES(payer_email),
        provider = VALUES(provider),
        amount = VALUES(amount),
        currency = VALUES(currency),
        paypal_payload = VALUES(paypal_payload),
        status = VALUES(status)
');
$stmt->execute([
    $planId,
    $subscriptionUser,
    $userId,
    $userEmail,
    'paypal',
    (string) $orderData['id'],
    $amount,
    $currency,
    json_encode($orderData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    (string) ($orderData['status'] ?? 'CREATED'),
]);

foreach ($orderData['links'] as $link) {
    if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
        header('Location: ' . $link['href']);
        exit;
    }
}

http_response_code(502);
exit('PayPal approval link is missing.');
