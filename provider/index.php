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
require __DIR__ . '/_guard.php';

$pdo = db();
$today = today_str();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$monthStart = date('Y-m-01');
$IN = "b.branch_id IN ({$BIDS_CSV})";

$count = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
};

$todayCount = $count("SELECT COUNT(*) FROM bookings b WHERE b.booking_date = ? AND b.status = 'confirmed' AND {$IN}", [$today]);
$tomorrowCount = $count("SELECT COUNT(*) FROM bookings b WHERE b.booking_date = ? AND b.status = 'confirmed' AND {$IN}", [$tomorrow]);
$pendingCount = $count("SELECT COUNT(*) FROM bookings b WHERE b.status = 'pending_payment' AND {$IN}");
$waitingCount = $count("SELECT COUNT(*) FROM waiting_list w WHERE w.status IN ('waiting','offered') AND w.branch_id IN ({$BIDS_CSV})");
$monthRevenue = $count("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.status = 'success' AND p.kind IN ('full','deposit','remaining') AND p.created_at >= ? AND {$IN}", [$monthStart . ' 00:00:00']);
$monthBookings = $count("SELECT COUNT(*) FROM bookings b WHERE b.created_at >= ? AND {$IN}", [$monthStart . ' 00:00:00']);
$notifQueued = $count("SELECT COUNT(*) FROM notifications n JOIN bookings b ON b.id = n.booking_id WHERE n.status = 'queued' AND {$IN}");

// درآمد ۱۴ روز اخیر برای نمودار
$rev = [];
$maxRev = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $d2 = date('Y-m-d', strtotime($d . ' +1 day'));
    $v = $count("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.status = 'success' AND p.kind IN ('full','deposit','remaining') AND p.created_at >= ? AND p.created_at < ? AND {$IN}", [$d . ' 00:00:00', $d2 . ' 00:00:00']);
    [$jy, $jm, $jd] = gdate_to_jalali($d);
    $rev[] = ['d' => $d, 'label' => $jd . ' ' . j_month_name($jm), 'v' => $v];
    if ($v > $maxRev) {
        $maxRev = $v;
    }
}

// توزیع وضعیت‌های ماه جاری
$dist = $pdo->query("SELECT b.status, COUNT(*) c FROM bookings b WHERE b.created_at >= '{$monthStart} 00:00:00' AND {$IN} GROUP BY b.status")->fetchAll();

// نوبت‌های امروز و فردا
$upcoming = $pdo->prepare("SELECT b.*, s.name AS service_name, st.name AS staff_name FROM bookings b
    JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id
    WHERE b.booking_date IN (?, ?) AND b.status = 'confirmed' AND {$IN} ORDER BY b.booking_date, b.start_time LIMIT 20");
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
      <a class="btn btn-primary btn-sm" href="<?= h(u('provider/manual.php')) ?>">📞 ثبت نوبت دستی (تلفنی)</a>
      <a class="btn btn-ghost btn-sm" href="<?= h(u('provider/bookings.php')) ?>">مشاهده رزروها</a>
    </p>
    <p class="small muted">اعلان در صف ارسال: <?= h(fa($notifQueued)) ?></p>
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
        <td><a class="btn btn-ghost btn-sm" href="<?= h(u('provider/booking.php?id=' . $b['id'])) ?>">جزئیات</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
