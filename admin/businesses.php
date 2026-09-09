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
        $fields = [
            post_int('owner_user_id') ?: null, post('name'), post('category', 'عمومی') ?: 'عمومی',
            post('description'), normalize_phone(post('phone')) ?: post('phone'),
            post('address'), post('city'), isset($_POST['active']) ? 1 : 0, post_int('sort'),
        ];
        if ($fields[1] === '') {
            flash('error', 'نام کسب‌وکار الزامی است.');
        } elseif ($id > 0) {
            db()->prepare('UPDATE businesses SET owner_user_id=?, name=?, category=?, description=?, phone=?, address=?, city=?, active=?, sort=? WHERE id=?')->execute([...$fields, $id]);
            flash('success', 'کسب‌وکار به‌روزرسانی شد.');
        } else {
            db()->prepare('INSERT INTO businesses (owner_user_id, name, category, description, phone, address, city, active, sort, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([...$fields, now_str()]);
            flash('success', 'کسب‌وکار جدید ثبت شد. حالا از بخش «شعبه‌ها» برای آن شعبه بسازید و هنگام ویرایش شعبه، کسب‌وکارش را تعیین کنید.');
        }
    } elseif ($do === 'delete') {
        $id = post_int('id');
        $st = db()->prepare('SELECT COUNT(*) FROM branches WHERE business_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            flash('error', 'این کسب‌وکار شعبه دارد و حذف نمی‌شود. ابتدا شعبه‌هایش را منتقل یا حذف کنید.');
        } else {
            db()->prepare('DELETE FROM businesses WHERE id = ?')->execute([$id]);
            flash('success', 'کسب‌وکار حذف شد.');
        }
    }
    redirect(u('admin/businesses.php'));
}

$edit = get_int('edit') ? get_business(get_int('edit')) : null;
$owners = db()->query("SELECT * FROM users WHERE role = 'provider' ORDER BY name, phone")->fetchAll();
$rows = db()->query('SELECT bz.*, u.name AS owner_name, u.phone AS owner_phone,
    (SELECT COUNT(*) FROM branches b WHERE b.business_id = bz.id) AS n_br,
    (SELECT COUNT(*) FROM services s JOIN branches b ON b.id = s.branch_id WHERE b.business_id = bz.id) AS n_svc,
    (SELECT COUNT(*) FROM staff st JOIN branches b ON b.id = st.branch_id WHERE b.business_id = bz.id) AS n_stf
    FROM businesses bz LEFT JOIN users u ON u.id = bz.owner_user_id ORDER BY bz.sort, bz.id')->fetchAll();
$categories = db()->query('SELECT DISTINCT category FROM businesses ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'مدیریت کسب‌وکارها';
$active = 'businesses';
require __DIR__ . '/_layout.php';
?>

<div class="card">
  <h3><?= $edit ? 'ویرایش کسب‌وکار' : 'کسب‌وکار جدید' ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $edit ? $edit['id'] : 0 ?>">
    <div class="form-row r3">
      <div class="form-group"><label>نام کسب‌وکار *</label><input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
      <div class="form-group"><label>دسته‌بندی شغلی</label><input class="form-control" name="category" list="bcats" value="<?= h($edit['category'] ?? 'عمومی') ?>"><datalist id="bcats"><?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist></div>
      <div class="form-group"><label>صاحب کسب‌وکار</label>
        <select class="form-control" name="owner_user_id">
          <option value="0">— بدون مالک —</option>
          <?php foreach ($owners as $o): ?><option value="<?= $o['id'] ?>"<?= ($edit['owner_user_id'] ?? null) == $o['id'] ? ' selected' : '' ?>><?= h($o['name'] ?: $o['phone']) ?> (<?= h($o['phone']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group"><label>معرفی کوتاه (در سایت نمایش داده می‌شود)</label><input class="form-control" name="description" value="<?= h($edit['description'] ?? '') ?>"></div>
    <div class="form-row r3">
      <div class="form-group"><label>شهر</label><input class="form-control" name="city" value="<?= h($edit['city'] ?? '') ?>"></div>
      <div class="form-group"><label>تلفن</label><input class="form-control" name="phone" dir="ltr" value="<?= h($edit['phone'] ?? '') ?>"></div>
      <div class="form-group"><label>ترتیب / وضعیت</label><div><input type="number" class="form-control" style="width:90px;display:inline-block" name="sort" value="<?= h($edit['sort'] ?? 0) ?>"> <label class="check" style="display:inline-flex"><input type="checkbox" name="active" value="1"<?= !isset($edit['active']) || $edit['active'] ? ' checked' : '' ?>> فعال</label></div></div>
    </div>
    <div class="form-group"><label>آدرس</label><input class="form-control" name="address" value="<?= h($edit['address'] ?? '') ?>"></div>
    <button class="btn btn-primary"><?= $edit ? 'ذخیره تغییرات' : 'افزودن کسب‌وکار' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="<?= h(u('admin/businesses.php')) ?>">انصراف</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>فهرست کسب‌وکارها</h3>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>نام</th><th>دسته</th><th>شهر</th><th>مالک</th><th>شعبه/خدمت/متخصص</th><th>وضعیت</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= h($r['name']) ?></b><?php if ($r['description']): ?><br><small class="muted"><?= h(mb_substr($r['description'], 0, 60)) ?></small><?php endif; ?><br><a class="small" href="<?= h(u('book.php?b=' . $r['id'])) ?>" target="_blank">مشاهده صفحه رزرو ↗</a></td>
        <td><?= h($r['category']) ?></td>
        <td><?= h($r['city'] ?: '—') ?></td>
        <td><?= $r['owner_user_id'] ? h($r['owner_name'] ?: $r['owner_phone']) . '<br><small class="muted" dir="ltr">' . h($r['owner_phone']) . '</small>' : '<span class="muted">—</span>' ?></td>
        <td><?= h(fa($r['n_br'])) ?> / <?= h(fa($r['n_svc'])) ?> / <?= h(fa($r['n_stf'])) ?></td>
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
