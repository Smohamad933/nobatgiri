<?php
declare(strict_types=1);
/**
 * نصب‌کننده‌ی تحت وب سیستم نوبت‌دهی
 * بعد از نصب موفق، این فایل را حذف کنید.
 */
require __DIR__ . '/config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';
require ROOT_PATH . '/lib/Booking.php';
require ROOT_PATH . '/lib/Payment.php';
require ROOT_PATH . '/lib/Share.php';
require ROOT_PATH . '/database/seed.php';
start_session();

$lockFile = STORAGE_PATH . '/installed.lock';
$alreadyInstalled = is_installed();

// نصب مجدد
if ($alreadyInstalled && ($_SERVER['REQUEST_METHOD'] === 'POST') && post('do') === 'reinstall' && isset($_POST['confirm'])) {
    require_csrf();
    @unlink($lockFile);
    if (db_driver() === 'sqlite') {
        [$dsn] = pdo_dsn();
        $file = substr($dsn, 7);
        @unlink($file);
        @unlink($file . '-wal');
        @unlink($file . '-shm');
        @unlink($file . '-journal');
    } else {
        drop_all_tables(db());
    }
    $alreadyInstalled = false;
}

function drop_all_tables(PDO $pdo): void
{
    $tables = ['otp_codes', 'notifications', 'waiting_list', 'payments', 'bookings', 'holidays', 'breaks', 'shifts', 'service_staff', 'services', 'staff', 'users', 'branches', 'settings'];
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function check_requirements(): array
{
    $checks = [];
    $checks[] = ['نسخه PHP (حداقل ۸٫۰)', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION];
    $checks[] = ['افزونه PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'فعال' : 'غیرفعال'];
    $checks[] = ['درایور pdo_mysql یا pdo_sqlite', extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), (extension_loaded('pdo_mysql') ? 'mysql ' : '') . (extension_loaded('pdo_sqlite') ? 'sqlite' : '')];
    $checks[] = ['افزونه mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'فعال' : 'غیرفعال'];
    $checks[] = ['افزونه json', extension_loaded('json'), extension_loaded('json') ? 'فعال' : 'غیرفعال'];
    $checks[] = ['قابل‌نوشتن بودن پوشه storage', is_writable(STORAGE_PATH), STORAGE_PATH];
    return $checks;
}

$errors = [];
$success = null;

// ثبت نصب
if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] === 'POST') && post('do') === 'install') {
    require_csrf();
    $driver = post('driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $env = [];
    if ($driver === 'mysql') {
        $env = [
            'DB_DRIVER' => 'mysql',
            'DB_HOST'   => post('db_host', '127.0.0.1') ?: '127.0.0.1',
            'DB_PORT'   => post('db_port', '3306') ?: '3306',
            'DB_NAME'   => post('db_name', 'nobatgiri'),
            'DB_USER'   => post('db_user', 'root'),
            'DB_PASS'   => (string) ($_POST['db_pass'] ?? ''),
        ];
        if ($env['DB_NAME'] === '' || $env['DB_USER'] === '') {
            $errors[] = 'نام دیتابیس و کاربر MySQL الزامی است.';
        }
    } else {
        $env = ['DB_DRIVER' => 'sqlite', 'SQLITE_PATH' => post('sqlite_path') ?: STORAGE_PATH . '/app.sqlite'];
    }
    $adminPhone = normalize_phone(post('admin_phone', '09120000000'));
    $adminPass = (string) ($_POST['admin_pass'] ?? 'admin123');
    $withSeed = isset($_POST['with_seed']);
    if (!valid_phone($adminPhone)) {
        $errors[] = 'شماره موبایل مدیر معتبر نیست.';
    }
    if (mb_strlen($adminPass) < 6) {
        $errors[] = 'رمز مدیر باید حداقل ۶ کاراکتر باشد.';
    }

    if (!$errors) {
        try {
            // اتصال آزمایشی (و ساخت دیتابیس در MySQL)
            if ($driver === 'mysql') {
                $server = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $server->exec("CREATE DATABASE IF NOT EXISTS `{$env['DB_NAME']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } else {
                $dir = dirname($env['SQLITE_PATH']);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (!is_writable($dir)) {
                    throw new RuntimeException('پوشه‌ی فایل SQLite قابل نوشتن نیست: ' . $dir);
                }
            }
            // ذخیره .env
            write_env($env);
            foreach ($env as $k => $v) {
                $_ENV[$k] = $v;
                putenv($k . '=' . $v);
            }
            // اجرای اسکیما
            $schemaFile = ROOT_PATH . '/database/' . ($driver === 'mysql' ? 'schema_mysql.sql' : 'schema_sqlite.sql');
            db()->exec(file_get_contents($schemaFile));
            // سید
            if ($withSeed) {
                run_seed(db());
                // جایگزینی مدیر نمایشی با مدیر واقعی
                db()->prepare('DELETE FROM users WHERE phone = ?')->execute(['09120000000']);
            } else {
                foreach (default_settings() as $k => $v) {
                    save_setting($k, $v);
                }
                save_setting('cron_key', bin2hex(random_bytes(16)));
            }
            $st = db()->prepare('INSERT INTO users (name, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
            $st->execute(['مدیر سیستم', $adminPhone, password_hash($adminPass, PASSWORD_DEFAULT), 'admin', now_str()]);
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
            $success = ['phone' => $adminPhone, 'driver' => $driver];
        } catch (Throwable $e) {
            $errors[] = 'خطا در نصب: ' . $e->getMessage();
            app_log('install.log', $e->getMessage());
        }
    }
}

function write_env(array $new): void
{
    $file = ROOT_PATH . '/.env';
    $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    $out = [];
    $seen = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) {
            $out[] = $line;
            continue;
        }
        $key = trim(substr($t, 0, strpos($t, '=')));
        if (isset($new[$key])) {
            $out[] = $key . '=' . $new[$key];
            $seen[$key] = true;
        } else {
            $out[] = $line;
        }
    }
    foreach ($new as $k => $v) {
        if (!isset($seen[$k])) {
            $out[] = $k . '=' . $v;
        }
    }
    file_put_contents($file, implode("\n", $out) . "\n");
}

$checks = check_requirements();
$canInstall = true;
foreach ($checks as $c) {
    if (!$c[1]) {
        $canInstall = false;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب سیستم نوبت‌دهی</title>
<link rel="stylesheet" href="<?= h(asset('css/admin.css')) ?>">
<style>.install-box{max-width:640px;margin:30px auto}.ok{color:#15803d}.bad{color:#9f1239}</style>
</head>
<body>
<div class="install-box">
  <div class="card">
    <h2>◔ نصب سیستم نوبت‌دهی</h2>
    <h3>پیش‌نیازها</h3>
    <table class="tbl">
      <?php foreach ($checks as $c): ?>
        <tr><td><?= h($c[0]) ?></td><td class="<?= $c[1] ? 'ok' : 'bad' ?>"><?= $c[1] ? '✓' : '✗' ?> <?= h((string) $c[2]) ?></td></tr>
      <?php endforeach; ?>
    </table>

    <?php if ($success): ?>
      <div class="alert alert-success">✓ نصب با موفقیت انجام شد! (درایور: <?= h($success['driver']) ?>)</div>
      <p>مشخصات ورود مدیر: <b dir="ltr"><?= h($success['phone']) ?></b></p>
      <p>
        <a class="btn btn-primary" href="<?= h(u('index.php')) ?>">مشاهده سایت</a>
        <a class="btn btn-ghost" href="<?= h(u('admin/login.php')) ?>">ورود به پنل مدیریت</a>
      </p>
      <div class="alert alert-warning">⚠️ حتماً فایل <code dir="ltr">install.php</code> را از روی سرور حذف کنید.</div>
    <?php elseif ($alreadyInstalled): ?>
      <div class="alert alert-info">سیستم قبلاً نصب شده است.</div>
      <p><a class="btn btn-primary" href="<?= h(u('index.php')) ?>">مشاهده سایت</a>
      <a class="btn btn-ghost" href="<?= h(u('admin/login.php')) ?>">پنل مدیریت</a></p>
      <hr>
      <form method="post" onsubmit="return confirm('همه‌ی اطلاعات پاک و نصب از نو انجام شود؟')">
        <?= csrf_field() ?><input type="hidden" name="do" value="reinstall"><input type="hidden" name="confirm" value="1">
        <button class="btn btn-danger btn-sm">نصب مجدد (پاک‌سازی کامل)</button>
      </form>
    <?php else: ?>
      <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
      <?php if (!$canInstall): ?>
        <div class="alert alert-error">ابتدا پیش‌نیازهای ناموفق را برطرف کنید.</div>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="install">
          <h3>دیتابیس</h3>
          <div class="form-group"><label>نوع دیتابیس</label>
            <select class="form-control" name="driver" id="driver">
              <option value="sqlite"<?= extension_loaded('pdo_sqlite') ? '' : ' disabled' ?>>SQLite (فایل — مناسب تست و شروع سریع)</option>
              <option value="mysql"<?= extension_loaded('pdo_mysql') ? '' : ' disabled' ?>>MySQL (پیشنهادی برای محصول نهایی)</option>
            </select>
          </div>
          <div id="frm-sqlite">
            <div class="form-group"><label>مسیر فایل SQLite</label><input class="form-control" dir="ltr" name="sqlite_path" value="<?= h(STORAGE_PATH . '/app.sqlite') ?>"></div>
          </div>
          <div id="frm-mysql" style="display:none">
            <div class="form-row">
              <div class="form-group"><label>هاست</label><input class="form-control" dir="ltr" name="db_host" value="127.0.0.1"></div>
              <div class="form-group"><label>پورت</label><input class="form-control" dir="ltr" name="db_port" value="3306"></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label>نام دیتابیس</label><input class="form-control" dir="ltr" name="db_name" value="nobatgiri"></div>
              <div class="form-group"><label>کاربر</label><input class="form-control" dir="ltr" name="db_user" value="root"></div>
            </div>
            <div class="form-group"><label>رمز دیتابیس</label><input class="form-control" type="password" dir="ltr" name="db_pass"></div>
          </div>
          <h3>مدیر سیستم</h3>
          <div class="form-row">
            <div class="form-group"><label>موبایل مدیر</label><input class="form-control" dir="ltr" name="admin_phone" value="09120000000"></div>
            <div class="form-group"><label>رمز عبور</label><input class="form-control" type="password" dir="ltr" name="admin_pass" value="admin123"></div>
          </div>
          <div class="form-group"><label class="check"><input type="checkbox" name="with_seed" value="1" checked> نصب داده‌ی نمایشی (شعبه‌ها، خدمات، متخصصان، شیفت‌ها)</label></div>
          <button class="btn btn-primary">شروع نصب</button>
        </form>
        <script>
        document.getElementById('driver').onchange = (e) => {
          document.getElementById('frm-sqlite').style.display = e.target.value === 'sqlite' ? '' : 'none';
          document.getElementById('frm-mysql').style.display = e.target.value === 'mysql' ? '' : 'none';
        };
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
