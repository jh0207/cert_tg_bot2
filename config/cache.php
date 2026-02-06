<?php

require_once __DIR__ . '/env.php';

$cacheDriver = env_value('CACHE_DRIVER', 'file');

return [
    // 默认缓存驱动
    'default' => $cacheDriver,
    // 缓存连接方式配置
    'stores' => [
        'file' => [
            'type' => 'File',
            'path' => env_value('CACHE_PATH', runtime_path() . 'cache'),
            'prefix' => env_value('CACHE_PREFIX', ''),
            'expire' => 0,
        ],
    ],
];
