<?php
declare(strict_types=1);
// چیدمان مشترک پنل صاحب خدمت. متغیرها: $pageTitle, $active (+ خروجی _guard.php)
if (!isset($provider) || !$provider) {
    $provider = require_provider();
    $myBusinesses = businesses_for_provider((int) $provider['id']);
    $business = provider_current_business($provider);
}
$menu = [
    ['index.php', 'داشبورد', '📊', 'dashboard'],
    ['bookings.php', 'رزروها', '📅', 'bookings'],
    ['manual.php', '➕ ثبت نوبت دستی', '📞', 'manual'],
    ['services.php', 'خدمات', '💈', 'services'],
    ['staff.php', 'متخصصان', '👩‍⚕️', 'staff'],
    ['branches.php', 'شعبه‌ها', '🏢', 'branches'],
    ['shifts.php', 'شیفت و تعطیلات', '🕐', 'shifts'],
    ['customers.php', 'مشتریان', '👥', 'customers'],
    ['waiting.php', 'صف انتظار', '⏳', 'waiting'],
    ['reports.php', 'گزارش‌ها', '📈', 'reports'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'پنل کسب‌وکار') ?> | <?= h($business['name'] ?? setting('business_name', 'نوبت‌یار')) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/admin.css')) ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <a class="brand" href="<?= h(u('provider/index.php')) ?>">◔ پنل کسب‌وکار</a>
    <?php foreach ($menu as [$file, $label, $ico, $key]): ?>
      <a class="side-link<?= ($active ?? '') === $key ? ' active' : '' ?>" href="<?= h(u('provider/' . $file)) ?>"><span><?= $ico ?></span><?= h($label) ?></a>
    <?php endforeach; ?>
    <div class="side-sec">—</div>
    <a class="side-link" href="<?= h(u('index.php')) ?>" target="_blank"><span>🌐</span>مشاهده سایت</a>
    <a class="side-link" href="<?= h(u('provider/logout.php')) ?>"><span>🚪</span>خروج</a>
  </aside>
  <div class="main">
    <div class="topbar">
      <h1><?= h($pageTitle ?? '') ?></h1>
      <span class="who">
        <?php if (count($myBusinesses) > 1): ?>
          <select id="biz-switch" class="form-control" style="width:auto;display:inline-block;padding:4px 8px">
            <?php foreach ($myBusinesses as $mb): ?>
              <option value="<?= $mb['id'] ?>"<?= $business && $mb['id'] == $business['id'] ? ' selected' : '' ?>><?= h($mb['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <script>
          document.getElementById('biz-switch').onchange = (e) => {
            const url = new URL(location.href);
            url.searchParams.set('biz', e.target.value);
            location.href = url.toString();
          };
          </script>
        <?php elseif ($business): ?>
          <?= h($business['name']) ?> |
        <?php endif; ?>
        <?= h($provider['name'] ?: $provider['phone']) ?> | <?= h(fa_long_date(today_str())) ?>
      </span>
    </div>
    <?= render_flashes() ?>
    <?php if (!$business): ?>
      <div class="alert alert-warning">هنوز کسب‌وکاری به حساب شما تخصیص داده نشده است. لطفاً با پشتیبانی تماس بگیرید.</div>
    <?php endif; ?>
