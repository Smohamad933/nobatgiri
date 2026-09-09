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

$id = get_int('id', post_int('id'));
$booking = biz_require_booking($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = post('do');
    try {
        if ($do === 'status') {
            set_booking_status($id, post('status'), post('admin_notes'));
            flash('success', 'وضعیت به‌روزرسانی شد.');
        } elseif ($do === 'notes') {
            $st = db()->prepare('UPDATE bookings SET admin_notes = ?, updated_at = ? WHERE id = ?');
            $st->execute([post('admin_notes'), now_str(), $id]);
            flash('success', 'یادداشت ذخیره شد.');
        } elseif ($do === 'reschedule' && in_array($booking['status'], ['confirmed', 'pending_payment'], true)) {
            // جابه‌جایی نوبت به ساعت آزاد دیگر (همان خدمت/متخصص)
            $newDate = post('new_date');
            $newStart = substr(post('new_start'), 0, 5);
            $r = generate_slots((int) $booking['branch_id'], (int) $booking['service_id'], (int) $booking['staff_id'], $newDate);
            $found = null;
            if ($r['status'] === 'open') {
                foreach ($r['slots'] as $s) {
                    if ($s['start'] === $newStart) {
                        $found = $s;
                        break;
                    }
                }
            }
            if (!$found) {
                throw new BookingException('ساعت جدید آزاد نیست: ' . ($r['reason'] ?? ''));
            }
            try {
                $st = db()->prepare('UPDATE bookings SET booking_date = ?, start_time = ?, end_time = ?, slot_key = ?, updated_at = ? WHERE id = ?');
                $st->execute([$newDate, $found['start'], $found['end'], $booking['staff_id'] . '|' . $newDate . '|' . $found['start'], now_str(), $id]);
            } catch (PDOException $e) {
                throw new BookingException('ساعت جدید لحظاتی پیش پر شد.');
            }
            flash('success', 'نوبت جابه‌جا شد.');
        }
    } catch (BookingException $e) {
        flash('error', $e->getMessage());
    }
    redirect(u('provider/booking.php?id=' . $id));
}

$payments = db()->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY id');
$payments->execute([$id]);
$payments = $payments->fetchAll();
$notifs = db()->prepare('SELECT * FROM notifications WHERE booking_id = ? ORDER BY id');
$notifs->execute([$id]);
$notifs = $notifs->fetchAll();

$pageTitle = 'رزرو ' . $booking['code'];
$active = 'bookings';
require __DIR__ . '/_layout.php';
?>

<div class="grid g2">
  <div class="card">
    <h3>مشخصات رزرو <span class="badge st-<?= h($booking['status']) ?>"><?= h(booking_status_label($booking['status'])) ?></span></h3>
    <dl class="kv">
      <dt>کد پیگیری</dt><dd dir="ltr"><code><?= h($booking['code']) ?></code></dd>
      <dt>مشتری</dt><dd><?= h($booking['customer_name']) ?> — <span dir="ltr"><?= h($booking['customer_phone']) ?></span></dd>
      <dt>خدمت</dt><dd><?= h($booking['service_name']) ?> (<?= h($booking['service_category']) ?>)</dd>
      <dt>متخصص</dt><dd><?= h($booking['staff_name']) ?></dd>
      <dt>شعبه</dt><dd><?= h($booking['branch_name']) ?></dd>
      <dt>زمان</dt><dd><?= h(fa_datetime($booking['booking_date'], $booking['start_time'])) ?> تا <?= h(fa(substr($booking['end_time'], 0, 5))) ?></dd>
      <dt>مبلغ</dt><dd><?= h(money($booking['price'])) ?> | پرداخت‌شده: <?= h(money($booking['amount_paid'])) ?> | بیعانه لازم: <?= h(money($booking['deposit_required'])) ?></dd>
      <dt>توضیح مشتری</dt><dd><?= h($booking['notes'] ?: '—') ?></dd>
      <dt>ثبت</dt><dd><?= h(fa_short_date(substr($booking['created_at'], 0, 10))) ?> <?= h(fa(substr($booking['created_at'], 11, 5))) ?></dd>
      <?php if ($booking['status'] === 'cancelled'): ?>
        <dt>لغو</dt><dd><?= h($booking['cancel_reason']) ?> | استرداد: <?= h(money($booking['refund_amount'])) ?></dd>
      <?php endif; ?>
    </dl>
    <p class="mt">
      <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= h(gcal_link($booking)) ?>">＋ گوگل کلندر</a>
      <a class="btn btn-ghost btn-sm" href="<?= h(u('ics.php?code=' . urlencode($booking['code']))) ?>">⬇ ICS</a>
    </p>
  </div>
  <div>
    <div class="card">
      <h3>تغییر وضعیت / یادداشت مدیر</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="do" value="status">
        <div class="form-group"><label>یادداشت مدیر</label><textarea class="form-control" name="admin_notes"><?= h($booking['admin_notes']) ?></textarea></div>
        <div class="form-group"><label>وضعیت جدید</label>
          <select class="form-control" name="status">
            <?php foreach (['confirmed' => 'تأیید شده', 'done' => 'انجام شده', 'no_show' => 'عدم مراجعه', 'cancelled' => 'لغو شده'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= $booking['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary">ذخیره</button>
      </form>
    </div>
    <?php if (in_array($booking['status'], ['confirmed', 'pending_payment'], true)): ?>
      <div class="card">
        <h3>جابه‌جایی نوبت</h3>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="do" value="reschedule">
          <div class="form-row">
            <div class="form-group"><label>تاریخ جدید</label><input type="date" class="form-control" name="new_date" value="<?= h($booking['booking_date']) ?>" required></div>
            <div class="form-group"><label>ساعت جدید</label><input type="time" class="form-control" name="new_start" value="<?= h(substr($booking['start_time'], 0, 5)) ?>" required></div>
          </div>
          <button class="btn btn-ghost">جابه‌جایی (در صورت خالی بودن)</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <h3>پرداخت‌ها</h3>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>مبلغ</th><th>نوع</th><th>درگاه</th><th>وضعیت</th><th>رسید</th><th>زمان</th></tr>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= h(money($p['amount'])) ?></td>
          <td><?= h(['full' => 'کامل', 'deposit' => 'بیعانه', 'remaining' => 'باقی‌مانده', 'refund' => 'استرداد'][$p['kind']] ?? $p['kind']) ?></td>
          <td><?= h($p['gateway']) ?></td>
          <td><span class="badge b-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
          <td dir="ltr"><small><?= h($p['ref_id'] ?: '—') ?></small></td>
          <td class="small"><?= h($p['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="6" class="muted">پرداختی ثبت نشده است.</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="card">
    <h3>اعلان‌های این رزرو</h3>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>قالب</th><th>وضعیت</th><th>زمان‌بندی</th><th>ارسال</th></tr>
      <?php foreach ($notifs as $n): ?>
        <tr>
          <td><?= h($n['template']) ?></td>
          <td><span class="badge b-<?= h($n['status']) ?>"><?= h($n['status']) ?></span></td>
          <td class="small"><?= h($n['scheduled_at']) ?></td>
          <td class="small"><?= h($n['sent_at'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$notifs): ?><tr><td colspan="4" class="muted">اعلانی ثبت نشده است.</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
