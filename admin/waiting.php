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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $allowed = ['waiting', 'offered', 'converted', 'cancelled', 'expired'];
    if (in_array(post('status'), $allowed, true)) {
        db()->prepare('UPDATE waiting_list SET status = ? WHERE id = ?')->execute([post('status'), post_int('id')]);
        flash('success', 'وضعیت به‌روزرسانی شد.');
    }
    redirect(u('admin/waiting.php'));
}

$status = get_param('status', 'active');
$sql = 'SELECT w.*, s.name AS service_name, st.name AS staff_name, b.name AS branch_name FROM waiting_list w
    JOIN services s ON s.id = w.service_id LEFT JOIN staff st ON st.id = w.staff_id JOIN branches b ON b.id = w.branch_id';
$params = [];
if ($status === 'active') {
    $sql .= " WHERE w.status IN ('waiting','offered')";
} elseif ($status !== 'all') {
    $sql .= ' WHERE w.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY w.created_at ASC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = 'صف انتظار';
$active = 'waiting';
require __DIR__ . '/_layout.php';
?>
<div class="card">
  <p class="small muted">💡 وقتی نوبتی لغو می‌شود، به‌صورت خودکار به قدیمی‌ترین نفر واجد شرایط پیامک پیشنهاد ارسال می‌گردد.</p>
  <form method="get"><div class="filters">
    <div class="form-group"><label>وضعیت</label>
      <select class="form-control" name="status" onchange="this.form.submit()">
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>فعال (در انتظار + پیشنهادشده)</option>
        <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>همه</option>
        <?php foreach (waiting_statuses() as $k => $v): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div></form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>مشتری</th><th>خدمت</th><th>متخصص</th><th>از تاریخ</th><th>وضعیت</th><th>ثبت</th><th>اقدام</th></tr>
    <?php foreach ($rows as $w): ?>
      <tr>
        <td><b><?= h($w['customer_name']) ?></b><br><small class="muted" dir="ltr"><?= h($w['customer_phone']) ?></small></td>
        <td><?= h($w['service_name']) ?><br><small class="muted"><?= h($w['branch_name']) ?></small></td>
        <td><?= h($w['staff_name'] ?? 'هر کدام') ?></td>
        <td><?= h(fa_short_date($w['date_from'])) ?></td>
        <td><span class="badge b-<?= h($w['status']) ?>"><?= h(waiting_statuses()[$w['status']] ?? $w['status']) ?></span>
          <?php if ($w['notified_at']): ?><br><small class="muted">اطلاع: <?= h($w['notified_at']) ?></small><?php endif; ?></td>
        <td class="small"><?= h($w['created_at']) ?></td>
        <td style="white-space:nowrap">
          <?php if (in_array($w['status'], ['waiting', 'offered'], true)): ?>
            <form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $w['id'] ?>"><input type="hidden" name="status" value="converted"><button class="btn btn-primary btn-sm" title="مشتری نوبت گرفت">تبدیل به رزرو</button></form>
            <form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $w['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-ghost btn-sm">لغو</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">موردی نیست.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
