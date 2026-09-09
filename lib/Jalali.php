<?php
declare(strict_types=1);

/**
 * تبدیل تاریخ میلادی ⇄ جلالی + قالب‌بندی تاریخ فارسی
 * (پیاده‌سازی مستقل، بدون نیاز به افزونه‌ی intl)
 */

function g2j_array(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days = $days % 12053;
    $jy += 4 * intdiv($days, 1461);
    $days = $days % 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function j2g_array(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
        + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days = $days % 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * intdiv($days, 36524);
        $days = $days % 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days = $days % 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
    $sal_a = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 11 && $gd > $sal_a[$gm]) {
        $gd -= $sal_a[$gm];
        $gm++;
    }
    return [$gy, $gm + 1, $gd];
}

/** 'Y-m-d' میلادی به آرایه‌ی [jy, jm, jd] */
function gdate_to_jalali(string $gdate): array
{
    [$y, $m, $d] = array_map('intval', explode('-', $gdate));
    return g2j_array($y, $m, $d);
}

/** تاریخ جلالی به رشته‌ی میلادی 'Y-m-d' */
function jalali_to_gdate(int $jy, int $jm, int $jd): string
{
    [$gy, $gm, $gd] = j2g_array($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

function is_jalali_leap(int $jy): bool
{
    // کبیسه بودن بر اساس طول سال: اختلاف روز اول دو سال متوالی
    $a = jalali_to_gdate($jy, 1, 1);
    $b = jalali_to_gdate($jy + 1, 1, 1);
    return (strtotime($b) - strtotime($a)) / 86400 === 366.0;
}

function jalali_month_days(int $jy, int $jm): int
{
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    return is_jalali_leap($jy) ? 30 : 29;
}

function j_month_name(int $m): string
{
    static $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $names[$m] ?? '';
}

/** نام روز هفته؛ ورودی 0=شنبه تا 6=جمعه */
function j_week_name(int $sat0): string
{
    static $names = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    return $names[$sat0 % 7];
}

/** ایندکس شنبه-مبنا (0=شنبه) از روی timestamp */
function weekday_sat0(int $ts): int
{
    return (intval(date('w', $ts)) + 1) % 7;
}

/**
 * قالب‌بندی تاریخ فارسی (مشابه date ولی با تقویم جلالی).
 * توکن‌ها: Y y m n d j H i s F M l D + جداکننده‌ها
 */
function jdate(string $format, ?int $ts = null): string
{
    $ts = $ts ?? time();
    [$jy, $jm, $jd] = g2j_array((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $out .= $format[++$i];
            continue;
        }
        switch ($c) {
            case 'Y': $out .= sprintf('%04d', $jy); break;
            case 'y': $out .= sprintf('%02d', $jy % 100); break;
            case 'm': $out .= sprintf('%02d', $jm); break;
            case 'n': $out .= $jm; break;
            case 'd': $out .= sprintf('%02d', $jd); break;
            case 'j': $out .= $jd; break;
            case 'F': $out .= j_month_name($jm); break;
            case 'M': $out .= j_month_name($jm); break;
            case 'l': $out .= j_week_name(weekday_sat0($ts)); break;
            case 'D': $out .= j_week_name(weekday_sat0($ts)); break;
            case 'H': $out .= date('H', $ts); break;
            case 'i': $out .= date('i', $ts); break;
            case 's': $out .= date('s', $ts); break;
            default:  $out .= $c;
        }
    }
    return $out;
}

/** تاریخ کوتاه فارسی: ۱۴۰۵/۰۶/۱۸ */
function fa_short_date(string $gdate): string
{
    [$jy, $jm, $jd] = gdate_to_jalali($gdate);
    return fa(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

/** تاریخ بلند فارسی: شنبه ۱۸ شهریور ۱۴۰۵ */
function fa_long_date(string $gdate): string
{
    $ts = strtotime($gdate . ' 12:00:00');
    return j_week_name(weekday_sat0($ts)) . ' ' . fa(jdate('j F Y', $ts));
}

/** تاریخ + ساعت فارسی: شنبه ۱۸ شهریور ۱۴۰۵، ساعت ۱۰:۰۰ */
function fa_datetime(string $gdate, string $time): string
{
    return fa_long_date($gdate) . '، ساعت ' . fa(substr($time, 0, 5));
}
