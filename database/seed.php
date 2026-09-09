<?php
declare(strict_types=1);

/**
 * داده‌ی نمایشی اولیه: تنظیمات، کسب‌وکارها، شعبه‌ها، خدمات، متخصصان، شیفت‌ها، کاربران
 */

function run_seed(PDO $pdo): array
{
    $now = now_str();

    // ---- تنظیمات ----
    foreach (default_settings() as $k => $v) {
        save_setting($k, $v);
    }
    save_setting('cron_key', bin2hex(random_bytes(16)));
    save_setting('business_name', 'نوبت‌یار');
    save_setting('business_about', 'رزرو آنلاین نوبت از بهترین کسب‌وکارهای شهر در هر دسته‌ی شغلی.');

    // ---- کسب‌وکارها: [نام, دسته شغلی, توضیح, تلفن, آدرس, شهر] ----
    $businesses = [
        ['کلینیک زیبایی آرا', 'آرایشگاه و زیبایی', 'ارائه‌ی تخصصی خدمات مو، پوست و ناخن با کادری مجرب', '021-11111111', 'تهران، خیابان ولیعصر', 'تهران'],
        ['پیرایش مردانه VIP', 'پیرایش مردانه', 'اصلاح تخصصی مو و صورت آقایان در محیطی مدرن', '021-33333333', 'تهران، سعادت‌آباد', 'تهران'],
        ['کلینیک دندانپزشکی لبخند', 'پزشکی و سلامت', 'خدمات عمومی و زیبایی دندان با تجهیزات روز', '026-44444444', 'کرج، عظیمیه', 'کرج'],
    ];
    $bizIds = [];
    $st = $pdo->prepare('INSERT INTO businesses (name, category, description, phone, address, city, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($businesses as $i => $b) {
        $st->execute([$b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $i, $now]);
        $bizIds[] = (int) $pdo->lastInsertId();
    }

    // ---- شعبه‌ها: [ایندکس کسب‌وکار, نام, آدرس, تلفن] ----
    $branches = [
        [0, 'شعبه مرکزی', 'تهران، خیابان ولیعصر، پلاک ۱', '021-11111111'],
        [0, 'شعبه غرب', 'تهران، صادقیه، بلوار فردوس', '021-22222222'],
        [1, 'شعبه سعادت‌آباد', 'تهران، سعادت‌آباد، بلوار دریا', '021-33333333'],
        [2, 'شعبه عظیمیه', 'کرج، عظیمیه، میدان مهران', '026-44444444'],
    ];
    $branchIds = [];
    $st = $pdo->prepare('INSERT INTO branches (business_id, name, address, phone, sort, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($branches as $i => $b) {
        $st->execute([$bizIds[$b[0]], $b[1], $b[2], $b[3], $i, $now]);
        $branchIds[] = (int) $pdo->lastInsertId();
    }

    // ---- خدمات: [ایندکس شعبه, دسته, نام, توضیح, قیمت, بیعانه٪, مدت, بافر] ----
    $services = [
        // کسب‌وکار ۱ (شعبه‌های ۰ و ۱)
        [0, 'مو', 'کوتاهی مو', 'کوتاهی تخصصی همراه با مشاوره‌ی استایل و سشوار', 250000, 30, 45, 0],
        [1, 'مو', 'کوتاهی مو', 'کوتاهی تخصصی همراه با مشاوره‌ی استایل و سشوار', 250000, 30, 45, 0],
        [0, 'مو', 'رنگ و مش', 'رنگ، مش و بالیاژ با مواد درجه‌یک', 1800000, 30, 180, 0],
        [0, 'مو', 'کراتین و احیا', 'صافی و احیای مو با کراتین برزیلی', 2500000, 30, 240, 0],
        [0, 'مو', 'شینیون مجلسی', 'شینیون حرفه‌ای مراسم و عروس', 900000, 30, 90, 0],
        [1, 'مو', 'شینیون مجلسی', 'شینیون حرفه‌ای مراسم و عروس', 900000, 30, 90, 0],
        [0, 'پوست و زیبایی', 'فیشال تخصصی', 'پاکسازی عمیق، ماسک و ماساژ صورت', 1200000, 30, 90, 0],
        [0, 'پوست و زیبایی', 'لیزر موهای زائد (ناحیه کوچک)', 'لیزر با دستگاه الکس‌دایود', 800000, 0, 30, 0],
        [1, 'ناخن', 'مانیکور و پدیکور', 'مراقبت کامل ناخن دست و پا', 450000, 0, 60, 0],
        [1, 'ناخن', 'کاشت ناخن', 'کاشت پودر/ژل همراه با دیزاین', 1500000, 30, 150, 0],
        // کسب‌وکار ۲ (شعبه ۲)
        [2, 'اصلاح', 'اصلاح موی آقایان', 'کوتاهی و مدل‌دادن مو با تیغ و ماشین', 180000, 0, 30, 0],
        [2, 'اصلاح', 'اصلاح صورت', 'شیو و مرتب‌سازی ریش و صورت', 120000, 0, 20, 0],
        [2, 'رنگ', 'رنگ و مش آقایان', 'رنگ موی طبیعی و مش', 700000, 20, 90, 0],
        [2, 'پوست', 'پاکسازی پوست آقایان', 'فیشال مخصوص آقایان', 500000, 0, 45, 0],
        // کسب‌وکار ۳ (شعبه ۳)
        [3, 'عمومی', 'معاینه و مشاوره', 'معاینه‌ی کامل و طرح درمان', 200000, 0, 20, 0],
        [3, 'عمومی', 'جرم‌گیری و بروساژ', 'تمیزکاری کامل دندان‌ها', 900000, 0, 45, 0],
        [3, 'ترمیمی', 'پرکردن دندان', 'ترمیم پوسیدگی با کامپوزیت', 1500000, 20, 60, 0],
        [3, 'زیبایی', 'بلیچینگ (سفیدکردن)', 'سفیدکردن دندان در یک جلسه', 3500000, 30, 90, 0],
    ];
    $serviceIds = []; // "branchIdx|name" => id
    $st = $pdo->prepare('INSERT INTO services (branch_id, category, name, description, price, deposit_percent, duration_minutes, buffer_minutes, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($services as $i => $s) {
        $st->execute([$branchIds[$s[0]], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7], $i]);
        $serviceIds[$s[0] . '|' . $s[2]] = (int) $pdo->lastInsertId();
    }

    // ---- متخصصان: [ایندکس شعبه, نام, سمت, تخصص, موبایل] ----
    $staff = [
        [0, 'سارا محمدی', 'متخصص ارشد مو', 'رنگ، مش و کراتین', '09121111111'],
        [0, 'نگار کریمی', 'میکاپ‌آرتیست', 'شینیون و میکاپ مجلسی', '09122222222'],
        [0, 'مریم احمدی', 'متخصص پوست', 'فیشال و لیزر', '09123333333'],
        [1, 'سارا رضایی', 'ناخن‌کار', 'کاشت و دیزاین ناخن', '09124444444'],
        [1, 'زهرا کریمی', 'آرایشگر', 'کوتاهی و شینیون', '09125555555'],
        [2, 'رضا کریمی', 'پیرایشگر ارشد', 'مدل‌های کلاسیک و فید', '09126666666'],
        [2, 'امیر حسینی', 'پیرایشگر', 'اصلاح صورت و داماد', '09127777777'],
        [3, 'دکتر نیما کریمی', 'دندانپزشک', 'ترمیمی و زیبایی', '09128888888'],
        [3, 'دکتر سارا موسوی', 'دندانپزشک', 'عمومی و کودکان', '09129999999'],
    ];
    $staffIds = [];
    $st = $pdo->prepare('INSERT INTO staff (branch_id, name, title, specialty, phone, sort) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($staff as $i => $s) {
        $st->execute([$branchIds[$s[0]], $s[1], $s[2], $s[3], $s[4], $i]);
        $staffIds[$s[1]] = (int) $pdo->lastInsertId();
    }

    // ---- ارتباط خدمت ↔ متخصص: [branchIdx, service, staffName] ----
    $map = [
        [0, 'کوتاهی مو', 'سارا محمدی'], [1, 'کوتاهی مو', 'زهرا کریمی'],
        [0, 'رنگ و مش', 'سارا محمدی'], [0, 'کراتین و احیا', 'سارا محمدی'],
        [0, 'شینیون مجلسی', 'نگار کریمی'], [1, 'شینیون مجلسی', 'زهرا کریمی'],
        [0, 'فیشال تخصصی', 'مریم احمدی'], [0, 'لیزر موهای زائد (ناحیه کوچک)', 'مریم احمدی'],
        [1, 'مانیکور و پدیکور', 'سارا رضایی'], [1, 'کاشت ناخن', 'سارا رضایی'],
        [2, 'اصلاح موی آقایان', 'رضا کریمی'], [2, 'اصلاح موی آقایان', 'امیر حسینی'],
        [2, 'اصلاح صورت', 'رضا کریمی'], [2, 'اصلاح صورت', 'امیر حسینی'],
        [2, 'رنگ و مش آقایان', 'رضا کریمی'], [2, 'پاکسازی پوست آقایان', 'امیر حسینی'],
        [3, 'معاینه و مشاوره', 'دکتر نیما کریمی'], [3, 'معاینه و مشاوره', 'دکتر سارا موسوی'],
        [3, 'جرم‌گیری و بروساژ', 'دکتر سارا موسوی'], [3, 'پرکردن دندان', 'دکتر نیما کریمی'],
        [3, 'بلیچینگ (سفیدکردن)', 'دکتر نیما کریمی'],
    ];
    $st = $pdo->prepare('INSERT INTO service_staff (service_id, staff_id) VALUES (?, ?)');
    foreach ($map as $m) {
        $st->execute([$serviceIds[$m[0] . '|' . $m[1]], $staffIds[$m[2]]]);
    }

    // ---- شیفت پیش‌فرض شعبه‌ها: شنبه تا پنجشنبه (جمعه تعطیل) ----
    $openDays = [6, 0, 1, 2, 3, 4];
    $hours = ['09:00-21:00', '09:00-21:00', '10:00-22:00', '10:00-20:00'];
    $st = $pdo->prepare('INSERT INTO shifts (branch_id, staff_id, weekday, start_time, end_time) VALUES (?, NULL, ?, ?, ?)');
    foreach ($branchIds as $bi => $bid) {
        [$hs, $he] = explode('-', $hours[$bi]);
        foreach ($openDays as $wd) {
            $st->execute([$bid, $wd, $hs, $he]);
        }
    }
    // شیفت اختصاصی نیمه‌وقت
    $st = $pdo->prepare('INSERT INTO shifts (branch_id, staff_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?, ?)');
    foreach ($openDays as $wd) {
        $st->execute([$branchIds[1], $staffIds['زهرا کریمی'], $wd, '14:00', '20:00']);
    }

    // ---- استراحت ناهار همه‌روزه ۱۳ تا ۱۴ ----
    $pdo->prepare("INSERT INTO breaks (branch_id, staff_id, weekday, break_date, start_time, end_time, reason) VALUES (NULL, NULL, NULL, NULL, '13:00', '14:00', 'ناهار')")->execute();

    // ---- تعطیلی‌های نمایشی ----
    $st = $pdo->prepare('INSERT INTO holidays (branch_id, holiday_date, title) VALUES (NULL, ?, ?)');
    $st->execute([date('Y-m-d', strtotime('+5 days')), 'تعطیل رسمی (نمایشی)']);
    $st->execute([date('Y-m-d', strtotime('+19 days')), 'بسته بودن مجموعه']);

    // ---- کاربران ----
    $st = $pdo->prepare('INSERT INTO users (name, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute(['مدیر سیستم', '09120000000', password_hash('admin123', PASSWORD_DEFAULT), 'admin', $now]);
    $st->execute(['مشتری نمایشی', '09123456789', null, 'customer', $now]);
    $demoCustomerId = (int) $pdo->lastInsertId();
    $st->execute(['صاحب کلینیک آرا', '09123334444', password_hash('provider123', PASSWORD_DEFAULT), 'provider', $now]);
    $provider1 = (int) $pdo->lastInsertId();
    $st->execute(['صاحب پیرایش VIP', '09124445555', password_hash('provider123', PASSWORD_DEFAULT), 'provider', $now]);
    $provider2 = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE businesses SET owner_user_id = ? WHERE id = ?')->execute([$provider1, $bizIds[0]]);
    $pdo->prepare('UPDATE businesses SET owner_user_id = ? WHERE id = ?')->execute([$provider2, $bizIds[1]]);
    $pdo->prepare('UPDATE businesses SET owner_user_id = ? WHERE id = ?')->execute([$provider1, $bizIds[2]]);

    // ---- رزروهای نمونه: [branchIdx, service, staff, روز, ساعت, وضعیت, پرداخت] ----
    $samples = [
        [0, 'کوتاهی مو', 'سارا محمدی', '+2 days 10:00', '10:00', 'confirmed', 250000],
        [0, 'فیشال تخصصی', 'مریم احمدی', '+3 days 16:00', '16:00', 'confirmed', 360000],
        [1, 'کاشت ناخن', 'سارا رضایی', '+6 days 11:00', '11:00', 'confirmed', 1500000],
        [0, 'رنگ و مش', 'سارا محمدی', '-3 days 10:00', '10:00', 'done', 1800000],
        [0, 'شینیون مجلسی', 'نگار کریمی', '-6 days 17:00', '17:00', 'done', 900000],
        [2, 'اصلاح موی آقایان', 'رضا کریمی', '+1 days 18:00', '18:00', 'confirmed', 180000],
        [2, 'اصلاح صورت', 'امیر حسینی', '-2 days 11:00', '11:00', 'done', 120000],
        [3, 'معاینه و مشاوره', 'دکتر سارا موسوی', '+4 days 10:00', '10:00', 'confirmed', 200000],
    ];
    $stB = $pdo->prepare("INSERT INTO bookings (code, branch_id, service_id, staff_id, customer_id, customer_name, customer_phone, booking_date, start_time, end_time, status, price, deposit_required, amount_paid, payment_status, slot_key, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stP = $pdo->prepare("INSERT INTO payments (booking_id, amount, kind, method, gateway, status, ref_id, created_at) VALUES (?, ?, ?, 'online', 'simulated', 'success', ?, ?)");
    $i = 0;
    foreach ($samples as $s) {
        $date = date('Y-m-d', strtotime($s[3]));
        while ((int) date('w', strtotime($date)) === 5) {
            $date = date('Y-m-d', strtotime($date . ' +1 day'));
        }
        $svc = $serviceIds[$s[0] . '|' . $s[1]];
        $row = $pdo->query("SELECT price, deposit_percent, duration_minutes, buffer_minutes FROM services WHERE id = {$svc}")->fetch();
        $dur = (int) $row['duration_minutes'] + (int) $row['buffer_minutes'];
        [$hh, $mm] = array_map('intval', explode(':', $s[4]));
        $end = sprintf('%02d:%02d', $hh + intdiv($mm + $dur, 60), ($mm + $dur) % 60);
        $code = 'NB-DEMO' . (++$i);
        $price = (int) $row['price'];
        $slotKey = in_array($s[5], ['confirmed', 'pending_payment'], true) ? $staffIds[$s[2]] . '|' . $date . '|' . $s[4] : null;
        $stB->execute([$code, $branchIds[$s[0]], $svc, $staffIds[$s[2]], $demoCustomerId, 'مشتری نمایشی', '09123456789',
            $date, $s[4], $end, $s[5], $price, (int) round($price * (int) $row['deposit_percent'] / 100), $s[6],
            $s[6] >= $price ? 'paid' : 'partial', $slotKey, date('Y-m-d H:i:s', strtotime($date . ' -1 day'))]);
        $bid = (int) $pdo->lastInsertId();
        $stP->execute([$bid, $s[6], $s[6] >= $price ? 'full' : 'deposit', 'DEMO-' . $bid, $now]);
    }

    // ---- صف انتظار نمونه ----
    $st = $pdo->prepare("INSERT INTO waiting_list (branch_id, service_id, staff_id, customer_name, customer_phone, date_from, status, created_at) VALUES (?, ?, NULL, ?, ?, ?, 'waiting', ?)");
    $st->execute([$branchIds[0], $serviceIds['0|رنگ و مش'], 'مشتری نمایشی', '09123456789', date('Y-m-d', strtotime('+2 days')), $now]);
    $st->execute([$branchIds[2], $serviceIds['2|رنگ و مش آقایان'], 'مشتری نمایشی', '09123456789', date('Y-m-d', strtotime('+3 days')), $now]);

    return [
        'admin_phone' => '09120000000', 'admin_pass' => 'admin123',
        'provider1_phone' => '09123334444', 'provider2_phone' => '09124445555', 'provider_pass' => 'provider123',
    ];
}
