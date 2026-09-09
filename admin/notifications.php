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

$status = get_param('status');
$channel = get_param('channel');
$sql = 'SELECT n.*, b.code AS booking_code FROM notifications n LEFT JOIN bookings b ON b.id = n.booking_id WHERE 1=1';
$params = [];
if ($status) {
    $sql .= ' AND n.status = ?';
    $params[] = $status;
}
if ($channel) {
    $sql .= ' AND n.channel = ?';
    $params[] = $channel;
}
$sql .= ' ORDER BY n.id DESC LIMIT 200';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$templates = ['booking_created' => 'تأیید رزرو', 'reminder_24h' => 'یادآوری ۲۴ ساعته', 'reminder_2h' => 'یادآوری ۲ ساعته', 'cancelled' => 'لغو', 'waiting_offer' => 'پیشنهاد صف انتظار'];

$pageTitle = 'اعلان‌ها و یادآوری‌ها';
$active = 'notifications';
require __DIR__ . '/_layout.php';
?>
<div class="card">
  <div class="filters">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <div class="form-group"><label>وضعیت</label>
        <select class="form-control" name="status" onchange="this.form.submit()">
          <option value="">همه</option>
          <?php foreach (['queued' => 'در صف', 'sending' => 'در حال ارسال', 'sent' => 'ارسال‌شده', 'failed' => 'ناموفق', 'cancelled' => 'لغوشده'] as $k => $v): ?>
            <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <div class="form-group"><label>&nbsp;</label>
      <button class="btn btn-primary btn-sm" id="btn-run">📤 اجرای ارسال پیام‌های سررسیده</button>
      <span id="run-res" class="small muted"></span>
    </div>
  </div>
  <?php if (setting('sms_driver', 'log') === 'log'): ?>
    <div class="alert alert-info">درایور پیامک روی حالت <b>ثبت در فایل (log)</b> است؛ پیام‌ها در <code dir="ltr">storage/logs/sms.log</code> ذخیره می‌شوند. برای ارسال واقعی، کلید کاوه‌نگار را در تنظیمات وارد کنید.</div>
  <?php endif; ?>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>گیرنده</th><th>قالب</th><th>متن</th><th>وضعیت</th><th>زمان‌بندی</th><th>ارسال</th></tr>
    <?php foreach ($rows as $n): ?>
      <tr>
        <td dir="ltr"><?= h($n['recipient']) ?><?php if ($n['booking_code']): ?><br><small class="muted" dir="ltr"><?= h($n['booking_code']) ?></small><?php endif; ?></td>
        <td><small><?= h($templates[$n['template']] ?? $n['template']) ?></small></td>
        <td class="small" style="max-width:320px"><?= h(mb_substr($n['message'], 0, 120)) ?><?= mb_strlen($n['message']) > 120 ? '…' : '' ?></td>
        <td><span class="badge b-<?= h($n['status']) ?>"><?= h($n['status']) ?></span><?php if ($n['error']): ?><br><small class="muted"><?= h($n['error']) ?></small><?php endif; ?></td>
        <td class="small"><?= h($n['scheduled_at']) ?></td>
        <td class="small"><?= h($n['sent_at'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">اعلانی نیست.</td></tr><?php endif; ?>
  </table></div>
</div>
<script>
document.getElementById('btn-run').onclick = async (e) => {
  e.target.disabled = true;
  const d = await adminApi('send_due', {}, 'POST');
  document.getElementById('run-res').textContent = d.ok ? ('ارسال: ' + d.sent + ' | ناموفق: ' + d.failed) : (d.error || 'خطا');
  if (d.ok) setTimeout(() => location.reload(), 800);
};
</script>
<?php require __DIR__ . '/_footer.php'; ?>
