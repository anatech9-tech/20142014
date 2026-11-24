<?php
/**
 * Shop\Rates — نرخ لحظه‌ای دلار
 *
 * اولویت:
 *  1) نرخ دستی (تنظیمات ادمین — برای ثابت نگه داشتن نرخ)
 *  2) کеш دیتابیس (تا shop_rate_ttl ثانیه معتبر)
 *  3) دریافت از منابع آنلاین (bonbast، tgju)
 *  4) آخرین کеш موجود (حتی منقضی)
 *  5) نرخ پیش‌فرض (تنظیمات)
 */

namespace Shop;

class Rates
{
    private const CACHE_KEY    = 'usd_rate_cache';
    private const CACHE_TS_KEY = 'usd_rate_cache_ts';

    /**
     * دریافت نرخ تومانِ هر دلار
     */
    public static function getUsd(?int $ttlOverride = null): int
    {
        // 1) دستی
        $manual = (string)\Settings::get('shop_usd_manual', '');
        if ($manual !== '' && (int)$manual > 0) {
            return (int)$manual;
        }

        $ttl = $ttlOverride ?? (int)\Settings::get('shop_rate_ttl', 1800);

        // 2) کеш تازه
        $cached = self::getCached();
        if ($cached['rate'] > 0 && (time() - $cached['ts']) < $ttl) {
            return $cached['rate'];
        }

        // 3) منابع آنلاین
        $fresh = self::fetchFromSources();
        if ($fresh > 0) {
            self::setCached($fresh);
            return $fresh;
        }

        // 4) کеш منقضی
        if ($cached['rate'] > 0) {
            return $cached['rate'];
        }

        // 5) پیش‌فرض
        return (int)\Settings::get('shop_usd_default', 950000);
    }

    /**
     * نرخ بدون استراتژی دستی (برای نمایش "آخرین نرخ سایت")
     */
    public static function getUsdAuto(): int
    {
        $cached = self::getCached();
        $ttl    = (int)\Settings::get('shop_rate_ttl', 1800);
        if ($cached['rate'] > 0 && (time() - $cached['ts']) < $ttl) {
            return $cached['rate'];
        }
        $fresh = self::fetchFromSources();
        if ($fresh > 0) {
            self::setCached($fresh);
            return $fresh;
        }
        if ($cached['rate'] > 0) return $cached['rate'];
        return (int)\Settings::get('shop_usd_default', 950000);
    }

    private static function getCached(): array
    {
        try {
            $raw = (string)\Settings::get(self::CACHE_KEY, '0');
            $ts  = (int)\Settings::get(self::CACHE_TS_KEY, 0);
            $rate = (int)$raw;
            return ($rate > 0) ? ['rate' => $rate, 'ts' => $ts] : ['rate' => 0, 'ts' => 0];
        } catch (\Throwable $e) {
            return ['rate' => 0, 'ts' => 0];
        }
    }

    private static function setCached(int $rate): void
    {
        try {
            \Settings::set(self::CACHE_KEY, (string)$rate);
            \Settings::set(self::CACHE_TS_KEY, (string)time());
        } catch (\Throwable $e) {
            // کاشته نمی‌شود — مهم نیست
        }
    }

    /**
     * دریافت از منابع آنلاین (به ترتیب اولویت)
     */
    private static function fetchFromSources(): int
    {
        $sources = [
            'bonbast' => self::fetchBonbast(),
            'tgju'    => self::fetchTgju(),
        ];
        foreach ($sources as $rate) {
            if ($rate > 100000 && $rate < 100000000) {
                return $rate;
            }
        }
        return 0;
    }

    private static function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body && $code === 200) ? (string)$body : null;
    }

    private static function fetchBonbast(): int
    {
        $body = self::httpGet('https://bonbast.com/api/dollar');
        if (!$body) return 0;
        $json = json_decode($body, true);
        if (is_array($json)) {
            $price = $json['price'] ?? $json['data']['price'] ?? null;
            if (is_numeric($price)) return (int)round($price);
        }
        return 0;
    }

    private static function fetchTgju(): int
    {
        $body = self::httpGet('https://api.tgju.org/v1/today');
        if (!$body) return 0;
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['rates']) && is_array($json['rates'])) {
            foreach ($json['rates'] as $r) {
                if (isset($r['currency']) && stripos($r['currency'], 'dollar') !== false
                    && isset($r['sell']) && is_numeric($r['sell'])) {
                    return (int)round($r['sell']);
                }
            }
        }
        return 0;
    }
}
