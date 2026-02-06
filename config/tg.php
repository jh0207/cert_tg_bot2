<?php

$ownerLock = getenv('OWNER_LOCK');

return [
    'token' => getenv('TG_BOT_TOKEN') ?: 'change-me',
    'api_base' => getenv('TG_API_BASE') ?: 'https://api.telegram.org',
    'owner_lock' => $ownerLock === false ? true : filter_var($ownerLock, FILTER_VALIDATE_BOOLEAN),
    'acme_path' => getenv('ACME_PATH') ?: '/root/.acme.sh/acme.sh',
    'acme_server' => getenv('ACME_SERVER') ?: 'letsencrypt',
    'cert_export_path' => getenv('CERT_EXPORT_PATH') ?: '/www/wwwroot/cert.com/ssl/',
];
