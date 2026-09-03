<?php
/**
 * Минимальная загрузка .env без внешних зависимостей.
 * Не перезаписывает уже заданные переменные окружения (сервер/Docker).
 */
declare(strict_types=1);

function finbuild_load_env(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = $path ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }

        // Уже задано в окружении сервера — не перезаписываем
        if (getenv($name) !== false) {
            continue;
        }

        // Убираем кавычки "..." или '...'
        $len = strlen($value);
        if ($len >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && $value[$len - 1] === $q) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

/** Строка из окружения с запасным значением. */
function finbuild_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return (string) $value;
}

/** Булево значение: true/1/yes/on. */
function finbuild_env_bool(string $key, bool $default = false): bool
{
    $raw = strtolower(trim(finbuild_env($key, $default ? '1' : '0')));
    if ($raw === '') {
        return $default;
    }
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

/** Целое число из окружения. */
function finbuild_env_int(string $key, int $default = 0): int
{
    $raw = trim(finbuild_env($key, (string) $default));
    return is_numeric($raw) ? (int) $raw : $default;
}
