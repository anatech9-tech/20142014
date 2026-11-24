<?php
/**
 * Shop\Coupons — اعتبارسنجی و اعمال کدهای تخفیف
 */

namespace Shop;

class Coupons
{
    /**
     * اعتبارسنجی کوپن برای یک مبلغ
     */
    public static function validate(string $code, int $subtotal): array
    {
        $coupon = \DB::fetch(
            'SELECT * FROM discount_codes WHERE code = ? AND is_active = 1 LIMIT 1',
            [trim($code)]
        );
        return self::validateRow($coupon, $subtotal);
    }

    /** Validate the same locked coupon row used by checkout. */
    public static function validateRow(?array $coupon, int $subtotal): array
    {
        if (!$coupon || !(int)$coupon['is_active']) {
            return ['valid' => false, 'error' => 'کد تخفیف معتبر نیست.'];
        }
        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
            return ['valid' => false, 'error' => 'این کد تخفیف منقضی شده است.'];
        }
        if ((int)$coupon['max_uses'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['max_uses']) {
            return ['valid' => false, 'error' => 'حد استفاده از این کد تکمیل شده است.'];
        }
        if ($subtotal < (int)$coupon['min_amount']) {
            $min = Price::faNumber((int)$coupon['min_amount']);
            return ['valid' => false, 'error' => "حداقل مبلغ برای استفاده از این کد: {$min} تومان."];
        }

        $discount = 0;
        if ($coupon['type'] === 'percent') {
            $pct = min(100, max(0, (float)$coupon['value']));
            $discount = (int)round($subtotal * $pct / 100);
        } else {
            $discount = min($subtotal, max(0, (int)$coupon['value']));
        }

        return ['valid' => true, 'discount' => $discount, 'coupon' => $coupon];
    }

    public static function list(int $limit = 200): array
    {
        return \DB::fetchAll('SELECT * FROM discount_codes ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit)));
    }
}
