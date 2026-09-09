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
start_session();

$token = get_param('token');
$ok = false;
$message = '';
$booking = null;

if ($token) {
    $payment = get_payment_by_token($token);
    if ($payment) {
        // بازگشت از زرین‌پال؟
        if ($payment['gateway'] === 'zarinpal' && $payment['status'] === 'pending') {
            $authority = get_param('Authority');
            $status = get_param('Status');
            if ($status === 'OK' && $authority && zarinpal_verify($payment, $authority)) {
                $ref = 'ZP-' . substr($authority, 0, 12);
                $booking = payment_success($payment, $ref);
                $ok = true;
            } else {
                payment_fail($payment, 'canceled_or_failed');
                $message = 'پرداخت ناموفق بود یا توسط شما لغو شد.';
                $booking = booking_detail((int) $payment['booking_id']);
            }
        } elseif ($payment['status'] === 'success') {
            $ok = true;
            $booking = booking_detail((int) $payment['booking_id']);
        } elseif ($payment['status'] === 'failed') {
            $message = 'پرداخت ناموفق بود.';
            $booking = booking_detail((int) $payment['booking_id']);
        } else {
            $message = 'پرداخت هنوز تکمیل نشده است.';
            $booking = booking_detail((int) $payment['booking_id']);
        }
    } else {
        $message = 'تراکنش یافت نشد.';
    }
} else {
    $message = 'درخواست نامعتبر است.';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نتیجه پرداخت | <?= h(setting('business_name')) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container">
  <a class="brand" href="<?= h(u('index.php')) ?>"><span class="brand-mark">◔</span><?= h(setting('business_name')) ?></a>
</div></header>
<main class="container">
  <div class="card pay-card">
    <?php if ($ok && $booking): ?>
      <div class="success-hero">
        <div class="big">🎉</div>
        <h2>پرداخت موفق — نوبت شما قطعی شد!</h2>
        <p>کد پیگیری:</p>
        <div class="code"><?= h($booking['code']) ?></div>
      </div>
      <div class="summary-row"><span class="k">خدمت</span><span class="v"><?= h($booking['service_name']) ?></span></div>
      <div class="summary-row"><span class="k">متخصص</span><span class="v"><?= h($booking['staff_name']) ?></span></div>
      <div class="summary-row"><span class="k">شعبه</span><span class="v"><?= h($booking['branch_name']) ?></span></div>
      <div class="summary-row"><span class="k">زمان</span><span class="v"><?= h(fa_datetime($booking['booking_date'], $booking['start_time'])) ?> تا <?= h(fa(substr($booking['end_time'], 0, 5))) ?></span></div>
      <div class="summary-row"><span class="k">پرداخت‌شده</span><span class="v"><?= h(money($booking['amount_paid'])) ?></span></div>
      <div class="result-actions" style="justify-content:center;margin-top:18px">
        <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= h(gcal_link($booking)) ?>">＋ افزودن به گوگل کلندر</a>
        <a class="btn btn-ghost btn-sm" href="<?= h(u('ics.php?code=' . urlencode($booking['code']))) ?>">⬇ دانلود ICS</a>
      </div>
      <p class="form-hint" style="text-align:center">پیامک تأیید و یادآوری برایتان ارسال می‌شود.</p>
      <p style="text-align:center"><a class="btn btn-primary" href="<?= h(u('index.php')) ?>">بازگشت به صفحه اصلی</a></p>
    <?php else: ?>
      <div class="success-hero"><div class="big">😕</div><h2><?= h($message ?: 'پرداخت ناموفق') ?></h2></div>
      <?php if ($booking): ?>
        <div class="summary-row"><span class="k">کد پیگیری</span><span class="v" dir="ltr"><?= h($booking['code']) ?></span></div>
        <p class="form-hint">رزرو شما به‌مدت <?= h(fa(setting_int('pending_timeout_minutes', 20))) ?> دقیقه نگه داشته می‌شود. می‌توانید دوباره تلاش کنید.</p>
        <p style="text-align:center"><a class="btn btn-primary" href="<?= h(u('index.php#track')) ?>">تلاش مجدد از بخش پیگیری</a></p>
      <?php else: ?>
        <p style="text-align:center"><a class="btn btn-primary" href="<?= h(u('index.php')) ?>">بازگشت</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
