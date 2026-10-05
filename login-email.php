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

header('Content-Type: application/json; charset=utf-8');

function cloud_email_login_response(bool $ok, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cloud_login_url(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/CarrotCloud/login-email.php'), '/\\');
    return ($base === '' || $base === '.') ? '/' . ltrim($path, '/') : $base . '/' . ltrim($path, '/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cloud_email_login_response(false, ui_label('error.method_not_allowed', 'Phương thức không được hỗ trợ.'), 405);
}

if (!($pdo ?? null) instanceof PDO) {
    cloud_email_login_response(false, $db_error ?? ui_label('error.mysql_connection', 'Lỗi kết nối MySQL.'), 500);
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$redirect = trim((string) ($_POST['redirect'] ?? ''));

try {
    if ($email === '' || $password === '') {
        throw new RuntimeException(ui_label('login.error_required', 'Vui lòng nhập email và mật khẩu.'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(ui_label('login.error_email', 'Email không hợp lệ.'));
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
    unset($_SESSION['home_login_redirect']);

    if ($redirect === '' || preg_match('~^(?:https?:)?//~i', $redirect) || str_contains($redirect, "\n") || str_contains($redirect, "\r")) {
        $redirect = cloud_login_url('index.php');
    } elseif (str_starts_with($redirect, '/')) {
        $redirect = $redirect;
    } else {
        $redirect = cloud_login_url($redirect);
    }

    cloud_email_login_response(true, ui_label('login.success', 'Đăng nhập thành công.'), 200, [
        'redirect' => $redirect,
        'user' => [
            'id' => (int) $user['id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ],
    ]);
} catch (PDOException $e) {
    cloud_email_login_response(false, ui_label('login.error_database', 'Không thể kiểm tra tài khoản vì database users chưa sẵn sàng.'), 500);
} catch (Throwable $e) {
    cloud_email_login_response(false, $e->getMessage(), 401);
}
