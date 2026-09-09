<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';
require ROOT_PATH . '/lib/Booking.php';
require ROOT_PATH . '/lib/Payment.php';
require ROOT_PATH . '/lib/Share.php';
require_installed();

$booking = booking_by_code(get_param('code'));
if (!$booking) {
    http_response_code(404);
    exit('رزرو یافت نشد.');
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="nobat-' . $booking['code'] . '.ics"');
echo ics_content($booking);
