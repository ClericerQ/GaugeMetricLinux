<?php

class cConfig {
    private static array $data = [];

    private static string $baseDir = '';

    private static string $file = '';

    public static function load(string $file): void {
        self::$file    = $file;
        self::$baseDir = dirname($file) . '/';

        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException("cConfig: cannot read $file");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("cConfig: invalid JSON in $file: " . json_last_error_msg());
        }

        self::$data = $data;

        if (!defined('CONFIG')) {
            define('CONFIG', self::resolve($data));
        }
    }

    public static function all(): array {
        return self::$data;
    }

    public static function get(string $path, mixed $default = null): mixed {
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public static function set(string $path, mixed $value): void {
        $keys = explode('.', $path);
        $last = array_pop($keys);

        $node = &self::$data;
        foreach ($keys as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node[$last] = $value;
        unset($node);
    }

    public static function save(): bool {
        $json = json_encode(
            self::$data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return false;
        }

        $tmp = self::$file . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return false;
        }
        return @rename($tmp, self::$file);
    }

    private static function resolve(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::resolve($value);
            } elseif (is_string($value)) {
                $data[$key] = str_replace('{dir}', self::$baseDir, $value);
            }
        }
        return $data;
    }
}
