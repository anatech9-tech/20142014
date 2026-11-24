<?php
/**
 * Shop\Jalali — تبدیل تاریخ میلادی به شمسی + قالب‌بندی اعداد فارسی
 * الگوریتم: Borkowski (port از jalaali-js — مرجع استاندارد تقویم جلالی)
 */

namespace Shop;

class Jalali
{
    /** سال‌های جلالی که چرخه ۳۳ ساله کبیسه را شروع می‌کنند */
    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    private const JALALI_MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /** تقسیم صحیح (به سمت صفر — معادل ~~ در جاوااسکریپت) */
    private static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /** مودولی مثبت (congruent modulo) */
    private static function mod(int $a, int $b): int
    {
        return $a - self::div($a, $b) * $b;
    }

    /** میلادی → عدد روز یولیان (JDN) */
    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;
        $d = $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
        return $d;
    }

    /** عدد روز یولیان → میلادی */
    private static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::div(self::div(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i  = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);
        return ['gy' => $gy, 'gm' => $gm, 'gd' => $gd];
    }

    /** هسته الگوریتم Borkowski: تاریخ فروردین ۱ سال jy به میلادی */
    private static function jalCalCore(int $jy): array
    {
        $gy    = $jy + 621;
        $leapJ = -14;
        $jp    = self::BREAKS[0];
        $jump  = 0;
        $len   = count(self::BREAKS);
        for ($i = 1; $i < $len; $i++) {
            $jm   = self::BREAKS[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) break;
            $leapJ = $leapJ + self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp    = $jm;
        }
        $n = $jy - $jp;
        $leapJ = $leapJ + self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);
        if (self::mod($jump, 33) === 4 && $jump - $n === 4) $leapJ += 1;
        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;
        return ['gy' => $gy, 'march' => 20 + $leapJ - $leapG, 'jump' => $jump, 'n' => $n];
    }

    private static function leapFromCycle(int $jump, int $n): int
    {
        $adjusted = $n;
        if ($jump - $n < 6) {
            $adjusted = $n - $jump + self::div($jump + 4, 33) * 33;
        }
        $leap = self::mod(self::mod($adjusted + 1, 33) - 1, 4);
        if ($leap === -1) $leap = 4;
        return $leap;
    }

    /**
     * میلادی → شمسی [jy, jm, jd]
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $jdn = self::g2d($gy, $gm, $gd);
        $g   = self::d2g($jdn);
        $jy  = min($g['gy'] - 621, self::BREAKS[count(self::BREAKS) - 1] - 1);
        $r   = self::jalCalCore($jy);
        $leap = self::leapFromCycle($r['jump'], $r['n']);
        $k   = $jdn - self::g2d($r['gy'], 3, $r['march']);

        if ($k >= 0) {
            if ($k <= 185) {
                return ['jy' => $jy, 'jm' => 1 + self::div($k, 31), 'jd' => self::mod($k, 31) + 1];
            }
            $k -= 186;
        } else {
            $jy -= 1;
            $k += 179;
            if ($leap === 1) $k += 1;
        }
        return ['jy' => $jy, 'jm' => 7 + self::div($k, 30), 'jd' => self::mod($k, 30) + 1];
    }

    /**
     * عدد/متن به ارقام فارسی
     */
    public static function toPersianDigits($value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '-', '/', ':'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', '−', '/', ':'],
            (string)$value
        );
    }

    /**
     * قالب‌بندی تاریخ شمسی: ۱۴۰۴/۰۶/۲۱
     */
    public static function date(?string $datetime): string
    {
        if (!$datetime) return '—';
        $ts = strtotime($datetime);
        if (!$ts) return '—';
        ['jy' => $jy, 'jm' => $jm, 'jd' => $jd] = self::toJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return self::toPersianDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    /**
     * قالب‌بندی تاریخ + ساعت شمسی: ۱۴۰۴/۰۶/۲۱ - ۱۴:۳۲
     */
    public static function dateTime(?string $datetime): string
    {
        if (!$datetime) return '—';
        $ts = strtotime($datetime);
        if (!$ts) return '—';
        return self::date($datetime) . ' - ' . self::toPersianDigits(date('H:i', $ts));
    }

    /**
     * قالب کامل: ۲۱ شهریور ۱۴۰۴
     */
    public static function longDate(?string $datetime): string
    {
        if (!$datetime) return '—';
        $ts = strtotime($datetime);
        if (!$ts) return '—';
        ['jy' => $jy, 'jm' => $jm, 'jd' => $jd] = self::toJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return self::toPersianDigits($jd) . ' ' . self::JALALI_MONTHS[$jm] . ' ' . self::toPersianDigits($jy);
    }
}
