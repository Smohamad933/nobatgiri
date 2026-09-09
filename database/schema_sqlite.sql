-- ============================================================
--  سیستم نوبت‌دهی — اسکیمای SQLite (حالت نمایشی/توسعه)
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NULL
);

CREATE TABLE IF NOT EXISTS businesses (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'سایر',
    description TEXT NULL,
    phone VARCHAR(20) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(60) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_biz_category ON businesses(category);
CREATE INDEX IF NOT EXISTS ix_biz_owner ON businesses(owner_user_id);

CREATE TABLE IF NOT EXISTS branches (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    business_id INTEGER NULL,
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_branch_biz ON branches(business_id);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NULL,
    phone VARCHAR(15) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    password_hash VARCHAR(255) NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_users_role ON users(role);

CREATE TABLE IF NOT EXISTS staff (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    name VARCHAR(120) NOT NULL,
    title VARCHAR(120) NULL,
    specialty VARCHAR(255) NULL,
    phone VARCHAR(15) NULL,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_staff_branch ON staff(branch_id);

CREATE TABLE IF NOT EXISTS services (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'عمومی',
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price INTEGER NOT NULL DEFAULT 0,
    deposit_percent INTEGER NOT NULL DEFAULT 0,
    duration_minutes INTEGER NOT NULL DEFAULT 30,
    buffer_minutes INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_services_branch ON services(branch_id);

CREATE TABLE IF NOT EXISTS service_staff (
    service_id INTEGER NOT NULL,
    staff_id INTEGER NOT NULL,
    PRIMARY KEY (service_id, staff_id)
);

CREATE TABLE IF NOT EXISTS shifts (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    staff_id INTEGER NULL,
    weekday INTEGER NOT NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS ix_shifts_lookup ON shifts(branch_id, staff_id, weekday);

CREATE TABLE IF NOT EXISTS breaks (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    staff_id INTEGER NULL,
    weekday INTEGER NULL,
    break_date CHAR(10) NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    reason VARCHAR(150) NULL
);
CREATE INDEX IF NOT EXISTS ix_breaks_lookup ON breaks(branch_id, staff_id, break_date);

CREATE TABLE IF NOT EXISTS holidays (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NULL,
    holiday_date CHAR(10) NOT NULL,
    title VARCHAR(150) NULL,
    UNIQUE (branch_id, holiday_date)
);
CREATE INDEX IF NOT EXISTS ix_holidays_date ON holidays(holiday_date);

CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(12) NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    staff_id INTEGER NOT NULL,
    customer_id INTEGER NULL,
    customer_name VARCHAR(120) NULL,
    customer_phone VARCHAR(15) NOT NULL,
    booking_date CHAR(10) NOT NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending_payment',
    price INTEGER NOT NULL DEFAULT 0,
    deposit_required INTEGER NOT NULL DEFAULT 0,
    amount_paid INTEGER NOT NULL DEFAULT 0,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    notes TEXT NULL,
    admin_notes TEXT NULL,
    remind_24h_sent_at TEXT NULL,
    remind_2h_sent_at TEXT NULL,
    cancelled_at TEXT NULL,
    cancel_reason VARCHAR(255) NULL,
    refund_amount INTEGER NOT NULL DEFAULT 0,
    slot_key VARCHAR(64) NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NULL,
    UNIQUE (slot_key)
);
CREATE INDEX IF NOT EXISTS ix_bookings_date ON bookings(booking_date, status);
CREATE INDEX IF NOT EXISTS ix_bookings_phone ON bookings(customer_phone);
CREATE INDEX IF NOT EXISTS ix_bookings_staff ON bookings(staff_id, booking_date);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    amount INTEGER NOT NULL DEFAULT 0,
    kind VARCHAR(20) NOT NULL DEFAULT 'full',
    method VARCHAR(20) NOT NULL DEFAULT 'online',
    gateway VARCHAR(20) NOT NULL DEFAULT 'simulated',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ref_id VARCHAR(64) NULL,
    token VARCHAR(64) NULL,
    meta TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS ix_payments_booking ON payments(booking_id);
CREATE INDEX IF NOT EXISTS ix_payments_token ON payments(token);

CREATE TABLE IF NOT EXISTS waiting_list (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    branch_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    staff_id INTEGER NULL,
    customer_id INTEGER NULL,
    customer_name VARCHAR(120) NULL,
    customer_phone VARCHAR(15) NOT NULL,
    date_from CHAR(10) NOT NULL,
    preferred_time CHAR(5) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    notified_at TEXT NULL,
    note VARCHAR(255) NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_waiting_lookup ON waiting_list(branch_id, service_id, status);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NULL,
    customer_id INTEGER NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'sms',
    template VARCHAR(40) NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    scheduled_at TEXT NOT NULL,
    sent_at TEXT NULL,
    error VARCHAR(255) NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_notif_schedule ON notifications(status, scheduled_at);
CREATE INDEX IF NOT EXISTS ix_notif_booking ON notifications(booking_id);

CREATE TABLE IF NOT EXISTS otp_codes (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    phone VARCHAR(15) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(20) NOT NULL DEFAULT 'login',
    expires_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_otp_phone ON otp_codes(phone);
