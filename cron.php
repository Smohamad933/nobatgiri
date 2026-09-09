<?php
declare(strict_types=1);
/**
 * کرون‌جاب: آزادسازی رزروهای پرداخت‌نشده + ارسال یادآورها + انقضای پیشنهادهای صف انتظار
 * اجرا: هر ۵ دقیقه
 *   CLI:  php /path/to/cron.php
 *   HTTP: https://domain/cron.php?key=CRON_KEY
 */
require __DIR__ . '/config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';
require ROOT_PATH . '/lib/Booking.php';
require ROOT_PATH . '/lib/Payment.php';
require ROOT_PATH . '/lib/Share.php';

if (!is_installed()) {
    exit("not installed\n");
}

if (PHP_SAPI !== 'cli') {
    $key = get_param('key');
    if (!$key || !hash_equals((string) setting('cron_key', ''), $key)) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);
$started = microtime(true);

try {
    check_db();
    $released = release_expired_pendings();
    [$sent, $failed] = send_due_notifications(200);
    $expired = expire_old_waiting_offers();
} catch (Throwable $e) {
    app_log('cron.log', 'ERROR: ' . $e->getMessage());
    exit('db error: ' . $e->getMessage() . "\n");
}

$summary = sprintf(
    '[%s] released=%d sent=%d failed=%d expired_offers=%d (%.2fs)',
    date('Y-m-d H:i:s'), $released, $sent, $failed, $expired, microtime(true) - $started
);
app_log('cron.log', $summary);
echo $summary . "\n";
