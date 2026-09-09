<?php
declare(strict_types=1);
/**
 * مهاجرت نسخه ۱ به ۲ (مارکت‌پلیس چندکسب‌وکاری).
 * اجرا: یک‌بار، از مرورگر (با لاگین مدیر) یا CLI:
 *   php database/migrate.php
 *   https://domain/database/migrate.php?key=CRON_KEY
 * کاملاً آیدم‌پوتنت است (اجرای چندباره بی‌خطر).
 */
require __DIR__ . '/../config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Notify.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = get_param('key');
    $ok = ($key !== '' && hash_equals((string) setting('cron_key', 'x'), $key)) || current_admin();
    if (!$ok) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

function migrate_log(string $msg): void
{
    echo $msg . "\n";
    app_log('migrate.log', $msg);
}

try {
    $pdo = db();
    $driver = db_driver();

    // ۱) جدول businesses
    if ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS businesses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT 'سایر',
            description TEXT NULL,
            phone VARCHAR(20) NULL,
            address VARCHAR(255) NULL,
            city VARCHAR(60) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY ix_biz_category (category),
            KEY ix_biz_owner (owner_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $cols = $pdo->query('SHOW COLUMNS FROM branches')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('business_id', $cols, true)) {
            $pdo->exec('ALTER TABLE branches ADD COLUMN business_id INT UNSIGNED NULL AFTER id, ADD KEY ix_branch_biz (business_id)');
            migrate_log('branches.business_id added (mysql)');
        }
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS businesses (
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
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_biz_category ON businesses(category)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_biz_owner ON businesses(owner_user_id)');
        $cols = $pdo->query('PRAGMA table_info(branches)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('business_id', $cols, true)) {
            $pdo->exec('ALTER TABLE branches ADD COLUMN business_id INTEGER NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_branch_biz ON branches(business_id)');
            migrate_log('branches.business_id added (sqlite)');
        }
    }
    migrate_log('businesses table ready');

    // ۲) ساخت کسب‌وکار پیش‌فرض و انتساب شعبه‌های بدون کسب‌وکار
    $n = (int) $pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
    if ($n === 0) {
        $st = $pdo->prepare('INSERT INTO businesses (name, category, description, phone, address, city, sort, created_at) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            setting('business_name', 'کسب‌وکار من'),
            'سایر',
            setting('business_about', ''),
            setting('business_phone', ''),
            setting('business_address', ''),
            'تهران',
            0,
            now_str(),
        ]);
        migrate_log('default business created: id=' . $pdo->lastInsertId());
    }
    $bizId = (int) $pdo->query('SELECT id FROM businesses ORDER BY id LIMIT 1')->fetchColumn();
    $u = $pdo->prepare('UPDATE branches SET business_id = ? WHERE business_id IS NULL');
    $u->execute([$bizId]);
    migrate_log('orphan branches assigned: ' . $u->rowCount());

    migrate_log('MIGRATE OK');
} catch (Throwable $e) {
    migrate_log('MIGRATE FAILED: ' . $e->getMessage());
    exit(1);
}
