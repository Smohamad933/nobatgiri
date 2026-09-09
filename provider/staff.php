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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = post('do');
    if ($do === 'save') {
        $id = post_int('id');
        if ($id > 0) {
            biz_require_staff($id);
        }
        if (!in_array(post_int('branch_id'), array_map('intval', $BIDS), true)) {
            flash('error', 'شعبه معتبر نیست.');
            redirect(u('provider/staff.php'));
        }
        $fields = [post_int('branch_id'), post('name'), post('title'), post('specialty'), normalize_phone(post('phone')) ?: null, isset($_POST['active']) ? 1 : 0, post_int('sort')];
        if ($fields[1] === '' || $fields[0] <= 0) {
            flash('error', 'نام و شعبه الزامی است.');
        } else {
            if ($id > 0) {
                $st = db()->prepare('UPDATE staff SET branch_id=?, name=?, title=?, specialty=?, phone=?, active=?, sort=? WHERE id=?');
                $st->execute([...$fields, $id]);
            } else {
                $st = db()->prepare('INSERT INTO staff (branch_id, name, title, specialty, phone, active, sort) VALUES (?,?,?,?,?,?,?)');
                $st->execute($fields);
                $id = (int) db()->lastInsertId();
            }
            // نگاشت خدمات (فقط خدمات کسب‌وکار جاری)
            $svcIds = array_values(array_filter(array_map('intval', $_POST['services'] ?? []), 'biz_owns_service'));
            db()->prepare('DELETE FROM service_staff WHERE staff_id = ?')->execute([$id]);
            $st = db()->prepare('INSERT INTO service_staff (service_id, staff_id) VALUES (?, ?)');
            foreach ($svcIds as $sid) {
                if ($sid > 0) {
                    $st->execute([$sid, $id]);
                }
            }
            flash('success', 'مشخصات متخصص ذخیره شد.');
        }
    } elseif ($do === 'delete') {
        biz_require_staff(post_int('id'));
        $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE staff_id = ? AND status IN (\'pending_payment\',\'confirmed\')');
        $st->execute([post_int('id')]);
        if ((int) $st->fetchColumn() > 0) {
            flash('error', 'این متخصص نوبت فعال دارد و حذف نمی‌شود. آن را غیرفعال کنید.');
        } else {
            db()->prepare('DELETE FROM service_staff WHERE staff_id = ?')->execute([post_int('id')]);
            db()->prepare('DELETE FROM shifts WHERE staff_id = ?')->execute([post_int('id')]);
            db()->prepare('DELETE FROM staff WHERE id = ?')->execute([post_int('id')]);
            flash('success', 'حذف شد.');
        }
    }
    redirect(u('provider/staff.php'));
}

$edit = null;
$editServices = [];
if (get_int('edit')) {
    $edit = biz_require_staff(get_int('edit'));
    if ($edit) {
        $st = db()->prepare('SELECT service_id FROM service_staff WHERE staff_id = ?');
        $st->execute([$edit['id']]);
        $editServices = $st->fetchAll(PDO::FETCH_COLUMN);
    }
}
$branches = biz_branches();
$services = db()->query("SELECT * FROM services WHERE active = 1 AND branch_id IN ({$BIDS_CSV}) ORDER BY category, name")->fetchAll();
$rows = db()->query("SELECT s.*, b.name AS branch_name FROM staff s JOIN branches b ON b.id = s.branch_id WHERE s.branch_id IN ({$BIDS_CSV}) ORDER BY s.branch_id, s.sort, s.id")->fetchAll();

$pageTitle = 'مدیریت متخصصان';
$active = 'staff';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <h3><?= $edit ? 'ویرایش متخصص' : 'متخصص جدید' ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $edit ? $edit['id'] : 0 ?>">
    <div class="form-row r3">
      <div class="form-group"><label>نام *</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>شعبه *</label>
        <select class="form-control" name="branch_id">
          <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= ($edit['branch_id'] ?? '') == $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>سمت</label><input class="form-control" name="title" value="<?= h($edit['title'] ?? '') ?>" placeholder="مثلاً متخصص ارشد مو"></div>
    </div>
    <div class="form-row r3">
      <div class="form-group"><label>تخصص</label><input class="form-control" name="specialty" value="<?= h($edit['specialty'] ?? '') ?>"></div>
      <div class="form-group"><label>موبایل</label><input class="form-control" name="phone" dir="ltr" value="<?= h($edit['phone'] ?? '') ?>"></div>
      <div class="form-group"><label>ترتیب / وضعیت</label><div><input type="number" class="form-control" style="width:90px;display:inline-block" name="sort" value="<?= h($edit['sort'] ?? 0) ?>"> <label class="check" style="display:inline-flex"><input type="checkbox" name="active" value="1"<?= !isset($edit['active']) || $edit['active'] ? ' checked' : '' ?>> فعال</label></div></div>
    </div>
    <div class="form-group"><label>خدماتی که این متخصص انجام می‌دهد</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php foreach ($services as $s): ?>
          <label class="check" style="border:1px solid var(--line);border-radius:8px;padding:4px 10px"><input type="checkbox" name="services[]" value="<?= $s['id'] ?>"<?= in_array($s['id'], $editServices, false) ? ' checked' : '' ?>> <?= h($s['name']) ?> <small class="muted">(<?= h($s['category']) ?>)</small></label>
        <?php endforeach; ?>
      </div>
      <div class="form-hint">اگر هیچ خدمتی تیک نخورد، همه‌ی خدمات شعبه به او نسبت داده می‌شود.</div>
    </div>
    <button class="btn btn-primary"><?= $edit ? 'ذخیره تغییرات' : 'افزودن' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="<?= h(u('provider/staff.php')) ?>">انصراف</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>فهرست متخصصان</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>نام</th><th>شعبه</th><th>سمت / تخصص</th><th>خدمات</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r):
        $st = db()->prepare('SELECT s.name FROM service_staff m JOIN services s ON s.id = m.service_id WHERE m.staff_id = ?');
        $st->execute([$r['id']]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
    ?>
      <tr>
        <td><b><?= h($r['name']) ?></b><?php if ($r['phone']): ?><br><small class="muted" dir="ltr"><?= h($r['phone']) ?></small><?php endif; ?></td>
        <td><?= h($r['branch_name']) ?></td>
        <td><?= h($r['title']) ?><br><small class="muted"><?= h($r['specialty']) ?></small></td>
        <td class="small"><?= $names ? h(implode('، ', $names)) : '<span class="muted">همه</span>' ?></td>
        <td><?= $r['active'] ? '<span class="badge b-sent">فعال</span>' : '<span class="badge b-expired">غیرفعال</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-ghost btn-sm" href="?edit=<?= $r['id'] ?>">ویرایش</a>
          <form class="inline-form" method="post" onsubmit="return confirm('حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-danger btn-sm">حذف</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
