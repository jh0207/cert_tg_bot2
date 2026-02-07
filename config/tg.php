<?php

require_once __DIR__ . '/env.php';

$ownerLock = env_value('OWNER_LOCK');

return [
    'token' => env_value('TG_BOT_TOKEN', ''),
    'api_base' => env_value('TG_API_BASE', 'https://api.telegram.org'),
    'owner_lock' => $ownerLock === null ? true : filter_var($ownerLock, FILTER_VALIDATE_BOOLEAN),
    'acme_path' => env_value('ACME_PATH', '/root/.acme.sh/acme.sh'),
    'acme_server' => env_value('ACME_SERVER', 'letsencrypt'),
    'acme_retry_limit' => (int) env_value('ACME_RETRY_LIMIT', 3),
    'cert_export_path' => env_value(
        'CERT_EXPORT_PATH',
        (function () {
            $base = function_exists('public_path')
                ? public_path()
                : (function_exists('root_path') ? root_path() . 'public' . DIRECTORY_SEPARATOR : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
            return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR;
        })()
    ),
    'cert_download_base_url' => env_value('CERT_DOWNLOAD_BASE_URL', 'https://cert.com/ssl'),
];
