<?php

return [
    // 默认日志通道
    'default' => 'file',
    // 日志通道列表
    'channels' => [
        'file' => [
            'type' => 'File',
            'path' => runtime_path() . 'log',
            'level' => [],
            'single' => false,
            'apart_level' => [],
        ],
    ],
];
