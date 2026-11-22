<?php
/**
 * api/live_calls.php — رویدادهای زنگِ همین کاربر (Polling صفحه‌ای)
 * بدون لاگین = 401؛ بدون داخلی ثبت‌شده = enabled:false
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/screenpop.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['enabled' => false, 'error' => 'unauthorized']); exit; }

ensure_screenpop_schema($pdo);
$me = (int)($_SESSION['user_id'] ?? 0);

$ext = '';
try {
    $st = $pdo->prepare("SELECT telefonchy_ext FROM users WHERE id = ?");
    $st->execute([$me]);
    $ext = trim((string)$st->fetchColumn());
} catch (Throwable $e) {}

if ($ext === '') {
    echo json_encode(['enabled' => false, 'reason' => 'no_ext'], JSON_UNESCAPED_UNICODE);
    exit;
}

$events = screenpop_live_events_for_user($pdo, $me, 90);

// حساب‌هایی که طبق روتر به CRM دسترسی ندارند، جزئیات پرونده را دریافت نمی‌کنند.
$crmAllowed = !is_karfarma() && !access_is_special_account();

/** وضعیت بر اساس دادهٔ ثبت‌شده است، نه ادعای آنلاین‌بودن یا مکالمهٔ قطعی. */
function sp_person_status(PDO $pdo, int $id, string $accountStatus): string {
    if (!$id) return 'نامشخص';
    if ($accountStatus !== 'active') return 'حساب غیرفعال';
    try {
        $st = $pdo->prepare("SELECT 1 FROM leave_requests WHERE user_id=? AND status='approved' AND start_date<=CURDATE() AND COALESCE(end_date,start_date)>=CURDATE() AND (COALESCE(leave_type,'daily')<>'hourly' OR (start_date=CURDATE() AND start_time IS NOT NULL AND end_time IS NOT NULL AND CURTIME() BETWEEN start_time AND end_time)) LIMIT 1");
        $st->execute([$id]);
        if ($st->fetchColumn()) return 'مرخصی تأییدشده';
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT l.event FROM live_call_events l WHERE l.agent_user_id=? AND l.created_at>=NOW()-INTERVAL 90 SECOND AND l.id=(SELECT MAX(x.id) FROM live_call_events x WHERE x.agent_user_id=l.agent_user_id AND x.cuid=l.cuid) ORDER BY l.id DESC LIMIT 1");
        $st->execute([$id]);
        if ($st->fetchColumn() === 'ringing') return 'رویداد زنگ اخیر؛ وضعیت مکالمه نامشخص';
    } catch (Throwable $e) {}
    try {
        $st=$pdo->prepare("SELECT 1 FROM work_hours WHERE user_id=? AND work_date=CURDATE() AND start_time IS NOT NULL AND start_time<>'' AND start_time<>'00:00:00' AND (end_time IS NULL OR end_time='' OR end_time='00:00:00') LIMIT 1");
        $st->execute([$id]);
        if ($st->fetchColumn()) return 'حضور ثبت‌شده؛ دسترس‌پذیری نامشخص';
    } catch (Throwable $e) {}
    return 'وضعیت حضور و مکالمه نامشخص';
}
function sp_customer_details(PDO $pdo, int $customerId): ?array {
    try {
        $st=$pdo->prepare("SELECT c.id,c.type,c.name,c.city,c.address,c.owner_user_id,c.created_by, ou.full_name AS owner_name, ou.status AS owner_status, cu.full_name AS creator_name
            FROM crm_customers c LEFT JOIN users ou ON ou.id=c.owner_user_id LEFT JOIN users cu ON cu.id=c.created_by WHERE c.id=? AND c.deleted_at IS NULL");
        $st->execute([$customerId]); $c=$st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $result=['type'=>$c['type'],'name'=>$c['name'],'city'=>$c['city'],'address'=>$c['address'],'creator'=>$c['creator_name'],'owner'=>$c['owner_name'],
            'owner_status'=>sp_person_status($pdo,(int)($c['owner_user_id'] ?? 0),(string)($c['owner_status'] ?? '')),
            'contacts'=>[], 'activities'=>[], 'tasks'=>[], 'deals'=>[]];
        try { $st=$pdo->prepare("SELECT name,position FROM crm_contacts WHERE customer_id=? ORDER BY is_primary DESC,id DESC LIMIT 3"); $st->execute([$customerId]); $result['contacts']=$st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try {
            $st=$pdo->prepare("SELECT a.subject,a.body,a.outcome,a.activity_date,u.full_name FROM crm_activities a LEFT JOIN users u ON u.id=a.user_id WHERE a.customer_id=? ORDER BY a.activity_date DESC,a.id DESC LIMIT 5");
            $st->execute([$customerId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) { $date=(string)$a['activity_date']; $result['activities'][]=['subject'=>$a['subject'],'body'=>mb_substr((string)$a['body'],0,500),'outcome'=>$a['outcome'],'user'=>$a['full_name'],'date'=>to_jalali(substr($date,0,10)).' '.substr($date,11,5)]; }
        } catch (Throwable $e) {}
        try {
            $st=$pdo->prepare("SELECT t.title,t.due_date,u.full_name FROM crm_tasks t LEFT JOIN users u ON u.id=t.user_id WHERE t.customer_id=? AND t.status='pending' ORDER BY t.due_date IS NULL,t.due_date,t.id DESC LIMIT 5"); $st->execute([$customerId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $result['tasks'][]=['title'=>$t['title'],'user'=>$t['full_name'],'date'=>$t['due_date'] ? to_jalali($t['due_date']) : ''];
        } catch (Throwable $e) {}
        try { $st=$pdo->prepare("SELECT d.title,d.status,s.name AS stage FROM crm_deals d LEFT JOIN crm_deal_stages s ON s.id=d.stage_id WHERE d.customer_id=? ORDER BY d.id DESC LIMIT 3"); $st->execute([$customerId]); $result['deals']=$st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        return $result;
    } catch (Throwable $e) { error_log('Screenpop customer: '.$e->getMessage()); return null; }
}

// خلاصهٔ سریع تماس‌های مشتری (تعداد تماس‌ها و آخرین وضعیت از CDR) فقط برای همین مشتریان
foreach ($events as &$e) {
    if (!$crmAllowed) { $e['customer'] = null; $e['lead'] = null; $e['customer_id'] = null; $e['lead_id'] = null; $e['crm_restricted'] = true; continue; }
    // سرنخ‌ها مثل صفحهٔ سرنخ محدود به مالک یا تیم مدیر هستند.
    if ($e['lead_id'] && !$e['customer_id']) {
        try {
            [$leadScope, $leadParams] = crm_scope_where($pdo, 'l');
            $leadCheck = $pdo->prepare("SELECT 1 FROM crm_leads l WHERE l.id=? AND ($leadScope) LIMIT 1");
            $leadCheck->execute(array_merge([(int)$e['lead_id']], $leadParams));
            if (!$leadCheck->fetchColumn()) { $e['lead'] = null; $e['lead_id'] = null; $e['crm_restricted'] = true; }
        } catch (Throwable $e2) { $e['lead'] = null; $e['lead_id'] = null; $e['crm_restricted'] = true; }
    }
    if ($e['customer_id']) {
        $e['details'] = sp_customer_details($pdo, (int)$e['customer_id']);
        if (!$e['details']) { $e['customer'] = null; $e['customer_id'] = null; continue; }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(COALESCE(created_at_tel, created_at)) AS last_at FROM phone_calls WHERE customer_id = ?");
            if ($st) { $st->execute([$e['customer_id']]); $r = $st->fetch(PDO::FETCH_ASSOC); $e['customer_calls'] = (int)($r['n'] ?? 0); $e['customer_last_call'] = $r['last_at'] ?? null; }
        } catch (Throwable $e2) { $e['customer_calls'] = 0; $e['customer_last_call'] = null; }
    }
}
unset($e);

echo json_encode(['enabled' => true, 'ext' => $ext, 'events' => $events], JSON_UNESCAPED_UNICODE);
