<?php
/**
 * آرش‌کوین — موتور پاداش، دفترکل کیف پول، محدودیت روزانه، چالش‌ها و رتبه‌بندی.
 * موجودی همیشه از جمع دفترکل به‌دست می‌آید تا با ویرایش ساعت/گزارش همگام بماند.
 */

function arash_coin_tables_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try { $ready = (bool)$pdo->query("SHOW TABLES LIKE 'arash_coin_transactions'")->fetchColumn(); }
    catch (Throwable $e) { $ready = false; }
    return $ready;
}

/**
 * آیکون پایدار آرش‌کوین برای اندازه‌های کوچک؛ به‌جای ایموجیِ وابسته به فونت.
 */
function arash_coin_icon_markup(string $class = 'arash-coin-tiny', string $alt = ''): string {
    if ($alt === '') $alt = t('آرش‌کوین');
    return '<img src="assets/icons/arash-coin-small.png" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">';
}
function arash_coin_has_event_date(PDO $pdo): bool {
    return arash_coin_tables_ready($pdo) && function_exists('db_has_column') && db_has_column($pdo, 'arash_coin_transactions', 'event_date');
}
function arash_coin_v16_ready(PDO $pdo): bool {
    return arash_coin_has_event_date($pdo)
        && function_exists('db_has_column')
        && db_has_column($pdo, 'arash_coin_challenges', 'event_type');
}
function arash_coin_setting(PDO $pdo, string $key, $default = null) {
    try { $st=$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?"); $st->execute([$key]); $v=$st->fetchColumn(); return $v === false ? $default : $v; }
    catch(Throwable $e) { return $default; }
}
function arash_coin_default_task_value(PDO $pdo): int {
    $v=(int)arash_coin_setting($pdo,'arash_coin_default_task',2);
    return $v>0?$v:2;
}
function arash_coin_balance(PDO $pdo, int $user_id, ?int $workshop_id = null): int {
    if ($user_id<=0 || !arash_coin_tables_ready($pdo)) return 0;
    try {
        $sql="SELECT COALESCE(SUM(amount),0) FROM arash_coin_transactions WHERE user_id=?"; $params=[$user_id];
        if($workshop_id!==null&&$workshop_id>0){$sql.=" AND workshop_id=?";$params[]=$workshop_id;}
        $st=$pdo->prepare($sql);$st->execute($params);return (int)round((float)$st->fetchColumn());
    } catch(Throwable $e){return 0;}
}

/** انواع امتیاز خودکار که مشمول سقف روزانه هستند. */
function arash_coin_capped_types(): array {
    return ['early_checkin','overtime','night_work','leave_unused','task_approved','report_approved','inventory_activity'];
}
function arash_coin_tx_date_expression(PDO $pdo, string $alias='t'): string {
    return arash_coin_has_event_date($pdo) ? "COALESCE($alias.event_date, DATE($alias.created_at))" : "DATE($alias.created_at)";
}
function arash_coin_daily_limit_status(PDO $pdo, int $user_id, ?string $event_date=null, ?string $exclude_source=null): array {
    $event_date=$event_date?:date('Y-m-d');
    $limit=max(0,(int)arash_coin_setting($pdo,'arash_coin_daily_limit',50)); // صفر = نامحدود
    $earned=0;
    if($user_id>0&&arash_coin_tables_ready($pdo)) {
        try {
            $types=arash_coin_capped_types(); $holders=implode(',',array_fill(0,count($types),'?'));
            $sql="SELECT COALESCE(SUM(amount),0) FROM arash_coin_transactions t WHERE t.user_id=? AND t.amount>0 AND t.transaction_type IN ($holders) AND ".arash_coin_tx_date_expression($pdo,'t')."=?";
            $params=array_merge([$user_id],$types,[$event_date]);
            if($exclude_source){$sql.=" AND (t.source_key IS NULL OR t.source_key != ?)";$params[]=$exclude_source;}
            $st=$pdo->prepare($sql);$st->execute($params);$earned=(int)$st->fetchColumn();
        }catch(Throwable $e){}
    }
    return ['date'=>$event_date,'limit'=>$limit,'earned'=>$earned,'remaining'=>$limit>0?max(0,$limit-$earned):null];
}
function arash_coin_cap_event_amount(PDO $pdo, int $user_id, string $event_type, int $requested, string $event_date, string $source_key): int {
    if($requested<=0 || !in_array($event_type,arash_coin_capped_types(),true)) return max(0,$requested);
    $status=arash_coin_daily_limit_status($pdo,$user_id,$event_date,$source_key);
    return $status['limit']<=0 ? $requested : min($requested,(int)$status['remaining']);
}

/** تراکنش دستی/فروشگاهی با source_key یکتا. */
function arash_coin_add_transaction(PDO $pdo, int $user_id, int $amount, string $type, string $description, ?int $workshop_id=null, ?int $created_by=null, ?string $source_key=null, ?int $rule_id=null, ?string $event_date=null): ?int {
    if($user_id<=0||$amount===0||!arash_coin_tables_ready($pdo))return null;
    try {
        if($source_key){$check=$pdo->prepare("SELECT id FROM arash_coin_transactions WHERE source_key=? LIMIT 1");$check->execute([$source_key]);if($check->fetchColumn())return null;}
        if(arash_coin_has_event_date($pdo)) {
            $st=$pdo->prepare("INSERT INTO arash_coin_transactions (user_id,workshop_id,amount,transaction_type,source_key,rule_id,description,created_by,event_date) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([$user_id,$workshop_id&&$workshop_id>0?$workshop_id:null,$amount,mb_substr($type,0,40),$source_key?:null,$rule_id?:null,mb_substr($description,0,500),$created_by?:null,$event_date?:date('Y-m-d')]);
        } else {
            $st=$pdo->prepare("INSERT INTO arash_coin_transactions (user_id,workshop_id,amount,transaction_type,source_key,rule_id,description,created_by) VALUES (?,?,?,?,?,?,?,?)");
            $st->execute([$user_id,$workshop_id&&$workshop_id>0?$workshop_id:null,$amount,mb_substr($type,0,40),$source_key?:null,$rule_id?:null,mb_substr($description,0,500),$created_by?:null]);
        }
        return (int)$pdo->lastInsertId();
    }catch(Throwable $e){return null;}
}

/** همگام‌سازی یک رویداد قابل‌محاسبهٔ مجدد؛ سقف روزانه در همین نقطه اعمال می‌شود. */
function arash_coin_set_event_amount(PDO $pdo,int $user_id,int $amount,string $type,string $description,int $workshop_id,string $source_key,?int $created_by=null,?int $rule_id=null,?string $event_date=null):?array {
    if(!arash_coin_tables_ready($pdo)||$user_id<=0||$source_key==='')return null;
    $event_date=$event_date?:date('Y-m-d');
    try {
        $st=$pdo->prepare("SELECT id FROM arash_coin_transactions WHERE source_key=? LIMIT 1");$st->execute([$source_key]);$existing=(int)$st->fetchColumn();
        $amount=arash_coin_cap_event_amount($pdo,$user_id,$type,$amount,$event_date,$source_key);
        if($amount<=0){if($existing)$pdo->prepare("DELETE FROM arash_coin_transactions WHERE id=?")->execute([$existing]);return null;}
        if($existing){
            if(arash_coin_has_event_date($pdo))$pdo->prepare("UPDATE arash_coin_transactions SET user_id=?,workshop_id=?,amount=?,transaction_type=?,rule_id=?,description=?,created_by=?,event_date=? WHERE id=?")->execute([$user_id,$workshop_id?:null,$amount,mb_substr($type,0,40),$rule_id?:null,mb_substr($description,0,500),$created_by?:null,$event_date,$existing]);
            else $pdo->prepare("UPDATE arash_coin_transactions SET user_id=?,workshop_id=?,amount=?,transaction_type=?,rule_id=?,description=?,created_by=? WHERE id=?")->execute([$user_id,$workshop_id?:null,$amount,mb_substr($type,0,40),$rule_id?:null,mb_substr($description,0,500),$created_by?:null,$existing]);
            $id=$existing;
        }else{
            $id=arash_coin_add_transaction($pdo,$user_id,$amount,$type,$description,$workshop_id,$created_by,$source_key,$rule_id,$event_date);if(!$id)return null;
        }
        return ['transaction_id'=>$id,'amount'=>$amount,'balance'=>arash_coin_balance($pdo,$user_id),'daily_limit'=>arash_coin_daily_limit_status($pdo,$user_id,$event_date)];
    }catch(Throwable $e){return null;}
}

/** قانون فعال از صفحهٔ مدیریت آرش‌کوین. */
function arash_coin_event_rule(PDO $pdo,int $workshop_id,string $event_type,string $subject,string $team,string $event_date):?array {
    if(!arash_coin_tables_ready($pdo))return null;
    try{
        $has_event=function_exists('db_has_column')&&db_has_column($pdo,'arash_coin_rules','event_type');
        $sql="SELECT * FROM arash_coin_rules WHERE status='active' AND (workshop_id=? OR workshop_id=0) AND (start_date IS NULL OR start_date<=?) AND (end_date IS NULL OR end_date>=?) AND (target_team IS NULL OR target_team='' OR target_team=?)";
        $args=[$workshop_id,$event_date,$event_date,$team];
        if($has_event){$sql.=" AND (event_type IS NULL OR event_type='' OR event_type='all' OR event_type=?)";$args[]=$event_type;}
        $sql.=" ORDER BY priority DESC,id DESC";$st=$pdo->prepare($sql);$st->execute($args);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $rule){$keyword=trim((string)($rule['task_keyword']??''));if($keyword===''||mb_stripos($subject,$keyword,0,'UTF-8')!==false)return $rule;}
    }catch(Throwable $e){}
    return null;
}

/** چالش‌های کوتاه‌مدت فعال؛ چند چالش هم‌زمان در ضریب هم ضرب می‌شوند. */
function arash_coin_active_challenges(PDO $pdo,int $workshop_id,string $event_type,string $subject,string $team,string $event_date):array {
    if(!arash_coin_v16_ready($pdo))return [];
    try{
        $st=$pdo->prepare("SELECT * FROM arash_coin_challenges WHERE status='active' AND workshop_id=? AND start_date<=? AND end_date>=? AND (event_type='all' OR event_type=?) AND (target_team IS NULL OR target_team='' OR target_team=?) ORDER BY id DESC");
        $st->execute([$workshop_id,$event_date,$event_date,$event_type,$team]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$key=trim((string)($row['task_keyword']??''));if($key===''||mb_stripos($subject,$key,0,'UTF-8')!==false)$out[]=$row;}
        return $out;
    }catch(Throwable $e){return [];}
}
function arash_coin_event_type_label(string $type):string{ $labels=['all'=>'همهٔ عملیات','early_checkin'=>'ورود زودهنگام','overtime'=>'اضافه‌کاری','night_work'=>'شب‌کاری','leave_unused'=>'مرخصی استفاده‌نشده','task_approved'=>'تأیید دستور کار','report_approved'=>'تأیید گزارش کار','inventory_activity'=>'فعالیت انبار','manual_adjustment'=>'اصلاح مدیر','reward_redemption'=>'خرج جایزه','reward_refund'=>'بازگشت سکه']; return t($labels[$type] ?? $type); }

/** اعمال نرخ پایه، قانون، چالش و سقف روزانه. */
function arash_coin_award_event(PDO $pdo,int $user_id,int $workshop_id,string $event_type,int $base_coins,string $description,string $event_date,string $source_key,string $subject='',string $team='',?int $created_by=null):?array {
    if($user_id<=0||!arash_coin_tables_ready($pdo))return null;
    if(function_exists('operations_wallet_is_locked') && operations_wallet_is_locked($pdo,$user_id,$workshop_id)) return null;
    if($base_coins<=0){arash_coin_remove_event($pdo,$source_key);return null;}
    $rule=arash_coin_event_rule($pdo,$workshop_id,$event_type,$subject,$team,$event_date);
    $base=$rule&&isset($rule['base_coins'])&&(int)$rule['base_coins']>0?(int)$rule['base_coins']:$base_coins;
    $multiplier=$rule?max(0.0,(float)$rule['multiplier']):1.0;
    $challenges=arash_coin_active_challenges($pdo,$workshop_id,$event_type,$subject,$team,$event_date);
    foreach($challenges as $challenge)$multiplier*=max(0.0,(float)$challenge['multiplier']);
    $amount=max(0,(int)round($base*$multiplier));$desc=$description;
    if($rule){$desc.=' — '.t('قانون').' «'.trim((string)$rule['title']).'»';}
    foreach($challenges as $challenge)$desc.=' — '.t('چالش').' «'.trim((string)$challenge['title']).'»';
    if($multiplier!=1.0)$desc.=' ×'.rtrim(rtrim(number_format($multiplier,2,'.',''),'0'),'.');
    $out=arash_coin_set_event_amount($pdo,$user_id,$amount,$event_type,$desc,$workshop_id,$source_key,$created_by,$rule?(int)$rule['id']:null,$event_date);
    if($out){$out['rule_title']=$rule?trim((string)$rule['title']):'';$out['challenges']=array_column($challenges,'title');}
    return $out;
}
function arash_coin_remove_event(PDO $pdo,string $source_key):void{if(!$source_key||!arash_coin_tables_ready($pdo))return;try{$pdo->prepare("DELETE FROM arash_coin_transactions WHERE source_key=?")->execute([$source_key]);}catch(Throwable $e){}}
function arash_coin_task_rule(PDO $pdo,int $workshop_id,string $task_text,string $team,string $event_date):?array{return arash_coin_event_rule($pdo,$workshop_id,'task_approved',$task_text,$team,$event_date);}

function arash_coin_award_work_order_item(PDO $pdo,int $item_id,int $approved_by):?array {
    if($item_id<=0||!arash_coin_tables_ready($pdo))return null;
    try{$st=$pdo->prepare("SELECT woi.id,woi.description,woi.completed_by,woi.completed_at,wo.workshop_id,wo.target_team FROM work_order_items woi JOIN work_orders wo ON wo.id=woi.work_order_id WHERE woi.id=? AND woi.is_completed=1");$st->execute([$item_id]);$item=$st->fetch(PDO::FETCH_ASSOC);if(!$item||empty($item['completed_by']))return null;$date=!empty($item['completed_at'])?substr($item['completed_at'],0,10):date('Y-m-d');return arash_coin_award_event($pdo,(int)$item['completed_by'],(int)$item['workshop_id'],'task_approved',2,t('پاداش تأیید دستور کار: ') . mb_substr(trim((string)$item['description']),0,180),$date,'task:'.$item_id.':approved',(string)$item['description'],(string)$item['target_team'],$approved_by);}catch(Throwable $e){return null;}
}
function arash_coin_reverse_work_order_item(PDO $pdo,int $item_id,?int $actor_id=null,string $reason=''):?array{if($item_id<=0)return null;arash_coin_remove_event($pdo,'task:'.$item_id.':approved');arash_coin_remove_event($pdo,'task:'.$item_id.':reward');arash_coin_remove_event($pdo,'task:'.$item_id.':reversal');return null;}
function arash_coin_award_daily_report(PDO $pdo,int $report_id,int $approved_by):?array {
    if($report_id<=0||!arash_coin_tables_ready($pdo))return null;
    try{$st=$pdo->prepare("SELECT * FROM daily_reports WHERE id=? AND status='approved'");$st->execute([$report_id]);$report=$st->fetch(PDO::FETCH_ASSOC);if(!$report)return null;$text=(string)($report['wo_description']?:$report['report_text']);return arash_coin_award_event($pdo,(int)$report['user_id'],(int)$report['workshop_id'],'report_approved',2,t('پاداش تأیید گزارش کار: ') . mb_substr(trim($text),0,180),(string)$report['report_date'],'daily_report:'.$report_id.':approved',$text,(string)($report['category']??''),$approved_by);}catch(Throwable $e){return null;}
}
function arash_coin_reverse_daily_report(PDO $pdo,int $report_id,?int $actor_id=null,string $reason=''):?array{arash_coin_remove_event($pdo,'daily_report:'.$report_id.':approved');return null;}

/** دقایق اضافه‌کار (۱۷–۲۱) و شب‌کاری (۲۱–۷) یک رکورد حضور. */
function arash_coin_work_hour_metrics(array $wh):array{
    if(empty($wh['work_date'])||empty($wh['start_time']))return ['early'=>false,'overtime_minutes'=>0,'night_minutes'=>0];$early=strtotime($wh['start_time'])<strtotime('08:00:00');if(($wh['end_time']??'')==='00:00:00')return ['early'=>$early,'overtime_minutes'=>0,'night_minutes'=>0];$start=strtotime($wh['work_date'].' '.$wh['start_time']);$end=strtotime($wh['work_date'].' '.$wh['end_time']);if($end<=$start)$end+=86400;$overlap=function($a,$b)use($start,$end){return max(0,min($end,$b)-max($start,$a));};$date=$wh['work_date'];$ot=$overlap(strtotime($date.' 17:00:00'),strtotime($date.' 21:00:00'));$night=$overlap(strtotime($date.' 00:00:00'),strtotime($date.' 07:00:00'))+$overlap(strtotime($date.' 21:00:00'),strtotime($date.' +1 day 07:00:00'));return ['early'=>$early,'overtime_minutes'=>(int)floor($ot/60),'night_minutes'=>(int)floor($night/60)];
}
function arash_coin_sync_work_hour_rewards(PDO $pdo,int $work_hour_id,?int $actor_id=null):array{
    $none=[];if($work_hour_id<=0||!arash_coin_tables_ready($pdo))return $none;try{$st=$pdo->prepare("SELECT * FROM work_hours WHERE id=?");$st->execute([$work_hour_id]);$wh=$st->fetch(PDO::FETCH_ASSOC);if(!$wh)return $none;$m=arash_coin_work_hour_metrics($wh);$uid=(int)$wh['user_id'];$ws=(int)$wh['workshop_id'];$date=$wh['work_date'];$ust=$pdo->prepare("SELECT is_karigar FROM users WHERE id=?");$ust->execute([$uid]);$is_employee=((int)$ust->fetchColumn()===0);$events=[];$events['early']=arash_coin_award_event($pdo,$uid,$ws,'early_checkin',($m['early']&&$is_employee)?2:0,t('پاداش ورود قبل از ساعت ۸'),$date,'work_hour:'.$work_hour_id.':early','','',$actor_id);$otCoins=(int)floor($m['overtime_minutes']/30);$events['overtime']=arash_coin_award_event($pdo,$uid,$ws,'overtime',$otCoins,strtr(t('پاداش اضافه‌کاری: {minutes} دقیقه'), ['{minutes}' => $m['overtime_minutes']]),$date,'work_hour:'.$work_hour_id.':overtime','','',$actor_id);$nightCoins=(int)floor($m['night_minutes']/60)*3;$events['night']=arash_coin_award_event($pdo,$uid,$ws,'night_work',$nightCoins,strtr(t('پاداش شب‌کاری: {minutes} دقیقه'), ['{minutes}' => $m['night_minutes']]),$date,'work_hour:'.$work_hour_id.':night','','',$actor_id);return array_filter($events);}catch(Throwable $e){return $none;}
}
function arash_coin_remove_work_hour_rewards(PDO $pdo,int $work_hour_id):void{foreach(['early','overtime','night'] as $suffix)arash_coin_remove_event($pdo,'work_hour:'.$work_hour_id.':'.$suffix);}
function arash_coin_award_inventory_activity(PDO $pdo,int $user_id,int $workshop_id,string $source_key,string $description,?string $event_date=null):?array{return arash_coin_award_event($pdo,$user_id,$workshop_id,'inventory_activity',1,$description,$event_date?:date('Y-m-d'),$source_key,$description,'anbar',$user_id);}

/** پاداش پایان ماه برای روزهای استفاده‌نشده از سهم مرخصی. */
function arash_coin_run_monthly_leave_rewards(PDO $pdo,int $workshop_id,?string $as_of=null):int{
    if(!arash_coin_tables_ready($pdo)||!function_exists('approved_leave_summary'))return 0;
    // §perf: این محاسبهٔ سنگین (برای همهٔ نیروها) قبلاً در هر بار باز شدن داشبورد اجرا می‌شد؛ حالا حداکثر ساعتی یک‌بار برای هر کارگاه.
    try {
        $__k='arash_leave_rewards_last_run_ws'.$workshop_id;
        $__st=$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?");$__st->execute([$__k]);$__last=$__st->fetchColumn();
        if($__last!==false&&(time()-(int)$__last)<3600)return 0;
        if($__last===false)$pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?)")->execute([$__k,(string)time()]);
        else $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?")->execute([(string)time(),$__k]);
    }catch(Throwable $e){}$as_of=$as_of?:date('Y-m-d');$j=gregorian_to_jalali((int)substr($as_of,0,4),(int)substr($as_of,5,2),(int)substr($as_of,8,2));$jy=(int)$j[0];$jm=(int)$j[1]-1;if($jm<1){$jm=12;$jy--;}[$start,$end]=jalali_month_bounds($jy,$jm);if($end>=$as_of)return 0;$allow=max(0,(int)arash_coin_setting($pdo,'arash_coin_monthly_leave_days',2));$done=0;try{$st=$pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN workshop_personnel wp ON wp.user_id=u.id WHERE wp.workshop_id=? AND u.is_karigar!=2 AND (wp.status='active' OR (wp.ended_at IS NOT NULL AND wp.ended_at>=?))");$st->execute([$workshop_id,$start]);$holidays=_holidays_between($pdo,$start,$end);foreach($st->fetchAll(PDO::FETCH_COLUMN)as $uid){$leave=approved_leave_summary($pdo,(int)$uid,$workshop_id,$start,$end,$holidays);$unused=max(0,$allow-(int)$leave['daily_days']);$source='leave_unused:'.(int)$uid.':'.$jy.'-'.$jm;$r=arash_coin_award_event($pdo,(int)$uid,$workshop_id,'leave_unused',$unused*10,$unused.' '.t('پاداش').' '.t('روز مرخصی استفاده‌نشده').' — '.jalali_month_name($jm).' '.$jy,$end,$source,'مرخصی','',null);if($r)$done++;}}catch(Throwable $e){}return $done;
}

function arash_coin_active_multiplier_notices(PDO $pdo,int $workshop_id,?string $date=null):array{
    if(!arash_coin_tables_ready($pdo))return [];$date=$date?:date('Y-m-d');$out=[];try{$has_event=function_exists('db_has_column')&&db_has_column($pdo,'arash_coin_rules','event_type');$cols=$has_event?'title,multiplier,event_type,task_keyword':'title,multiplier,task_keyword';$st=$pdo->prepare("SELECT $cols FROM arash_coin_rules WHERE status='active' AND workshop_id=? AND (start_date IS NULL OR start_date<=?) AND (end_date IS NULL OR end_date>=?) AND multiplier!=1 ORDER BY priority DESC,id DESC");$st->execute([$workshop_id,$date,$date]);foreach($st->fetchAll(PDO::FETCH_ASSOC)as $row){$row['kind']='rule';$out[]=$row;}}catch(Throwable $e){}if(arash_coin_v16_ready($pdo)){try{$st=$pdo->prepare("SELECT title,multiplier,event_type,task_keyword FROM arash_coin_challenges WHERE status='active' AND workshop_id=? AND start_date<=? AND end_date>=? AND multiplier!=1 ORDER BY id DESC");$st->execute([$workshop_id,$date,$date]);foreach($st->fetchAll(PDO::FETCH_ASSOC)as $row){$row['kind']='challenge';$out[]=$row;}}catch(Throwable $e){}}return $out;
}

/** شنبه تا جمعه و ماه شمسی برای جدول رتبه‌بندی. */
function arash_coin_week_bounds(?string $date=null):array{$date=$date?:date('Y-m-d');$w=(int)date('w',strtotime($date));$since_sat=($w+1)%7;$start=date('Y-m-d',strtotime($date." -{$since_sat} day"));return [$start,date('Y-m-d',strtotime($start.' +6 day'))];}
function arash_coin_month_bounds(?string $date=null):array{$date=$date?:date('Y-m-d');$j=gregorian_to_jalali((int)substr($date,0,4),(int)substr($date,5,2),(int)substr($date,8,2));return jalali_month_bounds((int)$j[0],(int)$j[1]);}
function arash_coin_period_leaderboard(PDO $pdo,int $workshop_id,string $start,string $end,int $limit=10):array{
    if(!arash_coin_tables_ready($pdo))return [];try{$dateExpr=arash_coin_tx_date_expression($pdo,'t');$sql="SELECT u.id,u.full_name,u.avatar,COALESCE(SUM(CASE WHEN t.amount>0 THEN t.amount ELSE 0 END),0) earned,COALESCE(SUM(CASE WHEN t.amount<0 THEN -t.amount ELSE 0 END),0) spent,COALESCE(SUM(t.amount),0) net FROM arash_coin_transactions t JOIN users u ON u.id=t.user_id WHERE t.workshop_id=? AND $dateExpr BETWEEN ? AND ? GROUP BY u.id,u.full_name,u.avatar HAVING earned>0 OR spent>0 ORDER BY earned DESC,net DESC,u.full_name LIMIT ".max(1,(int)$limit);$st=$pdo->prepare($sql);$st->execute([$workshop_id,$start,$end]);return $st->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){return [];}
}
function arash_coin_period_badges(PDO $pdo,int $workshop_id,string $start,string $end):array{
    if(!arash_coin_tables_ready($pdo))return [];$dateExpr=arash_coin_tx_date_expression($pdo,'t');$badges=[];$defs=['early_checkin'=>['key'=>'punctual','title'=>t('منظم‌ترین نیرو'),'icon'=>'⏰','metric'=>t('ورود زودهنگام')],'overtime'=>['key'=>'overtime','title'=>t('قهرمان اضافه‌کاری'),'icon'=>'🔥','metric'=>t('کوین اضافه‌کاری')],'inventory_activity'=>['key'=>'warehouse','title'=>t('انباردار دقیق'),'icon'=>'📦','metric'=>t('فعالیت انبار')]];foreach($defs as $type=>$def){try{$st=$pdo->prepare("SELECT u.id,u.full_name,COALESCE(SUM(t.amount),0) score FROM arash_coin_transactions t JOIN users u ON u.id=t.user_id WHERE t.workshop_id=? AND t.transaction_type=? AND t.amount>0 AND $dateExpr BETWEEN ? AND ? GROUP BY u.id,u.full_name ORDER BY score DESC,u.full_name LIMIT 1");$st->execute([$workshop_id,$type,$start,$end]);$row=$st->fetch(PDO::FETCH_ASSOC);if($row&&$row['score']>0)$badges[]=$def+['user_id'=>(int)$row['id'],'user_name'=>$row['full_name'],'score'=>(int)$row['score']];}catch(Throwable $e){}}return $badges;
}

/** رتبه‌بندی واحدها با تشخیص واحد از سمت کاری پرسنل. */
function arash_coin_team_leaderboard(PDO $pdo,int $workshop_id,string $start,string $end):array{
    if(!arash_coin_tables_ready($pdo))return [];
    try{
        $expr=arash_coin_tx_date_expression($pdo,'t');
        $st=$pdo->prepare("SELECT u.work_role,COALESCE(SUM(CASE WHEN t.amount>0 THEN t.amount ELSE 0 END),0) earned,COUNT(DISTINCT t.user_id) people FROM arash_coin_transactions t JOIN users u ON u.id=t.user_id WHERE t.workshop_id=? AND $expr BETWEEN ? AND ? GROUP BY u.work_role");
        $st->execute([$workshop_id,$start,$end]);$teams=['اجرا'=>['earned'=>0,'people'=>[]],'HSE'=>['earned'=>0,'people'=>[]],'انبار'=>['earned'=>0,'people'=>[]]];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$role=mb_strtolower((string)$row['work_role'],'UTF-8');$team=strpos($role,'hse')!==false?'HSE':(strpos($role,'انبار')!==false?'انبار':'اجرا');$teams[$team]['earned']+=(int)$row['earned'];$teams[$team]['people'][]=(int)$row['people'];}
        $out=[];foreach($teams as $name=>$v)if($v['earned']>0)$out[]=['team'=>$name,'earned'=>$v['earned'],'people'=>array_sum($v['people'])];usort($out,fn($a,$b)=>$b['earned']<=>$a['earned']);return $out;
    }catch(Throwable $e){return [];}
}

/**
 * §fix: گزارش کار (اجرا/HSE/انبار) دیگر به تأیید مدیر نیاز ندارد.
 * گزارش بلافاصله «تأییدشده» می‌شود و آرش‌کوینِ گزارش (و اگر از دستور کار آمده، آرش‌کوینِ آن آیتم) پرداخت می‌شود.
 * @return array{coin:?array,task_coin:?array}
 */
function daily_report_auto_approve(PDO $pdo, int $report_id, int $actor_id): array {
    $out = ['coin' => null, 'task_coin' => null];
    if ($report_id <= 0) return $out;
    try {
        if (db_has_column($pdo, 'daily_reports', 'status')) {
            $sets = ["status = 'approved'"]; $params = [];
            if (db_has_column($pdo, 'daily_reports', 'approved_by')) { $sets[] = 'approved_by = ?'; $params[] = $actor_id; }
            if (db_has_column($pdo, 'daily_reports', 'approved_at')) { $sets[] = 'approved_at = NOW()'; }
            $params[] = $report_id;
            $pdo->prepare("UPDATE daily_reports SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        $out['coin'] = arash_coin_award_daily_report($pdo, $report_id, $actor_id);
        $st = $pdo->prepare("SELECT source_item_id FROM daily_reports WHERE id = ?");
        $st->execute([$report_id]);
        $src = (int)$st->fetchColumn();
        if ($src > 0) $out['task_coin'] = arash_coin_award_work_order_item($pdo, $src, $actor_id);
    } catch (Throwable $e) { /* بدون خطا به کاربر */ }
    return $out;
}

/** آرش‌کوینِ یک گزارش (و آیتم دستور کارِ متصل به آن) را برمی‌گرداند؛ قبل از حذف گزارش صدا زده شود. */
function daily_report_reverse_coins(PDO $pdo, int $report_id, ?int $actor_id = null, string $reason = ''): void {
    if ($report_id <= 0) return;
    try {
        arash_coin_reverse_daily_report($pdo, $report_id, $actor_id, $reason);
        if (db_has_column($pdo, 'daily_reports', 'source_item_id')) {
            $st = $pdo->prepare("SELECT source_item_id FROM daily_reports WHERE id = ?");
            $st->execute([$report_id]);
            $src = (int)$st->fetchColumn();
            if ($src > 0) arash_coin_reverse_work_order_item($pdo, $src, $actor_id, $reason);
        }
    } catch (Throwable $e) {}
}
