<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require ROOT_PATH . '/lib/Helpers.php';
require ROOT_PATH . '/lib/Settings.php';
require ROOT_PATH . '/lib/Auth.php';
require ROOT_PATH . '/lib/Jalali.php';
require ROOT_PATH . '/lib/Notify.php';
admin_logout();
redirect(u('admin/login.php'));
