<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';
require ROOT_PATH . '/lib/Booking.php';
require ROOT_PATH . '/lib/Payment.php';
require ROOT_PATH . '/lib/Share.php';

require_installed_json();
start_session();

$action = get_param('action', post('action'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// همه‌ی درخواست‌های تغییردهنده نیاز به CSRF دارند
$csrfActions = ['send_otp', 'verify_otp', 'create_booking', 'join_waiting', 'cancel_mine', 'logout',
    'booking_status', 'waiting_status', 'send_due', 'ensure_payment'];
if (in_array($action, $csrfActions, true) && !csrf_check()) {
    json_error('نشست شما منقضی شده است. صفحه را تازه‌سازی کنید.', 419);
}

try {
    switch ($action) {
        // ================= عمومی =================
        case 'bootstrap':
            $customer = current_customer();
            $bizId = get_int('business_id');
            $biz = $bizId ? get_business($bizId) : null;
            if ((!$biz || !$biz['active']) && !$bizId) {
                $all = active_businesses();
                $biz = $all[0] ?? null;
            }
            if (!$biz || !$biz['active']) {
                json_error('کسب‌وکار یافت نشد.', 404);
            }
            json_out(['ok' => true,
                'platform' => [
                    'name'  => setting('business_name', 'نوبت‌گیری'),
                    'about' => setting('business_about', ''),
                ],
                'business' => [
                    'id' => (int) $biz['id'], 'name' => $biz['name'], 'category' => $biz['category'],
                    'description' => $biz['description'], 'phone' => $biz['phone'],
                    'address' => $biz['address'], 'city' => $biz['city'],
                ],
                'policy' => [
                    'free_cancel_hours' => setting_int('free_cancel_hours', 48),
                    'late_fee_percent'  => setting_int('late_cancel_fee_percent', 20),
                    'min_lead_hours'    => setting_int('min_lead_hours', 2),
                ],
                'branches' => array_map(function ($b) {
                    return ['id' => (int) $b['id'], 'name' => $b['name'], 'address' => $b['address'], 'phone' => $b['phone']];
                }, active_branches((int) $biz['id'])),
                'customer' => $customer ? ['name' => $customer['name'], 'phone' => $customer['phone']] : null,
                'csrf'     => csrf_token(),
            ]);

        case 'categories':
            json_out(['ok' => true,
                'categories' => array_map(function ($c) {
                    return ['name' => $c['category'], 'count' => (int) $c['c']];
                }, business_categories()),
                'cities' => business_cities(),
            ]);

        case 'businesses':
            $list = [];
            foreach (active_businesses(get_param('category') ?: null, get_param('q'), get_param('city') ?: null) as $b) {
                $next = business_next_available((int) $b['id']);
                $list[] = [
                    'id' => (int) $b['id'], 'name' => $b['name'], 'category' => $b['category'],
                    'description' => $b['description'], 'phone' => $b['phone'],
                    'address' => $b['address'], 'city' => $b['city'],
                    'stats' => business_card_stats((int) $b['id']),
                    'next'  => $next ?: null,
                    'book_url' => u('book.php?b=' . $b['id']),
                ];
            }
            json_out(['ok' => true, 'businesses' => $list]);

        case 'services':
            $branch_id = get_int('branch_id');
            if (!get_branch($branch_id)) {
                json_error('شعبه نامعتبر است.');
            }
            $services = [];
            foreach (active_services($branch_id) as $s) {
                $services[] = [
                    'id' => (int) $s['id'], 'category' => $s['category'], 'name' => $s['name'],
                    'description' => $s['description'], 'price' => (int) $s['price'],
                    'price_label' => $s['price'] > 0 ? money($s['price']) : 'رایگان',
                    'deposit' => (int) round($s['price'] * $s['deposit_percent'] / 100),
                    'deposit_label' => $s['price'] > 0 && $s['deposit_percent'] > 0 ? money(round($s['price'] * $s['deposit_percent'] / 100)) : null,
                    'duration' => (int) $s['duration_minutes'],
                    'duration_label' => duration_label((int) $s['duration_minutes']),
                ];
            }
            json_out(['ok' => true, 'services' => $services]);

        case 'staff':
            $branch_id = get_int('branch_id');
            $service_id = get_int('service_id');
            if (!get_branch($branch_id) || !get_service($service_id)) {
                json_error('اطلاعات نامعتبر است.');
            }
            $staff = [];
            foreach (staff_for_service($branch_id, $service_id) as $s) {
                $staff[] = ['id' => (int) $s['id'], 'name' => $s['name'], 'title' => $s['title'], 'specialty' => $s['specialty']];
            }
            json_out(['ok' => true, 'staff' => $staff]);

        case 'month':
            $branch_id = get_int('branch_id');
            $service_id = get_int('service_id');
            $staff_id = get_int('staff_id'); // 0 = هر متخصص
            $ym = get_param('ym', date('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $ym = date('Y-m');
            }
            [$gy, $gm] = array_map('intval', explode('-', $ym));
            $grid = month_grid($branch_id, $service_id, $staff_id ?: null, $gy, $gm);
            // محدوده‌ی مجاز ناوبری
            $minYm = date('Y-m');
            $maxYm = date('Y-m', strtotime('+' . setting_int('booking_window_days', 30) . ' days'));
            json_out(['ok' => true, 'grid' => $grid, 'min_ym' => $minYm, 'max_ym' => $maxYm]);

        case 'slots':
            $branch_id = get_int('branch_id');
            $service_id = get_int('service_id');
            $staff_id = get_int('staff_id'); // 0 = هر متخصص
            $date = get_param('date');
            if ($staff_id > 0) {
                $r = generate_slots($branch_id, $service_id, $staff_id, $date);
                $staff = get_staff($staff_id);
                foreach ($r['slots'] as &$s) {
                    $s['staff'] = [['id' => $staff_id, 'name' => $staff['name']]];
                }
                json_out(['ok' => true, 'status' => $r['status'], 'reason' => $r['reason'] ?? '', 'slots' => $r['slots']]);
            }
            json_out(['ok' => true] + merged_slots_out($branch_id, $service_id, $date));

        case 'send_otp':
            if ($method !== 'POST') {
                json_error('متد نامعتبر.', 405);
            }
            $r = request_otp(post('phone'));
            if (!$r['ok']) {
                json_error($r['error'], 400, ['wait' => $r['wait'] ?? 0]);
            }
            json_out(['ok' => true, 'wait' => $r['wait'], 'demo_code' => $r['demo_code'] ?? null]);

        case 'verify_otp':
            if ($method !== 'POST') {
                json_error('متد نامعتبر.', 405);
            }
            $r = verify_otp(post('phone'), post('code'));
            if (!$r['ok']) {
                json_error($r['error']);
            }
            $name = post('name');
            if ($name !== '') {
                $st = db()->prepare('UPDATE users SET name = ? WHERE id = ?');
                $st->execute([$name, $r['user']['id']]);
            }
            json_out(['ok' => true, 'customer' => ['name' => $name !== '' ? $name : $r['user']['name'], 'phone' => $r['user']['phone']]]);

        case 'me':
            $c = current_customer();
            json_out(['ok' => true, 'customer' => $c ? ['name' => $c['name'], 'phone' => $c['phone']] : null]);

        case 'logout':
            customer_logout();
            json_out(['ok' => true]);

        case 'create_booking':
            if ($method !== 'POST') {
                json_error('متد نامعتبر.', 405);
            }
            $customer = current_customer();
            if (!$customer) {
                json_error('ابتدا با کد تأیید وارد شوید.', 401);
            }
            $phone = normalize_phone(post('phone'));
            if ($phone !== $customer['phone']) {
                json_error('شماره با حساب تأییدشده مطابقت ندارد.', 403);
            }
            $branch_id = post_int('branch_id');
            $service_id = post_int('service_id');
            $staff_id = post_int('staff_id');
            $date = post('date');
            $start = post('start');
            $payKind = post('pay_kind', 'full'); // full | deposit
            $bizCheck = post_int('business_id');
            if ($bizCheck > 0) {
                $brCheck = get_branch($branch_id);
                if (!$brCheck || (int) ($brCheck['business_id'] ?? 0) !== $bizCheck) {
                    json_error('شعبه متعلق به این کسب‌وکار نیست.');
                }
            }
            try {
                if ($staff_id <= 0) {
                    $booking = create_booking_any_staff($branch_id, $service_id, $date, $start, post('name', $customer['name'] ?? ''), $phone, (int) $customer['id'], post('notes'));
                } else {
                    $booking = create_booking([
                        'branch_id' => $branch_id, 'service_id' => $service_id, 'staff_id' => $staff_id,
                        'date' => $date, 'start' => $start, 'customer_name' => post('name', $customer['name'] ?? ''),
                        'customer_phone' => $phone, 'customer_id' => (int) $customer['id'], 'notes' => post('notes'),
                    ]);
                }
            } catch (SlotTakenException $e) {
                json_error($e->getMessage(), 409);
            } catch (BookingException $e) {
                json_error($e->getMessage());
            }
            if ((int) $booking['price'] <= 0) {
                json_out(['ok' => true, 'booking' => booking_out($booking), 'pay_url' => null]);
            }
            $amount = ($payKind === 'deposit' && (int) $booking['deposit_required'] > 0)
                ? (int) $booking['deposit_required'] : (int) $booking['price'];
            $payment = create_payment((int) $booking['id'], $amount, $amount >= (int) $booking['price'] ? 'full' : 'deposit');
            json_out(['ok' => true, 'booking' => booking_out($booking), 'pay_url' => u('pay.php?token=' . $payment['token']), 'amount' => $amount]);

        case 'ensure_payment':
            // ساخت/بازیابی لینک پرداخت برای رزرو موجود (پیگیری)
            $code = post('code');
            $phone = normalize_phone(post('phone'));
            $booking = booking_by_code($code);
            if (!$booking || $booking['customer_phone'] !== $phone) {
                json_error('رزرو یافت نشد.');
            }
            if (!in_array($booking['status'], ['pending_payment', 'confirmed'], true)) {
                json_error('این رزرو قابل پرداخت نیست.');
            }
            $kind = post('kind', 'full');
            $due = (int) $booking['price'] - (int) $booking['amount_paid'];
            if ($due <= 0) {
                json_error('مبلغی بدهکار نیستید.');
            }
            if ($kind === 'deposit' && (int) $booking['amount_paid'] === 0 && (int) $booking['deposit_required'] > 0) {
                $due = (int) $booking['deposit_required'];
                $kind = 'deposit';
            } else {
                $kind = $due >= (int) $booking['price'] ? 'full' : 'remaining';
            }
            $st = db()->prepare("SELECT * FROM payments WHERE booking_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
            $st->execute([$booking['id']]);
            $payment = $st->fetch();
            if (!$payment || (int) $payment['amount'] !== $due) {
                $payment = create_payment((int) $booking['id'], $due, $kind);
            }
            json_out(['ok' => true, 'pay_url' => u('pay.php?token=' . $payment['token']), 'amount' => $due]);

        case 'booking_lookup':
            $code = $method === 'POST' ? post('code') : get_param('code');
            $phone = normalize_phone($method === 'POST' ? post('phone') : get_param('phone'));
            $booking = booking_by_code($code);
            if (!$booking || $booking['customer_phone'] !== $phone) {
                json_error('رزروی با این مشخصات یافت نشد.', 404);
            }
            $out = booking_out($booking);
            $out['gcal'] = gcal_link($booking);
            $out['ics_url'] = u('ics.php?code=' . urlencode($booking['code']));
            $future = hours_until($booking['booking_date'], $booking['start_time']) > 0;
            $out['can_cancel'] = in_array($booking['status'], ['pending_payment', 'confirmed'], true) && $future;
            $out['can_pay'] = in_array($booking['status'], ['pending_payment', 'confirmed'], true)
                && ((int) $booking['price'] - (int) $booking['amount_paid'] > 0) && $future;
            $out['due'] = max(0, (int) $booking['price'] - (int) $booking['amount_paid']);
            $out['due_label'] = money($out['due']);
            json_out(['ok' => true, 'booking' => $out]);

        case 'cancel_mine':
            if ($method !== 'POST') {
                json_error('متد نامعتبر.', 405);
            }
            $booking = booking_by_code(post('code'));
            if (!$booking || $booking['customer_phone'] !== normalize_phone(post('phone'))) {
                json_error('رزرو یافت نشد.', 404);
            }
            try {
                $r = cancel_booking((int) $booking['id'], 'لغو توسط مشتری', 'customer', true);
            } catch (BookingException $e) {
                json_error($e->getMessage());
            }
            json_out(['ok' => true, 'fee' => $r['fee'], 'fee_label' => money($r['fee']), 'refund' => $r['refund'], 'refund_label' => money($r['refund'])]);

        case 'join_waiting':
            if ($method !== 'POST') {
                json_error('متد نامعتبر.', 405);
            }
            $customer = current_customer();
            if (!$customer) {
                json_error('ابتدا با کد تأیید وارد شوید.', 401);
            }
            $branch_id = post_int('branch_id');
            $service_id = post_int('service_id');
            $staff_id = post_int('staff_id');
            $date_from = post('date_from', today_str());
            if (!get_branch($branch_id) || !get_service($service_id)) {
                json_error('اطلاعات نامعتبر است.');
            }
            $st = db()->prepare("SELECT id FROM waiting_list WHERE customer_phone = ? AND branch_id = ? AND service_id = ? AND status IN ('waiting','offered')");
            $st->execute([$customer['phone'], $branch_id, $service_id]);
            if ($st->fetch()) {
                json_error('شما قبلاً در صف انتظار این خدمت هستید.');
            }
            $st = db()->prepare('INSERT INTO waiting_list (branch_id, service_id, staff_id, customer_id, customer_name, customer_phone, date_from, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$branch_id, $service_id, $staff_id ?: null, $customer['id'], post('name', $customer['name'] ?? ''), $customer['phone'], $date_from, now_str()]);
            json_out(['ok' => true]);

        case 'my_bookings':
            $customer = current_customer();
            if (!$customer) {
                json_error('وارد نشده‌اید.', 401);
            }
            $st = db()->prepare('SELECT b.*, s.name AS service_name, st.name AS staff_name, br.name AS branch_name
                FROM bookings b JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id JOIN branches br ON br.id = b.branch_id
                WHERE b.customer_phone = ? ORDER BY b.booking_date DESC, b.start_time DESC LIMIT 30');
            $st->execute([$customer['phone']]);
            $list = [];
            foreach ($st->fetchAll() as $b) {
                $list[] = booking_out($b);
            }
            json_out(['ok' => true, 'bookings' => $list]);

        // ================= مدیر =================
        case 'admin_day':
            if (!current_admin()) {
                json_error('دسترسی غیرمجاز.', 403);
            }
            $date = get_param('date', today_str());
            $branch_id = get_int('branch_id');
            $staff_id = get_int('staff_id');
            $sql = 'SELECT b.*, s.name AS service_name, st.name AS staff_name, br.name AS branch_name
                FROM bookings b JOIN services s ON s.id = b.service_id JOIN staff st ON st.id = b.staff_id JOIN branches br ON br.id = b.branch_id
                WHERE b.booking_date = ?';
            $params = [$date];
            if ($branch_id) {
                $sql .= ' AND b.branch_id = ?';
                $params[] = $branch_id;
            }
            if ($staff_id) {
                $sql .= ' AND b.staff_id = ?';
                $params[] = $staff_id;
            }
            $sql .= ' ORDER BY b.start_time';
            $st = db()->prepare($sql);
            $st->execute($params);
            $list = [];
            foreach ($st->fetchAll() as $b) {
                $list[] = booking_out($b);
            }
            json_out(['ok' => true, 'date' => $date, 'label' => fa_long_date($date), 'bookings' => $list]);

        case 'booking_status':
            if (!current_admin()) {
                json_error('دسترسی غیرمجاز.', 403);
            }
            try {
                set_booking_status(post_int('id'), post('status'), post('admin_notes'));
            } catch (BookingException $e) {
                json_error($e->getMessage());
            }
            json_out(['ok' => true]);

        case 'waiting_status':
            if (!current_admin()) {
                json_error('دسترسی غیرمجاز.', 403);
            }
            $allowed = ['waiting', 'offered', 'converted', 'cancelled', 'expired'];
            if (!in_array(post('status'), $allowed, true)) {
                json_error('وضعیت نامعتبر.');
            }
            $st = db()->prepare('UPDATE waiting_list SET status = ? WHERE id = ?');
            $st->execute([post('status'), post_int('id')]);
            json_out(['ok' => true]);

        case 'send_due':
            if (!current_admin()) {
                json_error('دسترسی غیرمجاز.', 403);
            }
            $released = release_expired_pendings();
            [$sent, $failed] = send_due_notifications(100);
            $expired = expire_old_waiting_offers();
            json_out(['ok' => true, 'released' => $released, 'sent' => $sent, 'failed' => $failed, 'expired_offers' => $expired]);

        default:
            json_error('درخواست نامعتبر.', 404);
    }
} catch (Throwable $e) {
    app_log('api_errors.log', $action . ': ' . $e->getMessage());
    json_error(APP_DEBUG ? $e->getMessage() : 'خطای داخلی. لطفاً دوباره تلاش کنید.', 500);
}

// ================= توابع کمکی خروجی =================

function duration_label(int $minutes): string
{
    if ($minutes < 60) {
        return fa($minutes) . ' دقیقه';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $s = fa($h) . ' ساعت';
    if ($m > 0) {
        $s .= ' و ' . fa($m) . ' دقیقه';
    }
    return $s;
}

function merged_slots_out(int $branch_id, int $service_id, string $date): array
{
    $byStart = [];
    $lastReason = 'ظرفیت این روز تکمیل شده است.';
    $lastStatus = 'full';
    foreach (staff_for_service($branch_id, $service_id) as $stf) {
        $r = generate_slots($branch_id, $service_id, (int) $stf['id'], $date);
        if ($r['status'] === 'open') {
            foreach ($r['slots'] as $s) {
                $k = $s['start'];
                if (!isset($byStart[$k])) {
                    $byStart[$k] = ['start' => $s['start'], 'end' => $s['end'], 'label' => $s['label'], 'staff' => []];
                }
                $byStart[$k]['staff'][] = ['id' => (int) $stf['id'], 'name' => $stf['name']];
            }
        } else {
            $lastReason = $r['reason'] ?? $lastReason;
            if (in_array($r['status'], ['holiday', 'closed'], true)) {
                $lastStatus = $r['status'];
            }
        }
    }
    ksort($byStart);
    $slots = array_values($byStart);
    if ($slots) {
        return ['status' => 'open', 'reason' => '', 'slots' => $slots];
    }
    return ['status' => $lastStatus, 'reason' => $lastReason, 'slots' => []];
}

/** ثبت نوبت با اولین متخصص آزادی که این ساعت را دارد */
function create_booking_any_staff(int $branch_id, int $service_id, string $date, string $start, string $name, string $phone, int $customer_id, string $notes): array
{
    $merged = merged_slots_out($branch_id, $service_id, $date);
    if ($merged['status'] !== 'open') {
        throw new BookingException($merged['reason']);
    }
    $candidates = [];
    foreach ($merged['slots'] as $s) {
        if ($s['start'] === substr($start, 0, 5)) {
            $candidates = $s['staff'];
            break;
        }
    }
    if (!$candidates) {
        throw new SlotTakenException('متأسفانه این ساعت لحظاتی پیش پر شد.');
    }
    $last = null;
    foreach ($candidates as $c) {
        try {
            return create_booking([
                'branch_id' => $branch_id, 'service_id' => $service_id, 'staff_id' => $c['id'],
                'date' => $date, 'start' => $start, 'customer_name' => $name,
                'customer_phone' => $phone, 'customer_id' => $customer_id, 'notes' => $notes,
            ]);
        } catch (SlotTakenException $e) {
            $last = $e;
        }
    }
    throw $last ?? new SlotTakenException('متأسفانه این ساعت پر شد.');
}

/** خروجی استاندارد رزرو برای فرانت‌اند */
function booking_out(array $b): array
{
    return [
        'id' => (int) $b['id'],
        'code' => $b['code'],
        'service' => $b['service_name'] ?? '',
        'staff' => $b['staff_name'] ?? '',
        'branch' => $b['branch_name'] ?? '',
        'business' => $b['business_name'] ?? '',
        'date' => $b['booking_date'],
        'date_label' => fa_long_date($b['booking_date']),
        'start' => substr($b['start_time'], 0, 5),
        'end' => substr($b['end_time'], 0, 5),
        'time_label' => fa(substr($b['start_time'], 0, 5)) . ' تا ' . fa(substr($b['end_time'], 0, 5)),
        'status' => $b['status'],
        'status_label' => booking_status_label($b['status']),
        'price' => (int) $b['price'],
        'price_label' => money($b['price']),
        'deposit' => (int) $b['deposit_required'],
        'deposit_label' => money($b['deposit_required']),
        'paid' => (int) $b['amount_paid'],
        'paid_label' => money($b['amount_paid']),
        'payment_status' => $b['payment_status'],
        'customer_name' => $b['customer_name'],
        'customer_phone' => $b['customer_phone'],
    ];
}
