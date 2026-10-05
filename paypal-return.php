<?php
session_start();

require_once __DIR__ . '/../CarrotHome/config/database.php';
require_once __DIR__ . '/../CarrotHome/includes/functions.php';
require_once __DIR__ . '/../CarrotHome/includes/paypal_config.php';
require_once __DIR__ . '/../CarrotAdmin/includes/schema.php';

initialize_language_from_ip($pdo ?? null);

function cloud_url(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/CarrotCloud/paypal-return.php'), '/\\');
    return ($base === '' || $base === '.') ? '/' . ltrim($path, '/') : $base . '/' . ltrim($path, '/');
}

function cloud_paypal_fetch_subscription(PDO $pdo, string $orderId, int $planId = 0): ?array
{
    $sql = '
        SELECT cs.*, c.name AS plan_name, c.description AS plan_description, c.limit_gb, c.price
        FROM cloud_subscription cs
        JOIN cloud c ON c.id = cs.cloud_id
        WHERE cs.provider = "paypal" AND cs.provider_order_id = ?
    ';
    $params = [$orderId];
    if ($planId > 0) {
        $sql .= ' AND cs.cloud_id = ?';
        $params[] = $planId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function cloud_paypal_capture_order(PDO $pdo, array $paypalConfig, string $orderId): array
{
    if (empty($paypalConfig['client_id']) || empty($paypalConfig['client_secret'])) {
        throw new RuntimeException('PayPal config is missing.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for PayPal.');
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
        throw new RuntimeException('Unable to verify PayPal payment.');
    }

    $ch = curl_init($baseApi . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $tokenData['access_token'],
            'Content-Type: application/json',
        ],
    ]);
    $captureResponse = curl_exec($ch);
    $captureStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $captureData = json_decode((string) $captureResponse, true);
    if (!is_array($captureData)) {
        $captureData = ['status' => 'FAILED', 'raw_response' => (string) $captureResponse];
    }

    if ($captureStatus >= 300 || ($captureData['status'] ?? '') !== 'COMPLETED') {
        $stmt = $pdo->prepare('UPDATE cloud_subscription SET status = ?, paypal_payload = ? WHERE provider = "paypal" AND provider_order_id = ?');
        $stmt->execute([
            (string) ($captureData['status'] ?? 'FAILED'),
            json_encode($captureData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $orderId,
        ]);
        throw new RuntimeException('PayPal payment was not completed.');
    }

    $payerEmail = (string) ($captureData['payer']['email_address'] ?? '');
    $stmt = $pdo->prepare('
        UPDATE cloud_subscription
        SET status = "active",
            user = CASE WHEN ? <> "" THEN ? ELSE user END,
            payer_email = CASE WHEN ? <> "" THEN ? ELSE payer_email END,
            paypal_payload = ?,
            paid_at = COALESCE(paid_at, NOW()),
            started_at = COALESCE(started_at, NOW())
        WHERE provider = "paypal" AND provider_order_id = ?
    ');
    $stmt->execute([
        $payerEmail,
        $payerEmail,
        $payerEmail,
        $payerEmail,
        json_encode($captureData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $orderId,
    ]);

    return $captureData;
}

if ($pdo instanceof PDO) {
    admin_ensure_cloud_tables($pdo);
}

$paypalConfig = paypal_config_from_db($pdo ?? null, 'cloud');
$planId = (int) ($_GET['plan'] ?? 0);
$orderId = trim((string) ($_GET['token'] ?? ''));
$payerId = trim((string) ($_GET['PayerID'] ?? ''));
$errorMessage = $db_error ?? '';
$noticeMessage = '';
$subscription = null;

if ($orderId === '') {
    http_response_code(400);
    $errorMessage = 'Missing PayPal order token.';
} elseif (!$pdo instanceof PDO) {
    http_response_code(500);
    $errorMessage = $errorMessage ?: 'Database connection is unavailable.';
} else {
    try {
        $subscription = cloud_paypal_fetch_subscription($pdo, $orderId, $planId);
        if (!$subscription) {
            http_response_code(404);
            $errorMessage = 'Không tìm thấy đơn hàng Cloud PayPal này.';
        } elseif (($subscription['status'] ?? '') !== 'active' && $payerId !== '') {
            cloud_paypal_capture_order($pdo, $paypalConfig, $orderId);
            unset($_SESSION['paypal_cloud_orders'][$orderId]);
            $subscription = cloud_paypal_fetch_subscription($pdo, $orderId, (int) $subscription['cloud_id']);
            $noticeMessage = 'Thanh toán PayPal đã hoàn tất. Gói Cloud đã được kích hoạt.';
        } elseif (($subscription['status'] ?? '') !== 'active') {
            $noticeMessage = 'Đơn hàng chưa hoàn tất thanh toán trên PayPal.';
        }
    } catch (Throwable $e) {
        http_response_code(402);
        $errorMessage = $e->getMessage();
        if ($pdo instanceof PDO) {
            $subscription = cloud_paypal_fetch_subscription($pdo, $orderId, $planId);
        }
    }
}

$isCompleted = (($subscription['status'] ?? '') === 'active');
$styleVersion = file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time();
?>
<!doctype html>
<html lang="<?= h(current_lang_key()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(ui_label('paypal.order_detail', 'PayPal Order')) ?> - CarrotCloud</title>
<meta name="theme-color" content="#f97316">
<link rel="apple-touch-icon" sizes="180x180" href="<?= h(cloud_url('favicon/apple-touch-icon.png')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(cloud_url('favicon/favicon-32x32.png')) ?>">
<link rel="icon" href="<?= h(cloud_url('favicon/favicon.ico')) ?>" sizes="any">
<link rel="stylesheet" href="https://unpkg.com/lucide-static@0.468.0/font/lucide.css">
<link rel="stylesheet" href="<?= h(cloud_url('styles.css')) ?>?v=<?= $styleVersion ?>">
</head>
<body>
<header class="cloud-nav">
    <a class="brand" href="<?= h(cloud_url('index.php')) ?>">
        <img src="<?= h(cloud_url('favicon/favicon-32x32.png')) ?>" alt="CarrotCloud">
        <span>CarrotCloud</span>
    </a>
</header>
<main class="paypal-cloud-page">
    <section class="paypal-cloud-card">
        <div class="paypal-cloud-main">
            <?php if ($errorMessage): ?>
                <span class="paypal-cloud-status is-error"><?= h(ui_label('paypal.status_error', 'Payment issue')) ?></span>
                <h1><?= h(ui_label('paypal.result_error_title', 'Unable to confirm the order')) ?></h1>
                <p><?= h($errorMessage) ?></p>
            <?php elseif ($isCompleted): ?>
                <span class="paypal-cloud-status"><?= h(ui_label('paypal.status_completed', 'Payment completed')) ?></span>
                <h1><?= h(ui_label('cloud.paypal_success_title', 'Cloud plan activated')) ?></h1>
                <p><?= h($noticeMessage ?: ui_label('cloud.paypal_success_body', 'Your CarrotCloud storage is ready.')) ?></p>
            <?php else: ?>
                <span class="paypal-cloud-status is-pending"><?= h(ui_label('paypal.status_pending', 'Payment pending')) ?></span>
                <h1><?= h(ui_label('paypal.result_pending_title', 'Đơn hàng chưa hoàn tất')) ?></h1>
                <p><?= h($noticeMessage ?: ui_label('paypal.result_pending_body', 'PayPal chưa trả về trạng thái hoàn tất cho đơn hàng này.')) ?></p>
            <?php endif; ?>
            <div class="paypal-cloud-actions">
                <a class="paypal-cloud-button" href="<?= h(cloud_url('index.php')) ?>"><i data-lucide="cloud"></i><?= h(ui_label('cloud.open_workspace', 'Open Cloud')) ?></a>
            </div>
        </div>
        <aside class="paypal-cloud-side">
            <div><span><?= h(ui_label('cloud.current_plan', 'Current plan')) ?></span><strong><?= h($subscription['plan_name'] ?? '-') ?></strong></div>
            <div><span><?= h(ui_label('cloud.capacity', 'Capacity')) ?></span><strong><?= h(number_format((float) ($subscription['limit_gb'] ?? 0), 2)) ?> GB</strong></div>
            <div><span><?= h(ui_label('paypal.order_id', 'PayPal Order')) ?></span><strong><?= h($orderId) ?></strong></div>
            <div><span><?= h(ui_label('paypal.status', 'Status')) ?></span><strong><?= h($subscription['status'] ?? '-') ?></strong></div>
            <div><span><?= h(ui_label('paypal.amount', 'Amount')) ?></span><strong><?= h(number_format((float) ($subscription['amount'] ?? 0), 2)) ?> <?= h($subscription['currency'] ?? 'USD') ?></strong></div>
            <div><span><?= h(ui_label('paypal.payer', 'Payer')) ?></span><strong><?= h($subscription['payer_email'] ?? '-') ?></strong></div>
        </aside>
    </section>
</main>
<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
