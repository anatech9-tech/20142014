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
        } catch (Throwable $e) { throw new RuntimeException('Database backup failed for table ' . $table, 0, $e); }
    }
    return $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

/** Stream a consistent DB snapshot to a temporary file; no partial file is published. */
function operations_stream_database(PDO $pdo, string $path): void {
    $out = fopen($path, 'xb');
    if (!$out) throw new RuntimeException('Cannot create backup temporary file');
    $transaction = false;
    try {
        $write = static function(string $data) use ($out): void {
            $remaining = strlen($data); $offset = 0;
            while ($remaining > 0) {
                $n = fwrite($out, substr($data, $offset));
                if ($n === false || $n === 0) throw new RuntimeException('Backup write failed');
                $offset += $n; $remaining -= $n;
            }
        };
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'); $transaction = true;
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $write("-- Database backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($tables as $table) {
            $table = (string)$table; $safe = str_replace('`', '``', $table);
            $create = $pdo->query("SHOW CREATE TABLE `$safe`")->fetch(PDO::FETCH_NUM);
            if (!$create || empty($create[1])) throw new RuntimeException('Cannot read table schema: '.$table);
            $write("\n-- Table: `$safe`\n" . $create[1] . ";\n");
            // Unbuffered cursor avoids loading an entire table including BLOBs in memory.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $rows = $pdo->query("SELECT * FROM `$safe`");
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_map(static fn($c) => '`'.str_replace('`','``',$c).'`', array_keys($row));
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) $values[] = 'NULL';
                        else { $quoted = $pdo->quote((string)$value); if ($quoted === false) throw new RuntimeException('Cannot quote backup value'); $values[] = $quoted; }
                    }
                    $write("INSERT INTO `$safe` (".implode(',',$columns).") VALUES (".implode(',',$values).");\n");
                }
                $rows->closeCursor();
            } finally { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($out)) throw new RuntimeException('Backup flush failed');
        $pdo->commit(); $transaction = false;
    } catch (Throwable $e) {
        if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { fclose($out); }
}

function operations_backup_retention(string $dir, string $type, int $keep): void {
    $files = glob($dir . 'auto_' . $type . '_*.sql') ?: [];
    rsort($files,SORT_STRING);
    foreach (array_slice($files,max(0,$keep)) as $file) @unlink($file);
}

/** بکاپ request-driven: نخستین بازدید هر روز، هفته یا ماه آن را ایجاد می‌کند. */
/** خواندن مستقیم یک تنظیم از جدول settings (جایگزین خواندن کوین‌شدهٔ تنظیمات بکاپ). */
function operations_setting(PDO $pdo, string $key, $default) {
    try { $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=?"); $st->execute([$key]); $v = $st->fetchColumn(); return $v === false ? $default : $v; }
    catch (Throwable $e) { return $default; }
}

function operations_auto_backups(PDO $pdo): array {
    $dir = BACKUP_DIR;
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    if (!is_writable($dir)) return ['ok'=>false,'reason'=>t('پوشه بکاپ قابل‌نوشتن نیست')];
    $today=date('Y-m-d');
    $daily=$dir.'auto_daily_'.$today.'.sql';
    $made=[];
    try {
        $lock = fopen($dir.'.backup.lock','c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok'=>false,'reason'=>'Backup already running or lock unavailable'];
        try {
            if (!file_exists($daily)) {
                $tmp = $dir.'.backup_'.bin2hex(random_bytes(8)).'.tmp';
                try {
                    operations_stream_database($pdo, $tmp);
                    if (!rename($tmp, $daily)) throw new RuntimeException('Cannot publish backup');
                } finally { if (is_file($tmp)) @unlink($tmp); }
                $made[]='daily';
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        // شنبه = شروع هفتهٔ کاری ایران
        if ((int)date('w')===6) {
            $week=$dir.'auto_weekly_'.date('o_W').'.sql';
            if(!file_exists($week)){if (!copy($daily,$week)) throw new RuntimeException('Weekly backup copy failed'); $made[]='weekly';}
        }
        $j=gregorian_to_jalali((int)date('Y'),(int)date('m'),(int)date('d'));
        if ((int)$j[2]===1) {
            $month=$dir.'auto_monthly_'.$j[0].'_'.str_pad((string)$j[1],2,'0',STR_PAD_LEFT).'.sql';
            if(!file_exists($month)){if (!copy($daily,$month)) throw new RuntimeException('Monthly backup copy failed'); $made[]='monthly';}
        }
        operations_backup_retention($dir,'daily',max(1,(int)operations_setting($pdo,'backup_daily_retention',7)));
        operations_backup_retention($dir,'weekly',max(1,(int)operations_setting($pdo,'backup_weekly_retention',4)));
        operations_backup_retention($dir,'monthly',max(1,(int)operations_setting($pdo,'backup_monthly_retention',12)));
        return ['ok'=>true,'made'=>$made,'latest'=>file_exists($daily)?$daily:null];
    } catch(Throwable $e) { error_log('Auto backup failed: '.$e->getMessage()); return ['ok'=>false,'reason'=>'خطا در بکاپ؛ لاگ سرور را بررسی کنید']; }
}

function operations_backup_health(): array {
    $files=glob(BACKUP_DIR.'auto_daily_*.sql')?:[];
    rsort($files,SORT_STRING);
    $latest=$files[0]??null;
    $age=$latest?time()-filemtime($latest):null;
    return ['latest'=>$latest,'age_seconds'=>$age,'healthy'=>$age!==null&&$age<=86400*1.5];
}

/** پیام رسمی در چت عمومی واحد؛ در صورت نبود ستون‌های جدید، بی‌صدا نادیده گرفته می‌شود. */
/**
 * §fix: پیام‌های خودکار (گزارش کار، اضافه‌کاری) دیگر در چت‌روم ارسال نمی‌شوند.
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
