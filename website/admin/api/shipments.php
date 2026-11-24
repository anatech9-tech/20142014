<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
Auth::requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}

try {
    if ($action === 'save') {
        Auth::requirePermission('shipments.edit');
        $id = (int) ($_POST['id'] ?? 0);
        $code = trim($_POST['tracking_code'] ?? '');
        if (!$code) $code = 'STP-' . strtoupper(substr(md5(uniqid()), 0, 10));

        $data = [
            'tracking_code'     => $code,
            'customer_name'     => sts_clean($_POST['customer_name'] ?? '', 200),
            'customer_email'    => sts_clean($_POST['customer_email'] ?? '', 200),
            'customer_phone'    => sts_clean($_POST['customer_phone'] ?? '', 50),
            'origin_country'    => sts_clean($_POST['origin_country'] ?? '', 100),
            'origin_city'       => sts_clean($_POST['origin_city'] ?? '', 100),
            'destination_country' => sts_clean($_POST['destination_country'] ?? '', 100),
            'destination_city'  => sts_clean($_POST['destination_city'] ?? '', 100),
            'weight_kg'         => !empty($_POST['weight_kg']) ? (float) $_POST['weight_kg'] : null,
            'volume_m3'         => !empty($_POST['volume_m3']) ? (float) $_POST['volume_m3'] : null,
            'package_count'     => (int) ($_POST['package_count'] ?? 1),
            'service_type'      => $_POST['service_type'] ?? 'air',
            'status'            => $_POST['status'] ?? 'registered',
            'estimated_delivery' => !empty($_POST['estimated_delivery']) ? $_POST['estimated_delivery'] : null,
            'notes'             => sts_clean($_POST['notes'] ?? '', 1000),
            'is_public'         => !empty($_POST['is_public']) ? 1 : 0,
        ];
        $validServices = ['air', 'sea', 'land', 'multimodal'];
        $validStatus = ['registered', 'picked_up', 'in_transit', 'customs_clearance', 'out_for_delivery', 'delivered', 'cancelled', 'on_hold'];
        if (!in_array($data['service_type'], $validServices, true)) $data['service_type'] = 'air';
        if (!in_array($data['status'], $validStatus, true)) $data['status'] = 'registered';
        if (mb_strlen($data['customer_name']) < 2) sts_json(400, ['success' => false, 'error' => 'نام مشتری الزامی است.']);
        if (mb_strlen($data['origin_country']) < 2 || mb_strlen($data['destination_country']) < 2) {
            sts_json(400, ['success' => false, 'error' => 'مبدا و مقصد الزامی است.']);
        }

        if ($id) {
            DB::update('shipments', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['created_by'] = Auth::userId();
            $id = DB::insert('shipments', $data);
        }
        Auth::logActivity(Auth::userId(), $id ? 'update_shipment' : 'create_shipment', 'shipment', $id);
        sts_json(200, ['success' => true, 'id' => $id, 'tracking_code' => $code]);
    }

    if ($action === 'add_event') {
        Auth::requirePermission('shipments.edit');
        $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
        $eventType = sts_clean($_POST['event_type'] ?? '', 50);
        $location = sts_clean($_POST['location'] ?? '', 200);
        $desc = sts_clean($_POST['description'] ?? '', 1000);

        if (!$shipmentId || !$eventType || !$desc) {
            sts_json(400, ['success' => false, 'error' => 'فیلدهای الزامی پر نشده.']);
        }

        $eventId = DB::insert('shipment_events', [
            'shipment_id'   => $shipmentId,
            'event_type'    => $eventType,
            'location'      => $location,
            'description_fa' => $desc,
            'event_time'    => date('Y-m-d H:i:s'),
            'created_by'    => Auth::userId(),
        ]);

        // Update shipment status if event matches
        if (in_array($eventType, ['delivered'], true)) {
            DB::update('shipments', ['status' => 'delivered', 'actual_delivery' => date('Y-m-d')], 'id = :id', ['id' => $shipmentId]);
        }

        Auth::logActivity(Auth::userId(), 'add_shipment_event', 'shipment', $shipmentId, $eventType);
        sts_json(200, ['success' => true, 'event_id' => $eventId]);
    }

    if ($action === 'delete') {
        Auth::requirePermission('shipments.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) sts_json(400, ['success' => false]);
        DB::delete('shipments', 'id = :id', ['id' => $id]);
        Auth::logActivity(Auth::userId(), 'delete_shipment', 'shipment', $id);
        sts_json(200, ['success' => true]);
    }

    sts_json(400, ['success' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    sts_json(500, ['success' => false, 'error' => $e->getMessage()]);
}
