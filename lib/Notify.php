<?php
declare(strict_types=1);

/**
 * سیستم اعلان‌ها: صف پیامک/ایمیل + یادآوری خودکار + ارسال‌کننده‌ها
 */

/** ارسال مستقیم پیامک از طریق درایور فعال (برای OTP و موارد فوری) */
function send_sms_direct(string $phone, string $message): bool
{
    $driver = setting('sms_driver', 'log');
    if ($driver === 'kavenegar' && setting('kavenegar_api')) {
        $ok = kavenegar_send($phone, $message);
        app_log('sms.log', "KAVENEGAR to={$phone} ok=" . ($ok ? '1' : '0') . " msg=" . mb_substr($message, 0, 120));
        return $ok;
    }
    app_log('sms.log', "LOG to={$phone} msg={$message}");
    return true;
}

/** ارسال پیامک با کاوه‌نگار (در صورت تنظیم کلید) */
function kavenegar_send(string $phone, string $message): bool
{
    $api = (string) setting('kavenegar_api', '');
    if ($api === '') {
        return false;
    }
    $sender = (string) setting('sms_sender', '');
    $url = 'https://api.kavenegar.com/v1/' . urlencode($api) . '/sms/send.json';
    $payload = http_build_query([
        'receptor' => normalize_phone($phone),
        'sender'   => $sender,
        'message'  => $message,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        return false;
    }
    $data = json_decode($res, true);
    return isset($data['return']['status']) && (int) $data['return']['status'] === 200;
}

/** افزودن پیام به صف ارسال */
function queue_notification(?int $booking_id, ?int $customer_id, string $channel, string $template, string $recipient, string $message, ?string $scheduled_at = null): int
{
    $st = db()->prepare('INSERT INTO notifications (booking_id, customer_id, channel, template, recipient, message, status, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$booking_id, $customer_id, $channel, $template, $recipient, $message, 'queued', $scheduled_at ?? now_str(), now_str()]);
    return (int) db()->lastInsertId();
}

/** ساخت متن پیام از روی قالب */
function booking_message(string $template, array $booking, array $service, array $staff, array $branch): string
{
    $name = $booking['customer_name'] ?: 'مشتری گرامی';
    $biz  = setting('business_name', 'نوبت‌گیری');
    $date = fa_long_date($booking['booking_date']);
    $time = fa(substr($booking['start_time'], 0, 5));
    $code = $booking['code'];

    switch ($template) {
        case 'booking_created':
            return "{$name} عزیز، نوبت شما در {$biz} ثبت شد.\nخدمت: {$service['name']}\nمتخصص: {$staff['name']}\nزمان: {$date} ساعت {$time}\nکد پیگیری: {$code}";
        case 'reminder_24h':
            return "یادآوری: {$name} عزیز، نوبت فردای شما در {$biz}:\n{$service['name']} با {$staff['name']}\n{$date} ساعت {$time}\nکد پیگیری: {$code}";
        case 'reminder_2h':
            return "یادآوری: نوبت امروز شما در {$biz} دو ساعت دیگر است:\n{$service['name']} با {$staff['name']} ساعت {$time}\nکد پیگیری: {$code}";
        case 'cancelled':
            return "{$name} عزیز، نوبت {$date} ساعت {$time} شما در {$biz} لغو شد." . ($booking['refund_amount'] > 0 ? ' مبلغ ' . money($booking['refund_amount']) . ' مسترد می‌گردد.' : '');
        case 'waiting_offer':
            return "{$name} عزیز، ظرفیت خالی برای «{$service['name']}» در {$biz} آزاد شد. لطفاً سریعاً رزرو کنید:\n{$date} ساعت {$time}";
        default:
            return $template;
    }
}

/** زمان‌بندی اعلان‌های یک رزرو (تأیید + یادآوری‌ها) */
function schedule_booking_notifications(array $booking): void
{
    $pdo = db();
    $service = get_service((int) $booking['service_id']);
    $staff   = get_staff((int) $booking['staff_id']);
    $branch  = get_branch((int) $booking['branch_id']);
    if (!$service || !$staff || !$branch) {
        return;
    }
    $phone = $booking['customer_phone'];
    $cid = $booking['customer_id'] ? (int) $booking['customer_id'] : null;
    $bid = (int) $booking['id'];

    queue_notification($bid, $cid, 'sms', 'booking_created', $phone,
        booking_message('booking_created', $booking, $service, $staff, $branch), now_str());

    $startTs = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
    if (setting('reminder_24h', '1') === '1' && $startTs - 24 * 3600 > time() + 300) {
        queue_notification($bid, $cid, 'sms', 'reminder_24h', $phone,
            booking_message('reminder_24h', $booking, $service, $staff, $branch),
            date('Y-m-d H:i:s', $startTs - 24 * 3600));
    }
    if (setting('reminder_2h', '1') === '1' && $startTs - 2 * 3600 > time() + 300) {
        queue_notification($bid, $cid, 'sms', 'reminder_2h', $phone,
            booking_message('reminder_2h', $booking, $service, $staff, $branch),
            date('Y-m-d H:i:s', $startTs - 2 * 3600));
    }
}

/** لغو اعلان‌های در صف یک رزرو (مثلاً هنگام لغو نوبت) */
function cancel_queued_notifications(int $booking_id): void
{
    $st = db()->prepare("UPDATE notifications SET status = 'cancelled' WHERE booking_id = ? AND status = 'queued'");
    $st->execute([$booking_id]);
}

/**
 * ارسال پیام‌های سررسیدشده‌ی صف. خروجی: [sent, failed]
 * @return array{int,int}
 */
function send_due_notifications(int $limit = 50): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM notifications WHERE status = 'queued' AND scheduled_at <= ? ORDER BY scheduled_at ASC LIMIT {$limit}");
    $st->execute([now_str()]);
    $rows = $st->fetchAll();
    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $st2 = $pdo->prepare("UPDATE notifications SET status = 'sending' WHERE id = ? AND status = 'queued'");
        $st2->execute([$row['id']]);
        if ($st2->rowCount() === 0) {
            continue;
        }
        $ok = true;
        $err = null;
        if ($row['channel'] === 'sms') {
            $ok = send_sms_direct($row['recipient'], $row['message']);
            if (!$ok) {
                $err = 'خطای درایور پیامک';
            }
        } elseif ($row['channel'] === 'email') {
            $ok = @mail($row['recipient'], setting('business_name', 'نوبت‌گیری'), $row['message'], "Content-Type: text/plain; charset=utf-8");
            if (!$ok) {
                $err = 'خطای ارسال ایمیل';
            }
        }
        if ($ok) {
            $sent++;
            $st3 = $pdo->prepare("UPDATE notifications SET status = 'sent', sent_at = ? WHERE id = ?");
            $st3->execute([now_str(), $row['id']]);
            if (in_array($row['template'], ['reminder_24h', 'reminder_2h'], true) && $row['booking_id']) {
                $col = $row['template'] === 'reminder_24h' ? 'remind_24h_sent_at' : 'remind_2h_sent_at';
                $st4 = $pdo->prepare("UPDATE bookings SET {$col} = ? WHERE id = ?");
                $st4->execute([now_str(), $row['booking_id']]);
            }
        } else {
            $failed++;
            $st3 = $pdo->prepare('UPDATE notifications SET status = ? WHERE id = ?');
            $st3->execute([$err ? 'failed' : 'failed', $row['id']]);
            if ($err) {
                $st5 = $pdo->prepare('UPDATE notifications SET error = ? WHERE id = ?');
                $st5->execute([$err, $row['id']]);
            }
        }
    }
    return [$sent, $failed];
}
