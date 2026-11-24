<?php
/**
 * Analytics tracker — privacy-friendly
 */

declare(strict_types=1);

class Analytics
{
    public static function trackPageView(?string $pageTitle = null): void
    {
        // Don't track admin pages
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin')) return;
        // Don't track bots
        if (self::isBot()) return;

        try {
            $sessionId = $_COOKIE['sts_sid'] ?? self::getOrCreateSession();
            DB::insert('page_views', [
                'session_id'  => $sessionId,
                'page_url'    => mb_substr($_SERVER['REQUEST_URI'] ?? '/', 0, 500),
                'page_title'  => $pageTitle,
                'referrer'    => mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
                'user_agent'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'ip_hash'     => sts_hash_ip($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                'device_type' => self::detectDevice(),
                'viewed_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Silently fail — analytics should never break the site
        }
    }

    public static function trackConversion(string $type, ?string $value = null): void
    {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin')) return;
        try {
            $sessionId = $_COOKIE['sts_sid'] ?? 'unknown';
            DB::insert('conversion_events', [
                'event_type' => $type,
                'page_url'   => mb_substr($_SERVER['REQUEST_URI'] ?? '/', 0, 500),
                'session_id' => $sessionId,
                'value'      => $value,
                'ip_hash'    => sts_hash_ip($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {}
    }

    private static function getOrCreateSession(): string
    {
        $sid = sts_random(32);
        setcookie('sts_sid', $sid, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
        ]);
        $_COOKIE['sts_sid'] = $sid;
        return $sid;
    }

    private static function detectDevice(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/bot|crawl|spider/i', $ua)) return 'bot';
        if (preg_match('/tablet|ipad/i', $ua)) return 'tablet';
        if (preg_match('/mobile|android|iphone/i', $ua)) return 'mobile';
        return 'desktop';
    }

    private static function isBot(): bool
    {
        return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
