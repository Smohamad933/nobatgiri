<?php
declare(strict_types=1);

/**
 * داده‌ی نمایشی اولیه: تنظیمات، شعبه‌ها، خدمات، متخصصان، شیفت‌ها، کاربر مدیر
 */

function run_seed(PDO $pdo): array
{
    $now = now_str();

    // ---- تنظیمات ----
    foreach (default_settings() as $k => $v) {
        save_setting($k, $v);
    }
    save_setting('cron_key', bin2hex(random_bytes(16)));

    // ---- شعبه‌ها ----
    $branches = [
        ['شعبه مرکزی', 'تهران، خیابان ولیعصر، پلاک ۱', '021-11111111', 0],
        ['شعبه غرب', 'تهران، صادقیه، بلوار فردوس', '021-22222222', 1],
    ];
    $branchIds = [];
    $st = $pdo->prepare('INSERT INTO branches (name, address, phone, sort, created_at) VALUES (?, ?, ?, ?, ?)');
    foreach ($branches as $b) {
        $st->execute([$b[0], $b[1], $b[2], $b[3], $now]);
        $branchIds[] = (int) $pdo->lastInsertId();
    }

    // ---- خدمات (دسته‌بندی‌شده) ----
    // [شعبه|null, دسته, نام, توضیح, قیمت, بیعانه٪, مدت, بافر]
    $services = [
        [null, 'مو', 'کوتاهی مو', 'کوتاهی تخصصی همراه با مشاوره‌ی استایل و سشوار', 250000, 30, 45, 0],
        [null, 'مو', 'رنگ و مش', 'رنگ، مش و بالیاژ با مواد درجه‌یک', 1800000, 30, 180, 0],
        [null, 'مو', 'کراتین و احیا', 'صافی و احیای مو با کراتین برزیلی', 2500000, 30, 240, 0],
        [null, 'مو', 'شینیون مجلسی', 'شینیون حرفه‌ای مراسم و عروس', 900000, 30, 90, 0],
        [null, 'پوست و زیبایی', 'فیشال تخصصی', 'پاکسازی عمیق، ماسک و ماساژ صورت', 1200000, 30, 90, 0],
        [null, 'پوست و زیبایی', 'لیزر موهای زائد (ناحیه کوچک)', 'لیزر با دستگاه الکس‌دایود', 800000, 0, 30, 0],
        [null, 'ناخن', 'مانیکور و پدیکور', 'مراقبت کامل ناخن دست و پا', 450000, 0, 60, 0],
        [null, 'ناخن', 'کاشت ناخن', 'کاشت پودر/ژل همراه با دیزاین', 1500000, 30, 150, 0],
    ];
    $serviceIds = [];
    $st = $pdo->prepare('INSERT INTO services (branch_id, category, name, description, price, deposit_percent, duration_minutes, buffer_minutes, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($services as $i => $s) {
        $st->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $i]);
        $serviceIds[$s[2]] = (int) $pdo->lastInsertId();
    }

    // ---- متخصصان ----
    // [شعبه, نام, سمت, تخصص, موبایل]
    $staff = [
        [0, 'سارا محمدی', 'متخصص ارشد مو', 'رنگ، مش و کراتین', '09121111111'],
        [0, 'نگار کریمی', 'میکاپ‌آرتیست', 'شینیون و میکاپ مجلسی', '09122222222'],
        [0, 'مریم احمدی', 'متخصص پوست', 'فیشال و لیزر', '09123333333'],
        [1, 'سارا رضایی', 'ناخن‌کار', 'کاشت و دیزاین ناخن', '09124444444'],
        [1, 'زهرا کریمی', 'آرایشگر', 'کوتاهی و شینیون', '09125555555'],
    ];
    $staffIds = [];
    $st = $pdo->prepare('INSERT INTO staff (branch_id, name, title, specialty, phone, sort) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($staff as $i => $s) {
        $st->execute([$branchIds[$s[0]], $s[1], $s[2], $s[3], $s[4], $i]);
        $staffIds[$s[1]] = (int) $pdo->lastInsertId();
    }

    // ---- ارتباط خدمت ↔ متخصص ----
    $map = [
        'کوتاهی مو' => ['سارا محمدی', 'زهرا کریمی'],
        'رنگ و مش' => ['سارا محمدی'],
        'کراتین و احیا' => ['سارا محمدی'],
        'شینیون مجلسی' => ['نگار کریمی', 'زهرا کریمی'],
        'فیشال تخصصی' => ['مریم احمدی'],
        'لیزر موهای زائد (ناحیه کوچک)' => ['مریم احمدی'],
        'مانیکور و پدیکور' => ['سارا رضایی'],
        'کاشت ناخن' => ['سارا رضایی'],
    ];
    $st = $pdo->prepare('INSERT INTO service_staff (service_id, staff_id) VALUES (?, ?)');
    foreach ($map as $svc => $names) {
        foreach ($names as $n) {
            $st->execute([$serviceIds[$svc], $staffIds[$n]]);
        }
    }

    // ---- شیفت پیش‌فرض شعبه‌ها: شنبه تا پنجشنبه ۹ تا ۲۱ (جمعه تعطیل) ----
    // weekday مطابق date('w'): شنبه=6، یکشنبه=0 ... پنجشنبه=4، جمعه=5
    $openDays = [6, 0, 1, 2, 3, 4];
    $st = $pdo->prepare('INSERT INTO shifts (branch_id, staff_id, weekday, start_time, end_time) VALUES (?, NULL, ?, ?, ?)');
    foreach ($branchIds as $bid) {
        foreach ($openDays as $wd) {
            $st->execute([$bid, $wd, '09:00', '21:00']);
        }
    }
    // شیفت اختصاصی یک متخصص (نیمه‌وقت: ۱۴ تا ۲۰)
    $st = $pdo->prepare('INSERT INTO shifts (branch_id, staff_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?, ?)');
    foreach ($openDays as $wd) {
        $st->execute([$branchIds[1], $staffIds['زهرا کریمی'], $wd, '14:00', '20:00']);
    }

    // ---- استراحت ناهار همه‌روزه ۱۳ تا ۱۴ ----
    $st = $pdo->prepare("INSERT INTO breaks (branch_id, staff_id, weekday, break_date, start_time, end_time, reason) VALUES (NULL, NULL, NULL, NULL, '13:00', '14:00', 'ناهار')");
    $st->execute();

    // ---- تعطیلی‌های نمایشی در آینده ----
    $st = $pdo->prepare('INSERT INTO holidays (branch_id, holiday_date, title) VALUES (NULL, ?, ?)');
    $st->execute([date('Y-m-d', strtotime('+5 days')), 'تعطیل رسمی (نمایشی)']);
    $st->execute([date('Y-m-d', strtotime('+19 days')), 'بسته بودن مجموعه']);

    // ---- کاربران: مدیر + مشتری نمایشی ----
    $st = $pdo->prepare('INSERT INTO users (name, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute(['مدیر سیستم', '09120000000', password_hash('admin123', PASSWORD_DEFAULT), 'admin', $now]);
    $st->execute(['مشتری نمایشی', '09123456789', null, 'customer', $now]);
    $demoCustomerId = (int) $pdo->lastInsertId();

    // ---- چند رزرو نمونه ----
    $samples = [
        // [خدمت, متخصص, شعبه‌idx, روز آینده, ساعت, وضعیت, پرداخت‌شده]
        ['کوتاهی مو', 'سارا محمدی', 0, '+2 days 10:00', '10:00', 'confirmed', 250000],
        ['فیشال تخصصی', 'مریم احمدی', 0, '+3 days 16:00', '16:00', 'confirmed', 360000],
        ['کاشت ناخن', 'سارا رضایی', 1, '+6 days 11:00', '11:00', 'confirmed', 1500000],
        ['رنگ و مش', 'سارا محمدی', 0, '-3 days 10:00', '10:00', 'done', 1800000],
        ['شینیون مجلسی', 'نگار کریمی', 0, '-6 days 17:00', '17:00', 'done', 900000],
    ];
    $stB = $pdo->prepare("INSERT INTO bookings (code, branch_id, service_id, staff_id, customer_id, customer_name, customer_phone, booking_date, start_time, end_time, status, price, deposit_required, amount_paid, payment_status, slot_key, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stP = $pdo->prepare("INSERT INTO payments (booking_id, amount, kind, method, gateway, status, ref_id, created_at) VALUES (?, ?, ?, 'online', 'simulated', 'success', ?, ?)");
    $i = 0;
    foreach ($samples as $s) {
        $dt = strtotime($s[3]);
        $date = date('Y-m-d', $dt);
        // اگر روز نمونه جمعه/تعطیل شد، به شنبه بعد منتقل کن
        while ((int) date('w', strtotime($date)) === 5) {
            $date = date('Y-m-d', strtotime($date . ' +1 day'));
        }
        $svc = $serviceIds[$s[0]];
        $row = $pdo->query("SELECT price, deposit_percent, duration_minutes, buffer_minutes FROM services WHERE id = {$svc}")->fetch();
        $dur = (int) $row['duration_minutes'] + (int) $row['buffer_minutes'];
        [$hh, $mm] = array_map('intval', explode(':', $s[4]));
        $end = sprintf('%02d:%02d', $hh + intdiv($mm + $dur, 60), ($mm + $dur) % 60);
        $code = 'NB-DEMO' . (++$i);
        $price = (int) $row['price'];
        $slotKey = in_array($s[5], ['confirmed', 'pending_payment'], true) ? $staffIds[$s[1]] . '|' . $date . '|' . $s[4] : null;
        $stB->execute([$code, $branchIds[$s[2]], $svc, $staffIds[$s[1]], $demoCustomerId, 'مشتری نمایشی', '09123456789',
            $date, $s[4], $end, $s[5], $price, (int) round($price * (int) $row['deposit_percent'] / 100), $s[6],
            $s[6] >= $price ? 'paid' : 'partial', $slotKey, date('Y-m-d H:i:s', strtotime($date . ' -1 day'))]);
        $bid = (int) $pdo->lastInsertId();
        $stP->execute([$bid, $s[6], $s[6] >= $price ? 'full' : 'deposit', 'DEMO-' . $bid, $now]);
    }

    // ---- یک رکورد صف انتظار + یک اعلان نمونه ----
    $st = $pdo->prepare("INSERT INTO waiting_list (branch_id, service_id, staff_id, customer_name, customer_phone, date_from, status, created_at) VALUES (?, ?, NULL, ?, ?, ?, 'waiting', ?)");
    $st->execute([$branchIds[0], $serviceIds['رنگ و مش'], 'مشتری نمایشی', '09123456789', date('Y-m-d', strtotime('+2 days')), $now]);

    return ['admin_phone' => '09120000000', 'admin_pass' => 'admin123'];
}
