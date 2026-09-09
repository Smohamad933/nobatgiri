<?php
declare(strict_types=1);

/**
 * توابع کمکی عمومی: خروجی امن، اعداد فارسی، تاریخ/ساعت، پیام فلش، CSRF و...
 */

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** ارقام انگلیسی به فارسی */
function fa($v): string
{
    static $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    static $f  = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str_replace($en, $f, (string) $v);
}

/** ارقام فارسی/عربی به انگلیسی */
function en_digits(string $v): string
{
    static $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];
    return strtr($v, $map);
}

/** نرمال‌سازی شماره موبایل ایرانی به فرمت 09xxxxxxxxx */
function normalize_phone(string $phone): string
{
    $p = en_digits(trim($phone));
    $p = preg_replace('/[\s\-\(\)]/', '', $p);
    if (str_starts_with($p, '+98')) {
        $p = '0' . substr($p, 3);
    } elseif (str_starts_with($p, '98') && strlen($p) === 12) {
        $p = '0' . substr($p, 2);
    } elseif (str_starts_with($p, '9') && strlen($p) === 10) {
        $p = '0' . $p;
    }
    return $p;
}

function valid_phone(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', normalize_phone($phone));
}

/** نمایش مبلغ به تومان */
function money($amount, bool $withUnit = true): string
{
    $s = number_format((int) $amount);
    return fa($s) . ($withUnit ? ' تومان' : '');
}

/** ساعت HH:MM به دقیقه */
function time_to_min(string $t): int
{
    [$h, $m] = array_map('intval', explode(':', $t) + [0, 0]);
    return $h * 60 + $m;
}

/** دقیقه به ساعت HH:MM */
function min_to_time(int $m): string
{
    $m = max(0, $m % 1440);
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function today_str(): string
{
    return date('Y-m-d');
}

/** اختلاف ساعت بین «الان» و یک تاریخ+ساعت آینده */
function hours_until(string $date, string $time): float
{
    return (strtotime($date . ' ' . $time) - time()) / 3600;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------- پیام فلش ----------
function flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

/** @return array<int, array{type:string,msg:string}> */
function get_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function render_flashes(): string
{
    $out = '';
    foreach (get_flashes() as $f) {
        $out .= '<div class="alert alert-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
    return $out;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(?string $token = null): bool
{
    start_session();
    $token = $token ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function require_csrf(): void
{
    if (!csrf_check()) {
        http_response_code(419);
        exit('نشست شما منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.');
    }
}

// ---------- خروجی JSON ----------
/** @param mixed $data */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $code);
}

// ---------- ورودی ----------
function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function get_param(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int
{
    return (int) ($_POST[$key] ?? $default);
}

function get_int(string $key, int $default = 0): int
{
    return (int) ($_GET[$key] ?? $default);
}

// ---------- لاگ ----------
function app_log(string $file, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(LOG_PATH . '/' . $file, $line, FILE_APPEND);
}

// ---------- وضعیت‌های رزرو ----------
function booking_statuses(): array
{
    return [
        'pending_payment' => 'در انتظار پرداخت',
        'confirmed'       => 'تأیید شده',
        'cancelled'       => 'لغو شده',
        'done'            => 'انجام شده',
        'no_show'         => 'عدم مراجعه',
    ];
}

function booking_status_label(string $s): string
{
    return booking_statuses()[$s] ?? $s;
}

function waiting_statuses(): array
{
    return [
        'waiting'   => 'در صف انتظار',
        'offered'   => 'پیشنهاد ارسال شد',
        'converted' => 'تبدیل به رزرو',
        'cancelled' => 'لغو شده',
        'expired'   => 'منقضی',
    ];
}

/** تولید کد پیگیری یکتا */
function generate_booking_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 20; $i++) {
        $code = 'NB-';
        for ($j = 0; $j < 6; $j++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $st = $pdo->prepare('SELECT id FROM bookings WHERE code = ?');
        $st->execute([$code]);
        if (!$st->fetch()) {
            return $code;
        }
    }
    return 'NB-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
}
