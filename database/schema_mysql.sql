-- ============================================================
--  سیستم نوبت‌دهی — اسکیمای MySQL (utf8mb4)
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT UNSIGNED NULL COMMENT 'صاحب کسب‌وکار (نقش provider)',
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'سایر' COMMENT 'دسته‌بندی شغلی',
    description TEXT NULL,
    phone VARCHAR(20) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(60) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY ix_biz_category (category),
    KEY ix_biz_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    business_id INT UNSIGNED NULL COMMENT 'NULL یعنی کسب‌وکار پیش‌فرض',
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY ix_branch_biz (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NULL,
    phone VARCHAR(15) NOT NULL,
    email VARCHAR(150) NULL,
    password_hash VARCHAR(255) NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_users_phone (phone),
    KEY ix_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    title VARCHAR(120) NULL,
    specialty VARCHAR(255) NULL,
    phone VARCHAR(15) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    KEY ix_staff_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NULL COMMENT 'NULL یعنی همه‌ی شعبه‌ها',
    category VARCHAR(80) NOT NULL DEFAULT 'عمومی',
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price INT UNSIGNED NOT NULL DEFAULT 0,
    deposit_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    KEY ix_services_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_staff (
    service_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (service_id, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL COMMENT 'NULL یعنی پیش‌فرض شعبه',
    weekday TINYINT NOT NULL COMMENT '0=یکشنبه ... 6=شنبه (مطابق date w)',
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    KEY ix_shifts_lookup (branch_id, staff_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS breaks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    weekday TINYINT NULL COMMENT 'NULL+date NULL یعنی هر روز',
    break_date CHAR(10) NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    reason VARCHAR(150) NULL,
    KEY ix_breaks_lookup (branch_id, staff_id, break_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NULL COMMENT 'NULL یعنی همه‌ی شعبه‌ها',
    holiday_date CHAR(10) NOT NULL,
    title VARCHAR(150) NULL,
    UNIQUE KEY uq_holiday (branch_id, holiday_date),
    KEY ix_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(12) NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    customer_name VARCHAR(120) NULL,
    customer_phone VARCHAR(15) NOT NULL,
    booking_date CHAR(10) NOT NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending_payment',
    price INT UNSIGNED NOT NULL DEFAULT 0,
    deposit_required INT UNSIGNED NOT NULL DEFAULT 0,
    amount_paid INT UNSIGNED NOT NULL DEFAULT 0,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    notes TEXT NULL,
    admin_notes TEXT NULL,
    remind_24h_sent_at DATETIME NULL,
    remind_2h_sent_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    refund_amount INT UNSIGNED NOT NULL DEFAULT 0,
    slot_key VARCHAR(64) NULL COMMENT 'کلید یکتای اسلات فعال؛ با لغو/اتمام NULL می‌شود تا ساعت قابل رزرو مجدد باشد',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_booking_code (code),
    UNIQUE KEY uq_slot_active (slot_key),
    KEY ix_bookings_date (booking_date, status),
    KEY ix_bookings_phone (customer_phone),
    KEY ix_bookings_staff (staff_id, booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL DEFAULT 0,
    kind VARCHAR(20) NOT NULL DEFAULT 'full' COMMENT 'deposit|full|refund|remaining',
    method VARCHAR(20) NOT NULL DEFAULT 'online',
    gateway VARCHAR(20) NOT NULL DEFAULT 'simulated',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ref_id VARCHAR(64) NULL,
    token VARCHAR(64) NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY ix_payments_booking (booking_id),
    KEY ix_payments_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waiting_list (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    customer_name VARCHAR(120) NULL,
    customer_phone VARCHAR(15) NOT NULL,
    date_from CHAR(10) NOT NULL,
    preferred_time CHAR(5) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    notified_at DATETIME NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY ix_waiting_lookup (branch_id, service_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'sms',
    template VARCHAR(40) NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY ix_notif_schedule (status, scheduled_at),
    KEY ix_notif_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(15) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(20) NOT NULL DEFAULT 'login',
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY ix_otp_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
