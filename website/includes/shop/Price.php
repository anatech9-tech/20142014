<?php
/**
 * Shop\Price — محاسبه و قالب‌بندی قیمت‌ها (همیشه نمایش به تومان)
 */

namespace Shop;

class Price
{
    /**
     * قیمت نهایی یک محصول به تومان
     */
    public static function productToman(array $product): int
    {
        if (($product['currency'] ?? 'toman') === 'usd') {
            $usd = (float)($product['price_usd'] ?? 0);
            $rate = Rates::getUsd();
            return self::usdToToman($usd, $rate);
        }
        return (int)($product['price_toman'] ?? 0);
    }

    /**
     * تبدیل دلار به تومان (گرد به ۱۰ تومان)
     */
    public static function usdToToman(float $usd, ?int $rate = null): int
    {
        $rate = $rate ?? Rates::getUsd();
        return (int)(round($usd * $rate / 10) * 10);
    }

    /**
     * عدد به اعداد فارسی با جداکننده هزارگان: ۱۲٬۵۰۰٬۰۰۰
     */
    public static function faNumber(int|float $n): string
    {
        $formatted = number_format((float)$n, 0, '.', '٬');
        return Jalali::toPersianDigits($formatted);
    }

    /**
     * قیمت تومان با کلمه
     */
    public static function tomanLabel(int|float $toman): string
    {
        return self::faNumber($toman) . ' تومان';
    }

    /**
     * مبلغ بدون کلمه (برای جدول‌ها)
     */
    public static function plain(int|float $toman): string
    {
        return self::faNumber($toman);
    }
}
