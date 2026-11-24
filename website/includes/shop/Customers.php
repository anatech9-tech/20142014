<?php
/**
 * Shop\Customers — احراز هویت مشتریان فروشگاه
 * (جدا از Auth مدیر؛ شناسه در session: customer_id)
 */

namespace Shop;

class Customers
{
    private const SESSION_KEY = 'shop_customer_id';

    public static function register(array $d): array
    {
        $errors = [];
        $username = trim((string)($d['username'] ?? ''));
        $email    = strtolower(trim((string)($d['email'] ?? '')));
        $password = (string)($d['password'] ?? '');
        $fullName = trim((string)($d['full_name'] ?? ''));

        if (mb_strlen($username) < 3)  $errors[] = 'نام کاربری حداقل ۳ کاراکتر';
        if (mb_strlen($fullName) < 3)   $errors[] = 'نام و نام خانوادگی الزامی است';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'ایمیل نامعتبر است';
        if (strlen($password) < 8)      $errors[] = 'رمز عبور حداقل ۸ کاراکتر';

        if ($errors) return ['success' => false, 'errors' => $errors];

        $exists = \DB::fetch(
            'SELECT id FROM customer_users WHERE username = ? OR email = ? LIMIT 1',
            [$username, $email]
        );
        if ($exists) {
            return ['success' => false, 'errors' => ['این نام کاربری یا ایمیل قبلاً ثبت شده است.']];
        }

        \DB::insert('customer_users', [
            'username'      => $username,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name'     => $fullName,
        ]);
        self::loginAs((int)\DB::pdo()->lastInsertId());
        return ['success' => true];
    }

    public static function login(string $login, string $password): array
    {
        $user = \DB::fetch(
            'SELECT * FROM customer_users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1',
            [$login, $login]
        );
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'نام کاربری/ایمیل یا رمز عبور اشتباه است.'];
        }
        self::loginAs((int)$user['id']);
        return ['success' => true, 'user' => self::user()];
    }

    private static function loginAs(int $id): void
    {
        if (session_status() === PHP_SESSION_NONE) return;
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $id;
        \DB::execute('UPDATE customer_users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function isLoggedIn(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function user(): ?array
    {
        if (!self::isLoggedIn()) return null;
        static $cached = null;
        if ($cached !== null) return $cached;
        $user = \DB::fetch('SELECT * FROM customer_users WHERE id = ? LIMIT 1', [(int)$_SESSION[self::SESSION_KEY]]);
        if (!$user || !(int)$user['is_active']) {
            self::logout();
            return null;
        }
        return $cached = $user;
    }

    public static function updateProfile(int $id, array $d): array
    {
        $errors = [];
        $fullName = trim((string)($d['full_name'] ?? ''));
        $email    = strtolower(trim((string)($d['email'] ?? '')));
        $phone    = trim((string)($d['phone'] ?? ''));
        $province = trim((string)($d['province'] ?? ''));
        $city     = trim((string)($d['city'] ?? ''));
        $postal   = trim((string)($d['postal_code'] ?? ''));
        $address  = trim((string)($d['address'] ?? ''));

        if (mb_strlen($fullName) < 3) $errors[] = 'نام و نام خانوادگی الزامی است';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'ایمیل نامعتبر است';

        $exists = \DB::fetch('SELECT id FROM customer_users WHERE email = ? AND id != ? LIMIT 1', [$email, $id]);
        if ($exists) $errors[] = 'این ایمیل متعلق به کاربر دیگری است.';

        if ($errors) return ['success' => false, 'errors' => $errors];

        \DB::update('customer_users', [
            'full_name'   => $fullName,
            'email'       => $email,
            'phone'       => $phone,
            'province'    => $province,
            'city'        => $city,
            'postal_code' => $postal,
            'address'     => $address,
        ], 'id = :id', ['id' => $id]);
        return ['success' => true];
    }

    public static function changePassword(int $id, string $current, string $new): array
    {
        $user = \DB::fetch('SELECT * FROM customer_users WHERE id = ? LIMIT 1', [$id]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            return ['success' => false, 'error' => 'رمز فعلی اشتباه است.'];
        }
        if (strlen($new) < 8) {
            return ['success' => false, 'error' => 'رمز جدید حداقل ۸ کاراکتر باشد.'];
        }
        \DB::update('customer_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', ['id' => $id]);
        return ['success' => true];
    }

    /**
     * سفارش‌های یک مشتری
     */
    public static function orders(int $id): array
    {
        return \DB::fetchAll(
            'SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC, id DESC',
            [$id]
        );
    }
}
