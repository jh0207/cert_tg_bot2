<?php

require_once __DIR__ . '/env.php';

$ownerLock = env_value('OWNER_LOCK');

return [
    'token' => env_value('TG_BOT_TOKEN', ''),
    'api_base' => env_value('TG_API_BASE', 'https://api.telegram.org'),
    'owner_lock' => $ownerLock === null ? true : filter_var($ownerLock, FILTER_VALIDATE_BOOLEAN),
    'acme_path' => env_value('ACME_PATH', '/root/.acme.sh/acme.sh'),
    'acme_server' => env_value('ACME_SERVER', 'letsencrypt'),
    'cert_export_path' => env_value('CERT_EXPORT_PATH', '/www/wwwroot/cert.com/ssl/'),
    'cert_download_base_url' => env_value('CERT_DOWNLOAD_BASE_URL', 'https://cert.com/ssl'),
];
