<?php
declare(strict_types=1);

/**
 * احراز هویت: مشتری با کد یکبارمصرف (OTP) + مدیر با رمز عبور
 */

const OTP_TTL_SECONDS = 120;
const OTP_RESEND_SECONDS = 60;
const OTP_MAX_ATTEMPTS = 5;

// ---------- مشتری ----------

function find_user_by_phone(string $phone): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE phone = ?');
    $st->execute([normalize_phone($phone)]);
    $row = $st->fetch();
    return $row ?: null;
}

function find_or_create_customer(string $phone, ?string $name = null): array
{
    $phone = normalize_phone($phone);
    $pdo = db();
    $user = find_user_by_phone($phone);
    if ($user) {
        if ($name && !$user['name']) {
            $st = $pdo->prepare('UPDATE users SET name = ?, updated_at = ? WHERE id = ?');
            $st->execute([$name, now_str(), $user['id']]);
            $user['name'] = $name;
        }
        return $user;
    }
    $st = $pdo->prepare('INSERT INTO users (name, phone, role, created_at) VALUES (?, ?, ?, ?)');
    $st->execute([$name, $phone, 'customer', now_str()]);
    return find_user_by_phone($phone);
}

/**
 * ارسال کد تأیید. خروجی: ['wait'=>int] یا ['sent'=>true,'demo_code'=>?]
 * @return array{ok:bool,error?:string,wait?:int,demo_code?:string}
 */
function request_otp(string $phone, string $purpose = 'login'): array
{
    $phone = normalize_phone($phone);
    if (!valid_phone($phone)) {
        return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست.'];
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$phone, $purpose]);
    $last = $st->fetch();
    if ($last) {
        $wait = OTP_RESEND_SECONDS - (time() - strtotime($last['created_at']));
        if ($wait > 0 && strtotime($last['expires_at']) > time()) {
            return ['ok' => false, 'error' => 'کد قبلاً ارسال شده است.', 'wait' => $wait];
        }
    }
    $code = (string) random_int(10000, 99999);
    $st = $pdo->prepare('INSERT INTO otp_codes (phone, code_hash, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$phone, password_hash($code, PASSWORD_DEFAULT), $purpose, date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS), now_str()]);

    send_sms_direct($phone, "کد تأیید " . setting('business_name', 'نوبت‌گیری') . ": {$code}");

    $out = ['ok' => true, 'wait' => OTP_RESEND_SECONDS];
    if (setting('sms_driver', 'log') === 'log') {
        $out['demo_code'] = $code; // فقط در حالت نمایشی
    }
    return $out;
}

/** @return array{ok:bool,error?:string,user?:array} */
function verify_otp(string $phone, string $code, string $purpose = 'login'): array
{
    $phone = normalize_phone($phone);
    $code = en_digits(trim($code));
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM otp_codes WHERE phone = ? AND purpose = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$phone, $purpose]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'کدی برای این شماره ثبت نشده است.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'کد منقضی شده است. دوباره تلاش کنید.'];
    }
    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'تعداد تلاش‌ها بیش از حد مجاز است. کد جدید بگیرید.'];
    }
    $st = $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?');
    $st->execute([$row['id']]);
    if (!password_verify($code, $row['code_hash'])) {
        return ['ok' => false, 'error' => 'کد وارد شده صحیح نیست.'];
    }
    $st = $pdo->prepare('UPDATE otp_codes SET consumed_at = ? WHERE id = ?');
    $st->execute([now_str(), $row['id']]);

    $user = find_or_create_customer($phone);
    start_session();
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int) $user['id'];
    return ['ok' => true, 'user' => $user];
}

function current_customer(): ?array
{
    start_session();
    $id = (int) ($_SESSION['customer_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function customer_logout(): void
{
    start_session();
    unset($_SESSION['customer_id']);
}

// ---------- مدیر ----------

/** @return array{ok:bool,error?:string,user?:array} */
function admin_login(string $phone, string $password): array
{
    $user = find_user_by_phone($phone);
    if (!$user || $user['role'] !== 'admin' || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    return ['ok' => true, 'user' => $user];
}

function current_admin(): ?array
{
    start_session();
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND role = ?');
    $st->execute([$id, 'admin']);
    $row = $st->fetch();
    return $row ?: null;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect(u('admin/login.php'));
    }
    return $admin;
}

function admin_logout(): void
{
    start_session();
    unset($_SESSION['admin_id']);
}
