<?php

$dbDebug = getenv('DB_DEBUG');

return [
    'default'         => 'mysql',
    'time_query_rule' => [],
    'connections'     => [
        'mysql' => [
            'type'            => 'mysql',
            'hostname'        => getenv('DB_HOST') ?: '127.0.0.1',
            'database'        => getenv('DB_NAME') ?: 'tg_cert_bot',
            'username'        => getenv('DB_USER') ?: 'root',
            'password'        => getenv('DB_PASS') ?: '',
            'hostport'        => getenv('DB_PORT') ?: '3306',
            'charset'         => 'utf8mb4',
            'prefix'          => '',
            'debug'           => $dbDebug === false ? true : filter_var($dbDebug, FILTER_VALIDATE_BOOLEAN),
            'fields_strict'   => true,
            'resultset_type'  => 'array',
            'auto_timestamp'  => true,
            'datetime_format' => 'Y-m-d H:i:s',
        ],
    ],
];
