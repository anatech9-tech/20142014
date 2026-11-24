<?php
/**
 * Internationalization helper
 */

declare(strict_types=1);

class I18n
{
    private static string $currentLang = DEFAULT_LANG;
    private static array $loaded = [];

    public static function setLang(string $lang): void
    {
        if (!in_array($lang, AVAILABLE_LANGS, true)) {
            $lang = DEFAULT_LANG;
        }
        self::$currentLang = $lang;
        $_SESSION['lang'] = $lang;
    }

    public static function getLang(): string
    {
        if (!empty($_SESSION['lang'])) {
            return $_SESSION['lang'];
        }
        return self::$currentLang;
    }

    /**
     * Translate a key
     */
    public static function t(string $key, array $params = []): string
    {
        $lang = self::getLang();
        if (empty(self::$loaded[$lang])) {
            self::loadLang($lang);
        }
        $str = self::$loaded[$lang][$key] ?? $key;
        foreach ($params as $k => $v) {
            $str = str_replace(':' . $k, (string) $v, $str);
        }
        return $str;
    }

    private static function loadLang(string $lang): void
    {
        $cacheKey = BASE_PATH . '/cache/translations_' . $lang . '.php';
        if (is_file($cacheKey)) {
            self::$loaded[$lang] = require $cacheKey;
            return;
        }
        $rows = DB::fetchAll('SELECT translation_key, value FROM translations WHERE lang = ?', [$lang]);
        $dict = [];
        foreach ($rows as $row) {
            $dict[$row['translation_key']] = $row['value'];
        }
        self::$loaded[$lang] = $dict;
    }
}
