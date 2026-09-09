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
        $fields = [post('name'), post('address'), post('phone'), isset($_POST['active']) ? 1 : 0, post_int('sort')];
        if ($fields[0] === '') {
            flash('error', 'نام شعبه الزامی است.');
        } elseif ($id > 0) {
            biz_require_branch($id);
            db()->prepare('UPDATE branches SET name=?, address=?, phone=?, active=?, sort=? WHERE id=?')->execute([...$fields, $id]);
            flash('success', 'شعبه به‌روزرسانی شد.');
        } else {
            db()->prepare('INSERT INTO branches (business_id, name, address, phone, active, sort, created_at) VALUES (?,?,?,?,?,?,?)')->execute([(int) $business['id'], ...$fields, now_str()]);
            flash('success', 'شعبه جدید ثبت شد.');
        }
    } elseif ($do === 'delete') {
        $id = post_int('id');
        biz_require_branch($id);
        $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE branch_id = ? AND status IN (\'pending_payment\',\'confirmed\')');
        $st->execute([$id]);
        $st2 = db()->prepare('SELECT COUNT(*) FROM staff WHERE branch_id = ?');
        $st2->execute([$id]);
        if ((int) $st->fetchColumn() > 0 || (int) $st2->fetchColumn() > 0) {
            flash('error', 'این شعبه نوبت فعال یا متخصص دارد و حذف نمی‌شود. آن را غیرفعال کنید.');
        } else {
            db()->prepare('DELETE FROM branches WHERE id = ?')->execute([$id]);
            flash('success', 'شعبه حذف شد.');
        }
    }
    redirect(u('provider/branches.php'));
}

$edit = get_int('edit') ? biz_require_branch(get_int('edit')) : null;
$rows = db()->query("SELECT b.*, (SELECT COUNT(*) FROM staff s WHERE s.branch_id = b.id AND s.active = 1) AS staff_count FROM branches b WHERE b.business_id = " . (int) $business['id'] . ' ORDER BY b.sort, b.id')->fetchAll();

$pageTitle = 'مدیریت شعبه‌ها';
$active = 'branches';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <h3><?= $edit ? 'ویرایش شعبه' : 'شعبه جدید' ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $edit ? $edit['id'] : 0 ?>">
    <div class="form-row r3">
      <div class="form-group"><label>نام شعبه *</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>تلفن</label><input class="form-control" name="phone" value="<?= h($edit['phone'] ?? '') ?>"></div>
      <div class="form-group"><label>ترتیب / وضعیت</label><div><input type="number" class="form-control" style="width:90px;display:inline-block" name="sort" value="<?= h($edit['sort'] ?? 0) ?>"> <label class="check" style="display:inline-flex"><input type="checkbox" name="active" value="1"<?= !isset($edit['active']) || $edit['active'] ? ' checked' : '' ?>> فعال</label></div></div>
    </div>
    <div class="form-group"><label>آدرس</label><input class="form-control" name="address" value="<?= h($edit['address'] ?? '') ?>"></div>
    <button class="btn btn-primary"><?= $edit ? 'ذخیره تغییرات' : 'افزودن شعبه' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="<?= h(u('provider/branches.php')) ?>">انصراف</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>فهرست شعبه‌ها</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>نام</th><th>آدرس</th><th>تلفن</th><th>متخصص فعال</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= h($r['name']) ?></b></td>
        <td><?= h($r['address']) ?></td>
        <td dir="ltr"><?= h($r['phone']) ?></td>
        <td><?= h(fa($r['staff_count'])) ?></td>
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
