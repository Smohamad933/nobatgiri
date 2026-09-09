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

// تغییر وضعیت سریع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'status') {
    require_csrf();
    try {
        biz_require_booking(post_int('id'));
        set_booking_status(post_int('id'), post('status'), post('admin_notes'));
        flash('success', 'وضعیت رزرو به‌روزرسانی شد.');
    } catch (BookingException $e) {
        flash('error', $e->getMessage());
    }
    redirect(u('provider/bookings.php?' . http_build_query($_GET)));
}

$date = get_param('date', today_str());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = today_str();
}
$branch_id = get_int('branch_id');
$staff_id = get_int('staff_id');
if ($branch_id && !in_array($branch_id, array_map('intval', $BIDS), true)) { $branch_id = 0; }
if ($staff_id && !biz_owns_staff($staff_id)) { $staff_id = 0; }
$status = get_param('status');
$q = get_param('q');

$sql = "SELECT b.*, s.name AS service_name, st.name AS staff_name, br.name AS branch_name FROM bookings b
    JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id JOIN branches br ON br.id = b.branch_id WHERE b.branch_id IN ({$BIDS_CSV})";
$params = [];
if ($date !== '' && get_param('all_dates') !== '1') {
    $sql .= ' AND b.booking_date = ?';
    $params[] = $date;
}
if ($branch_id) {
    $sql .= ' AND b.branch_id = ?';
    $params[] = $branch_id;
}
if ($staff_id) {
    $sql .= ' AND b.staff_id = ?';
    $params[] = $staff_id;
}
if ($status) {
    $sql .= ' AND b.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (b.code LIKE ? OR b.customer_name LIKE ? OR b.customer_phone LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$sql .= ' ORDER BY b.booking_date DESC, b.start_time ASC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$branches = biz_branches();
$staffAll = db()->query("SELECT s.*, b.name AS branch_name FROM staff s JOIN branches b ON b.id = s.branch_id WHERE s.branch_id IN ({$BIDS_CSV}) ORDER BY s.branch_id, s.sort")->fetchAll();

$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));

$pageTitle = 'مدیریت رزروها';
$active = 'bookings';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <form method="get">
    <div class="filters">
      <div class="form-group"><label>تاریخ</label>
        <div class="daynav">
          <a class="btn btn-ghost btn-sm" href="?<?= http_build_query(array_merge($_GET, ['date' => $prev])) ?>">→</a>
          <input type="date" class="form-control" name="date" value="<?= h($date) ?>" style="width:auto">
          <a class="btn btn-ghost btn-sm" href="?<?= http_build_query(array_merge($_GET, ['date' => $next])) ?>">←</a>
        </div>
        <small class="muted"><?= h(fa_long_date($date)) ?></small>
      </div>
      <div class="form-group"><label>شعبه</label>
        <select class="form-control" name="branch_id">
          <option value="0">همه</option>
          <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= $branch_id == $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>متخصص</label>
        <select class="form-control" name="staff_id">
          <option value="0">همه</option>
          <?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>"<?= $staff_id == $s['id'] ? ' selected' : '' ?>><?= h($s['name']) ?> (<?= h($s['branch_name']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>وضعیت</label>
        <select class="form-control" name="status">
          <option value="">همه</option>
          <?php foreach (booking_statuses() as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>جستجو (کد/نام/موبایل)</label><input class="form-control" name="q" value="<?= h($q) ?>"></div>
      <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">اعمال</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h3><?= h(fa(count($rows))) ?> رزرو</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>کد</th><th>تاریخ/ساعت</th><th>مشتری</th><th>خدمت / متخصص</th><th>مبلغ</th><th>وضعیت</th><th>اقدام</th></tr>
    <?php foreach ($rows as $b): ?>
      <tr>
        <td><a href="<?= h(u('provider/booking.php?id=' . $b['id'])) ?>" dir="ltr"><code><?= h($b['code']) ?></code></a></td>
        <td><?= h(fa_short_date($b['booking_date'])) ?><br><b><?= h(fa(substr($b['start_time'], 0, 5))) ?></b></td>
        <td><?= h($b['customer_name']) ?><br><small class="muted" dir="ltr"><?= h($b['customer_phone']) ?></small></td>
        <td><?= h($b['service_name']) ?><br><small class="muted"><?= h($b['staff_name']) ?> — <?= h($b['branch_name']) ?></small></td>
        <td><?= h(money($b['price'])) ?><br><small class="muted">پرداخت: <?= h(money($b['amount_paid'])) ?></small></td>
        <td><span class="badge st-<?= h($b['status']) ?>"><?= h(booking_status_label($b['status'])) ?></span></td>
        <td style="white-space:nowrap">
          <?php if ($b['status'] === 'confirmed'): ?>
            <form class="inline-form" method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="done"><button class="btn btn-primary btn-sm">انجام شد</button></form>
            <form class="inline-form" method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="no_show"><button class="btn btn-ghost btn-sm">عدم مراجعه</button></form>
          <?php elseif ($b['status'] === 'pending_payment'): ?>
            <form class="inline-form" method="post" onsubmit="return confirm('تأیید دستی این رزرو؟')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="confirmed"><button class="btn btn-primary btn-sm">تأیید دستی</button></form>
          <?php endif; ?>
          <?php if (in_array($b['status'], ['pending_payment', 'confirmed'], true)): ?>
            <form class="inline-form" method="post" onsubmit="return confirm('این رزرو لغو شود؟')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-danger btn-sm">لغو</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">رزروی یافت نشد.</td></tr><?php endif; ?>
  </table></div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
