<?php
/**
 * Authentication & Authorization
 */

declare(strict_types=1);

class Auth
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_MANAGER    = 'manager';
    public const ROLE_SALES      = 'sales';
    public const ROLE_OPERATIONS = 'operations';
    public const ROLE_SUPPORT    = 'support';

    /**
     * Permission matrix: role => list of allowed actions
     */
    public const PERMISSIONS = [
        self::ROLE_SUPERADMIN => ['*'],
        self::ROLE_MANAGER    => [
            'messages.*', 'team.*', 'services.*', 'blog.*', 'settings.view',
            'analytics.view', 'shipments.*', 'users.view', 'shop.*',
        ],
        self::ROLE_SALES      => [
            'messages.view', 'messages.reply', 'messages.assign',
            'analytics.view', 'shipments.view', 'shop.products', 'shop.orders',
        ],
        self::ROLE_OPERATIONS => [
            'messages.view', 'shipments.*', 'analytics.view', 'shop.orders',
        ],
        self::ROLE_SUPPORT    => [
            'messages.view', 'messages.reply', 'shop.orders',
        ],
    ];

    /**
     * Attempt login
     */
    public static function login(string $username, string $password, bool $remember = false): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit by IP
        if (self::isRateLimited($ip)) {
            return ['success' => false, 'error' => 'تعداد تلاش‌های ناموفق زیاد است. ۱۰ دقیقه صبر کنید.'];
        }

        // Find user
        $user = DB::fetch(
            'SELECT * FROM admin_users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1',
            [$username, $username]
        );

        if (!$user) {
            self::logAttempt($username, $ip, false);
            return ['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        // Check if locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'error' => "حساب شما موقتاً قفل شده است. {$remaining} دقیقه دیگر تلاش کنید."];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            self::logAttempt($username, $ip, false);
            self::incrementFailedAttempts((int) $user['id']);
            return ['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        // Check if password needs rehash
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            DB::update('admin_users', ['password_hash' => $newHash], 'id = :id', ['id' => $user['id']]);
        }

        // Login successful — create session
        self::createSession((int) $user['id'], $remember);

        // Update last login
        DB::update('admin_users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'failed_login_count' => 0,
            'locked_until' => null,
        ], 'id = :id', ['id' => $user['id']]);

        // Reset attempts
        self::logAttempt($username, $ip, true);

        // Activity log
        self::logActivity((int) $user['id'], 'login', 'admin_user', (int) $user['id'], 'ورود موفق');

        return ['success' => true, 'user' => $user];
    }

    /**
     * Logout
     */
    public static function logout(): void
    {
        if (self::isLoggedIn()) {
            $userId = self::userId();
            if (isset($_SESSION['session_id'])) {
                DB::delete('admin_sessions', 'id = :id', ['id' => $_SESSION['session_id']]);
            }
            self::logActivity($userId, 'logout', 'admin_user', $userId, 'خروج');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Check if logged in
     */
    public static function isLoggedIn(): bool
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['session_id'])) {
            return false;
        }

        // Verify session in DB
        $session = DB::fetch(
            'SELECT s.*, u.is_active, u.role FROM admin_sessions s
             JOIN admin_users u ON u.id = s.user_id
             WHERE s.id = ? AND s.last_activity > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$_SESSION['session_id'], SESSION_LIFETIME]
        );

        if (!$session || !$session['is_active']) {
            self::logout();
            return false;
        }

        // Update activity
        DB::update('admin_sessions', [
            'last_activity' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $_SESSION['session_id']]);

        return true;
    }

    /**
     * Get current user
     */
    public static function user(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return DB::fetch('SELECT * FROM admin_users WHERE id = ?', [self::userId()]);
    }

    public static function userId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user['role'] ?? null;
    }

    /**
     * Check permission
     * Examples:
     *   can('messages.view')    → exactly this
     *   can('messages.*')       → any messages.* permission
     *   can(['messages.view', 'messages.reply'])  → all must match
     */
    public static function can($action): bool
    {
        $user = self::user();
        if (!$user) return false;

        $role = $user['role'];
        $allowed = self::PERMISSIONS[$role] ?? [];

        if (in_array('*', $allowed, true)) {
            return true;
        }

        $actions = is_array($action) ? $action : [$action];

        foreach ($actions as $act) {
            $has = false;
            foreach ($allowed as $perm) {
                if ($perm === $act) { $has = true; break; }
                if (substr($perm, -2) === '.*') {
                    $prefix = substr($perm, 0, -2);
                    if (strpos($act, $prefix . '.') === 0 || $act === $prefix) {
                        $has = true; break;
                    }
                }
            }
            if (!$has) return false;
        }
        return true;
    }

    /**
     * Create DB session record
     */
    private static function createSession(int $userId, bool $remember): void
    {
        session_regenerate_id(true);
        $sessionId = sts_random(32);
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_id'] = $sessionId;
        $_SESSION['login_time'] = time();
        $_SESSION['is_remembered'] = $remember;

        DB::insert('admin_sessions', [
            'id'            => $sessionId,
            'user_id'       => $userId,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'    => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'last_activity' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function isRateLimited(string $ip): bool
    {
        $count = (int) DB::fetchColumn(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE ip_address = ? AND success = 0
             AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$ip, RATE_LIMIT_LOGIN_WINDOW]
        );
        return $count >= RATE_LIMIT_LOGIN_ATTEMPTS;
    }

    private static function logAttempt(string $username, string $ip, bool $success): void
    {
        DB::insert('admin_login_attempts', [
            'username'    => $username,
            'ip_address'  => $ip,
            'success'     => $success ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function incrementFailedAttempts(int $userId): void
    {
        $user = DB::fetch('SELECT failed_login_count FROM admin_users WHERE id = ?', [$userId]);
        $count = ($user['failed_login_count'] ?? 0) + 1;

        $data = ['failed_login_count' => $count];
        if ($count >= 5) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + 900); // 15 min
        }
        DB::update('admin_users', $data, 'id = :id', ['id' => $userId]);
    }

    public static function logActivity(int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $description = null): void
    {
        DB::insert('admin_activity_log', [
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'description' => $description,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * CSRF token
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > CSRF_TOKEN_LIFETIME) {
            $_SESSION['csrf_token'] = sts_random(32);
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['csrf_token'])) return false;
        if ((time() - ($_SESSION['csrf_token_time'] ?? 0)) > CSRF_TOKEN_LIFETIME) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Require login middleware
     */
    public static function requireLogin(string $redirectUrl = '/admin/login.php'): void
    {
        if (!self::isLoggedIn()) {
            if (sts_is_ajax()) {
                sts_json(401, ['error' => 'لطفاً وارد شوید.']);
            }
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            sts_redirect($redirectUrl);
        }
    }

    public static function requirePermission($permission, string $redirectUrl = '/admin/'): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            if (sts_is_ajax()) {
                sts_json(403, ['error' => 'دسترسی غیرمجاز.']);
            }
            $_SESSION['flash_error'] = 'شما دسترسی به این بخش را ندارید.';
            sts_redirect($redirectUrl);
        }
    }
}
