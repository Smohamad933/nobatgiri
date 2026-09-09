<?php
declare(strict_types=1);
// نگهبان مشترک پنل صاحب خدمت: احراز هویت + کسب‌وکار جاری + ابزار محدودسازی دسترسی
// در ابتدای همه‌ی صفحات provider (بعد از require_installed) لود می‌شود.
$provider = require_provider();
$myBusinesses = businesses_for_provider((int) $provider['id']);
$business = provider_current_business($provider);
$BIDS = $business ? provider_branch_ids($business) : [0];
$BIDS_CSV = implode(',', array_map('intval', $BIDS));

/** شعبه‌های کسب‌وکار جاری (فعال و غیرفعال) */
function biz_branches(): array
{
    global $business;
    return $business ? branches_of_business((int) $business['id'], false) : [];
}

function biz_no_access(string $back = 'provider/index.php'): void
{
    flash('error', 'دسترسی غیرمجاز.');
    redirect(u($back));
}

/** بررسی تعلق شعبه به کسب‌وکار جاری */
function biz_require_branch(int $id): array
{
    global $business;
    $b = get_branch($id);
    if (!$b || !$business || (int) $b['business_id'] !== (int) $business['id']) {
        biz_no_access();
    }
    return $b;
}

/** بررسی تعلق خدمت به کسب‌وکار جاری (خدمت سراسری قابل ویرایش توسط provider نیست) */
function biz_require_service(int $id): array
{
    global $business;
    $s = $id ? get_service($id) : null;
    $bb = ($s && $s['branch_id']) ? get_branch((int) $s['branch_id']) : null;
    if (!$s || !$bb || !$business || (int) $bb['business_id'] !== (int) $business['id']) {
        biz_no_access('provider/services.php');
    }
    return $s;
}

function biz_require_staff(int $id): array
{
    global $business;
    $s = $id ? get_staff($id) : null;
    $bb = $s ? get_branch((int) $s['branch_id']) : null;
    if (!$s || !$bb || !$business || (int) $bb['business_id'] !== (int) $business['id']) {
        biz_no_access('provider/staff.php');
    }
    return $s;
}

function biz_require_booking(int $id): array
{
    global $business;
    $b = $id ? booking_detail($id) : null;
    $bb = $b ? get_branch((int) $b['branch_id']) : null;
    if (!$b || !$bb || !$business || (int) $bb['business_id'] !== (int) $business['id']) {
        biz_no_access('provider/bookings.php');
    }
    return $b;
}

/** بررسی تعلق شیفت/استراحت/تعطیلی به کسب‌وکار جاری (حذف امن) */
function biz_require_shift(int $id, string $table = 'shifts'): void
{
    global $business, $BIDS_CSV;
    $table = in_array($table, ['shifts', 'breaks', 'holidays'], true) ? $table : 'shifts';
    if ($table === 'holidays') {
        $st = db()->prepare('SELECT t.*, b.business_id AS b_biz FROM holidays t LEFT JOIN branches b ON b.id = t.branch_id WHERE t.id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $mine = $row && $business && (int) ($row['b_biz'] ?? 0) === (int) $business['id'];
    } else {
        $st = db()->prepare("SELECT t.*, b.business_id AS b_biz, b2.business_id AS s_biz FROM {$table} t
            LEFT JOIN branches b ON b.id = t.branch_id
            LEFT JOIN staff st ON st.id = t.staff_id LEFT JOIN branches b2 ON b2.id = st.branch_id
            WHERE t.id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        $mine = $row && $business && ((int) ($row['b_biz'] ?? 0) === (int) $business['id'] || (int) ($row['s_biz'] ?? 0) === (int) $business['id']);
    }
    if (!$mine) {
        biz_no_access('provider/shifts.php');
    }
}

/** آیا شناسه‌ی خدمت متعلق به کسب‌وکار جاری است؟ */
function biz_owns_service(int $id): bool
{
    global $business;
    if ($id <= 0 || !$business) {
        return false;
    }
    $s = get_service($id);
    if (!$s || !$s['branch_id']) {
        return false;
    }
    $bb = get_branch((int) $s['branch_id']);
    return $bb && (int) $bb['business_id'] === (int) $business['id'];
}

/** آیا شناسه‌ی متخصص متعلق به کسب‌وکار جاری است؟ */
function biz_owns_staff(int $id): bool
{
    global $business;
    if ($id <= 0 || !$business) {
        return false;
    }
    $s = get_staff($id);
    if (!$s) {
        return false;
    }
    $bb = get_branch((int) $s['branch_id']);
    return $bb && (int) $bb['business_id'] === (int) $business['id'];
}
