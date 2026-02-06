<?php

require_once __DIR__ . '/env.php';

return [
    // 应用调试模式
    'app_debug' => filter_var(env_value('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    // 默认应用
    'default_app' => 'index',
    // 应用命名空间
    'app_namespace' => 'app',
    // 应用时区
    'default_timezone' => 'Asia/Shanghai',
    // 是否开启路由
    'with_route' => true,
    // 路由是否缓存
    'route_check_cache' => false,
    // 默认 URL 模式
    'pathinfo_depr' => '/',
    // URL 伪静态后缀
    'url_html_suffix' => '',
    // 错误显示信息
    'error_message' => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg' => true,
];
