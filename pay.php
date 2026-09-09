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

$token = get_param('token', post('token'));
$payment = $token ? get_payment_by_token($token) : null;
if (!$payment) {
    http_response_code(404);
    exit('پرداخت یافت نشد.');
}
$booking = booking_detail((int) $payment['booking_id']);
if (!$booking) {
    exit('رزرو یافت نشد.');
}
if ($payment['status'] === 'success') {
    redirect(u('verify.php?token=' . urlencode($token)));
}
if (!in_array($booking['status'], ['pending_payment', 'confirmed'], true)) {
    exit('این رزرو دیگر قابل پرداخت نیست (وضعیت: ' . booking_status_label($booking['status']) . ').');
}

// درگاه زرین‌پال: هدایت به درگاه واقعی
if ($payment['gateway'] === 'zarinpal') {
    $callback = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . u('verify.php?token=' . urlencode($token));
    try {
        $init = zarinpal_request($payment, $callback);
        redirect($init['url']);
    } catch (Throwable $e) {
        exit('خطا در اتصال به درگاه: ' . h($e->getMessage()));
    }
}

// درگاه شبیه‌سازی‌شده: فرم کارت نمایشی
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $card = en_digits(post('card'));
    if (!preg_match('/^\d{16}$/', str_replace([' ', '-'], '', $card))) {
        $error = 'شماره کارت باید ۱۶ رقم باشد.';
    } elseif (post('otp') === '') {
        $error = 'رمز دوم را وارد کنید.';
    } else {
        $ref = 'SIM-' . strtoupper(substr(md5($token . microtime(true)), 0, 10));
        payment_success($payment, $ref);
        redirect(u('verify.php?token=' . urlencode($token)));
    }
}
$kindLabel = ['full' => 'پرداخت کامل', 'deposit' => 'پرداخت بیعانه', 'remaining' => 'تسویه باقی‌مانده'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پرداخت | <?= h(setting('business_name')) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container">
  <a class="brand" href="<?= h(u('index.php')) ?>"><span class="brand-mark">◔</span><?= h(setting('business_name')) ?></a>
</div></header>
<main class="container">
  <div class="card pay-card">
    <h2 style="text-align:center;margin-top:0">درگاه پرداخت</h2>
    <div class="alert alert-info" style="font-size:.85rem">حالت نمایشی فعال است — هیچ مبلغ واقعی کسر نمی‌شود. برای اتصال درگاه واقعی، مرچنت زرین‌پال را در تنظیمات وارد کنید.</div>
    <div class="pay-amount"><?= h(money($payment['amount'])) ?></div>
    <div class="summary-row"><span class="k">نوع پرداخت</span><span class="v"><?= h($kindLabel[$payment['kind']] ?? $payment['kind']) ?></span></div>
    <div class="summary-row"><span class="k">خدمت</span><span class="v"><?= h($booking['service_name']) ?></span></div>
    <div class="summary-row"><span class="k">زمان</span><span class="v"><?= h(fa_long_date($booking['booking_date'])) ?> — <?= h(fa(substr($booking['start_time'], 0, 5))) ?></span></div>
    <div class="summary-row"><span class="k">کد پیگیری</span><span class="v" dir="ltr"><?= h($booking['code']) ?></span></div>
    <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <div class="form-group"><label>شماره کارت (نمایشی)</label><input class="form-control" name="card" dir="ltr" inputmode="numeric" maxlength="19" placeholder="0000 0000 0000 0000" value="6219 8600 0000 0000"></div>
      <div class="form-row">
        <div class="form-group"><label>رمز دوم</label><input class="form-control" type="password" name="otp" inputmode="numeric" maxlength="8" placeholder="••••••"></div>
        <div class="form-group"><label>CVV2</label><input class="form-control" name="cvv" inputmode="numeric" maxlength="4" placeholder="123"></div>
      </div>
      <button class="btn btn-primary btn-block btn-lg">پرداخت <?= h(money($payment['amount'])) ?></button>
      <p style="text-align:center;margin-bottom:0"><a href="<?= h(u('index.php#track')) ?>">انصراف و بازگشت</a></p>
    </form>
  </div>
</main>
</body>
</html>
