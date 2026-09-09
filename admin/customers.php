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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'notes') {
    require_csrf();
    $st = db()->prepare('UPDATE users SET notes = ?, name = ?, updated_at = ? WHERE id = ?');
    $st->execute([post('notes'), post('name'), now_str(), post_int('id')]);
    flash('success', 'مشخصات مشتری ذخیره شد.');
    redirect(u('admin/customers.php?id=' . post_int('id')));
}

$id = get_int('id');
if ($id) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $customer = $st->fetch();
    if (!$customer) {
        redirect(u('admin/customers.php'));
    }
    $st = db()->prepare('SELECT b.*, s.name AS service_name, st.name AS staff_name FROM bookings b
        JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id
        WHERE b.customer_phone = ? ORDER BY b.booking_date DESC, b.start_time DESC LIMIT 50');
    $st->execute([$customer['phone']]);
    $history = $st->fetchAll();
    $st = db()->prepare('SELECT w.*, s.name AS service_name FROM waiting_list w JOIN services s ON s.id = w.service_id WHERE w.customer_phone = ? ORDER BY w.created_at DESC');
    $st->execute([$customer['phone']]);
    $waitings = $st->fetchAll();

    $pageTitle = 'پرونده ' . ($customer['name'] ?: $customer['phone']);
    $active = 'customers';
    require __DIR__ . '/_layout.php';
    ?>
    <p><a href="<?= h(u('admin/customers.php')) ?>">→ بازگشت به فهرست</a></p>
    <div class="grid g2">
      <div class="card">
        <h3>مشخصات</h3>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="notes"><input type="hidden" name="id" value="<?= $customer['id'] ?>">
          <div class="form-group"><label>نام</label><input class="form-control" name="name" value="<?= h($customer['name']) ?>"></div>
          <div class="form-group"><label>موبایل</label><input class="form-control" dir="ltr" value="<?= h($customer['phone']) ?>" disabled></div>
          <div class="form-group"><label>یادداشت‌های مشتری</label><textarea class="form-control" name="notes" placeholder="مثلاً حساسیت، ترجیحات، سابقه…"><?= h($customer['notes']) ?></textarea></div>
          <button class="btn btn-primary">ذخیره</button>
        </form>
      </div>
      <div class="card">
        <h3>صف انتظار</h3>
        <?php if (!$waitings): ?><p class="muted">موردی نیست.</p><?php endif; ?>
        <?php foreach ($waitings as $w): ?>
          <p><span class="badge b-<?= h($w['status']) ?>"><?= h(waiting_statuses()[$w['status']] ?? $w['status']) ?></span> <?= h($w['service_name']) ?> — از <?= h(fa_short_date($w['date_from'])) ?></p>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <h3>سابقه نوبت‌ها (<?= h(fa(count($history))) ?>)</h3>
      <div class="tbl-wrap"><table class="tbl">
        <tr><th>کد</th><th>تاریخ</th><th>خدمت</th><th>متخصص</th><th>مبلغ</th><th>وضعیت</th></tr>
        <?php foreach ($history as $b): ?>
          <tr>
            <td><a href="<?= h(u('admin/booking.php?id=' . $b['id'])) ?>" dir="ltr"><code><?= h($b['code']) ?></code></a></td>
            <td><?= h(fa_short_date($b['booking_date'])) ?> <?= h(fa(substr($b['start_time'], 0, 5))) ?></td>
            <td><?= h($b['service_name']) ?></td>
            <td><?= h($b['staff_name']) ?></td>
            <td><?= h(money($b['price'])) ?></td>
            <td><span class="badge st-<?= h($b['status']) ?>"><?= h(booking_status_label($b['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

$q = get_param('q');
$sql = "SELECT u.*, (SELECT COUNT(*) FROM bookings b WHERE b.customer_phone = u.phone) AS total_n,
    (SELECT COUNT(*) FROM bookings b WHERE b.customer_phone = u.phone AND b.status = 'done') AS done_n,
    (SELECT COALESCE(SUM(amount_paid),0) FROM bookings b WHERE b.customer_phone = u.phone) AS paid_n
    FROM users u WHERE u.role = 'customer'";
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.phone LIKE ?)';
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
}
$sql .= ' ORDER BY u.created_at DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'مدیریت مشتریان';
$active = 'customers';
require __DIR__ . '/_layout.php';
?>
<div class="card">
  <form method="get"><div class="filters">
    <div class="form-group"><label>جستجو (نام/موبایل)</label><input class="form-control" name="q" value="<?= h($q) ?>"></div>
    <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">جستجو</button></div>
  </div></form>
</div>
<div class="card">
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>نام</th><th>موبایل</th><th>نوبت‌ها</th><th>انجام‌شده</th><th>جمع پرداخت</th><th>عضویت</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= h($r['name'] ?: '—') ?></b><?php if ($r['notes']): ?><br><small class="muted">📝 <?= h(mb_substr($r['notes'], 0, 40)) ?></small><?php endif; ?></td>
        <td dir="ltr"><?= h($r['phone']) ?></td>
        <td><?= h(fa($r['total_n'])) ?></td>
        <td><?= h(fa($r['done_n'])) ?></td>
        <td><?= h(money($r['paid_n'])) ?></td>
        <td class="small"><?= h(fa_short_date(substr($r['created_at'], 0, 10))) ?></td>
        <td><a class="btn btn-ghost btn-sm" href="?id=<?= $r['id'] ?>">پرونده</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">مشتری یافت نشد.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
