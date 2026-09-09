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
$admin = require_admin();

$pdo = db();
$today = today_str();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$monthStart = date('Y-m-01');

$count = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
};

$todayCount = $count("SELECT COUNT(*) FROM bookings WHERE booking_date = ? AND status = 'confirmed'", [$today]);
$tomorrowCount = $count("SELECT COUNT(*) FROM bookings WHERE booking_date = ? AND status = 'confirmed'", [$tomorrow]);
$pendingCount = $count("SELECT COUNT(*) FROM bookings WHERE status = 'pending_payment'");
$waitingCount = $count("SELECT COUNT(*) FROM waiting_list WHERE status IN ('waiting','offered')");
$monthRevenue = $count("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND kind IN ('full','deposit','remaining') AND created_at >= ?", [$monthStart . ' 00:00:00']);
$monthBookings = $count("SELECT COUNT(*) FROM bookings WHERE created_at >= ?", [$monthStart . ' 00:00:00']);
$notifQueued = $count("SELECT COUNT(*) FROM notifications WHERE status = 'queued'");

// درآمد ۱۴ روز اخیر برای نمودار
$rev = [];
$maxRev = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $v = $count("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND kind IN ('full','deposit','remaining') AND created_at >= ? AND created_at < ?", [$d . ' 00:00:00', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00']);
    [$jy, $jm, $jd] = gdate_to_jalali($d);
    $rev[] = ['d' => $d, 'label' => $jd . ' ' . j_month_name($jm), 'v' => $v];
    if ($v > $maxRev) {
        $maxRev = $v;
    }
}

// توزیع وضعیت‌های ماه جاری
$dist = $pdo->query("SELECT status, COUNT(*) c FROM bookings WHERE created_at >= '{$monthStart} 00:00:00' GROUP BY status")->fetchAll();

// نوبت‌های امروز و فردا
$upcoming = $pdo->prepare("SELECT b.*, s.name AS service_name, st.name AS staff_name FROM bookings b
    JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id
    WHERE b.booking_date IN (?, ?) AND b.status = 'confirmed' ORDER BY b.booking_date, b.start_time LIMIT 20");
$upcoming->execute([$today, $tomorrow]);

$pageTitle = 'داشبورد';
$active = 'dashboard';
require __DIR__ . '/_layout.php';
?>

<div class="grid g4">
  <div class="stat"><div class="n"><?= h(fa($todayCount)) ?></div><div class="t">نوبت تأییدشده‌ی امروز</div></div>
  <div class="stat"><div class="n"><?= h(fa($tomorrowCount)) ?></div><div class="t">نوبت فردا</div></div>
  <div class="stat"><div class="n"><?= h(fa($pendingCount)) ?></div><div class="t">در انتظار پرداخت</div></div>
  <div class="stat"><div class="n"><?= h(fa($waitingCount)) ?></div><div class="t">نفر در صف انتظار</div></div>
</div>

<div class="grid g2">
  <div class="card">
    <h3>درآمد ۱۴ روز اخیر</h3>
    <div class="chart-bar">
      <?php foreach ($rev as $r): $h = max(4, round($r['v'] / $maxRev * 160)); ?>
        <div class="bar" style="height:<?= $h ?>px" data-tip="<?= h($r['label']) ?>: <?= h(money($r['v'])) ?>"></div>
      <?php endforeach; ?>
    </div>
    <div class="chart-x"><?php foreach ($rev as $r): ?><span><?= h(fa($r['label'])) ?></span><?php endforeach; ?></div>
    <p class="muted small">درآمد این ماه: <b><?= h(money($monthRevenue)) ?></b> از <?= h(fa($monthBookings)) ?> رزرو</p>
  </div>
  <div class="card">
    <h3>وضعیت رزروهای این ماه</h3>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>وضعیت</th><th>تعداد</th></tr>
      <?php foreach ($dist as $d): ?>
        <tr><td><span class="badge st-<?= h($d['status']) ?>"><?= h(booking_status_label($d['status'])) ?></span></td><td><?= h(fa($d['c'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$dist): ?><tr><td colspan="2" class="muted">رزروی ثبت نشده است.</td></tr><?php endif; ?>
    </table></div>
    <h3 class="mt">اقدام سریع</h3>
    <p>
      <button class="btn btn-ghost btn-sm" id="btn-send-due">📤 اجرای یادآورها و آزادسازی‌ها</button>
      <span id="send-due-res" class="small muted"></span>
    </p>
    <p class="small muted">اعلان در صف: <?= h(fa($notifQueued)) ?> — کرون هر ۵ دقیقه: <code dir="ltr">cron.php?key=…</code> (کلید در تنظیمات)</p>
  </div>
</div>

<div class="card">
  <h3>نوبت‌های امروز و فردا</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>تاریخ</th><th>ساعت</th><th>مشتری</th><th>خدمت</th><th>متخصص</th><th>مبلغ</th><th></th></tr>
    <?php foreach ($upcoming as $b): ?>
      <tr>
        <td><?= h(fa_short_date($b['booking_date'])) ?></td>
        <td><?= h(fa(substr($b['start_time'], 0, 5))) ?></td>
        <td><?= h($b['customer_name']) ?><br><small class="muted" dir="ltr"><?= h($b['customer_phone']) ?></small></td>
        <td><?= h($b['service_name']) ?></td>
        <td><?= h($b['staff_name']) ?></td>
        <td><?= h(money($b['price'])) ?></td>
        <td><a class="btn btn-ghost btn-sm" href="<?= h(u('admin/booking.php?id=' . $b['id'])) ?>">جزئیات</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<script>
document.getElementById('btn-send-due').onclick = async (e) => {
  const btn = e.target;
  btn.disabled = true;
  const d = await adminApi('send_due', {}, 'POST');
  document.getElementById('send-due-res').textContent = d.ok
    ? ('ارسال شد: ' + d.sent + ' | ناموفق: ' + d.failed + ' | آزادسازی: ' + d.released)
    : (d.error || 'خطا');
  btn.disabled = false;
};
</script>
<?php require __DIR__ . '/_footer.php'; ?>
