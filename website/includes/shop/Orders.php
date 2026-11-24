<?php
/**
 * Shop\Orders — ثبت و مدیریت سفارش‌ها
 */

namespace Shop;

class Orders
{
    /**
     * ثبت سفارش جدید (transactional)
     */
    public static function create(array $customerData): array
    {
        $items = Cart::items();
        if (empty($items)) {
            return ['success' => false, 'error' => 'سبد خرید شما خالی است.'];
        }

        // اعتبارسنجی دوباره موجودی
        foreach ($items as $it) {
            if ((int)$it['product']['stock'] < $it['qty']) {
                return ['success' => false, 'error' => 'موجودی «' . $it['product']['name_fa'] . '» کافی نیست.'];
            }
        }

        $name = trim((string)($customerData['name'] ?? ''));
        $phone = trim((string)($customerData['phone'] ?? ''));
        $email = strtolower(trim((string)($customerData['email'] ?? '')));
        $address = trim((string)($customerData['address'] ?? ''));

        $errors = [];
        if (mb_strlen($name) < 3)  $errors[] = 'نام و نام خانوادگی را وارد کنید.';
        if (!preg_match('/^0?9\d{9}$|^\+?98?9\d{9}$/', preg_replace('/[\s-]/', '', $phone))) {
            $errors[] = 'شماره موبایل معتبر نیست.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== '') {
            $errors[] = 'ایمیل نامعتبر است.';
        }
        if (mb_strlen($address) < 10) $errors[] = 'آدرس پستی را کامل وارد کنید.';
        if ($errors) return ['success' => false, 'errors' => $errors];

        $subtotal = Cart::subtotal();
        $discount = Cart::discount();
        $afterDiscount = max(0, $subtotal - $discount);
        $shipping = Cart::shippingFee($afterDiscount);
        $total = $afterDiscount + $shipping;
        $couponCode = Cart::couponCode();
        $rate = Rates::getUsd();
        $customer = Customers::user();

        $pdo = \DB::pdo();
        try {
            $pdo->beginTransaction();

            $orderNo = self::generateOrderNo();
            $token = bin2hex(random_bytes(24));

            \DB::insert('orders', [
                'order_no'         => $orderNo,
                'customer_id'      => $customer['id'] ?? null,
                'customer_name'    => $name,
                'customer_phone'   => $phone,
                'customer_email'   => $email,
                'province'         => trim((string)($customerData['province'] ?? '')),
                'city'             => trim((string)($customerData['city'] ?? '')),
                'postal_code'      => trim((string)($customerData['postal_code'] ?? '')),
                'address'          => $address,
                'notes'            => trim((string)($customerData['notes'] ?? '')),
                'subtotal_toman'   => $subtotal,
                'shipping_fee'     => $shipping,
                'discount_toman'   => $discount,
                'coupon_code'      => $couponCode,
                'total_toman'      => $total,
                'usd_rate'         => $rate,
                'payment_status'   => 'pending',
                'order_status'     => 'pending',
                'success_token'    => $token,
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $decrement = [];
            foreach ($items as $it) {
                \DB::insert('order_items', [
                    'order_id'      => $orderId,
                    'product_id'    => (int)$it['product']['id'],
                    'product_name'  => $it['product']['name_fa'],
                    'product_image' => $it['product']['first_image'],
                    'price_toman'   => $it['unit_price'],
                    'qty'           => $it['qty'],
                    'total_toman'   => $it['line_total'],
                ]);
                $decrement[(int)$it['product']['id']] = ($decrement[(int)$it['product']['id']] ?? 0) + $it['qty'];
            }

            // کسر موجودی و افزایش فروش
            foreach ($decrement as $pid => $qty) {
                \DB::execute('UPDATE products SET stock = GREATEST(stock - ?, 0), sold_count = sold_count + ? WHERE id = ?', [$qty, $qty, $pid]);
            }

            // کوپن
            if ($couponCode) {
                \DB::execute('UPDATE discount_codes SET used_count = used_count + 1 WHERE code = ?', [$couponCode]);
            }

            // رویداد اولیه
            \DB::insert('order_events', [
                'order_id' => $orderId,
                'status'   => 'pending',
                'note'     => 'سفارش با موفقیت ثبت شد.',
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'error' => 'خطا در ثبت سفارش. لطفاً دوباره تلاش کنید.'];
        }

        Cart::clear();
        return ['success' => true, 'order_no' => $orderNo, 'token' => $token];
    }

    private static function generateOrderNo(): string
    {
        // مثال: WTP-14040621-4821
        ['jy' => $jy, 'jm' => $jm, 'jd' => $jd] = Jalali::toJalali((int)date('Y'), (int)date('n'), (int)date('j'));
        $datePart = sprintf('%04d%02d%02d', $jy, $jm, $jd);
        do {
            $no = 'WTP-' . $datePart . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $exists = \DB::fetch('SELECT id FROM orders WHERE order_no = ? LIMIT 1', [$no]);
        } while ($exists);
        return $no;
    }

    public static function byNo(string $orderNo, string $token = ''): ?array
    {
        if ($orderNo === '' || $token === '') return null;
        $order = \DB::fetch('SELECT * FROM orders WHERE order_no = ? LIMIT 1', [$orderNo]);
        if (!$order || !hash_equals((string)$order['success_token'], $token)) return null;
        $order['items'] = \DB::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [(int)$order['id']]);
        $order['events'] = \DB::fetchAll(
            'SELECT * FROM order_events WHERE order_id = ? ORDER BY created_at DESC, id DESC',
            [(int)$order['id']]
        );
        return $order;
    }

    public static function byId(int $id): ?array
    {
        $order = \DB::fetch('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
        if (!$order) return null;
        $order['items'] = \DB::fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$id]);
        $order['events'] = \DB::fetchAll(
            'SELECT * FROM order_events WHERE order_id = ? ORDER BY created_at DESC, id DESC',
            [$id]
        );
        return $order;
    }

    public static function list(?string $status = null, int $limit = 100): array
    {
        if ($status && in_array($status, ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'], true)) {
            return \DB::fetchAll(
                'SELECT o.*, (SELECT SUM(total_toman) FROM order_items WHERE order_id = o.id) AS items_total
                 FROM orders o WHERE o.order_status = ? ORDER BY o.created_at DESC, o.id DESC LIMIT ' . max(1, min(500, $limit)),
                [$status]
            );
        }
        return \DB::fetchAll(
            'SELECT o.*, (SELECT SUM(total_toman) FROM order_items WHERE order_id = o.id) AS items_total
             FROM orders o ORDER BY o.created_at DESC, o.id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    public static function setStatus(int $id, string $status, string $note = ''): bool
    {
        $allowed = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($status, $allowed, true)) return false;
        \DB::execute('UPDATE orders SET order_status = ?, status_note = ? WHERE id = ?', [$status, $note, $id]);
        \DB::insert('order_events', [
            'order_id' => $id,
            'status'   => $status,
            'note'     => $note,
        ]);

        // در صورت لغو، موجودی برگردانده می‌شود
        if ($status === 'cancelled') {
            $order = self::byId($id);
            if ($order) {
                foreach ($order['items'] as $it) {
                    if ($it['product_id']) {
                        \DB::execute('UPDATE products SET stock = stock + ?, sold_count = GREATEST(sold_count - ?, 0) WHERE id = ?',
                            [(int)$it['qty'], (int)$it['qty'], (int)$it['product_id']]);
                    }
                }
            }
        }
        return true;
    }

    public static function setPaymentStatus(int $id, string $status): bool
    {
        $allowed = ['pending', 'paid', 'failed', 'refunded'];
        if (!in_array($status, $allowed, true)) return false;
        return (bool)\DB::execute('UPDATE orders SET payment_status = ? WHERE id = ?', [$status, $id]);
    }

    public const STATUS_LABELS = [
        'pending'    => 'در انتظار بررسی',
        'confirmed'  => 'تأیید شده',
        'packed'     => 'پک شده',
        'shipped'    => 'ارسال شد',
        'delivered'  => 'تحویل شد',
        'cancelled'  => 'لغو شده',
    ];

    public const PAYMENT_LABELS = [
        'pending'  => 'در انتظار پرداخت',
        'paid'     => 'پرداخت شده',
        'failed'   => 'ناموفق',
        'refunded' => 'مرجوع شده',
    ];
}
