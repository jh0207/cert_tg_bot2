<?php

require_once __DIR__ . '/env.php';

$dbDebug = env_value('DB_DEBUG');

return [
    'default'         => 'mysql',
    'time_query_rule' => [],
    'connections'     => [
        'mysql' => [
            'type'            => 'mysql',
            'hostname'        => env_value('DB_HOST', '127.0.0.1'),
            'database'        => env_value('DB_NAME', 'tg_cert_bot'),
            'username'        => env_value('DB_USER', 'root'),
            'password'        => env_value('DB_PASS', ''),
            'hostport'        => env_value('DB_PORT', '3306'),
            'charset'         => 'utf8mb4',
            'prefix'          => '',
            'debug'           => $dbDebug === null ? true : filter_var($dbDebug, FILTER_VALIDATE_BOOLEAN),
            'fields_strict'   => true,
            'resultset_type'  => 'array',
            'auto_timestamp'  => true,
            'datetime_format' => 'Y-m-d H:i:s',
        ],
    ],
];
