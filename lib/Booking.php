<?php
declare(strict_types=1);

/**
 * موتور رزرو: موجودی، تولید ساعت‌ها، ثبت/لغو نوبت، صف انتظار
 */

class SlotTakenException extends Exception {}
class BookingException extends Exception {}

// ---------- خواندن اطلاعات پایه ----------

function get_branch(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM branches WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function get_service(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM services WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function get_staff(int $id): ?array
{
    $st = db()->prepare('SELECT s.*, b.name AS branch_name FROM staff s JOIN branches b ON b.id = s.branch_id WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function get_business(int $id): ?array
{
    $st = db()->prepare('SELECT b.*, u.name AS owner_name, u.phone AS owner_phone FROM businesses b LEFT JOIN users u ON u.id = b.owner_user_id WHERE b.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** کسب‌وکارهای فعال (با فیلتر دسته/جستجو/شهر) */
function active_businesses(?string $category = null, string $q = '', ?string $city = null): array
{
    $sql = 'SELECT * FROM businesses WHERE active = 1';
    $params = [];
    if ($category) {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    if ($city) {
        $sql .= ' AND city = ?';
        $params[] = $city;
    }
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    $sql .= ' ORDER BY sort, id';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** دسته‌بندی‌های شغلی به‌همراه تعداد کسب‌وکار فعال */
function business_categories(): array
{
    return db()->query("SELECT category, COUNT(*) AS c FROM businesses WHERE active = 1 GROUP BY category ORDER BY c DESC, category")->fetchAll();
}

/** شهرهای دارای کسب‌وکار فعال */
function business_cities(): array
{
    return db()->query('SELECT DISTINCT city FROM businesses WHERE active = 1 AND city IS NOT NULL AND city <> "" ORDER BY city')->fetchAll(PDO::FETCH_COLUMN);
}

/** کسب‌وکارهای یک صاحب خدمت */
function businesses_for_provider(int $user_id): array
{
    $st = db()->prepare('SELECT * FROM businesses WHERE owner_user_id = ? ORDER BY sort, id');
    $st->execute([$user_id]);
    return $st->fetchAll();
}

function active_branches(?int $business_id = null): array
{
    if ($business_id) {
        $st = db()->prepare('SELECT * FROM branches WHERE active = 1 AND business_id = ? ORDER BY sort, id');
        $st->execute([$business_id]);
        return $st->fetchAll();
    }
    return db()->query('SELECT * FROM branches WHERE active = 1 ORDER BY sort, id')->fetchAll();
}

/** شعبه‌های یک کسب‌وکار (پیش‌فرض فقط فعال‌ها) */
function branches_of_business(int $business_id, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM branches WHERE business_id = ?';
    if ($onlyActive) {
        $sql .= ' AND active = 1';
    }
    $sql .= ' ORDER BY sort, id';
    $st = db()->prepare($sql);
    $st->execute([$business_id]);
    return $st->fetchAll();
}

/** کسب‌وکارِ یک شعبه */
function branch_business(?array $branch): ?array
{
    if (!$branch || empty($branch['business_id'])) {
        return null;
    }
    return get_business((int) $branch['business_id']);
}

/**
 * نزدیک‌ترین روز دارای ظرفیت یک کسب‌وکار (برای نشان مارکت‌پلیس).
 * @return array{date:string,label:string,free:int,today:bool}|array{}
 */
/**
 * نزدیک‌ترین ظرفیت آزاد یک کسب‌وکار در ۷ روز آینده.
 * @return array{date:string,date_label:string,today:int}|null (today = تعداد ساعت خالی امروز، اگر نزدیک‌ترین روز امروز باشد)
 */
function business_next_available(int $business_id): ?array
{
    $branches = active_branches($business_id);
    if (!$branches) {
        return null;
    }
    // حداکثر ۳ خدمت اول هر شعبه با اولین متخصصِ آن (توقف در اولین روزِ دارای ظرفیت)
    $probes = [];
    foreach ($branches as $br) {
        $bid = (int) $br['id'];
        foreach (array_slice(active_services($bid), 0, 3) as $svc) {
            $staff = staff_for_service($bid, (int) $svc['id']);
            if ($staff) {
                $probes[] = ['branch' => $bid, 'service' => (int) $svc['id'], 'staff' => (int) $staff[0]['id']];
            }
        }
    }
    if (!$probes) {
        return null;
    }
    for ($d = 0; $d < 7; $d++) {
        $date = date('Y-m-d', strtotime("+{$d} days"));
        $total = 0;
        foreach ($probes as $p) {
            $r = generate_slots($p['branch'], $p['service'], $p['staff'], $date);
            if (($r['status'] ?? '') === 'open') {
                $total += count($r['slots'] ?? []);
            }
        }
        if ($total > 0) {
            return ['date' => $date, 'date_label' => fa_long_date($date), 'today' => $d === 0 ? $total : 0];
        }
    }
    return null;
}

/** آمار خلاصه‌ی یک کسب‌وکار برای کارت مارکت‌پلیس */
function business_card_stats(int $business_id): array
{
    $pdo = db();
    $brIds = array_column(active_branches($business_id), 'id');
    if (!$brIds) {
        return ['services' => 0, 'staff' => 0, 'branches' => 0, 'done' => 0];
    }
    $in = implode(',', array_map('intval', $brIds));
    $services = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE active = 1 AND branch_id IN ({$in})")->fetchColumn();
    $staff = (int) $pdo->query("SELECT COUNT(*) FROM staff WHERE active = 1 AND branch_id IN ({$in})")->fetchColumn();
    $done = (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE branch_id IN ({$in}) AND status = 'done'")->fetchColumn();
    return ['services' => $services, 'staff' => $staff, 'branches' => count($brIds), 'done' => $done];
}

function active_services(int $branch_id): array
{
    $st = db()->prepare('SELECT * FROM services WHERE active = 1 AND (branch_id IS NULL OR branch_id = ?) ORDER BY category, sort, id');
    $st->execute([$branch_id]);
    return $st->fetchAll();
}

/** متخصصانی که خدمت مشخصی را در شعبه انجام می‌دهند */
function staff_for_service(int $branch_id, int $service_id): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM service_staff WHERE service_id = ?');
    $st->execute([$service_id]);
    $hasMap = (int) $st->fetchColumn() > 0;
    if ($hasMap) {
        $st = $pdo->prepare('SELECT s.* FROM staff s JOIN service_staff m ON m.staff_id = s.id WHERE s.branch_id = ? AND s.active = 1 AND m.service_id = ? ORDER BY s.sort, s.id');
        $st->execute([$branch_id, $service_id]);
    } else {
        $st = $pdo->prepare('SELECT * FROM staff WHERE branch_id = ? AND active = 1 ORDER BY sort, id');
        $st->execute([$branch_id]);
    }
    return $st->fetchAll();
}

// ---------- شیفت‌ها، استراحت‌ها، تعطیلی‌ها ----------

/**
 * بازه‌های کاری مؤثر یک متخصص در یک روز هفته.
 * اگر برای متخصص شیفت خاص تعریف شده همان، وگرنه پیش‌فرض شعبه.
 * @return array<int, array{0:string,1:string}>
 */
function shifts_for(int $branch_id, int $staff_id, int $weekday): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT start_time, end_time FROM shifts WHERE active = 1 AND staff_id = ? AND weekday = ? ORDER BY start_time');
    $st->execute([$staff_id, $weekday]);
    $rows = $st->fetchAll();
    if (!$rows) {
        $st = $pdo->prepare('SELECT start_time, end_time FROM shifts WHERE active = 1 AND staff_id IS NULL AND (branch_id IS NULL OR branch_id = ?) AND weekday = ? ORDER BY start_time');
        $st->execute([$branch_id, $weekday]);
        $rows = $st->fetchAll();
    }
    $out = [];
    foreach ($rows as $r) {
        if ($r['end_time'] > $r['start_time']) {
            $out[] = [$r['start_time'], $r['end_time']];
        }
    }
    return $out;
}

/** بازه‌های استراحت/مسدودی مؤثر در یک تاریخ مشخص */
function breaks_for(int $branch_id, int $staff_id, string $date): array
{
    $weekday = (int) date('w', strtotime($date));
    $st = db()->prepare('SELECT * FROM breaks WHERE (branch_id IS NULL OR branch_id = ?) AND (staff_id IS NULL OR staff_id = ?) AND (break_date = ? OR (break_date IS NULL AND (weekday IS NULL OR weekday = ?)))');
    $st->execute([$branch_id, $staff_id, $date, $weekday]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['end_time'] > $r['start_time']) {
            $out[] = [$r['start_time'], $r['end_time']];
        }
    }
    return $out;
}

function is_holiday(int $branch_id, string $date): ?array
{
    $st = db()->prepare('SELECT * FROM holidays WHERE holiday_date = ? AND (branch_id IS NULL OR branch_id = ?) LIMIT 1');
    $st->execute([$date, $branch_id]);
    return $st->fetch() ?: null;
}

// ---------- تداخل بازه‌ها ----------

function intervals_overlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
{
    return $aStart < $bEnd && $bStart < $aEnd;
}

/** آیا بازه با هیچ‌کدام از بازه‌های لیست تداخل ندارد؟ */
function is_free_of(string $start, string $end, array $busy): bool
{
    foreach ($busy as $b) {
        if (intervals_overlap($start, $end, $b[0], $b[1])) {
            return false;
        }
    }
    return true;
}

// ---------- رزروهای اشغال‌کننده ----------

/** آزادسازی رزروهای پرداخت‌نشده‌ی منقضی (نگه‌داشتن موقت ظرفیت) */
function release_expired_pendings(): int
{
    $timeout = setting_int('pending_timeout_minutes', 20);
    $border = date('Y-m-d H:i:s', time() - $timeout * 60);
    $st = db()->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = 'انقضای مهلت پرداخت', cancelled_at = ?, slot_key = NULL, updated_at = ? WHERE status = 'pending_payment' AND created_at < ?");
    $st->execute([now_str(), now_str(), $border]);
    return $st->rowCount();
}

/** بازه‌های اشغال یک متخصص در یک تاریخ (تأییدشده + در انتظار پرداخت معتبر) */
function occupied_intervals(int $staff_id, string $date): array
{
    release_expired_pendings();
    $st = db()->prepare("SELECT start_time, end_time FROM bookings WHERE staff_id = ? AND booking_date = ? AND status IN ('confirmed', 'pending_payment') ORDER BY start_time");
    $st->execute([$staff_id, $date]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [$r['start_time'], $r['end_time']];
    }
    return $out;
}

// ---------- تولید ساعت‌های خالی ----------

/**
 * @return array{status:string,reason?:string,slots:array<int,array{start:string,end:string,label:string}>}
 */
function generate_slots(int $branch_id, int $service_id, int $staff_id, string $date): array
{
    $service = get_service($service_id);
    $staff = get_staff($staff_id);
    if (!$service || !$service['active']) {
        return ['status' => 'closed', 'reason' => 'خدمت نامعتبر است.', 'slots' => []];
    }
    if (!$staff || !$staff['active'] || (int) $staff['branch_id'] !== $branch_id) {
        return ['status' => 'closed', 'reason' => 'متخصص نامعتبر است.', 'slots' => []];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['status' => 'closed', 'reason' => 'تاریخ نامعتبر است.', 'slots' => []];
    }
    $today = today_str();
    $window = setting_int('booking_window_days', 30);
    $maxDate = date('Y-m-d', strtotime("+{$window} days"));
    if ($date < $today) {
        return ['status' => 'closed', 'reason' => 'این روز گذشته است.', 'slots' => []];
    }
    if ($date > $maxDate) {
        return ['status' => 'closed', 'reason' => 'رزرو فقط تا ' . fa($window) . ' روز آینده مجاز است.', 'slots' => []];
    }
    $holiday = is_holiday($branch_id, $date);
    if ($holiday) {
        return ['status' => 'holiday', 'reason' => $holiday['title'] ?: 'تعطیل', 'slots' => []];
    }
    $weekday = (int) date('w', strtotime($date));
    $shifts = shifts_for($branch_id, $staff_id, $weekday);
    if (!$shifts) {
        return ['status' => 'closed', 'reason' => 'در این روز کاری انجام نمی‌شود.', 'slots' => []];
    }

    $slotLen = max(5, (int) $service['duration_minutes'] + (int) $service['buffer_minutes']);
    $step = max(5, setting_int('slot_step_minutes', 15));
    $leadTs = time() + setting_int('min_lead_hours', 2) * 3600;

    $busy = array_merge(occupied_intervals($staff_id, $date), breaks_for($branch_id, $staff_id, $date));
    $slots = [];
    foreach ($shifts as [$sStart, $sEnd]) {
        $cursor = time_to_min($sStart);
        $endMin = time_to_min($sEnd);
        for ($t = $cursor; $t + $slotLen <= $endMin; $t += $step) {
            $start = min_to_time($t);
            $end = min_to_time($t + $slotLen);
            $slotTs = strtotime($date . ' ' . $start);
            if ($slotTs < $leadTs) {
                continue; // گذشته یا کمتر از حداقل فاصله
            }
            if (!is_free_of($start, $end, $busy)) {
                continue;
            }
            $slots[] = ['start' => $start, 'end' => $end, 'label' => fa($start) . ' تا ' . fa($end)];
        }
    }
    if (!$slots) {
        return ['status' => 'full', 'reason' => 'ظرفیت این روز تکمیل شده است.', 'slots' => []];
    }
    return ['status' => 'open', 'slots' => $slots];
}

/**
 * وضعیت یک روز برای تقویم (با پشتیبانی از «هر متخصص»)
 * @return array{status:string,reason:string,free:int}
 */
function day_status(int $branch_id, int $service_id, ?int $staff_id, string $date): array
{
    $staffIds = $staff_id ? [$staff_id] : array_column(staff_for_service($branch_id, $service_id), 'id');
    if (!$staffIds) {
        return ['status' => 'closed', 'reason' => 'متخصصی برای این خدمت فعال نیست.', 'free' => 0];
    }
    $totalFree = 0;
    $lastReason = '';
    $lastStatus = 'full';
    foreach ($staffIds as $sid) {
        $r = generate_slots($branch_id, $service_id, (int) $sid, $date);
        if ($r['status'] === 'open') {
            $totalFree += count($r['slots']);
            $lastStatus = 'open';
            if (!$staff_id && $totalFree > 0) {
                break; // برای حالت «هر متخصص» همین که یکی باز باشد کافی است
            }
        } else {
            $lastReason = $r['reason'] ?? '';
            if (in_array($r['status'], ['holiday', 'closed'], true)) {
                $lastStatus = $r['status'];
            }
        }
    }
    if ($totalFree > 0) {
        return ['status' => 'open', 'reason' => '', 'free' => $totalFree];
    }
    return ['status' => $lastStatus, 'reason' => $lastReason ?: 'ظرفیت تکمیل است.', 'free' => 0];
}

/**
 * شبکه‌ی ماهانه‌ی تقویم (میلادی) با برچسب‌های جلالی، هفته از شنبه.
 * @return array{weeks:array,caption:string}
 */
function month_grid(int $branch_id, int $service_id, ?int $staff_id, int $gy, int $gm): array
{
    $firstTs = strtotime(sprintf('%04d-%02d-01 12:00:00', $gy, $gm));
    $offset = weekday_sat0($firstTs); // چند روز از ماه قبل
    $startTs = $firstTs - $offset * 86400;
    $weeks = [];
    $week = [];
    for ($i = 0; $i < 42; $i++) {
        $ts = $startTs + $i * 86400;
        $gdate = date('Y-m-d', $ts);
        [$jy, $jm, $jd] = gdate_to_jalali($gdate);
        $inMonth = ((int) date('n', $ts) === $gm);
        $st = day_status($branch_id, $service_id, $staff_id, $gdate);
        $week[] = [
            'date'     => $gdate,
            'in_month' => $inMonth,
            'jday'     => $jd,
            'jmonth'   => $jm,
            'label'    => fa_long_date($gdate),
            'is_today' => $gdate === today_str(),
            'status'   => $st['status'],
            'reason'   => $st['reason'],
            'free'     => $st['free'],
        ];
        if (count($week) === 7) {
            $weeks[] = $week;
            $week = [];
            // اگر هفته کامل شد و وارد ماه بعد شده‌ایم و حداقل ۴ هفته گذشته، توقف
            if ($i >= 27 && (int) date('n', $ts) !== $gm) {
                break;
            }
        }
    }
    [$jy0, $jm0] = gdate_to_jalali(sprintf('%04d-%02d-15', $gy, $gm));
    return [
        'weeks'   => $weeks,
        'caption' => j_month_name($jm0) . ' ' . fa($jy0),
        'ym'      => sprintf('%04d-%02d', $gy, $gm),
    ];
}

// ---------- ثبت نوبت (با محافظت از رزرو هم‌زمان) ----------

/**
 * @param array $input [branch_id,service_id,staff_id,date,start,customer_name,customer_phone,customer_id?,notes?]
 * @throws BookingException|SlotTakenException
 */
function create_booking(array $input): array
{
    $pdo = db();
    $branch_id = (int) $input['branch_id'];
    $service_id = (int) $input['service_id'];
    $staff_id = (int) $input['staff_id'];
    $date = $input['date'];
    $start = substr($input['start'], 0, 5);
    $phone = normalize_phone((string) ($input['customer_phone'] ?? ''));
    $name = trim((string) ($input['customer_name'] ?? ''));

    if (!valid_phone($phone)) {
        throw new BookingException('شماره موبایل معتبر نیست.');
    }
    if ($name === '') {
        throw new BookingException('نام را وارد کنید.');
    }
    $service = get_service($service_id);
    $staff = get_staff($staff_id);
    $branch = get_branch($branch_id);
    if (!$branch || !$branch['active'] || !$service || !$service['active'] || !$staff || !$staff['active']) {
        throw new BookingException('اطلاعات رزرو نامعتبر است.');
    }
    if ((int) $staff['branch_id'] !== $branch_id) {
        throw new BookingException('متخصص متعلق به این شعبه نیست.');
    }
    if ($service['branch_id'] !== null && (int) $service['branch_id'] !== $branch_id) {
        throw new BookingException('این خدمت در شعبه‌ی انتخابی ارائه نمی‌شود.');
    }
    $slotLen = max(5, (int) $service['duration_minutes'] + (int) $service['buffer_minutes']);
    $end = min_to_time(time_to_min($start) + $slotLen);

    // سقف نوبت فعال هر شماره (جلوگیری از سوءاستفاده)
    $maxActive = setting_int('max_active_per_phone', 5);
    $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE customer_phone = ? AND status IN ('pending_payment','confirmed') AND booking_date >= ?");
    $st->execute([$phone, today_str()]);
    if ((int) $st->fetchColumn() >= $maxActive) {
        throw new BookingException('سقف نوبت فعال برای این شماره تکمیل شده است.');
    }

    // اعتبارسنجی اولیه‌ی ساعت (قبل از تراکنش)
    $check = generate_slots($branch_id, $service_id, $staff_id, $date);
    if ($check['status'] !== 'open') {
        throw new BookingException($check['reason'] ?? 'این ساعت قابل رزرو نیست.');
    }
    $matched = false;
    foreach ($check['slots'] as $s) {
        if ($s['start'] === $start) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        throw new SlotTakenException('متأسفانه این ساعت لحظاتی پیش پر شد. لطفاً ساعت دیگری انتخاب کنید.');
    }

    $price = (int) $service['price'];
    $depositPct = (int) $service['deposit_percent'];
    $deposit = (int) round($price * $depositPct / 100);
    $customer_id = isset($input['customer_id']) ? (int) $input['customer_id'] : null;
    if ($customer_id) {
        $st = $pdo->prepare('UPDATE users SET name = COALESCE(NULLIF(name,\'\'), ?) WHERE id = ?');
        $st->execute([$name, $customer_id]);
    }

    try {
        $pdo->beginTransaction();

        // قفل خوش‌بینانه/کنترل تداخل داخل تراکنش (جلوگیری از رزرو هم‌زمان)
        $forUpdate = db_driver() === 'mysql' ? ' FOR UPDATE' : '';
        $st = $pdo->prepare(
            "SELECT id FROM bookings WHERE staff_id = ? AND booking_date = ? AND status IN ('confirmed','pending_payment')
             AND NOT (end_time <= ? OR start_time >= ?){$forUpdate}"
        );
        $st->execute([$staff_id, $date, $start, $end]);
        if ($st->fetch()) {
            $pdo->rollBack();
            throw new SlotTakenException('متأسفانه این ساعت لحظاتی پیش پر شد. لطفاً ساعت دیگری انتخاب کنید.');
        }

        $code = generate_booking_code($pdo);
        $manual = !empty($input['force_confirmed']); // ثبت دستی توسط صاحب خدمت (نوبت تلفنی/حضوری)
        $status = ($price > 0 && !$manual) ? 'pending_payment' : 'confirmed';
        $paidInit = $manual ? max(0, (int) ($input['amount_paid'] ?? 0)) : 0;
        $payStatusInit = $paidInit >= $price && $price > 0 ? 'paid' : ($paidInit > 0 ? 'partial' : 'unpaid');
        if ($price <= 0) {
            $payStatusInit = 'paid';
        }
        $slotKey = $staff_id . '|' . $date . '|' . $start; // یکتای سطح دیتابیس برای رزرو هم‌زمان
        $st = $pdo->prepare('INSERT INTO bookings (code, branch_id, service_id, staff_id, customer_id, customer_name, customer_phone, booking_date, start_time, end_time, status, price, deposit_required, amount_paid, payment_status, notes, admin_notes, slot_key, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$code, $branch_id, $service_id, $staff_id, $customer_id, $name, $phone, $date, $start, $end, $status, $price, $deposit, $paidInit, $payStatusInit, trim((string) ($input['notes'] ?? '')), trim((string) ($input['admin_notes'] ?? '')), $slotKey, now_str()]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (SlotTakenException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // خطای یکتایی اسلات (رقابت هم‌زمان در سطح دیتابیس)
        if (in_array($e->getCode(), ['23000', '19'], true) || str_contains($e->getMessage(), 'uq_slot') || str_contains($e->getMessage(), 'slot_key') || str_contains($e->getMessage(), 'UNIQUE')) {
            throw new SlotTakenException('متأسفانه این ساعت لحظاتی پیش پر شد. لطفاً ساعت دیگری انتخاب کنید.');
        }
        throw $e;
    }

    $booking = booking_detail($id);
    if ($status === 'confirmed') {
        // ثبت پرداخت دستی (نقدی/کارتخوان)
        if ($manual && $paidInit > 0) {
            $st = $pdo->prepare("INSERT INTO payments (booking_id, amount, kind, method, gateway, status, ref_id, created_at) VALUES (?, ?, ?, ?, 'manual', 'success', ?, ?)");
            $st->execute([$id, $paidInit, $paidInit >= $price ? 'full' : 'deposit', $input['pay_method'] ?? 'cash', 'MANUAL-' . $id, now_str()]);
        }
        schedule_booking_notifications($booking);
    }
    return $booking;
}

/** جزئیات کامل یک رزرو به‌همراه نام خدمت/متخصص/شعبه */
function booking_detail(int $id): ?array
{
    $st = db()->prepare('SELECT b.*, s.name AS service_name, s.category AS service_category, s.duration_minutes,
        st.name AS staff_name, st.title AS staff_title, br.name AS branch_name, br.address AS branch_address,
        bz.name AS business_name
        FROM bookings b
        JOIN services s ON s.id = b.service_id
        JOIN staff st ON st.id = b.staff_id
        JOIN branches br ON br.id = b.branch_id
        LEFT JOIN businesses bz ON bz.id = br.business_id
        WHERE b.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function booking_by_code(string $code): ?array
{
    $st = db()->prepare('SELECT b.*, s.name AS service_name, s.category AS service_category, s.duration_minutes,
        st.name AS staff_name, st.title AS staff_title, br.name AS branch_name, br.address AS branch_address,
        bz.name AS business_name
        FROM bookings b
        JOIN services s ON s.id = b.service_id
        JOIN staff st ON st.id = b.staff_id
        JOIN branches br ON br.id = b.branch_id
        LEFT JOIN businesses bz ON bz.id = br.business_id
        WHERE b.code = ?');
    $st->execute([trim($code)]);
    return $st->fetch() ?: null;
}

// ---------- لغو نوبت با سیاست استرداد ----------

/**
 * @return array{fee:int,refund:int}
 * @throws BookingException
 */
function cancel_booking(int $id, string $reason, string $by = 'customer', bool $applyFee = true): array
{
    $pdo = db();
    $booking = booking_detail($id);
    if (!$booking) {
        throw new BookingException('رزرو یافت نشد.');
    }
    if (!in_array($booking['status'], ['pending_payment', 'confirmed'], true)) {
        throw new BookingException('این رزرو قابل لغو نیست.');
    }
    $hours = hours_until($booking['booking_date'], $booking['start_time']);
    if ($hours < 0 && $by === 'customer') {
        throw new BookingException('زمان این نوبت گذشته و امکان لغو ندارد.');
    }
    $fee = 0;
    $refund = 0;
    $paid = (int) $booking['amount_paid'];
    if ($applyFee && $paid > 0 && $hours < setting_int('free_cancel_hours', 48)) {
        $fee = (int) round((int) $booking['price'] * setting_int('late_cancel_fee_percent', 20) / 100);
        $fee = min($fee, $paid);
    }
    $refund = max(0, $paid - $fee);

    $st = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancel_reason = ?, refund_amount = ?, payment_status = ?, slot_key = NULL, updated_at = ? WHERE id = ?");
    $st->execute([now_str(), $reason, $refund, $refund > 0 ? 'refunded' : $booking['payment_status'], now_str(), $id]);

    if ($refund > 0) {
        $st = $pdo->prepare("INSERT INTO payments (booking_id, amount, kind, method, gateway, status, created_at) VALUES (?, ?, 'refund', 'online', ?, 'success', ?)");
        $st->execute([$id, $refund, setting('payment_driver', 'simulated'), now_str()]);
    }

    cancel_queued_notifications($id);
    $fresh = booking_detail($id);
    $service = get_service((int) $booking['service_id']);
    $staff = get_staff((int) $booking['staff_id']);
    $branch = get_branch((int) $booking['branch_id']);
    queue_notification($id, $booking['customer_id'] ? (int) $booking['customer_id'] : null, 'sms', 'cancelled',
        $booking['customer_phone'], booking_message('cancelled', $fresh, $service, $staff, $branch), now_str());

    // پیشنهاد خودکار به نفر اول صف انتظار
    process_waiting_for_slot((int) $booking['branch_id'], (int) $booking['service_id'], $booking['booking_date'], $booking['start_time']);

    return ['fee' => $fee, 'refund' => $refund];
}

/** تغییر وضعیت توسط مدیر */
function set_booking_status(int $id, string $status, string $adminNotes = ''): void
{
    $allowed = ['confirmed', 'cancelled', 'done', 'no_show'];
    if (!in_array($status, $allowed, true)) {
        throw new BookingException('وضعیت نامعتبر است.');
    }
    if ($status === 'cancelled') {
        cancel_booking($id, 'لغو توسط مدیر', 'admin', false);
        return;
    }
    $booking = booking_detail($id);
    if (!$booking) {
        throw new BookingException('رزرو یافت نشد.');
    }
    // تأیید مجدد رزرو لغوشده/تمام‌شده: فقط اگر ساعت هنوز آزاد باشد
    if ($status === 'confirmed' && !in_array($booking['status'], ['confirmed', 'pending_payment'], true)) {
        $r = generate_slots((int) $booking['branch_id'], (int) $booking['service_id'], (int) $booking['staff_id'], $booking['booking_date']);
        $free2 = false;
        if ($r['status'] === 'open') {
            foreach ($r['slots'] as $s) {
                if ($s['start'] === substr($booking['start_time'], 0, 5)) {
                    $free2 = true;
                    break;
                }
            }
        }
        if (!$free2) {
            throw new BookingException('این ساعت دیگر آزاد نیست و رزرو قابل تأیید مجدد نیست.');
        }
        try {
            $st = db()->prepare('UPDATE bookings SET status = ?, admin_notes = ?, slot_key = ?, updated_at = ? WHERE id = ?');
            $st->execute([$status, $adminNotes, $booking['staff_id'] . '|' . $booking['booking_date'] . '|' . substr($booking['start_time'], 0, 5), now_str(), $id]);
        } catch (PDOException $e) {
            throw new BookingException('این ساعت لحظاتی پیش پر شد.');
        }
        return;
    }
    // با انجام/عدم‌مراجعه، کلید اسلات آزاد می‌شود
    $free = in_array($status, ['done', 'no_show'], true) ? ', slot_key = NULL' : '';
    $st = db()->prepare("UPDATE bookings SET status = ?, admin_notes = ?, updated_at = ?{$free} WHERE id = ?");
    $st->execute([$status, $adminNotes, now_str(), $id]);
}

// ---------- صف انتظار ----------

/**
 * پیشنهاد خودکار ظرفیت آزادشده به قدیمی‌ترین نفر واجد شرایط صف انتظار.
 * @return array|null رکورد صف انتظار پیشنهادشده
 */
function process_waiting_for_slot(int $branch_id, int $service_id, string $date, string $time): ?array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM waiting_list WHERE branch_id = ? AND service_id = ? AND status = 'waiting' AND date_from <= ? ORDER BY created_at ASC LIMIT 5");
    $st->execute([$branch_id, $service_id, $date]);
    foreach ($st->fetchAll() as $w) {
        // آیا هنوز برای این مشتری جا هست؟ (اعتبارسنجی سبک: همان تاریخ/خدمت)
        $staffIds = $w['staff_id'] ? [(int) $w['staff_id']] : array_column(staff_for_service($branch_id, $service_id), 'id');
        $fits = false;
        foreach ($staffIds as $sid) {
            $r = generate_slots($branch_id, $service_id, (int) $sid, $date);
            if ($r['status'] === 'open') {
                $fits = true;
                break;
            }
        }
        if (!$fits) {
            continue;
        }
        $st2 = $pdo->prepare("UPDATE waiting_list SET status = 'offered', notified_at = ? WHERE id = ? AND status = 'waiting'");
        $st2->execute([now_str(), $w['id']]);
        if ($st2->rowCount() === 0) {
            continue;
        }
        $service = get_service($service_id);
        $branch = get_branch($branch_id);
        $fake = ['customer_name' => $w['customer_name'], 'booking_date' => $date, 'start_time' => $time, 'code' => ''];
        queue_notification(null, $w['customer_id'] ? (int) $w['customer_id'] : null, 'sms', 'waiting_offer',
            $w['customer_phone'], booking_message('waiting_offer', $fake, $service, ['name' => ''], $branch, branch_business($branch)), now_str());
        $w['status'] = 'offered';
        return $w;
    }
    return null;
}

/** منقضی‌کردن پیشنهادهای قدیمی صف انتظار (۲۴ ساعت) */
function expire_old_waiting_offers(): int
{
    $border = date('Y-m-d H:i:s', time() - 24 * 3600);
    $st = db()->prepare("UPDATE waiting_list SET status = 'expired' WHERE status = 'offered' AND notified_at < ?");
    $st->execute([$border]);
    return $st->rowCount();
}
