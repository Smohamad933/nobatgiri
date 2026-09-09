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
$bizName = setting('business_name', 'نوبت‌گیری');
$bizAbout = setting('business_about', '');
$bizPhone = setting('business_phone', '');
$bizAddress = setting('business_address', '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($bizName) ?> | رزرو آنلاین نوبت</title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>

<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= h(u('index.php')) ?>"><span class="brand-mark">◔</span><?= h($bizName) ?></a>
    <nav class="nav">
      <a href="#wizard">رزرو نوبت</a>
      <a href="#track">پیگیری نوبت</a>
      <a href="#my" id="nav-account" class="hide-m">نوبت‌های من</a>
      <span id="nav-user"></span>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="container">
    <h1>رزرو آنلاین نوبت در کمتر از یک دقیقه</h1>
    <p><?= h($bizAbout ?: 'خدمت و متخصص موردنظرتان را انتخاب کنید، ساعت خالی را ببینید و نوبتتان را قطعی کنید.') ?></p>
    <a href="#wizard" class="btn btn-ghost btn-lg">شروع رزرو ←</a>
    <div class="hero-meta">
      <span>✓ یادآوری پیامکی نوبت</span>
      <span>✓ لغو آسان تا <?= h(fa(setting_int('free_cancel_hours', 48))) ?> ساعت قبل</span>
      <span>✓ پرداخت امن آنلاین</span>
    </div>
  </div>
</section>

<main class="container wizard-wrap">
  <!-- ویزارد رزرو -->
  <div id="wizard" class="card" style="scroll-margin-top:80px">
    <div class="wizard-grid">
      <div>
        <div class="steps" id="steps"></div>
        <div id="step-body"><div class="loading-box"><span class="spinner dark"></span> در حال بارگذاری…</div></div>
        <div class="wizard-nav">
          <button class="btn btn-ghost" id="btn-prev" style="visibility:hidden">→ مرحله قبل</button>
          <button class="btn btn-primary" id="btn-next">مرحله بعد ←</button>
        </div>
      </div>
      <aside class="summary-card" id="summary"></aside>
    </div>
  </div>

  <!-- ویژگی‌ها -->
  <section class="section">
    <h2>چرا رزرو آنلاین؟</h2>
    <p class="sub">بدون تماس تلفنی و معطلی، نوبتتان را مدیریت کنید.</p>
    <div class="features">
      <div class="feature"><div class="ico">📅</div><h3>تقویم زنده</h3><p>ساعت‌های خالی هر متخصص را لحظه‌ای ببینید و همان‌جا رزرو کنید.</p></div>
      <div class="feature"><div class="ico">💬</div><h3>یادآوری پیامکی</h3><p>۲۴ ساعت و ۲ ساعت قبل از نوبت، پیامک یادآوری دریافت می‌کنید.</p></div>
      <div class="feature"><div class="ico">↩️</div><h3>لغو آسان</h3><p>تا <?= h(fa(setting_int('free_cancel_hours', 48))) ?> ساعت قبل رایگان لغو کنید و مبلغتان مسترد می‌شود.</p></div>
      <div class="feature"><div class="ico">⏳</div><h3>صف انتظار هوشمند</h3><p>اگر ظرفیت پر بود، در صف انتظار بمانید تا جا خالی شد خبرتان کنیم.</p></div>
    </div>
  </section>

  <!-- پیگیری نوبت -->
  <section class="section" id="track" style="scroll-margin-top:80px">
    <h2>پیگیری نوبت</h2>
    <p class="sub">کد پیگیری و شماره موبایلتان را وارد کنید تا جزئیات نوبت را ببینید، پرداخت کنید یا لغو کنید.</p>
    <div class="card">
      <div class="form-row">
        <div class="form-group"><label>کد پیگیری</label><input class="form-control" id="track-code" placeholder="مثل NB-XXXXXX" dir="ltr" style="text-align:center"></div>
        <div class="form-group"><label>شماره موبایل</label><input class="form-control" id="track-phone" inputmode="numeric" placeholder="09xxxxxxxxx"></div>
      </div>
      <button class="btn btn-primary" id="track-btn">نمایش نوبت</button>
      <div id="track-result"></div>
    </div>
  </section>

  <!-- نوبت‌های من -->
  <section class="section" id="my" style="scroll-margin-top:80px">
    <h2>نوبت‌های من</h2>
    <p class="sub">اگر با کد تأیید وارد شده باشید، تاریخچه‌ی نوبت‌هایتان را اینجا می‌بینید.</p>
    <div class="card"><div id="my-list"><div class="loading-box">…</div></div></div>
  </section>
</main>

<footer class="site-footer">
  <div class="container">
    <div><b><?= h($bizName) ?></b><br><?= h($bizAddress) ?><br><?= h($bizPhone) ?></div>
    <div><a href="#wizard">رزرو نوبت</a><br><a href="#track">پیگیری نوبت</a><br><a href="<?= h(u('admin/login.php')) ?>">ورود مدیر</a></div>
  </div>
</footer>

<div id="toast"></div>
<script>
window.APP = { api: <?= json_encode(u('api.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> };
</script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
</body>
</html>
