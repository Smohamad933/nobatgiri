<?php
declare(strict_types=1);

/**
 * ============================================================
 *  سیستم نوبت‌دهی (Nobatgiri) — فایل پیکربندی مرکزی
 *  PHP 8.0+ / MySQL 5.7+ (حالت نمایشی: SQLite)
 * ============================================================
 */

const APP_NAME    = 'نوبت‌گیری';
const APP_VERSION = '1.0.0';

date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');

// ---------- مسیرها ----------
define('ROOT_PATH', __DIR__);
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');

foreach ([STORAGE_PATH, LOG_PATH] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

// ---------- خواندن فایل .env ----------
function load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $file = ROOT_PATH . '/.env';
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false && !isset($_ENV[$key])) {
            $_ENV[$key] = $val;
            putenv($key . '=' . $val);
        }
    }
}

function env(string $key, $default = null)
{
    load_env();
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// ---------- حالت اشکال‌زدایی ----------
define('APP_DEBUG', (string) env('APP_DEBUG', '0') === '1');
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php_errors.log');

// ---------- مسیر پایه‌ی URL (پشتیبانی از نصب در ساب‌فولدر) ----------
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = '';
    if (PHP_SAPI !== 'cli' && isset($_SERVER['DOCUMENT_ROOT'])) {
        $doc = realpath((string) $_SERVER['DOCUMENT_ROOT']);
        $root = realpath(ROOT_PATH);
        if ($doc && $root && str_starts_with($root, $doc)) {
            $base = str_replace('\\', '/', substr($root, strlen($doc)));
        }
    }
    return $base;
}

/** ساخت آدرس کامل نسبت به ریشه‌ی اپلیکیشن */
function u(string $path = ''): string
{
    $path = ltrim($path, '/');
    $b = base_path();
    return ($b === '' ? '' : $b) . '/' . $path;
}

/** آدرس asset */
function asset(string $path): string
{
    return u('assets/' . ltrim($path, '/')) . '?v=' . APP_VERSION;
}

// ---------- سشن امن ----------
function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // اگر مسیر پیش‌فرض سشن قابل نوشتن نیست، از پوشه‌ی امن اپ استفاده کن
    $sp = (string) ini_get('session.save_path');
    if (str_contains($sp, ';')) {
        $sp = substr($sp, strrpos($sp, ';') + 1);
    }
    if ($sp === '' || !is_dir($sp) || !is_writable($sp)) {
        $dir = STORAGE_PATH . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            ini_set('session.save_path', $dir);
        }
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('nobatgiri');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/' ?: '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------- اتصال دیتابیس (PDO: mysql یا sqlite) ----------
function db_driver(): string
{
    $d = strtolower((string) env('DB_DRIVER', 'auto'));
    if ($d === 'auto') {
        $d = env('DB_HOST') ? 'mysql' : 'sqlite';
    }
    return $d === 'mysql' ? 'mysql' : 'sqlite';
}

function pdo_dsn(): array
{
    if (db_driver() === 'mysql') {
        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (string) env('DB_PORT', '3306');
        $name = (string) env('DB_NAME', 'nobatgiri');
        return ["mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", (string) env('DB_USER', 'root'), (string) env('DB_PASS', '')];
    }
    $file = (string) env('SQLITE_PATH', STORAGE_PATH . '/app.sqlite');
    return ["sqlite:{$file}", '', ''];
}

/** @return PDO */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    [$dsn, $user, $pass] = pdo_dsn();
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if (db_driver() === 'sqlite') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+03:30'");
    }
    return $pdo;
}

/**
 * آیا سیستم نصب شده؟
 * حقیقت نصب = وجود اسکیما در دیتابیس؛ فایل قفل فقط یک نگهبان سریع است.
 * (اگر قفل پاک شده باشد ولی دیتابیس سالم باشد، همچنان «نصب‌شده» حساب می‌شود.)
 */
function is_installed(): bool
{
    if (file_exists(STORAGE_PATH . '/installed.lock')) {
        return true;
    }
    try {
        db()->query('SELECT `key` FROM settings LIMIT 1');
        // دیتابیس سالم است؛ قفل را بازسازی کن
        @file_put_contents(STORAGE_PATH . '/installed.lock', date('Y-m-d H:i:s'));
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** بررسی سلامت اتصال دیتابیس؛ در صورت خطا استثنا می‌دهد */
function check_db(): void
{
    db()->query('SELECT `key` FROM settings LIMIT 1');
}

function require_installed(): void
{
    if (!is_installed()) {
        header('Location: ' . u('install.php'));
        exit;
    }
    try {
        check_db();
    } catch (Throwable $e) {
        app_log('db_errors.log', $e->getMessage());
        http_response_code(500);
        exit('خطای اتصال به دیتابیس. لطفاً چند لحظه بعد دوباره تلاش کنید.');
    }
}

/** نسخه‌ی JSON برای API */
function require_installed_json(): void
{
    if (!is_installed()) {
        json_error('سیستم نصب نشده است.', 503);
    }
    try {
        check_db();
    } catch (Throwable $e) {
        app_log('db_errors.log', $e->getMessage());
        json_error('خطای اتصال به دیتابیس.', 500);
    }
}
