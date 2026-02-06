<?php

$cacheDriver = getenv('CACHE_DRIVER') ?: 'file';

return [
    // 默认缓存驱动
    'default' => $cacheDriver,
    // 缓存连接方式配置
    'stores' => [
        'file' => [
            'type' => 'File',
            'path' => getenv('CACHE_PATH') ?: runtime_path() . 'cache',
            'prefix' => getenv('CACHE_PREFIX') ?: '',
            'expire' => 0,
        ],
    ],
];
