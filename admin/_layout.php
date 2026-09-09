<?php
declare(strict_types=1);
// چیدمان مشترک پنل مدیریت. متغیرها: $pageTitle, $active
$adminUser = current_admin();
if (!$adminUser) {
    redirect(u('admin/login.php'));
}
$menu = [
    ['index.php', 'داشبورد', '📊', 'dashboard'],
    ['bookings.php', 'رزروها', '📅', 'bookings'],
    ['services.php', 'خدمات', '💈', 'services'],
    ['staff.php', 'متخصصان', '👩‍⚕️', 'staff'],
    ['branches.php', 'شعبه‌ها', '🏢', 'branches'],
    ['shifts.php', 'شیفت و تعطیلات', '🕐', 'shifts'],
    ['customers.php', 'مشتریان', '👥', 'customers'],
    ['waiting.php', 'صف انتظار', '⏳', 'waiting'],
    ['notifications.php', 'اعلان‌ها', '💬', 'notifications'],
    ['reports.php', 'گزارش‌ها', '📈', 'reports'],
    ['settings.php', 'تنظیمات', '⚙️', 'settings'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'پنل مدیریت') ?> | <?= h(setting('business_name', 'نوبت‌گیری')) ?></title>
<link rel="stylesheet" href="<?= h(asset('css/admin.css')) ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <a class="brand" href="<?= h(u('admin/index.php')) ?>">◔ پنل مدیریت</a>
    <?php foreach ($menu as [$file, $label, $ico, $key]): ?>
      <a class="side-link<?= ($active ?? '') === $key ? ' active' : '' ?>" href="<?= h(u('admin/' . $file)) ?>"><span><?= $ico ?></span><?= h($label) ?></a>
    <?php endforeach; ?>
    <div class="side-sec">—</div>
    <a class="side-link" href="<?= h(u('index.php')) ?>" target="_blank"><span>🌐</span>مشاهده سایت</a>
    <a class="side-link" href="<?= h(u('admin/logout.php')) ?>"><span>🚪</span>خروج</a>
  </aside>
  <div class="main">
    <div class="topbar">
      <h1><?= h($pageTitle ?? '') ?></h1>
      <span class="who"><?= h($adminUser['name'] ?: $adminUser['phone']) ?> | <?= h(fa_long_date(today_str())) ?></span>
    </div>
    <?= render_flashes() ?>
