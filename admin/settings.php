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
    if (post('do') === 'regen_cron') {
        save_setting('cron_key', bin2hex(random_bytes(16)));
        flash('success', 'کلید کرون تازه‌سازی شد.');
    } else {
        $fields = [
            'business_name', 'business_phone', 'business_address', 'business_about',
            'min_lead_hours', 'booking_window_days', 'slot_step_minutes', 'pending_timeout_minutes',
            'free_cancel_hours', 'late_cancel_fee_percent', 'deposit_default_percent', 'max_active_per_phone',
            'sms_driver', 'sms_sender', 'kavenegar_api', 'payment_driver', 'zarinpal_merchant',
        ];
        foreach ($fields as $f) {
            save_setting($f, post($f, ''));
        }
        save_setting('reminder_24h', isset($_POST['reminder_24h']) ? '1' : '0');
        save_setting('reminder_2h', isset($_POST['reminder_2h']) ? '1' : '0');
        // تغییر رمز مدیر
        $np = (string) ($_POST['new_password'] ?? '');
        if ($np !== '') {
            if (mb_strlen($np) < 6) {
                flash('error', 'رمز جدید باید حداقل ۶ کاراکتر باشد.');
            } else {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($np, PASSWORD_DEFAULT), $admin['id']]);
                flash('success', 'تنظیمات و رمز عبور ذخیره شد.');
            }
        } else {
            flash('success', 'تنظیمات ذخیره شد.');
        }
    }
    redirect(u('admin/settings.php'));
}

$pageTitle = 'تنظیمات';
$active = 'settings';
require __DIR__ . '/_layout.php';
?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h3>🏢 مشخصات مجموعه</h3>
    <div class="form-row r3">
      <div class="form-group"><label>نام مجموعه</label><input class="form-control" name="business_name" value="<?= h(setting('business_name')) ?>"></div>
      <div class="form-group"><label>تلفن</label><input class="form-control" name="business_phone" value="<?= h(setting('business_phone')) ?>"></div>
      <div class="form-group"><label>آدرس</label><input class="form-control" name="business_address" value="<?= h(setting('business_address')) ?>"></div>
    </div>
    <div class="form-group"><label>معرفی کوتاه (نمایش در صفحه اصلی)</label><input class="form-control" name="business_about" value="<?= h(setting('business_about')) ?>"></div>
  </div>

  <div class="card">
    <h3>📏 قوانین رزرو</h3>
    <div class="form-row r3">
      <div class="form-group"><label>حداقل فاصله رزرو تا نوبت (ساعت)</label><input class="form-control" type="number" min="0" name="min_lead_hours" value="<?= h(setting('min_lead_hours', '2')) ?>"></div>
      <div class="form-group"><label>رزرو تا چند روز آینده مجاز است</label><input class="form-control" type="number" min="1" name="booking_window_days" value="<?= h(setting('booking_window_days', '30')) ?>"></div>
      <div class="form-group"><label>گام ساعت‌ها (دقیقه)</label><input class="form-control" type="number" min="5" step="5" name="slot_step_minutes" value="<?= h(setting('slot_step_minutes', '15')) ?>"></div>
    </div>
    <div class="form-row r3">
      <div class="form-group"><label>مهلت پرداخت رزرو (دقیقه)</label><input class="form-control" type="number" min="5" name="pending_timeout_minutes" value="<?= h(setting('pending_timeout_minutes', '20')) ?>"></div>
      <div class="form-group"><label>بیعانه پیش‌فرض خدمات جدید (٪)</label><input class="form-control" type="number" min="0" max="100" name="deposit_default_percent" value="<?= h(setting('deposit_default_percent', '30')) ?>"></div>
      <div class="form-group"><label>سقف نوبت فعال هر شماره</label><input class="form-control" type="number" min="1" name="max_active_per_phone" value="<?= h(setting('max_active_per_phone', '5')) ?>"></div>
    </div>
  </div>

  <div class="card">
    <h3>↩️ سیاست لغو و استرداد</h3>
    <div class="form-row">
      <div class="form-group"><label>لغو رایگان تا چند ساعت قبل</label><input class="form-control" type="number" min="0" name="free_cancel_hours" value="<?= h(setting('free_cancel_hours', '48')) ?>"></div>
      <div class="form-group"><label>جریمه لغو دیرهنگام (٪ از مبلغ)</label><input class="form-control" type="number" min="0" max="100" name="late_cancel_fee_percent" value="<?= h(setting('late_cancel_fee_percent', '20')) ?>"></div>
    </div>
  </div>

  <div class="card">
    <h3>💬 یادآوری و پیامک</h3>
    <div class="form-row r3">
      <div class="form-group"><label>یادآوری‌ها</label>
        <label class="check"><input type="checkbox" name="reminder_24h" value="1"<?= setting('reminder_24h', '1') === '1' ? ' checked' : '' ?>> ۲۴ ساعت قبل</label><br>
        <label class="check"><input type="checkbox" name="reminder_2h" value="1"<?= setting('reminder_2h', '1') === '1' ? ' checked' : '' ?>> ۲ ساعت قبل</label>
      </div>
      <div class="form-group"><label>درایور پیامک</label>
        <select class="form-control" name="sms_driver">
          <option value="log"<?= setting('sms_driver', 'log') === 'log' ? ' selected' : '' ?>>ثبت در فایل (نمایشی)</option>
          <option value="kavenegar"<?= setting('sms_driver') === 'kavenegar' ? ' selected' : '' ?>>کاوه‌نگار</option>
        </select>
      </div>
      <div class="form-group"><label>شماره فرستنده (اختیاری)</label><input class="form-control" name="sms_sender" dir="ltr" value="<?= h(setting('sms_sender', '')) ?>"></div>
    </div>
    <div class="form-group"><label>کلید API کاوه‌نگار</label><input class="form-control" name="kavenegar_api" dir="ltr" value="<?= h(setting('kavenegar_api', '')) ?>"></div>
  </div>

  <div class="card">
    <h3>💳 درگاه پرداخت</h3>
    <div class="form-row">
      <div class="form-group"><label>درگاه فعال</label>
        <select class="form-control" name="payment_driver">
          <option value="simulated"<?= setting('payment_driver', 'simulated') === 'simulated' ? ' selected' : '' ?>>شبیه‌سازی‌شده (نمایشی)</option>
          <option value="zarinpal"<?= setting('payment_driver') === 'zarinpal' ? ' selected' : '' ?>>زرین‌پال</option>
        </select>
      </div>
      <div class="form-group"><label>مرچنت زرین‌پال</label><input class="form-control" name="zarinpal_merchant" dir="ltr" value="<?= h(setting('zarinpal_merchant', '')) ?>"></div>
    </div>
  </div>

  <div class="card">
    <h3>🔑 امنیت و کرون</h3>
    <div class="form-group"><label>آدرس کرون‌جاب (هر ۵ دقیقه)</label><input class="form-control" dir="ltr" readonly value="<?= h((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . u('cron.php?key=' . setting('cron_key', ''))) ?>"></div>
    <div class="form-group"><label>رمز عبور جدید مدیر (خالی = بدون تغییر)</label><input class="form-control" type="password" name="new_password" autocomplete="new-password"></div>
  </div>

  <button class="btn btn-primary">💾 ذخیره همه تنظیمات</button>
</form>
<form method="post" class="inline-form" style="margin-top:10px" onsubmit="return confirm('کلید کرون عوض شود؟ آدرس قبلی از کار می‌افتد.')">
  <?= csrf_field() ?><input type="hidden" name="do" value="regen_cron">
  <button class="btn btn-ghost">تازه‌سازی کلید کرون</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
