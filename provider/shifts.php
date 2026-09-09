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

$weekdays = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = post('do');
    if ($do === 'add_shift') {
        $staff_id = post_int('staff_id');
        $branch_id = post_int('branch_id');
        if (!in_array($branch_id, array_map('intval', $BIDS), true) || ($staff_id && !biz_owns_staff($staff_id))) {
            flash('error', 'شعبه یا متخصص معتبر نیست.');
            redirect(u('provider/shifts.php'));
        }
        $wd = post_int('weekday', -1);
        $s = substr(post('start_time'), 0, 5);
        $e = substr(post('end_time'), 0, 5);
        if (!isset($weekdays[$wd]) || !preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e) || $e <= $s) {
            flash('error', 'روز یا ساعت نامعتبر است.');
        } else {
            db()->prepare('INSERT INTO shifts (branch_id, staff_id, weekday, start_time, end_time) VALUES (?,?,?,?,?)')
                ->execute([$branch_id ?: null, $staff_id ?: null, $wd, $s, $e]);
            flash('success', 'شیفت ثبت شد.');
        }
    } elseif ($do === 'del_shift') {
        biz_require_shift(post_int('id'));
        db()->prepare('DELETE FROM shifts WHERE id = ?')->execute([post_int('id')]);
        flash('success', 'شیفت حذف شد.');
    } elseif ($do === 'add_break') {
        if (!in_array(post_int('branch_id'), array_map('intval', $BIDS), true) || (post_int('staff_id') && !biz_owns_staff(post_int('staff_id')))) {
            flash('error', 'شعبه یا متخصص معتبر نیست.');
            redirect(u('provider/shifts.php'));
        }
        $kind = post('kind', 'daily');
        $wd = $kind === 'weekly' ? post_int('weekday', -1) : null;
        $dt = $kind === 'date' ? post('break_date') : null;
        $s = substr(post('start_time'), 0, 5);
        $e = substr(post('end_time'), 0, 5);
        if (($kind === 'weekly' && !isset($weekdays[$wd])) || ($kind === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dt)) || !preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e) || $e <= $s) {
            flash('error', 'اطلاعات استراحت نامعتبر است.');
        } else {
            db()->prepare('INSERT INTO breaks (branch_id, staff_id, weekday, break_date, start_time, end_time, reason) VALUES (?,?,?,?,?,?,?)')
                ->execute([post_int('branch_id') ?: null, post_int('staff_id') ?: null, $wd, $dt, $s, $e, post('reason')]);
            flash('success', 'استراحت/مسدودی ثبت شد.');
        }
    } elseif ($do === 'del_break') {
        biz_require_shift(post_int('id'), 'breaks');
        db()->prepare('DELETE FROM breaks WHERE id = ?')->execute([post_int('id')]);
        flash('success', 'حذف شد.');
    } elseif ($do === 'add_holiday') {
        if (!in_array(post_int('branch_id'), array_map('intval', $BIDS), true)) {
            flash('error', 'شعبه معتبر نیست.');
            redirect(u('provider/shifts.php'));
        }
        $dt = post('holiday_date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
            flash('error', 'تاریخ نامعتبر است.');
        } else {
            try {
                db()->prepare('INSERT INTO holidays (branch_id, holiday_date, title) VALUES (?,?,?)')
                    ->execute([post_int('branch_id') ?: null, $dt, post('title')]);
                flash('success', 'تعطیلی ثبت شد.');
            } catch (PDOException $e) {
                flash('error', 'این تاریخ قبلاً تعطیل اعلام شده است.');
            }
        }
    } elseif ($do === 'del_holiday') {
        biz_require_shift(post_int('id'), 'holidays');
        db()->prepare('DELETE FROM holidays WHERE id = ?')->execute([post_int('id')]);
        flash('success', 'تعطیلی حذف شد.');
    }
    redirect(u('provider/shifts.php'));
}

$branches = biz_branches();
$staffAll = db()->query("SELECT s.*, b.name AS branch_name FROM staff s JOIN branches b ON b.id = s.branch_id WHERE s.branch_id IN ({$BIDS_CSV}) ORDER BY s.branch_id, s.sort")->fetchAll();

$shifts = db()->query("SELECT sh.*, b.name AS branch_name, st.name AS staff_name FROM shifts sh
    LEFT JOIN branches b ON b.id = sh.branch_id LEFT JOIN staff st ON st.id = sh.staff_id
    LEFT JOIN staff st2 ON st2.id = sh.staff_id LEFT JOIN branches b2 ON b2.id = st2.branch_id
    WHERE sh.active = 1 AND (sh.branch_id IN ({$BIDS_CSV}) OR b2.business_id = " . (int) $business['id'] . ')
    ORDER BY sh.staff_id IS NULL DESC, sh.branch_id, sh.weekday, sh.start_time')->fetchAll();

$breaks = db()->query("SELECT br.*, b.name AS branch_name, st.name AS staff_name FROM breaks br
    LEFT JOIN branches b ON b.id = br.branch_id LEFT JOIN staff st ON st.id = br.staff_id
    LEFT JOIN staff st2 ON st2.id = br.staff_id LEFT JOIN branches b2 ON b2.id = st2.branch_id
    WHERE (br.branch_id IN ({$BIDS_CSV}) OR b2.business_id = " . (int) $business['id'] . ')
    ORDER BY br.break_date IS NULL, br.break_date, br.start_time')->fetchAll();

$holidays = db()->prepare("SELECT h.*, b.name AS branch_name FROM holidays h LEFT JOIN branches b ON b.id = h.branch_id WHERE h.holiday_date >= ? AND h.branch_id IN ({$BIDS_CSV}) ORDER BY h.holiday_date");
$holidays->execute([today_str()]);
$holidays = $holidays->fetchAll();

$pageTitle = 'شیفت‌ها، استراحت‌ها و تعطیلات';
$active = 'shifts';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <h3>➕ شیفت کاری جدید</h3>
  <p class="small muted">اگر برای متخصصی شیفت اختصاصی تعریف شود، همان ملاک است؛ وگرنه شیفت پیش‌فرض شعبه اعمال می‌شود.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_shift">
    <div class="filters">
      <div class="form-group"><label>شعبه</label>
        <select class="form-control" name="branch_id">
          
          <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>متخصص (خالی = پیش‌فرض شعبه)</label>
        <select class="form-control" name="staff_id">
          <option value="0">— پیش‌فرض شعبه —</option>
          <?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['branch_name']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>روز هفته</label>
        <select class="form-control" name="weekday"><?php foreach ($weekdays as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group"><label>از ساعت</label><input type="time" class="form-control" name="start_time" value="09:00" required></div>
      <div class="form-group"><label>تا ساعت</label><input type="time" class="form-control" name="end_time" value="21:00" required></div>
      <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">ثبت شیفت</button></div>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>محدوده</th><th>روز</th><th>ساعت</th><th></th></tr>
    <?php foreach ($shifts as $sh): ?>
      <tr>
        <td><?= $sh['staff_name'] ? '👤 ' . h($sh['staff_name']) : '🏢 پیش‌فرض ' . h($sh['branch_name'] ?? 'همه‌ی شعبه‌ها') ?></td>
        <td><?= h($weekdays[(int) $sh['weekday']] ?? '') ?></td>
        <td dir="ltr"><?= h($sh['start_time']) ?> - <?= h($sh['end_time']) ?></td>
        <td><form class="inline-form" method="post" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="del_shift"><input type="hidden" name="id" value="<?= $sh['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="card">
  <h3>☕ استراحت / ساعت مسدود</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_break">
    <div class="filters">
      <div class="form-group"><label>شعبه</label>
        <select class="form-control" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group"><label>متخصص</label>
        <select class="form-control" name="staff_id"><option value="0">همه</option><?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group"><label>تکرار</label>
        <select class="form-control" name="kind" id="break-kind">
          <option value="daily">هر روز</option>
          <option value="weekly">هفته‌ای یک روز</option>
          <option value="date">فقط یک تاریخ</option>
        </select>
      </div>
      <div class="form-group" id="break-wd" style="display:none"><label>روز هفته</label>
        <select class="form-control" name="weekday"><?php foreach ($weekdays as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group" id="break-dt" style="display:none"><label>تاریخ</label><input type="date" class="form-control" name="break_date"></div>
      <div class="form-group"><label>از</label><input type="time" class="form-control" name="start_time" value="13:00" required></div>
      <div class="form-group"><label>تا</label><input type="time" class="form-control" name="end_time" value="14:00" required></div>
      <div class="form-group"><label>علت</label><input class="form-control" name="reason" placeholder="ناهار"></div>
      <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">ثبت</button></div>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>محدوده</th><th>تکرار</th><th>ساعت</th><th>علت</th><th></th></tr>
    <?php foreach ($breaks as $br): ?>
      <tr>
        <td><?= h($br['staff_name'] ?? $br['branch_name'] ?? 'همه') ?></td>
        <td><?= $br['break_date'] ? '📆 ' . h(fa_short_date($br['break_date'])) : ($br['weekday'] !== null ? '🔁 ' . h($weekdays[(int) $br['weekday']]) . 'ها' : '🔁 هر روز') ?></td>
        <td dir="ltr"><?= h($br['start_time']) ?> - <?= h($br['end_time']) ?></td>
        <td><?= h($br['reason']) ?></td>
        <td><form class="inline-form" method="post" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="del_break"><input type="hidden" name="id" value="<?= $br['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<div class="card">
  <h3>🎌 روزهای تعطیل</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_holiday">
    <div class="filters">
      <div class="form-group"><label>شعبه</label>
        <select class="form-control" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-group"><label>تاریخ</label><input type="date" class="form-control" name="holiday_date" required></div>
      <div class="form-group"><label>عنوان</label><input class="form-control" name="title" placeholder="مثلاً تعطیل رسمی"></div>
      <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary">ثبت تعطیلی</button></div>
    </div>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>تاریخ</th><th>شعبه</th><th>عنوان</th><th></th></tr>
    <?php foreach ($holidays as $hol): ?>
      <tr>
        <td><?= h(fa_long_date($hol['holiday_date'])) ?></td>
        <td><?= h($hol['branch_name'] ?? 'همه') ?></td>
        <td><?= h($hol['title']) ?></td>
        <td><form class="inline-form" method="post" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="del_holiday"><input type="hidden" name="id" value="<?= $hol['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$holidays): ?><tr><td colspan="4" class="muted">تعطیلی آینده‌ای ثبت نشده است.</td></tr><?php endif; ?>
  </table></div>
</div>

<script>
document.getElementById('break-kind').onchange = (e) => {
  document.getElementById('break-wd').style.display = e.target.value === 'weekly' ? '' : 'none';
  document.getElementById('break-dt').style.display = e.target.value === 'date' ? '' : 'none';
};
</script>
<?php require __DIR__ . '/_footer.php'; ?>
