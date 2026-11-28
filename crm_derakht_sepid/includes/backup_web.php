<?php
/** Backup download helpers; caller must independently enforce CSRF and active-admin authorization. */
function backup_web_open(string $dir,$name) {
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,128}\.sql$/D',$name)) return false;
    $root=realpath($dir); if ($root===false || !is_dir($root)) return false;
    $path=$root.DIRECTORY_SEPARATOR.$name; $real=realpath($path); $before=@lstat($path);
    if ($real===false || dirname($real)!==$root || !$before || (($before['mode'] & 0170000)!==0100000)) return false;
    $stream=@fopen($path,'rb'); if (!$stream) return false;
    $after=@fstat($stream); $pathNow=@lstat($path);
    if (!$after || !$pathNow || (($after['mode'] & 0170000)!==0100000) || (($pathNow['mode'] & 0170000)!==0100000)
        || $before['dev']!==$after['dev'] || $before['ino']!==$after['ino'] || $pathNow['dev']!==$after['dev'] || $pathNow['ino']!==$after['ino']) { fclose($stream); return false; }
    return $stream;
}
function backup_web_send(string $dir,string $name): void {
    $stream=backup_web_open($dir,$name); if (!$stream) { http_response_code(404); echo 'فایل بکاپ در دسترس نیست.'; return; }
    try {
        $stat=fstat($stream); if (!$stat) { http_response_code(503); echo 'خواندن بکاپ ممکن نیست.'; return; }
        header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.(string)$stat['size']); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        fpassthru($stream);
    } finally { fclose($stream); }
}
