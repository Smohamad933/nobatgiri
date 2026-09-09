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
$platform = setting('business_name', 'نوبت‌یار');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($platform) ?> | رزرو آنلاین نوبت</title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>

<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= h(u('index.php')) ?>"><span class="brand-mark">◔</span><?= h($platform) ?></a>
    <nav class="nav">
      <a href="#businesses">کسب‌وکارها</a>
      <a href="#track">پیگیری نوبت</a>
      <a href="#my" id="nav-account" class="hide-m">نوبت‌های من</a>
      <span id="nav-user"></span>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="container">
    <h1>هر نوبتی، از هر کسب‌وکاری — یک‌جا</h1>
    <p>آرایشگاه، کلینیک، پزشک، تعمیرکار و ده‌ها خدمت دیگر؛ ساعت خالی را ببینید و در کمتر از یک دقیقه رزرو کنید.</p>
    <div class="search-bar">
      <input id="biz-search" placeholder="جستجوی کسب‌وکار، خدمت یا شهر… (مثلاً کوتاهی مو)">
      <span class="search-ico">🔍</span>
    </div>
    <div class="cat-pills" id="cat-pills"></div>
  </div>
</section>

<main class="container">
  <!-- لیست کسب‌وکارها -->
  <section class="section" id="businesses" style="scroll-margin-top:80px">
    <div class="section-head">
      <div>
        <h2>کسب‌وکارها</h2>
        <p class="sub" id="biz-count"></p>
      </div>
      <div class="form-group" style="min-width:180px;margin:0">
        <select class="form-control" id="city-filter"><option value="">همه‌ی شهرها</option></select>
      </div>
    </div>
    <div id="biz-list"><div class="loading-box"><span class="spinner dark"></span> در حال بارگذاری…</div></div>
  </section>

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
    <div><b><?= h($platform) ?></b><br>پلتفرم یکپارچه‌ی رزرو آنلاین نوبت</div>
    <div><a href="#businesses">کسب‌وکارها</a><br><a href="#track">پیگیری نوبت</a><br><a href="<?= h(u('provider/login.php')) ?>">ورود صاحبان کسب‌وکار</a><br><a href="<?= h(u('admin/login.php')) ?>">ورود مدیر</a></div>
  </div>
</footer>

<div id="toast"></div>
<script>
window.APP = { api: <?= json_encode(u('api.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> };
</script>
<script src="<?= h(asset('js/common.js')) ?>"></script>
<script src="<?= h(asset('js/market.js')) ?>"></script>
</body>
</html>
