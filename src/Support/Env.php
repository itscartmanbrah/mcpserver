<?php
declare(strict_types=1);

namespace App\Support;

final class Env
{
    /**
     * Minimal .env loader (no Composer required).
     * Loads KEY=VALUE lines into getenv(), $_ENV, and $_SERVER for this request.
     */
    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $name  = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip surrounding quotes if present
            if ($value !== '' && (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false) ? $default : (string)$v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key, '');
        if ($v === '') {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return $v;
    }
}
