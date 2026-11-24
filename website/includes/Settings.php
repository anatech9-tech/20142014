<?php
/**
 * Settings cache & helpers
 */

declare(strict_types=1);

class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, $default = null)
    {
        if (self::$cache === null) {
            self::load();
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, $value, ?int $userId = null): void
    {
        $type = self::detectType($value);
        $jsonValue = $type === 'json' ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;

        // Upsert
        $existing = DB::fetch('SELECT setting_key FROM site_settings WHERE setting_key = ?', [$key]);
        if ($existing) {
            DB::update('site_settings', [
                'setting_value' => $jsonValue,
                'setting_type'  => $type,
                'updated_by'    => $userId,
            ], 'setting_key = :k', ['k' => $key]);
        } else {
            DB::insert('site_settings', [
                'setting_key'   => $key,
                'setting_value' => $jsonValue,
                'setting_type'  => $type,
                'updated_by'    => $userId,
            ]);
        }
        self::$cache[$key] = $value;
    }

    public static function getAll(): array
    {
        if (self::$cache === null) self::load();
        return self::$cache;
    }

    public static function getByCategory(string $category): array
    {
        $rows = DB::fetchAll('SELECT * FROM site_settings WHERE category = ? ORDER BY setting_key', [$category]);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = self::castValue($row['setting_value'], $row['setting_type']);
        }
        return $out;
    }

    private static function load(): void
    {
        $rows = DB::fetchAll('SELECT setting_key, setting_value, setting_type FROM site_settings');
        self::$cache = [];
        foreach ($rows as $row) {
            self::$cache[$row['setting_key']] = self::castValue($row['setting_value'], $row['setting_type']);
        }
    }

    private static function castValue(?string $value, string $type)
    {
        if ($value === null) return null;
        switch ($type) {
            case 'boolean': return (bool) $value;
            case 'number':  return $value + 0;
            case 'json':    return json_decode($value, true);
            default:        return $value;
        }
    }

    private static function detectType($value): string
    {
        if (is_bool($value)) return 'boolean';
        if (is_int($value) || is_float($value)) return 'number';
        if (is_array($value)) return 'json';
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) return 'email';
        if (filter_var($value, FILTER_VALIDATE_URL)) return 'url';
        if (mb_strlen((string) $value) > 255) return 'longtext';
        return 'text';
    }
}
