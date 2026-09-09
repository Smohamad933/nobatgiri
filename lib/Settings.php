<?php
declare(strict_types=1);

/** خواندن یک تنظیم از دیتابیس (با حافظه‌ی موقت) */
function setting(string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        $val = $row ? $row['value'] : $default;
    } catch (Throwable $e) {
        $val = $default;
    }
    $cache[$key] = $val;
    return $val;
}

function setting_int(string $key, int $default = 0): int
{
    return (int) setting($key, (string) $default);
}

/** ذخیره‌ی یک تنظیم */
function save_setting(string $key, $value): void
{
    $pdo = db();
    if (db_driver() === 'mysql') {
        $st = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        $st->execute([$key, (string) $value]);
    } else {
        $st = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`');
        $st->execute([$key, (string) $value]);
    }
}

/** مقادیر پیش‌فرض تنظیمات */
function default_settings(): array
{
    return [
        'business_name'            => 'کلینیک زیبایی آرا',
        'business_phone'           => '021-00000000',
        'business_address'         => 'تهران، خیابان ولیعصر',
        'business_about'           => 'رزرو آنلاین نوبت با انتخاب خدمت، متخصص و ساعت دلخواه.',
        'min_lead_hours'           => '2',     // حداقل فاصله‌ی رزرو تا شروع نوبت
        'booking_window_days'      => '30',    // رزرو تا چند روز آینده مجاز است
        'slot_step_minutes'        => '15',    // گام تولید ساعت‌ها
        'pending_timeout_minutes'  => '20',    // انقضای رزرو پرداخت‌نشده
        'free_cancel_hours'        => '48',    // لغو رایگان تا X ساعت قبل
        'late_cancel_fee_percent'  => '20',    // جریمه‌ی لغو دیرهنگام (درصد از مبلغ)
        'deposit_default_percent'  => '30',    // درصد بیعانه‌ی پیش‌فرض خدمات جدید
        'max_active_per_phone'     => '5',     // سقف نوبت فعال برای هر شماره
        'reminder_24h'             => '1',     // یادآوری ۲۴ ساعت قبل
        'reminder_2h'              => '1',     // یادآوری ۲ ساعت قبل
        'sms_driver'               => 'log',   // log | kavenegar
        'sms_sender'               => '',
        'kavenegar_api'            => '',
        'payment_driver'           => 'simulated', // simulated | zarinpal
        'zarinpal_merchant'        => '',
        'cron_key'                 => bin2hex(random_bytes(16)),
    ];
}
