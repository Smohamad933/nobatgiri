<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
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

if (current_admin()) {
    redirect(u('admin/index.php'));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $r = admin_login(post('phone'), $_POST['password'] ?? '');
    if ($r['ok']) {
        redirect(u('admin/index.php'));
    }
    $error = $r['error'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود مدیر | <?= h(setting('business_name')) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/admin.css')) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="card login-card">
    <h1>◔ ورود مدیر</h1>
    <p class="muted" style="text-align:center"><?= h(setting('business_name')) ?></p>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group"><label>شماره موبایل</label><input class="form-control" name="phone" dir="ltr" inputmode="numeric" placeholder="09xxxxxxxxx" required></div>
      <div class="form-group"><label>رمز عبور</label><input class="form-control" type="password" name="password" required></div>
      <button class="btn btn-primary" style="width:100%">ورود</button>
    </form>
    <p class="small muted" style="text-align:center">مشخصات نمایشی: 09120000000 / admin123</p>
  </div>
</div>
</body>
</html>
