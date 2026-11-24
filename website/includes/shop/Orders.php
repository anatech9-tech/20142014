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
        try { $requested = Cart::requested(); }
        catch (\DomainException $e) { return ['success'=>false, 'error'=>$e->getMessage()]; }
        if (!$requested) return ['success'=>false, 'error'=>'سبد خرید شما خالی است.'];
        $name = trim((string)($customerData['name'] ?? ''));
        $phone = trim((string)($customerData['phone'] ?? ''));
        $email = strtolower(trim((string)($customerData['email'] ?? '')));
        $address = trim((string)($customerData['address'] ?? ''));
        $province = trim((string)($customerData['province'] ?? ''));
        $city = trim((string)($customerData['city'] ?? ''));
        $postal = trim((string)($customerData['postal_code'] ?? ''));
        $notes = trim((string)($customerData['notes'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) $errors[] = 'نام و نام خانوادگی معتبر وارد کنید.';
        if (mb_strlen($phone) > 30 || !preg_match('/^0?9\d{9}$|^\+?98?9\d{9}$/', preg_replace('/[\s-]/', '', $phone))) $errors[] = 'شماره موبایل معتبر نیست.';
        if (mb_strlen($email) > 200 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) $errors[] = 'ایمیل نامعتبر است.';
        if (mb_strlen($address) < 10 || mb_strlen($address) > 500) $errors[] = 'آدرس پستی را کامل وارد کنید.';
        if (mb_strlen($province) > 100 || mb_strlen($city) > 100 || mb_strlen($postal) > 20 || mb_strlen($notes) > 5000) $errors[] = 'اطلاعات ارسال بیش از حد طولانی است.';
        $expected = (string)($customerData['expected_total'] ?? '');
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D', $expected)) $errors[] = 'مبلغ تأیید نشده است؛ صفحه را دوباره بارگذاری کنید.';
        if ($errors) return ['success'=>false, 'errors'=>$errors];

        // The rate is obtained once before locks; every USD line uses this same snapshot.
        $rate = Rates::getUsd();
        $customer = Customers::user();
        $couponCode = Cart::couponCode();
        $pdo = \DB::pdo();
        try {
            $pdo->beginTransaction();
            $items = []; $subtotal = 0;
            // Stable lock order avoids deadlocks when carts have common products.
            foreach ($requested as $pid => $qty) {
                $row = \DB::fetch('SELECT * FROM products WHERE id = ? FOR UPDATE', [$pid]);
                if (!$row || !(int)$row['is_active'] || (int)$row['stock'] < $qty) {
                    throw new \DomainException('موجودی سبد تغییر کرده است؛ لطفاً سبد را بررسی کنید.');
                }
                $p = Products::decode($row);
                if (($p['currency'] ?? '') === 'usd') {
                    if ($rate <= 0 || !isset($p['price_usd']) || (float)$p['price_usd'] <= 0) throw new \DomainException('قیمت ارزی کالا معتبر نیست.');
                    $unit = Price::usdToToman((float)$p['price_usd'], $rate);
                } else {
                    if (!isset($p['price_toman']) || (int)$p['price_toman'] <= 0) throw new \DomainException('قیمت کالا معتبر نیست.');
                    $unit = (int)$p['price_toman'];
                }
                if ($unit <= 0 || $unit > intdiv(PHP_INT_MAX, $qty) || $subtotal > PHP_INT_MAX - $unit * $qty) throw new \DomainException('مبلغ سبد معتبر نیست.');
                $line = $unit * $qty; $subtotal += $line;
                $items[] = ['product'=>$p, 'qty'=>$qty, 'unit_price'=>$unit, 'line_total'=>$line];
            }
            $discount = 0;
            if ($couponCode) {
                $coupon = \DB::fetch('SELECT * FROM discount_codes WHERE code = ? FOR UPDATE', [$couponCode]);
                $result = Coupons::validateRow($coupon, $subtotal);
                if (!$result['valid']) throw new \DomainException($result['error'].' لطفاً کد را حذف یا اصلاح کنید.');
                $discount = (int)$result['discount'];
                if (\DB::execute('UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ? AND is_active = 1 AND (max_uses = 0 OR used_count < max_uses)', [(int)$coupon['id']]) !== 1) throw new \DomainException('ظرفیت کد تخفیف تکمیل شده است.');
            }
            $afterDiscount = max(0, $subtotal - $discount);
            $shipping = Cart::shippingFee($afterDiscount);
            if ($shipping < 0 || $afterDiscount > PHP_INT_MAX - $shipping) throw new \DomainException('هزینه ارسال معتبر نیست.');
            $total = $afterDiscount + $shipping;
            if ((string)$total !== $expected) throw new \DomainException('مبلغ سفارش تغییر کرده است؛ صفحه تسویه را دوباره بارگذاری و مبلغ جدید را بررسی کنید.');
            $orderNo = self::generateOrderNo();
            $token = bin2hex(random_bytes(24));
            \DB::insert('orders', [
                'order_no'=>$orderNo, 'customer_id'=>$customer['id'] ?? null,
                'customer_name'=>$name, 'customer_phone'=>$phone, 'customer_email'=>$email,
                'province'=>$province, 'city'=>$city, 'postal_code'=>$postal, 'address'=>$address, 'notes'=>$notes,
                'subtotal_toman'=>$subtotal, 'shipping_fee'=>$shipping, 'discount_toman'=>$discount,
                'coupon_code'=>$couponCode, 'total_toman'=>$total, 'usd_rate'=>$rate,
                'payment_status'=>'pending', 'order_status'=>'pending', 'success_token'=>$token,
            ]);
            $orderId = (int)$pdo->lastInsertId();
            foreach ($items as $it) {
                $pid=(int)$it['product']['id']; $qty=(int)$it['qty'];
                if (\DB::execute('UPDATE products SET stock = stock - ?, sold_count = sold_count + ? WHERE id = ? AND is_active = 1 AND stock >= ?', [$qty,$qty,$pid,$qty]) !== 1) throw new \DomainException('موجودی کالا تغییر کرده است؛ سبد را بررسی کنید.');
                \DB::insert('order_items', ['order_id'=>$orderId,'product_id'=>$pid,'product_name'=>$it['product']['name_fa'],
                    'product_image'=>$it['product']['first_image'],'price_toman'=>$it['unit_price'],'qty'=>$qty,'total_toman'=>$it['line_total']]);
            }
            \DB::insert('order_events', ['order_id'=>$orderId,'status'=>'pending','note'=>'سفارش با موفقیت ثبت شد.']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) return ['success'=>false, 'error'=>$e->getMessage()];
            error_log('Shop order creation failed');
            return ['success'=>false, 'error'=>'خطا در ثبت سفارش. لطفاً دوباره تلاش کنید.'];
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

    /** Update order/payment atomically; cancellation is terminal and stock is returned once. */
    public static function update(int $id, ?string $status, ?string $payment, string $note = '', ?string $expectedStatus = null, ?string $expectedPayment = null): array
    {
        if (($status !== null && !array_key_exists($status, self::STATUS_LABELS)) || ($payment !== null && !array_key_exists($payment, self::PAYMENT_LABELS)) || mb_strlen($note) > 5000) return ['success'=>false,'error'=>'اطلاعات وضعیت نامعتبر است.'];
        $pdo=\DB::pdo();
        try {
            $pdo->beginTransaction();
            $order=\DB::fetch('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
            if (!$order) throw new \DomainException('سفارش یافت نشد.');
            if (($expectedStatus !== null && $expectedStatus !== $order['order_status']) || ($expectedPayment !== null && $expectedPayment !== $order['payment_status'])) throw new \DomainException('سفارش تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
            $old=$order['order_status'];
            if ($old === 'cancelled' && $status !== null && $status !== 'cancelled') throw new \DomainException('سفارش لغوشده قابل فعال‌سازی مجدد نیست؛ سفارش جدید ثبت کنید.');
            if ($status !== null && ($status !== $old || $note !== '')) {
                if ($status === 'cancelled' && $status !== $old) {
                    if (in_array($old, ['shipped','delivered'], true)) throw new \DomainException('سفارش ارسال یا تحویل‌شده از این مسیر قابل لغو و بازگرداندن موجودی نیست؛ مرجوعی را دستی بررسی کنید.');
                    $items=\DB::fetchAll('SELECT product_id, SUM(qty) AS qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id', [$id]);
                    foreach ($items as $it) {
                        $qty=(int)$it['qty'];
                        if ($qty > 0) \DB::execute('UPDATE products SET stock = stock + ?, sold_count = CASE WHEN sold_count >= ? THEN sold_count - ? ELSE 0 END WHERE id = ?', [$qty,$qty,$qty,(int)$it['product_id']]);
                    }
                }
                \DB::execute('UPDATE orders SET order_status = ?, status_note = ? WHERE id = ?', [$status,$note,$id]);
                \DB::insert('order_events', ['order_id'=>$id,'status'=>$status,'note'=>$note]);
            }
            if ($payment !== null && $payment !== $order['payment_status']) \DB::execute('UPDATE orders SET payment_status = ? WHERE id = ?', [$payment,$id]);
            $pdo->commit();
            return ['success'=>true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) return ['success'=>false,'error'=>$e->getMessage()];
            error_log('Shop order update failed');
            return ['success'=>false,'error'=>'به‌روزرسانی سفارش ممکن نشد.'];
        }
    }
    public static function setStatus(int $id, string $status, string $note = ''): bool
    {
        return self::update($id, $status, null, $note)['success'];
    }
    public static function setPaymentStatus(int $id, string $status): bool
    {
        return self::update($id, null, $status)['success'];
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
