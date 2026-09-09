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
    $do = post('do');
    if ($do === 'save') {
        $id = post_int('id');
        $data = [
            post_int('branch_id') ?: null, post('category', 'عمومی') ?: 'عمومی', post('name'),
            post('description'), max(0, post_int('price')), min(100, max(0, post_int('deposit_percent'))),
            max(5, post_int('duration_minutes', 30)), max(0, post_int('buffer_minutes')),
            isset($_POST['active']) ? 1 : 0, post_int('sort'),
        ];
        if ($data[2] === '') {
            flash('error', 'نام خدمت الزامی است.');
        } elseif ($id > 0) {
            $st = db()->prepare('UPDATE services SET branch_id=?, category=?, name=?, description=?, price=?, deposit_percent=?, duration_minutes=?, buffer_minutes=?, active=?, sort=? WHERE id=?');
            $st->execute([...$data, $id]);
            flash('success', 'خدمت به‌روزرسانی شد.');
        } else {
            $st = db()->prepare('INSERT INTO services (branch_id, category, name, description, price, deposit_percent, duration_minutes, buffer_minutes, active, sort) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute($data);
            flash('success', 'خدمت جدید ثبت شد.');
        }
    } elseif ($do === 'delete') {
        $st = db()->prepare('SELECT COUNT(*) FROM bookings WHERE service_id = ? AND status IN (\'pending_payment\',\'confirmed\')');
        $st->execute([post_int('id')]);
        if ((int) $st->fetchColumn() > 0) {
            flash('error', 'این خدمت نوبت فعال دارد و حذف نمی‌شود. آن را غیرفعال کنید.');
        } else {
            db()->prepare('DELETE FROM service_staff WHERE service_id = ?')->execute([post_int('id')]);
            db()->prepare('DELETE FROM services WHERE id = ?')->execute([post_int('id')]);
            flash('success', 'خدمت حذف شد.');
        }
    }
    redirect(u('admin/services.php'));
}

$edit = null;
if (get_int('edit')) {
    $edit = get_service(get_int('edit'));
}
$branches = db()->query('SELECT * FROM branches ORDER BY sort, id')->fetchAll();
$rows = db()->query('SELECT s.*, b.name AS branch_name FROM services s LEFT JOIN branches b ON b.id = s.branch_id ORDER BY s.category, s.sort, s.id')->fetchAll();
$categories = db()->query('SELECT DISTINCT category FROM services ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'مدیریت خدمات';
$active = 'services';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <h3><?= $edit ? 'ویرایش خدمت' : 'خدمت جدید' ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $edit ? $edit['id'] : 0 ?>">
    <div class="form-row r3">
      <div class="form-group"><label>نام خدمت *</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>دسته‌بندی</label><input class="form-control" name="category" list="cats" value="<?= h($edit['category'] ?? 'عمومی') ?>"><datalist id="cats"><?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist></div>
      <div class="form-group"><label>شعبه</label>
        <select class="form-control" name="branch_id">
          <option value="0">همه‌ی شعبه‌ها</option>
          <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= ($edit['branch_id'] ?? null) == $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group"><label>توضیح کوتاه</label><input class="form-control" name="description" value="<?= h($edit['description'] ?? '') ?>"></div>
    <div class="form-row r3">
      <div class="form-group"><label>قیمت (تومان)</label><input class="form-control" type="number" min="0" name="price" value="<?= h($edit['price'] ?? 0) ?>"></div>
      <div class="form-group"><label>بیعانه (٪)</label><input class="form-control" type="number" min="0" max="100" name="deposit_percent" value="<?= h($edit['deposit_percent'] ?? setting('deposit_default_percent', '30')) ?>"></div>
      <div class="form-group"><label>ترتیب نمایش</label><input class="form-control" type="number" name="sort" value="<?= h($edit['sort'] ?? 0) ?>"></div>
    </div>
    <div class="form-row r3">
      <div class="form-group"><label>مدت اجرا (دقیقه)</label><input class="form-control" type="number" min="5" name="duration_minutes" value="<?= h($edit['duration_minutes'] ?? 30) ?>"></div>
      <div class="form-group"><label>زمان آماده‌سازی بین نوبت‌ها (دقیقه)</label><input class="form-control" type="number" min="0" name="buffer_minutes" value="<?= h($edit['buffer_minutes'] ?? 0) ?>"></div>
      <div class="form-group"><label>وضعیت</label><label class="check"><input type="checkbox" name="active" value="1"<?= !isset($edit['active']) || $edit['active'] ? ' checked' : '' ?>> فعال</label></div>
    </div>
    <button class="btn btn-primary"><?= $edit ? 'ذخیره تغییرات' : 'افزودن خدمت' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="<?= h(u('admin/services.php')) ?>">انصراف</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>فهرست خدمات</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>خدمت</th><th>دسته</th><th>شعبه</th><th>قیمت</th><th>بیعانه</th><th>مدت</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= h($r['name']) ?></b><?php if ($r['description']): ?><br><small class="muted"><?= h(mb_substr($r['description'], 0, 60)) ?></small><?php endif; ?></td>
        <td><?= h($r['category']) ?></td>
        <td><?= h($r['branch_name'] ?? 'همه') ?></td>
        <td><?= h(money($r['price'])) ?></td>
        <td><?= $r['deposit_percent'] > 0 ? h(fa($r['deposit_percent'])) . '٪' : '—' ?></td>
        <td><?= h(fa($r['duration_minutes'])) ?> دقیقه</td>
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
