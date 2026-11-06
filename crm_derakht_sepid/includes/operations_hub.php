<?php
/**
 * عملیات پشتیبانی: بکاپ خودکار، لاگ استاندارد، هشدارهای ضدتقلب و پیام سیستمی.
 * همهٔ این عملیات بدون سرویس جداگانه کار می‌کنند و در اولین بازدید روز اجرا می‌شوند.
 */

function operations_audit(PDO $pdo, int $actor_id, string $action, string $entity_type, ?int $entity_id, string $new_value = '', string $old_value = ''): void {
    try {
        $st = $pdo->prepare("INSERT INTO audit_log (user_id,action,entity_type,entity_id,old_value,new_value) VALUES (?,?,?,?,?,?)");
        $st->execute([$actor_id,$action,$entity_type,$entity_id,$old_value,$new_value]);
    } catch (Throwable $e) {}
}

function operations_upload_file(array $file, string $relative_dir, array $allowed_mimes, int $max_bytes = 12_000_000): ?string {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException(t('آپلود فایل ناموفق بود.'));
    if (($file['size'] ?? 0) > $max_bytes) throw new RuntimeException(t('حجم فایل از حد مجاز بیشتر است.'));
    $mime = @mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed_mimes[$mime])) throw new RuntimeException(t('نوع فایل مجاز نیست.'));
    $dir = __DIR__ . '/../' . trim($relative_dir,'/') . '/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $ext = $allowed_mimes[$mime];
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'],$dir.$name)) throw new RuntimeException(t('ذخیره فایل ناموفق بود.'));
    if (str_starts_with($mime,'image/')) compress_image($dir.$name,1600,85);
    return trim($relative_dir,'/') . '/' . $name;
}

function operations_dump_database(PDO $pdo): string {
    $sql = "-- Kargah auto backup " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/',$table)) continue;
        try {
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            if ($create && !empty($create[1])) $sql .= "\n-- Table: `$table`\n" . $create[1] . ";\n";
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $columns = array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($rows[0]));
                foreach (array_chunk($rows,200) as $chunk) {
                    $values=[];
                    foreach ($chunk as $row) {
                        $vals=[];
                        foreach ($row as $value) $vals[] = $value===null ? 'NULL' : $pdo->quote((string)$value);
                        $values[]='('.implode(',',$vals).')';
                    }
                    $sql .= "INSERT INTO `$table` (".implode(',',$columns).") VALUES\n".implode(",\n",$values).";\n";
                }
            }
        } catch (Throwable $e) { $sql .= "-- skipped $table: " . str_replace("\n",' ',$e->getMessage()) . "\n"; }
    }
    return $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

function operations_backup_retention(string $dir, string $type, int $keep): void {
    $files = glob($dir . 'auto_' . $type . '_*.sql') ?: [];
    rsort($files,SORT_STRING);
    foreach (array_slice($files,max(0,$keep)) as $file) @unlink($file);
}

/** بکاپ request-driven: نخستین بازدید هر روز، هفته یا ماه آن را ایجاد می‌کند. */
function operations_auto_backups(PDO $pdo): array {
    $dir = BACKUP_DIR;
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    if (!is_writable($dir)) return ['ok'=>false,'reason'=>t('پوشه بکاپ قابل‌نوشتن نیست')];
    $today=date('Y-m-d');
    $daily=$dir.'auto_daily_'.$today.'.sql';
    $made=[];
    try {
        if (!file_exists($daily)) {
            $dump=operations_dump_database($pdo);
            file_put_contents($daily,$dump,LOCK_EX);
            $made[]='daily';
        }
        // شنبه = شروع هفتهٔ کاری ایران
        if ((int)date('w')===6) {
            $week=$dir.'auto_weekly_'.date('o_W').'.sql';
            if(!file_exists($week)){@copy($daily,$week);$made[]='weekly';}
        }
        $j=gregorian_to_jalali((int)date('Y'),(int)date('m'),(int)date('d'));
        if ((int)$j[2]===1) {
            $month=$dir.'auto_monthly_'.$j[0].'_'.str_pad((string)$j[1],2,'0',STR_PAD_LEFT).'.sql';
            if(!file_exists($month)){@copy($daily,$month);$made[]='monthly';}
        }
        operations_backup_retention($dir,'daily',max(1,(int)arash_coin_setting($pdo,'backup_daily_retention',7)));
        operations_backup_retention($dir,'weekly',max(1,(int)arash_coin_setting($pdo,'backup_weekly_retention',4)));
        operations_backup_retention($dir,'monthly',max(1,(int)arash_coin_setting($pdo,'backup_monthly_retention',12)));
        return ['ok'=>true,'made'=>$made,'latest'=>file_exists($daily)?$daily:null];
    } catch(Throwable $e) { return ['ok'=>false,'reason'=>$e->getMessage()]; }
}

function operations_backup_health(): array {
    $files=glob(BACKUP_DIR.'auto_daily_*.sql')?:[];
    rsort($files,SORT_STRING);
    $latest=$files[0]??null;
    $age=$latest?time()-filemtime($latest):null;
    return ['latest'=>$latest,'age_seconds'=>$age,'healthy'=>$age!==null&&$age<=86400*1.5];
}

function operations_wallet_is_locked(PDO $pdo,int $user_id,int $workshop_id): bool {
    if (!function_exists('db_has_column') || !db_has_column($pdo,'arash_coin_wallet_controls','is_locked')) return false;
    try {$st=$pdo->prepare("SELECT is_locked FROM arash_coin_wallet_controls WHERE user_id=? AND workshop_id=?");$st->execute([$user_id,$workshop_id]);return (bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}

function operations_scan_coin_anomalies(PDO $pdo,int $workshop_id,?string $date=null): int {
    if (!function_exists('arash_coin_tx_date_expression') || !function_exists('db_has_column') || !db_has_column($pdo,'arash_coin_flags','id')) return 0;
    $date=$date?:date('Y-m-d');$threshold=max(1,(int)arash_coin_setting($pdo,'coin_anomaly_threshold',40));$count=0;
    try {
        $types=arash_coin_capped_types();$holders=implode(',',array_fill(0,count($types),'?'));
        $expr=arash_coin_tx_date_expression($pdo,'t');
        $sql="SELECT t.user_id,COALESCE(SUM(t.amount),0) total FROM arash_coin_transactions t WHERE t.workshop_id=? AND t.amount>0 AND t.transaction_type IN ($holders) AND $expr=? GROUP BY t.user_id HAVING total>=?";
        $st=$pdo->prepare($sql);$st->execute(array_merge([$workshop_id],$types,[$date,$threshold]));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $reason=strtr(t('دریافت غیرعادی کوین در یک روز: {count} کوین'), ['{count}' => (int)$row['total']]);
            $ins=$pdo->prepare("INSERT IGNORE INTO arash_coin_flags (user_id,workshop_id,flag_date,severity,reason) VALUES (?,?,?,'warning',?)");
            $ins->execute([(int)$row['user_id'],$workshop_id,$date,$reason]);$count+=$ins->rowCount();
        }
    }catch(Throwable $e){}
    return $count;
}

/** پیام رسمی در چت عمومی کارگاه؛ در صورت نبود ستون‌های جدید، بی‌صدا نادیده گرفته می‌شود. */
/**
 * §fix: پیام‌های خودکار (گزارش کار، WTTP COIN، اضافه‌کاری، چالش و فصل) دیگر در چت‌روم ارسال نمی‌شوند.
 * فقط اگر $force=true باشد ثبت می‌شود (فعلاً فقط اعلان‌های «درخواست خرید» با این حالت صدا زده می‌شوند).
 */
function operations_system_chat_message(PDO $pdo,int $workshop_id,int $actor_id,string $message,?int $channel_id=null,bool $force=false): void {
    if (!$force) return;
    if (!function_exists('db_has_column') || !db_has_column($pdo,'chat_messages','is_system')) return;
    try {
        $st=$pdo->prepare("INSERT INTO chat_messages (sender_id,receiver_id,message,attachment_type,is_read,workshop_id,channel_id,is_system) VALUES (?,0,?,'emoji',0,?,?,1)");
        $st->execute([$actor_id,$message,$workshop_id,$channel_id]);
    }catch(Throwable $e){}
}
