<?php
/** Local assignment/customer links for read-only shop replicas. */
function shop_work_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_order_work (
            replica_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner_user_id INT NULL, team_id INT NULL,
            crm_customer_id INT NULL, version INT UNSIGNED NOT NULL DEFAULT 0,
            updated_by INT NULL, updated_at DATETIME NULL,
            KEY idx_shop_work_owner(owner_user_id,replica_id), KEY idx_shop_work_team(team_id,replica_id), KEY idx_shop_work_customer(crm_customer_id,replica_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_order_work_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, replica_id BIGINT UNSIGNED NOT NULL,
            actor_id INT NOT NULL, action VARCHAR(20) NOT NULL, old_value INT NULL, new_value INT NULL,
            created_at DATETIME NOT NULL, KEY idx_shop_work_event_order(replica_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_work_ensure(PDO $pdo): bool {
    foreach (['crm_shop_order_work','crm_shop_order_work_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) {
            try { $schemas=shop_work_schema(); $pdo->exec($schemas[$i]); }
            catch (Throwable $e) { error_log('Shop order work schema unavailable'); return false; }
        }
    }
    return true;
}
/** Scope is deliberately narrower than finance-wide access. */
function shop_work_scope(int $me, bool $admin, array $managed, string $alias='w'): array {
    if ($me <= 0) return ['1=0',[]];
    if ($admin) return ['1=1',[]];
    if ($managed) return ["($alias.owner_user_id=? OR $alias.team_id IN (".implode(',',array_fill(0,count($managed),'?')).'))',array_merge([$me],$managed)];
    return ["$alias.owner_user_id=?",[$me]];
}
function shop_work_can_view(array $work, int $me, bool $admin, array $managed): bool {
    if ($me <= 0) return false;
    return $admin || (int)($work['owner_user_id'] ?? 0)===$me || in_array((int)($work['team_id'] ?? 0),$managed,true);
}
/** Identical visibility policy to pages/crm_customers.php, not the broader generic CRM scope. */
function shop_customer_scope(int $me, bool $admin, array $managed, string $alias='c'): array {
    if ($admin) return ['1=1',[]];
    $scope="($alias.owner_user_id = ? OR $alias.created_by = ?)"; $params=[$me,$me];
    if ($managed) {
        $ph=implode(',',array_fill(0,count($managed),'?'));
        $scope="($scope OR $alias.team_id IN ($ph) OR EXISTS (SELECT 1 FROM users su WHERE su.id IN ($alias.owner_user_id,$alias.created_by) AND su.team_id IN ($ph)))";
        $params=array_merge($params,$managed,$managed);
    }
    return [$scope,$params];
}
function shop_visible_customer(PDO $pdo, int $id, int $me, bool $admin, array $managed): ?array {
    if ($id<=0) return null;
    [$sql,$args]=shop_customer_scope($me,$admin,$managed);
    $st=$pdo->prepare("SELECT c.id,c.name FROM crm_customers c WHERE c.id=? AND c.deleted_at IS NULL AND ($sql)");
    $st->execute(array_merge([$id],$args)); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** One transaction locks the replica and work row, validates current scope and appends an event. */
function shop_work_apply(PDO $pdo, int $replicaId, int $me, bool $admin, array $managed, string $action, int $value, int $expectedVersion): void {
    if ($replicaId<=0 || $me<=0 || $expectedVersion<0 || !in_array($action,['assign','link'],true)) throw new DomainException('درخواست نامعتبر است.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$me]); $actor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$actor || ($actor['status'] ?? '')!=='active' || ($admin && ($actor['role'] ?? '')!=='admin') || in_array($actor['role'] ?? '',['nazer','nazer_kol','karfarma'],true)) throw new DomainException('دسترسی شما معتبر نیست.');
        // Re-read managed teams under lock; do not trust a scope computed before the transaction.
        if (!$admin) { $st=$pdo->prepare('SELECT id FROM teams WHERE manager_user_id=? FOR UPDATE'); $st->execute([$me]); $managed=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)); }
        $st=$pdo->prepare('SELECT id FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replicaId]);
        if (!$st->fetchColumn()) throw new DomainException('سفارش یافت نشد.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_order_work WHERE replica_id=? FOR UPDATE'); $st->execute([$replicaId]);
        $work=$st->fetch(PDO::FETCH_ASSOC) ?: ['owner_user_id'=>null,'team_id'=>null,'crm_customer_id'=>null,'version'=>0];
        if (!shop_work_can_view($work,$me,$admin,$managed)) throw new DomainException('دسترسی به سفارش مجاز نیست.');
        if ((int)$work['version']!==$expectedVersion) throw new DomainException('سفارش تغییر کرده؛ صفحه را دوباره بارگذاری کنید.');
        $old=null; $new=null; $now=date('Y-m-d H:i:s');
        if ($action==='assign') {
            if (!$admin && !in_array((int)($work['team_id'] ?? 0),$managed,true)) throw new DomainException('ارجاع این سفارش مجاز نیست.');
            $st=$pdo->prepare("SELECT id,team_id,status,role,username FROM users WHERE id=? FOR UPDATE"); $st->execute([$value]); $target=$st->fetch(PDO::FETCH_ASSOC);
            if (!$target || ($target['status'] ?? '')!=='active' || ($target['role'] ?? '')==='karfarma' || in_array($target['username'] ?? '', ['yaser','javidhse'],true) || (!$admin && !in_array((int)($target['team_id'] ?? 0),$managed,true))) throw new DomainException('کارشناس فعال و مجاز انتخاب کنید.');
            $old=(int)($work['owner_user_id'] ?? 0) ?: null; $new=(int)$target['id'];
            $work['owner_user_id']=$new; $work['team_id']=(int)($target['team_id'] ?? 0) ?: null;
        } else {
            $old=(int)($work['crm_customer_id'] ?? 0) ?: null;
            if ($old && !$admin && !shop_visible_customer($pdo,$old,$me,$admin,$managed)) throw new DomainException('مشتری متصل خارج از محدودهٔ دسترسی شماست؛ با مدیر کل هماهنگ کنید.');
            if ($value<0 || ($value>0 && !shop_visible_customer($pdo,$value,$me,$admin,$managed))) throw new DomainException('مشتری انتخاب‌شده در محدودهٔ دسترسی شما نیست.');
            $new=$value ?: null; $work['crm_customer_id']=$new;
        }
        $exists=$pdo->prepare('SELECT 1 FROM crm_shop_order_work WHERE replica_id=?'); $exists->execute([$replicaId]);
        if ($exists->fetchColumn()) {
            $st=$pdo->prepare('UPDATE crm_shop_order_work SET owner_user_id=?,team_id=?,crm_customer_id=?,version=version+1,updated_by=?,updated_at=? WHERE replica_id=?');
            $st->execute([$work['owner_user_id'],$work['team_id'],$work['crm_customer_id'],$me,$now,$replicaId]);
        } else {
            $st=$pdo->prepare('INSERT INTO crm_shop_order_work(replica_id,owner_user_id,team_id,crm_customer_id,version,updated_by,updated_at) VALUES (?,?,?,?,1,?,?)');
            $st->execute([$replicaId,$work['owner_user_id'],$work['team_id'],$work['crm_customer_id'],$me,$now]);
        }
        $pdo->prepare('INSERT INTO crm_shop_order_work_events(replica_id,actor_id,action,old_value,new_value,created_at) VALUES (?,?,?,?,?,?)')->execute([$replicaId,$me,$action,$old,$new,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
