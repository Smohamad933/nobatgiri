<?php
declare(strict_types=1);

/**
 * اشتراک‌گذاری نوبت: لینک گوگل کلندر + فایل ICS
 */

function gcal_link(array $booking): string
{
    $tz = new DateTimeZone('Asia/Tehran');
    $start = new DateTime($booking['booking_date'] . ' ' . $booking['start_time'], $tz);
    $end = new DateTime($booking['booking_date'] . ' ' . $booking['end_time'], $tz);
    $params = [
        'action'   => 'TEMPLATE',
        'text'     => 'نوبت ' . ($booking['service_name'] ?? '') . ' — ' . setting('business_name', ''),
        'dates'    => $start->format('Ymd\THis') . '/' . $end->format('Ymd\THis'),
        'ctz'      => 'Asia/Tehran',
        'details'  => 'متخصص: ' . ($booking['staff_name'] ?? '') . "\nکد پیگیری: " . $booking['code'],
        'location' => ($booking['branch_name'] ?? '') . ' ' . ($booking['branch_address'] ?? ''),
    ];
    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}

function ics_content(array $booking): string
{
    $tz = new DateTimeZone('Asia/Tehran');
    $utc = new DateTimeZone('UTC');
    $start = (new DateTime($booking['booking_date'] . ' ' . $booking['start_time'], $tz))->setTimezone($utc);
    $end = (new DateTime($booking['booking_date'] . ' ' . $booking['end_time'], $tz))->setTimezone($utc);
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Nobatgiri//Booking//FA',
        'BEGIN:VEVENT',
        'UID:' . $booking['code'] . '@nobatgiri',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $start->format('Ymd\THis\Z'),
        'DTEND:' . $end->format('Ymd\THis\Z'),
        'SUMMARY:' . ics_escape('نوبت ' . ($booking['service_name'] ?? '') . ' — ' . setting('business_name', '')),
        'DESCRIPTION:' . ics_escape('متخصص: ' . ($booking['staff_name'] ?? '') . ' — کد پیگیری: ' . $booking['code']),
        'LOCATION:' . ics_escape(($booking['branch_name'] ?? '') . ' ' . ($booking['branch_address'] ?? '')),
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", $lines) . "\r\n";
}

function ics_escape(string $s): string
{
    return str_replace(["\\", ";", ",", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", ''], $s);
}
