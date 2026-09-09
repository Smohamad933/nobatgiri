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

$from = get_param('from', date('Y-m-d', strtotime('-30 days')));
$to = get_param('to', today_str());
$branch_id = get_int('branch_id');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = today_str();
}
$fromTs = $from . ' 00:00:00';
$toTs = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
if ($branch_id && !in_array($branch_id, array_map('intval', $BIDS), true)) { $branch_id = 0; }
$brCond = $branch_id ? ' AND b.branch_id = ' . $branch_id : " AND b.branch_id IN ({$BIDS_CSV})";

$pdo = db();
// خلاصه
$sum = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN b.status IN ('confirmed','done') THEN 1 ELSE 0 END),0) active_c,
    COALESCE(SUM(b.amount_paid),0) paid, COALESCE(SUM(b.refund_amount),0) refunded
    FROM bookings b WHERE b.created_at >= '{$fromTs}' AND b.created_at < '{$toTs}'{$brCond}")->fetch();

// درآمد روزانه (بر اساس پرداخت‌های موفق)
$daily = $pdo->query("SELECT substr(p.created_at,1,10) d, COALESCE(SUM(CASE WHEN p.kind IN ('full','deposit','remaining') THEN p.amount ELSE 0 END),0) income,
    COALESCE(SUM(CASE WHEN p.kind = 'refund' THEN p.amount ELSE 0 END),0) outcome
    FROM payments p JOIN bookings b ON b.id = p.booking_id
    WHERE p.status = 'success' AND p.created_at >= '{$fromTs}' AND p.created_at < '{$toTs}'{$brCond}
    GROUP BY substr(p.created_at,1,10) ORDER BY d")->fetchAll();
$maxDaily = 1;
foreach ($daily as $d) {
    if ((int) $d['income'] > $maxDaily) {
        $maxDaily = (int) $d['income'];
    }
}

// عملکرد خدمات
$byService = $pdo->query("SELECT s.name, s.category, COUNT(*) c, COALESCE(SUM(b.amount_paid),0) paid
    FROM bookings b JOIN services s ON s.id = b.service_id
    WHERE b.created_at >= '{$fromTs}' AND b.created_at < '{$toTs}'{$brCond}
    GROUP BY s.id ORDER BY paid DESC")->fetchAll();

// عملکرد متخصصان
$byStaff = $pdo->query("SELECT st.name, COUNT(*) c, COALESCE(SUM(CASE WHEN b.status = 'done' THEN 1 ELSE 0 END),0) done_c,
    COALESCE(SUM(CASE WHEN b.status = 'no_show' THEN 1 ELSE 0 END),0) noshow_c, COALESCE(SUM(b.amount_paid),0) paid
    FROM bookings b JOIN staff st ON st.id = b.staff_id
    WHERE b.created_at >= '{$fromTs}' AND b.created_at < '{$toTs}'{$brCond}
    GROUP BY st.id ORDER BY paid DESC")->fetchAll();

// وضعیت‌ها
$byStatus = $pdo->query("SELECT b.status, COUNT(*) c FROM bookings b WHERE b.created_at >= '{$fromTs}' AND b.created_at < '{$toTs}'{$brCond} GROUP BY b.status")->fetchAll();

// خروجی CSV
if (get_param('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $from . '-' . $to . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['تاریخ', 'درآمد', 'استرداد', 'تعداد رزرو']);
    $counts = [];
    $crows = $pdo->query("SELECT b.booking_date d, COUNT(*) c FROM bookings b WHERE b.booking_date >= '{$from}' AND b.booking_date <= '{$to}'{$brCond} GROUP BY b.booking_date")->fetchAll();
    foreach ($crows as $r) {
        $counts[$r['d']] = $r['c'];
    }
    foreach ($daily as $d) {
        fputcsv($out, [$d['d'], $d['income'], $d['outcome'], $counts[$d['d']] ?? 0]);
    }
    fclose($out);
    exit;
}

$branches = biz_branches();

$pageTitle = 'گزارش‌گیری مالی و آماری';
$active = 'reports';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <form method="get"><div class="filters">
    <div class="form-group"><label>از تاریخ</label><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
    <div class="form-group"><label>تا تاریخ</label><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
    <div class="form-group"><label>شعبه</label>
      <select class="form-control" name="branch_id">
        <option value="0">همه</option>
        <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= $branch_id == $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">نمایش</button>
      <a class="btn btn-ghost" href="?<?= http_build_query(['from' => $from, 'to' => $to, 'branch_id' => $branch_id, 'export' => 'csv']) ?>">⬇ خروجی CSV</a></div>
  </div></form>
</div>

<div class="grid g4">
  <div class="stat"><div class="n"><?= h(fa($sum['c'])) ?></div><div class="t">کل رزروهای بازه</div></div>
  <div class="stat"><div class="n"><?= h(fa($sum['active_c'])) ?></div><div class="t">تأییدشده / انجام‌شده</div></div>
  <div class="stat"><div class="n" style="font-size:1.2rem"><?= h(money($sum['paid'])) ?></div><div class="t">جمع دریافتی</div></div>
  <div class="stat"><div class="n" style="font-size:1.2rem"><?= h(money($sum['refunded'])) ?></div><div class="t">جمع استرداد</div></div>
</div>

<div class="card">
  <h3>درآمد روزانه</h3>
  <div class="chart-bar">
    <?php foreach ($daily as $d): $hh = max(4, round($d['income'] / $maxDaily * 160)); ?>
      <div class="bar" style="height:<?= $hh ?>px" data-tip="<?= h(fa_short_date($d['d'])) ?>: <?= h(money($d['income'])) ?>"></div>
    <?php endforeach; ?>
    <?php if (!$daily): ?><p class="muted">پرداختی در این بازه ثبت نشده است.</p><?php endif; ?>
  </div>
  <div class="chart-x"><?php foreach ($daily as $d): [$jy, $jm, $jd] = gdate_to_jalali($d['d']); ?><span><?= h(fa($jd . '/' . $jm)) ?></span><?php endforeach; ?></div>
</div>

<div class="grid g2">
  <div class="card">
    <h3>عملکرد خدمات</h3>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>خدمت</th><th>تعداد</th><th>دریافتی</th></tr>
      <?php foreach ($byService as $r): ?><tr><td><?= h($r['name']) ?> <small class="muted">(<?= h($r['category']) ?>)</small></td><td><?= h(fa($r['c'])) ?></td><td><?= h(money($r['paid'])) ?></td></tr><?php endforeach; ?>
    </table></div>
  </div>
  <div class="card">
    <h3>عملکرد متخصصان</h3>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>متخصص</th><th>رزرو</th><th>انجام</th><th>عدم مراجعه</th><th>دریافتی</th></tr>
      <?php foreach ($byStaff as $r): ?><tr><td><?= h($r['name']) ?></td><td><?= h(fa($r['c'])) ?></td><td><?= h(fa($r['done_c'])) ?></td><td><?= h(fa($r['noshow_c'])) ?></td><td><?= h(money($r['paid'])) ?></td></tr><?php endforeach; ?>
    </table></div>
  </div>
</div>

<div class="card">
  <h3>تفکیک وضعیت‌ها</h3>
  <p><?php foreach ($byStatus as $r): ?><span class="badge st-<?= h($r['status']) ?>"><?= h(booking_status_label($r['status'])) ?>: <?= h(fa($r['c'])) ?></span> <?php endforeach; ?></p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
