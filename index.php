<?php
session_start();

if (!defined('CARROT_SITE_KEY')) {
    define('CARROT_SITE_KEY', 'CarrotCloud');
}
if (!defined('CARROT_SITE_ALIASES')) {
    define('CARROT_SITE_ALIASES', ['CarrotCloud', 'Cloud', 'cloud.carrot28.com']);
}

require_once __DIR__ . '/../CarrotHome/config/database.php';
require_once __DIR__ . '/../CarrotHome/includes/functions.php';
require_once __DIR__ . '/../CarrotHome/includes/visit_tracker.php';
require_once __DIR__ . '/../CarrotAdmin/includes/schema.php';

initialize_language_from_ip($pdo ?? null);
visit_track_daily_ip($pdo ?? null, 'cloud');

if ($pdo instanceof PDO) {
    admin_ensure_cloud_tables($pdo);
}

function cloud_url(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/CarrotCloud/index.php'), '/\\');
    return ($base === '' || $base === '.') ? '/' . ltrim($path, '/') : $base . '/' . ltrim($path, '/');
}

function cloud_storage_dir(): string
{
    return __DIR__ . '/storage_private/users';
}

function cloud_user_key(): string
{
    $identity = trim((string) ($_SESSION['home_user_id'] ?? '')) ?: trim((string) ($_SESSION['home_user_email'] ?? 'guest'));
    return preg_replace('/[^a-z0-9_.@-]+/i', '-', strtolower($identity)) ?: 'guest';
}

function cloud_current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['home_user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, email, avatar FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['home_user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function cloud_active_subscription(PDO $pdo, ?array $user): ?array
{
    if (!$user) {
        return null;
    }

    $userId = (int) ($user['id'] ?? 0);
    $email = trim((string) ($user['email'] ?? ''));
    $idText = (string) $userId;

    $stmt = $pdo->prepare('
        SELECT cs.*, c.name, c.description, c.limit_gb, c.price, c.currency
        FROM cloud_subscription cs
        JOIN cloud c ON c.id = cs.cloud_id
        WHERE cs.status = "active"
          AND (cs.expires_at IS NULL OR cs.expires_at >= NOW())
          AND (cs.user_id = :user_id OR cs.user = :email_user OR cs.user = :id_text OR cs.payer_email = :email_payer)
        ORDER BY c.limit_gb DESC, cs.id DESC
        LIMIT 1
    ');
    $stmt->execute([
        ':user_id' => $userId,
        ':email_user' => $email,
        ':email_payer' => $email,
        ':id_text' => $idText,
    ]);
    $subscription = $stmt->fetch();
    if ($subscription) {
        return $subscription;
    }

    $stmt = $pdo->prepare('
        SELECT NULL AS id, c.id AS cloud_id, c.user, NULL AS user_id, c.name, c.description, c.limit_gb, c.price, c.currency
        FROM cloud c
        WHERE c.status = "active"
          AND (c.user = :email OR c.user = :id_text)
        ORDER BY c.limit_gb DESC, c.id DESC
        LIMIT 1
    ');
    $stmt->execute([':email' => $email, ':id_text' => $idText]);
    $planGrant = $stmt->fetch();
    return $planGrant ?: null;
}

function cloud_localized_plans(PDO $pdo, string $langKey): array
{
    $stmt = $pdo->prepare('
        SELECT c.*, COALESCE(cl.name, c.name) AS display_name, COALESCE(cl.description, c.description) AS display_description
        FROM cloud c
        LEFT JOIN cloud_lang cl ON cl.cloud_id = c.id AND cl.lang_key = :lang_key
        WHERE c.status = "active"
        ORDER BY c.sort_order ASC, c.limit_gb ASC, c.id ASC
    ');
    $stmt->execute([':lang_key' => $langKey]);
    return $stmt->fetchAll();
}

function cloud_user_files(PDO $pdo, ?array $user): array
{
    if (!$user) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT * FROM cloud_file WHERE user_id = ? OR user = ? ORDER BY created_at DESC, id DESC LIMIT 200');
    $stmt->execute([(int) $user['id'], (string) $user['email']]);
    return $stmt->fetchAll();
}

function cloud_used_bytes(PDO $pdo, ?array $user): int
{
    if (!$user) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM cloud_file WHERE user_id = ? OR user = ?');
    $stmt->execute([(int) $user['id'], (string) $user['email']]);
    return (int) $stmt->fetchColumn();
}

function cloud_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = max(0, $bytes);
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return number_format($size, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

function cloud_file_icon(string $mime): string
{
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'music';
    if (strpos($mime, 'pdf') !== false) return 'file-text';
    return 'file';
}

$message = '';
$error = '';
$currentLang = current_lang_key();
$countries = [];
$currentCountryId = (int) ($_SESSION['country_id'] ?? 0);
$currentUser = null;
$subscription = null;
$plans = [];
$files = [];
$usedBytes = 0;

if ($pdo instanceof PDO) {
    try {
        $countries = $pdo->query('SELECT id, icon, name, lang_key, lang_country FROM country ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e) {
        $countries = [];
    }
}

if ($currentCountryId <= 0 && $countries) {
    foreach ($countries as $country) {
        if ((string) ($country['lang_key'] ?? '') === $currentLang) {
            $currentCountryId = (int) $country['id'];
            break;
        }
    }
}

if ($currentCountryId <= 0 && $countries) {
    $currentCountryId = (int) ($countries[0]['id'] ?? 0);
}

if (($_POST['action'] ?? '') === 'language' && $pdo instanceof PDO) {
    $countryId = (int) ($_POST['country_id'] ?? 0);
    $langKey = trim((string) ($_POST['lang_key'] ?? ''));
    $stmt = $countryId > 0
        ? $pdo->prepare('SELECT id, lang_key, lang_country FROM country WHERE id = ? LIMIT 1')
        : $pdo->prepare('SELECT id, lang_key, lang_country FROM country WHERE lang_key = ? LIMIT 1');
    $stmt->execute([$countryId > 0 ? $countryId : $langKey]);
    $country = $stmt->fetch();
    if ($country) {
        $_SESSION['key_lang'] = $country['lang_key'];
        $_SESSION['country_id'] = (int) $country['id'];
        $_SESSION['lang_country'] = $country['lang_country'];
    }
    header('Location: ' . cloud_url('index.php'));
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['home_user_id'], $_SESSION['home_user_name'], $_SESSION['home_user_email'], $_SESSION['home_user_role'], $_SESSION['home_user_avatar']);
    header('Location: ' . cloud_url('index.php'));
    exit;
}

if ($pdo instanceof PDO && ($_POST['action'] ?? '') === 'login') {
    try {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new RuntimeException(ui_label('login.error_required', 'Vui lòng nhập email và mật khẩu.'));
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $storedPassword = (string) ($user['password'] ?? '');
        $passwordOk = $user && $storedPassword !== '' && hash_equals($storedPassword, $password);

        if (!$user || !$passwordOk) {
            throw new RuntimeException(ui_label('login.error_invalid', 'Sai email hoặc mật khẩu.'));
        }

        session_regenerate_id(true);
        $_SESSION['home_user_id'] = (int) $user['id'];
        $_SESSION['home_user_name'] = (string) ($user['name'] ?? '');
        $_SESSION['home_user_email'] = (string) ($user['email'] ?? '');
        $_SESSION['home_user_role'] = (string) ($user['role'] ?? 'user');
        $_SESSION['home_user_avatar'] = (string) ($user['avatar'] ?? '');
        $message = ui_label('login.success', 'Đăng nhập thành công.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($pdo instanceof PDO) {
    $currentUser = cloud_current_user($pdo);
    $subscription = cloud_active_subscription($pdo, $currentUser);
    $plans = cloud_localized_plans($pdo, $currentLang);
    $files = cloud_user_files($pdo, $currentUser);
    $usedBytes = cloud_used_bytes($pdo, $currentUser);
}

if (isset($_GET['file']) && $pdo instanceof PDO && $currentUser && $subscription) {
    $fileId = (int) $_GET['file'];
    $stmt = $pdo->prepare('SELECT * FROM cloud_file WHERE id = ? AND (user_id = ? OR user = ?) LIMIT 1');
    $stmt->execute([$fileId, (int) $currentUser['id'], (string) $currentUser['email']]);
    $file = $stmt->fetch();
    $path = $file ? dirname(cloud_storage_dir()) . '/' . ltrim((string) $file['relative_path'], '/\\') : '';
    if (!$file || !is_file($path)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $mime = (string) ($file['mime_type'] ?? 'application/octet-stream') ?: 'application/octet-stream';
    $inline = ($_GET['inline'] ?? '') === '1';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    $downloadName = preg_replace('/[\r\n"]+/', '_', (string) $file['original_name']) ?: 'cloud-file';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
    readfile($path);
    exit;
}

if ($pdo instanceof PDO && ($_POST['action'] ?? '') === 'upload' && $currentUser && $subscription) {
    try {
        $file = $_FILES['cloud_file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(ui_label('cloud.upload_error', 'Vui lòng chọn file hợp lệ.'));
        }

        $fileSize = (int) ($file['size'] ?? 0);
        $limitBytes = (int) round(((float) ($subscription['limit_gb'] ?? 0)) * 1024 * 1024 * 1024);
        if ($limitBytes > 0 && ($usedBytes + $fileSize) > $limitBytes) {
            throw new RuntimeException(ui_label('cloud.limit_error', 'File vượt quá dung lượng gói hiện tại.'));
        }

        $userDir = cloud_storage_dir() . '/' . cloud_user_key();
        if (!is_dir($userDir) && !mkdir($userDir, 0775, true)) {
            throw new RuntimeException(ui_label('cloud.storage_error', 'Không thể tạo thư mục lưu trữ.'));
        }

        $originalName = basename((string) ($file['name'] ?? 'file'));
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . ($extension !== '' ? '.' . strtolower($extension) : '');
        $target = $userDir . '/' . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException(ui_label('cloud.upload_error', 'Vui lòng chọn file hợp lệ.'));
        }

        $mime = mime_content_type($target) ?: 'application/octet-stream';
        $relativePath = 'users/' . cloud_user_key() . '/' . $storedName;
        $stmt = $pdo->prepare('
            INSERT INTO cloud_file (subscription_id, user, user_id, original_name, stored_name, mime_type, size_bytes, relative_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            !empty($subscription['id']) ? (int) $subscription['id'] : null,
            (string) $currentUser['email'],
            (int) $currentUser['id'],
            $originalName,
            $storedName,
            $mime,
            $fileSize,
            $relativePath,
        ]);

        header('Location: ' . cloud_url('index.php?uploaded=1'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['delete']) && $pdo instanceof PDO && $currentUser && $subscription) {
    try {
        $fileId = (int) $_GET['delete'];
        $stmt = $pdo->prepare('SELECT * FROM cloud_file WHERE id = ? AND (user_id = ? OR user = ?) LIMIT 1');
        $stmt->execute([$fileId, (int) $currentUser['id'], (string) $currentUser['email']]);
        $file = $stmt->fetch();
        if ($file) {
            $path = dirname(cloud_storage_dir()) . '/' . ltrim((string) $file['relative_path'], '/\\');
            if (is_file($path)) {
                @unlink($path);
            }
            $pdo->prepare('DELETE FROM cloud_file WHERE id = ?')->execute([$fileId]);
        }
        header('Location: ' . cloud_url('index.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['uploaded'])) {
    $message = ui_label('cloud.upload_success', 'Đã tải file lên Cloud.');
}

$limitBytes = $subscription ? (int) round(((float) ($subscription['limit_gb'] ?? 0)) * 1024 * 1024 * 1024) : 0;
$usedPercent = $limitBytes > 0 ? min(100, round(($usedBytes / $limitBytes) * 100, 1)) : 0;
$styleVersion = file_exists(__DIR__ . '/styles.css') ? filemtime(__DIR__ . '/styles.css') : time();
$languageScriptVersion = file_exists(__DIR__ . '/language.js') ? filemtime(__DIR__ . '/language.js') : time();
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(ui_label('cloud.meta_title', 'CarrotCloud - Cloud Storage')) ?></title>
<meta name="description" content="<?= h(ui_label('cloud.meta_description', 'Buy cloud storage plans and keep photos, videos and documents in one private workspace.')) ?>">
<?= carrot_google_search_verification_meta($pdo ?? null, 'CarrotCloud') ?>
<meta name="theme-color" content="#f97316">
<link rel="apple-touch-icon" sizes="180x180" href="<?= h(cloud_url('favicon/apple-touch-icon.png')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= h(cloud_url('favicon/favicon-32x32.png')) ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= h(cloud_url('favicon/favicon-16x16.png')) ?>">
<link rel="icon" href="<?= h(cloud_url('favicon/favicon.ico')) ?>" sizes="any">
<link rel="manifest" href="<?= h(cloud_url('favicon/site.webmanifest')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<link rel="stylesheet" href="https://unpkg.com/lucide-static@0.468.0/font/lucide.css">
<link rel="stylesheet" href="<?= h(cloud_url('styles.css')) ?>?v=<?= $styleVersion ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<header class="cloud-nav">
    <a class="brand" href="<?= h(cloud_url('index.php')) ?>">
        <img src="<?= h(cloud_url('favicon/favicon-32x32.png')) ?>" alt="CarrotCloud">
        <span>CarrotCloud</span>
    </a>
    <nav class="nav-actions">
        <?php if ($countries): ?>
            <form method="post" class="language-form">
                <input type="hidden" name="action" value="language">
                <select class="js-cloud-language-select" name="country_id" aria-label="<?= h(ui_label('aria.choose_language', 'Choose language')) ?>">
                    <?php foreach ($countries as $country): ?>
                        <option
                            value="<?= (int) $country['id'] ?>"
                            data-lang-key="<?= h($country['lang_key']) ?>"
                            data-icon="<?= h($country['icon'] ?? '') ?>"
                            data-name="<?= h($country['name']) ?>"
                            data-lang-country="<?= h($country['lang_country']) ?>"
                            <?= (int) $country['id'] === $currentCountryId ? 'selected' : '' ?>
                        >
                            <?= h(($country['icon'] ? $country['icon'] . ' ' : '') . $country['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($currentUser): ?>
            <a class="ghost-button" href="?logout=1"><i data-lucide="log-out"></i><?= h(ui_label('action.logout', 'Logout')) ?></a>
        <?php else: ?>
            <button class="ghost-button js-cloud-login" type="button"><i data-lucide="log-in"></i><?= h(ui_label('nav.login', 'Login')) ?></button>
        <?php endif; ?>
    </nav>
</header>

<?php if ($currentUser && $subscription): ?>
<main class="nas-shell">
    <aside class="nas-sidebar">
        <div class="user-card">
            <div class="avatar"><?= h(strtoupper(substr((string) ($currentUser['name'] ?: $currentUser['email']), 0, 1))) ?></div>
            <div>
                <strong><?= h($currentUser['name'] ?: $currentUser['email']) ?></strong>
                <span><?= h($currentUser['email']) ?></span>
            </div>
        </div>
        <div class="storage-card">
            <span><?= h(ui_label('cloud.storage_used', 'Storage used')) ?></span>
            <strong><?= h(cloud_format_bytes($usedBytes)) ?> / <?= h(number_format((float) $subscription['limit_gb'], 2)) ?> GB</strong>
            <div class="storage-bar"><span style="width:<?= h((string) $usedPercent) ?>%"></span></div>
        </div>
        <div class="plan-chip">
            <i data-lucide="cloud"></i>
            <div>
                <span><?= h(ui_label('cloud.current_plan', 'Current plan')) ?></span>
                <strong><?= h((string) $subscription['name']) ?></strong>
            </div>
        </div>
    </aside>

    <section class="nas-main">
        <div class="nas-toolbar">
            <div>
                <p><?= h(ui_label('cloud.workspace', 'Private workspace')) ?></p>
                <h1><?= h(ui_label('cloud.my_files', 'My Cloud Files')) ?></h1>
            </div>
            <form class="upload-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <label class="upload-button">
                    <i data-lucide="upload-cloud"></i>
                    <span><?= h(ui_label('cloud.upload', 'Upload')) ?></span>
                    <input name="cloud_file" type="file" onchange="this.form.submit()">
                </label>
            </form>
        </div>

        <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice warning"><?= h($error) ?></div><?php endif; ?>

        <div class="file-grid">
            <?php foreach ($files as $file): ?>
                <?php
                $mime = (string) ($file['mime_type'] ?? '');
                $isImage = strpos($mime, 'image/') === 0;
                $fileUrl = cloud_url('index.php?file=' . (int) $file['id'] . '&inline=1');
                ?>
                <article class="file-tile">
                    <a class="file-preview" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <?php if ($isImage): ?>
                            <img src="<?= h($fileUrl) ?>" alt="">
                        <?php else: ?>
                            <i data-lucide="<?= h(cloud_file_icon($mime)) ?>"></i>
                        <?php endif; ?>
                    </a>
                    <div class="file-meta">
                        <strong title="<?= h($file['original_name']) ?>"><?= h($file['original_name']) ?></strong>
                        <span><?= h(cloud_format_bytes((int) $file['size_bytes'])) ?> · <?= h((string) $file['created_at']) ?></span>
                    </div>
                    <a class="file-delete" href="?delete=<?= (int) $file['id'] ?>" onclick="return confirm('Delete this file?')" title="<?= h(ui_label('action.delete', 'Delete')) ?>"><i data-lucide="trash-2"></i></a>
                </article>
            <?php endforeach; ?>
            <?php if (!$files): ?>
                <div class="empty-files">
                    <i data-lucide="folder-open"></i>
                    <h2><?= h(ui_label('cloud.empty_title', 'Your cloud is ready')) ?></h2>
                    <p><?= h(ui_label('cloud.empty_text', 'Upload photos, videos, PDF files, documents and anything you want to keep close.')) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php else: ?>
<main>
    <section class="hero">
        <div class="hero-copy">
            <p><?= h(ui_label('cloud.eyebrow', 'Secure cloud storage')) ?></p>
            <h1><?= h(ui_label('cloud.hero_title', 'CarrotCloud')) ?></h1>
            <span><?= h(ui_label('cloud.hero_text', 'Store photos, videos and documents with flexible capacity plans for every user.')) ?></span>
            <div class="hero-signals" aria-label="<?= h(ui_label('cloud.hero_features', 'Cloud highlights')) ?>">
                <div><i data-lucide="hard-drive"></i><strong><?= h(ui_label('cloud.signal_capacity', 'Flexible GB')) ?></strong></div>
                <div><i data-lucide="shield-check"></i><strong><?= h(ui_label('cloud.signal_private', 'Private files')) ?></strong></div>
                <div><i data-lucide="upload-cloud"></i><strong><?= h(ui_label('cloud.signal_upload', 'Fast upload')) ?></strong></div>
            </div>
        </div>
        <div class="login-card">
            <?php if ($currentUser): ?>
                <div class="login-card-head">
                    <span><i data-lucide="user-check"></i></span>
                    <div>
                        <h2><?= h(ui_label('cloud.account_ready', 'Account ready')) ?></h2>
                        <p><?= h($currentUser['email'] ?? ($_SESSION['home_user_email'] ?? '')) ?></p>
                    </div>
                </div>
                <div class="notice success"><?= h(ui_label('cloud.choose_plan_hint', 'Choose a Cloud plan below and pay with PayPal to open your NAS workspace.')) ?></div>
            <?php else: ?>
                <div class="login-card-head">
                    <span><i data-lucide="credit-card"></i></span>
                    <div>
                        <h2><?= h(ui_label('cloud.buy_without_login', 'Buy without login')) ?></h2>
                        <p><?= h(ui_label('cloud.buy_without_login_hint', 'Pay with PayPal first. Your plan will be linked to the payer email.')) ?></p>
                    </div>
                </div>
                <div class="notice success"><?= h(ui_label('cloud.paypal_no_login_hint', 'You can buy a plan now and login later with the same email to open your workspace.')) ?></div>
                <?php if ($error): ?><div class="notice warning"><?= h($error) ?></div><?php endif; ?>
                <?php if ($message): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
                <button class="js-cloud-login" type="button"><i data-lucide="log-in"></i><?= h(ui_label('nav.login', 'Login')) ?></button>
                <a href="../CarrotHome/login.php?mode=register"><?= h(ui_label('nav.register', 'Register')) ?></a>
            <?php endif; ?>
        </div>
    </section>

    <section class="plans-section">
        <div class="section-heading">
            <p><?= h(ui_label('cloud.plans_eyebrow', 'Choose capacity')) ?></p>
            <h2><?= h(ui_label('cloud.plans_title', 'Cloud plans')) ?></h2>
        </div>
        <div class="plans-grid">
            <?php foreach ($plans as $plan): ?>
                <article class="plan-card">
                    <div class="plan-top">
                        <h3><?= h($plan['display_name']) ?></h3>
                        <span><?= h(number_format((float) $plan['limit_gb'], 0)) ?> GB</span>
                    </div>
                    <p><?= h($plan['display_description'] ?: ui_label('cloud.plan_default', 'Private cloud capacity for photos, videos and documents.')) ?></p>
                    <div class="plan-price">
                        <strong><?= h($plan['currency']) ?> <?= h(number_format((float) $plan['price'], 2)) ?></strong>
                        <span><?= h(ui_label('cloud.per_plan', 'per plan')) ?></span>
                    </div>
                    <form class="plan-pay-form" method="get" action="<?= h(cloud_url('paypal-create.php')) ?>">
                        <input type="hidden" name="plan" value="<?= (int) $plan['id'] ?>">
                        <button type="submit"><i data-lucide="credit-card"></i><?= h(ui_label('cloud.buy_paypal', 'Buy with PayPal')) ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$plans): ?>
                <div class="empty-plan"><?= h(ui_label('cloud.no_plans', 'Cloud plans are being prepared.')) ?></div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php endif; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="<?= h(cloud_url('language.js')) ?>?v=<?= $languageScriptVersion ?>"></script>
<script>
const cloudEmailLoginEndpoint = <?= json_encode(cloud_url('login-email.php'), JSON_UNESCAPED_SLASHES) ?>;

function cloudEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}

function cloudOpenLogin() {
    if (!window.Swal) {
        window.location.href = '../CarrotHome/login.php';
        return;
    }

    const redirect = window.location.pathname + window.location.search;
    Swal.fire({
        title: <?= json_encode(ui_label('nav.login', 'Login'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        html: `
            <div class="cloud-login-popup">
                <div class="cloud-login-brand">
                    <img src="<?= h(cloud_url('favicon/android-chrome-192x192.png')) ?>" alt="CarrotCloud">
                </div>
                <p><?= h(ui_label('cloud.login_intro', 'Đăng nhập để mở workspace CarrotCloud.')) ?></p>
                <form class="cloud-login-form" data-cloud-email-login>
                    <input name="redirect" type="hidden" value="${cloudEscapeHtml(redirect)}">
                    <label>
                        <span><?= h(ui_label('label.email', 'Email')) ?></span>
                        <input name="email" type="email" autocomplete="email" required>
                    </label>
                    <label>
                        <span><?= h(ui_label('label.password', 'Password')) ?></span>
                        <input name="password" type="password" autocomplete="current-password" required>
                    </label>
                    <button class="cloud-login-submit" type="submit">
                        <i data-lucide="log-in"></i>
                        <span><?= h(ui_label('nav.login', 'Login')) ?></span>
                    </button>
                    <div class="cloud-login-message" data-cloud-login-message aria-live="polite"></div>
                </form>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        customClass: {popup: 'cloud-login-swal'},
        background: '#fff',
        didOpen: () => {
            if (window.lucide) lucide.createIcons();
            const emailField = Swal.getPopup()?.querySelector('input[name="email"]');
            if (emailField) emailField.focus();
        },
    });
}

document.addEventListener('click', (event) => {
    const loginButton = event.target.closest('.js-cloud-login');
    if (!loginButton) return;
    event.preventDefault();
    cloudOpenLogin();
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-cloud-email-login]');
    if (!form) return;

    event.preventDefault();
    const submitButton = form.querySelector('.cloud-login-submit');
    const message = form.querySelector('[data-cloud-login-message]');

    if (submitButton) submitButton.disabled = true;
    if (message) {
        message.className = 'cloud-login-message';
        message.textContent = <?= json_encode(ui_label('login.processing', 'Đang đăng nhập...'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    }

    try {
        const response = await fetch(cloudEmailLoginEndpoint, {
            method: 'POST',
            body: new FormData(form),
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || <?= json_encode(ui_label('login.error_invalid', 'Sai email hoặc mật khẩu.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        }
        if (message) {
            message.className = 'cloud-login-message is-success';
            message.textContent = payload.message || <?= json_encode(ui_label('login.success', 'Đăng nhập thành công.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        }
        window.setTimeout(() => {
            window.location.href = payload.redirect || window.location.href;
        }, 350);
    } catch (error) {
        if (message) {
            message.className = 'cloud-login-message is-error';
            message.textContent = error.message || <?= json_encode(ui_label('login.error_title', 'Không thể đăng nhập'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        }
        if (submitButton) submitButton.disabled = false;
    }
});

if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
