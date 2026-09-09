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
$bizId = get_int('b');
$biz = $bizId ? get_business($bizId) : null;
if (!$biz || !$biz['active']) {
    http_response_code(404);
    $missing = true;
} else {
    $missing = false;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>رزرو نوبت<?= $biz ? ' — ' . h($biz['name']) : '' ?></title>
<link rel="stylesheet" href="<?= h(asset('css/style.css')) ?>">
</head>
<body>

<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= h(u('index.php')) ?>"><span class="brand-mark">◔</span><?= h($platform) ?></a>
    <nav class="nav">
      <a href="<?= h(u('index.php')) ?>">→ همه‌ی کسب‌وکارها</a>
      <span id="nav-user"></span>
    </nav>
  </div>
</header>

<?php if ($missing): ?>
<main class="container wizard-wrap">
  <div class="card center" style="padding:48px 24px">
    <h2>کسب‌وکار یافت نشد</h2>
    <p style="color:var(--muted)">ممکن است این کسب‌وکار حذف یا غیرفعال شده باشد.</p>
    <a class="btn btn-primary" href="<?= h(u('index.php')) ?>">مشاهده‌ی کسب‌وکارها</a>
  </div>
</main>
<?php else: ?>
<section class="biz-hero" id="biz-hero">
  <div class="container">
    <span class="cat-badge" data-biz="cat"><?= h($biz['category'] ?? '') ?></span>
    <h1 data-biz="name"><?= h($biz['name']) ?></h1>
    <p data-biz="desc"><?= h($biz['description'] ?? '') ?></p>
    <div class="biz-hero-meta">
      <span>📍 <b data-biz="addr"><?= h(trim(($biz['city'] ?? '') . '، ' . ($biz['address'] ?? ''), '، ')) ?></b></span>
      <?php if (!empty($biz['phone'])): ?><span>📞 <b data-biz="phone" dir="ltr"><?= h($biz['phone']) ?></b></span><?php endif; ?>
    </div>
  </div>
</section>

<main class="container wizard-wrap">
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
</main>
<?php endif; ?>

<footer class="site-footer">
  <div class="container">
    <div><b><?= h($platform) ?></b><br>پلتفرم یکپارچه‌ی رزرو آنلاین نوبت</div>
    <div><a href="<?= h(u('index.php')) ?>">کسب‌وکارها</a><br><a href="<?= h(u('index.php')) ?>#track">پیگیری نوبت</a></div>
  </div>
</footer>

<div id="toast"></div>
<script>
window.APP = {
  api: <?= json_encode(u('api.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  home: <?= json_encode(u('index.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  businessId: <?= (int) $bizId ?>,
};
</script>
<script src="<?= h(asset('js/common.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
</body>
</html>
