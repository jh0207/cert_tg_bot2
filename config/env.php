<?php

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null)
    {
        static $loaded = false;
        static $fileValues = [];

        if (!$loaded) {
            $loaded = true;
            $envPath = dirname(__DIR__) . '/.env';
            if (is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }

                    $envKey = trim($parts[0]);
                    $envValue = trim($parts[1]);
                    if ($envKey === '') {
                        continue;
                    }

                    $valueLength = strlen($envValue);
                    if ($valueLength >= 2) {
                        $firstChar = $envValue[0];
                        $lastChar = $envValue[$valueLength - 1];
                        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                            $envValue = substr($envValue, 1, -1);
                        }
                    }

                    $fileValues[$envKey] = $envValue;
                }
            }
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        if (array_key_exists($key, $fileValues)) {
            return $fileValues[$key];
        }

        return $default;
    }
}
