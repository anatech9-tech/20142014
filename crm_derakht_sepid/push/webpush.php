<?php
/**
 * §Web Push — پیاده‌سازی VAPID + RFC 8291 (aes128gcm) با openssl خالص
 * بدون هیچ کتابخانهٔ خارجی — سازگار با PHP 7.4+ (نیازمند: openssl, curl, mbstring)
 */

function wp_b64u_encode($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function wp_b64u_decode($d) {
    $d = strtr((string)$d, '-_', '+/');
    $pad = strlen($d) % 4;
    if ($pad) $d .= str_repeat('=', 4 - $pad);
    return base64_decode($d);
}

/**
 * ساخت/خواندن کلیدهای VAPID (EC P-256)
 * @return array|null ['public' => base64url, 'private_pem' => PEM]
 */
function wp_vapid_keys($dir) {
    $f = rtrim($dir, '/') . '/vapid.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && !empty($j['public']) && !empty($j['private_pem'])) return $j;
    }
    if (!function_exists('openssl_pkey_new')) return null;
    $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) return null;
    $det = openssl_pkey_get_details($res);
    if (!$det || empty($det['ec'])) return null;
    $pub_raw = "\x04" . $det['ec']['x'] . $det['ec']['y'];
    $pem = '';
    openssl_pkey_export($res, $pem);
    $keys = ['public' => wp_b64u_encode($pub_raw), 'private_pem' => $pem];
    @file_put_contents($f, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($f, 0600);
    return $keys;
}

/** تبدیل امضای DER (خروجی openssl_sign) به R||S خام ۶۴ بایتی برای ES256 */
function wp_der_to_raw($der) {
    $p = 0;
    if (ord($der[$p] ?? "\0") !== 0x30) return null;
    $p = 2;
    if (ord($der[$p] ?? "\0") !== 0x02) return null;
    $rl = ord($der[$p + 1]);
    $r = substr($der, $p + 2, $rl);
    $p += 2 + $rl;
    if (ord($der[$p] ?? "\0") !== 0x02) return null;
    $sl = ord($der[$p + 1]);
    $s = substr($der, $p + 2, $sl);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

/** امضای ES256 (JWT) */
function wp_sign_es256($data, $pem) {
    $pkey = @openssl_pkey_get_private($pem);
    if (!$pkey) return null;
    if (!@openssl_sign($data, $sig, $pkey, OPENSSL_ALGO_SHA256)) return null;
    return wp_der_to_raw($sig);
}

/** HKDF-Expand */
function wp_hkdf_expand($prk, $info, $len) {
    $t = ''; $out = '';
    for ($i = 1; strlen($out) < $len; $i++) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t;
    }
    return substr($out, 0, $len);
}

/** تبدیل نقطهٔ خام EC (0x04||X||Y) به PEM کلید عمومی */
function wp_raw_pub_to_pem($raw) {
    $alg = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $bit = "\x03" . chr(strlen($raw) + 1) . "\x00" . $raw;
    $spki = "\x30" . chr(strlen($alg) + strlen($bit)) . $alg . $bit;
    $b64 = base64_encode($spki);
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($b64, 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * رمزنگاری payload طبق RFC 8291 (aes128gcm)
 * @return string|null باینری قابل ارسال
 */
function wp_encrypt_payload($plaintext, $client_pub_raw, $auth_secret) {
    if (strlen($client_pub_raw) !== 65 || strlen($auth_secret) < 16) return null;
    $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) return null;
    $det = openssl_pkey_get_details($res);
    if (!$det || empty($det['ec'])) return null;
    $peer = @openssl_pkey_get_public(wp_raw_pub_to_pem($client_pub_raw));
    if (!$peer) return null;
    $shared = @openssl_pkey_derive($peer, $res);
    if ($shared === false || $shared === null) return null;

    // RFC 8291: PRK = HKDF-Extract(salt=auth_secret, ikm=ecdh_secret)
    $prk = hash_hmac('sha256', $shared, $auth_secret, true);
    $cek = wp_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = wp_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    $tag = '';
    $cipher = @openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) return null;

    $salt = random_bytes(16);
    $rs = 4096;
    // header: salt(16) + rs(4) + idlen(1=0)
    return $salt . pack('N', $rs) . "\x00" . $cipher . $tag;
}

/** ساخت JWT VAPID */
function wp_vapid_jwt($aud, $pem, $sub = 'mailto:admin@omranp.ir') {
    $h = wp_b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = wp_b64u_encode(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    $sig = wp_sign_es256($h . '.' . $p, $pem);
    if ($sig === null) return null;
    return $h . '.' . $p . '.' . wp_b64u_encode($sig);
}

/**
 * ارسال یک Push
 * @return array ['ok'=>bool,'code'=>int,'body'=>string]
 */
function wp_send($endpoint, $payload, $vapid_pub, $pem, $ttl = 86400) {
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['host'])) return ['ok' => false, 'code' => 0, 'body' => 'invalid endpoint'];
    $aud = $parts['scheme'] . '://' . $parts['host'];
    $jwt = wp_vapid_jwt($aud, $pem);
    if (!$jwt) return ['ok' => false, 'code' => 0, 'body' => 'jwt failed'];

    $headers = [
        'Content-Type: application/octet-stream',
        'TTL: ' . (int)$ttl,
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid_pub,
        'Content-Encoding: aes128gcm',
    ];

    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'body' => 'curl missing'];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if (in_array($code, [200, 201, 202, 204], true)) return ['ok' => true, 'code' => $code, 'body' => ''];
    return ['ok' => false, 'code' => $code, 'body' => mb_substr((string)$resp, 0, 300), 'err' => $err];
}
