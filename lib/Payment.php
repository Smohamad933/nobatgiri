<?php
declare(strict_types=1);

/**
 * پرداخت آنلاین: درایور شبیه‌سازی‌شده (نمایشی) + ساختار آماده‌ی زرین‌پال
 */

function create_payment(int $booking_id, int $amount, string $kind = 'full', string $method = 'online'): array
{
    $gateway = setting('payment_driver', 'simulated');
    if ($gateway === 'zarinpal' && !setting('zarinpal_merchant')) {
        $gateway = 'simulated'; // اگر مرچنت تنظیم نشده، نمایشی
    }
    $token = bin2hex(random_bytes(16));
    $st = db()->prepare('INSERT INTO payments (booking_id, amount, kind, method, gateway, status, token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$booking_id, $amount, $kind, $method, $gateway, 'pending', $token, now_str()]);
    $st = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([(int) db()->lastInsertId()]);
    return $st->fetch();
}

function get_payment_by_token(string $token): ?array
{
    $st = db()->prepare('SELECT p.*, b.code AS booking_code FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** ثبت موفقیت پرداخت و تأیید نهایی رزرو */
function payment_success(array $payment, string $refId = ''): array
{
    $pdo = db();
    $st = $pdo->prepare("UPDATE payments SET status = 'success', ref_id = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
    $st->execute([$refId, now_str(), $payment['id']]);

    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$payment['booking_id']]);
    $booking = $st->fetch();
    if ($booking) {
        $paid = (int) $booking['amount_paid'] + (int) $payment['amount'];
        $payStatus = $paid >= (int) $booking['price'] ? 'paid' : 'partial';
        $st = $pdo->prepare("UPDATE bookings SET amount_paid = ?, payment_status = ?, status = 'confirmed', updated_at = ? WHERE id = ?");
        $st->execute([$paid, $payStatus, now_str(), $booking['id']]);
        $booking = booking_detail((int) $booking['id']);
        schedule_booking_notifications($booking);
        return $booking;
    }
    return [];
}

function payment_fail(array $payment, string $error = ''): void
{
    $st = db()->prepare('UPDATE payments SET status = ?, updated_at = ? WHERE id = ?');
    $st->execute(['failed', now_str(), $payment['id']]);
    if ($error) {
        app_log('payments.log', "payment #{$payment['id']} failed: {$error}");
    }
}

// ---------- درگاه زرین‌پال (آماده‌ی اتصال با مرچنت واقعی) ----------

function zarinpal_request(array $payment, string $callbackUrl): array
{
    $merchant = (string) setting('zarinpal_merchant', '');
    if ($merchant === '') {
        throw new BookingException('مرچنت زرین‌پال تنظیم نشده است.');
    }
    $booking = booking_detail((int) $payment['booking_id']);
    $payload = [
        'merchant_id'  => $merchant,
        'amount'       => (int) $payment['amount'],
        'currency'     => 'IRT',
        'description'  => 'رزرو ' . setting('business_name', '') . ' - کد ' . $booking['code'],
        'callback_url' => $callbackUrl,
        'metadata'     => ['mobile' => $booking['customer_phone'], 'order_id' => $booking['code']],
    ];
    $res = zarinpal_call('https://api.zarinpal.com/pg/v4/payment/request.json', $payload);
    if (($res['data']['code'] ?? 0) !== 100 || empty($res['data']['authority'])) {
        throw new BookingException('خطا در اتصال به درگاه پرداخت.');
    }
    $st = db()->prepare('UPDATE payments SET ref_id = ? WHERE id = ?');
    $st->execute([$res['data']['authority'], $payment['id']]);
    return ['authority' => $res['data']['authority'], 'url' => 'https://www.zarinpal.com/pg/StartPay/' . $res['data']['authority']];
}

function zarinpal_verify(array $payment, string $authority): bool
{
    $res = zarinpal_call('https://api.zarinpal.com/pg/v4/payment/verify.json', [
        'merchant_id' => (string) setting('zarinpal_merchant', ''),
        'amount'      => (int) $payment['amount'],
        'authority'   => $authority,
    ]);
    $code = (int) ($res['data']['code'] ?? 0);
    return $code === 100 || $code === 101;
}

function zarinpal_call(string $url, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new BookingException('افزونه‌ی cURL روی سرور فعال نیست.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new BookingException('خطای ارتباط با درگاه: ' . $err);
    }
    return json_decode($body, true) ?: [];
}
