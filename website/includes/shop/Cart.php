<?php
/**
 * Shop\Cart — سبد خرید (بر پایه session؛ برای مهمان و کاربر ورودی یکسان کار می‌کند)
 */

namespace Shop;

class Cart
{
    private const KEY = 'shop_cart';
    private const COUPON_KEY = 'shop_cart_coupon';

    /**
     * ساختار: [productId => qty]
     */
    private static function raw(): array
    {
        if (session_status() === PHP_SESSION_NONE) return [];
        $cart = $_SESSION[self::KEY] ?? [];
        return is_array($cart) ? $cart : [];
    }

    private static function save(array $cart): void
    {
        if (session_status() === PHP_SESSION_NONE) return;
        $_SESSION[self::KEY] = $cart;
    }

    public static function add(int $productId, int $qty = 1): array
    {
        $product = Products::byId($productId);
        if (!$product || !(int)$product['is_active']) {
            return ['success' => false, 'error' => 'محصول یافت نشد.'];
        }
        if ((int)$product['stock'] < 1) {
            return ['success' => false, 'error' => 'این محصول موجود نیست.'];
        }
        $cart = self::raw();
        $current = $cart[$productId] ?? 0;
        $newQty = $current + max(1, min(999, $qty));
        if ($newQty > (int)$product['stock']) {
            $newQty = (int)$product['stock'];
        }
        $cart[$productId] = $newQty;
        self::save($cart);
        return ['success' => true, 'count' => self::count()];
    }

    public static function setQty(int $productId, int $qty): array
    {
        $product = Products::byId($productId);
        $cart = self::raw();
        if ($qty <= 0 || !isset($cart[$productId])) {
            unset($cart[$productId]);
        } else {
            if ($product && $qty > (int)$product['stock']) {
                $qty = (int)$product['stock'];
            }
            $cart[$productId] = max(1, min(999, $qty));
        }
        self::save($cart);
        return ['success' => true, 'count' => self::count()];
    }

    public static function remove(int $productId): array
    {
        $cart = self::raw();
        unset($cart[$productId]);
        self::save($cart);
        if (isset($_SESSION[self::COUPON_KEY]) && empty($cart)) {
            unset($_SESSION[self::COUPON_KEY]);
        }
        return ['success' => true, 'count' => self::count()];
    }

    public static function clear(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        unset($_SESSION[self::KEY], $_SESSION[self::COUPON_KEY]);
    }

    public static function count(): int
    {
        return array_sum(self::raw());
    }

    /**
     * اقلام سبد با جزئیات
     */
    public static function items(): array
    {
        $cart = self::raw();
        if (empty($cart)) return [];

        $ids = array_map('intval', array_keys($cart));
        $in = implode(',', $ids);
        $rows = \DB::fetchAll("SELECT * FROM products WHERE id IN ($in) AND is_active = 1");
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = Products::decode($r);

        $items = [];
        foreach ($cart as $pid => $qty) {
            if (!isset($byId[(int)$pid])) continue;
            $p = $byId[(int)$pid];
            $unit = Price::productToman($p);
            $items[] = [
                'product'      => $p,
                'qty'          => min((int)$qty, (int)$p['stock']),
                'unit_price'   => $unit,
                'line_total'   => $unit * min((int)$qty, (int)$p['stock']),
            ];
        }
        return $items;
    }

    public static function subtotal(): int
    {
        $sum = 0;
        foreach (self::items() as $it) $sum += $it['line_total'];
        return $sum;
    }

    public static function hasCoupon(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[self::COUPON_KEY]);
    }

    public static function couponCode(): ?string
    {
        return $_SESSION[self::COUPON_KEY] ?? null;
    }

    /**
     * اعمال کوپن
     */
    public static function applyCoupon(string $code): array
    {
        $code = trim($code);
        if ($code === '') return ['success' => false, 'error' => 'کد تخفیف را وارد کنید.'];
        $result = Coupons::validate($code, self::subtotal());
        if (!$result['valid']) {
            unset($_SESSION[self::COUPON_KEY]);
            return ['success' => false, 'error' => $result['error']];
        }
        $_SESSION[self::COUPON_KEY] = $code;
        return [
            'success' => true,
            'discount' => self::discount(),
            'coupon'   => $result['coupon'],
        ];
    }

    public static function removeCoupon(): void
    {
        unset($_SESSION[self::COUPON_KEY]);
    }

    public static function discount(): int
    {
        $code = self::couponCode();
        if (!$code) return 0;
        $result = Coupons::validate($code, self::subtotal());
        return $result['valid'] ? (int)$result['discount'] : 0;
    }

    /**
     * خلاصه محاسبات سبد
     */
    public static function summary(): array
    {
        $items = self::items();
        $subtotal = self::subtotal();
        $discount = self::discount();
        $shipping = self::shippingFee($subtotal - $discount);
        return [
            'items'      => $items,
            'subtotal'   => $subtotal,
            'discount'   => $discount,
            'coupon'     => self::couponCode(),
            'shipping'   => $shipping,
            'total'      => max(0, $subtotal - $discount) + $shipping,
            'count'      => self::count(),
        ];
    }

    public static function shippingFee(int $amountAfterDiscount): int
    {
        $freeMin = (int)\Settings::get('shop_free_shipping_min', 0);
        if ($freeMin > 0 && $amountAfterDiscount >= $freeMin) return 0;
        return (int)\Settings::get('shop_shipping_cost', 0);
    }
}
