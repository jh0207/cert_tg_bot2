<?php

namespace app\controller;

use app\service\TelegramService;
use think\Request;

class Webhook
{
    public function handle(Request $request)
    {
        $payload = $request->getInput();
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return json(['ok' => false]);
        }

        $response = json(['ok' => true]);
        if (function_exists('fastcgi_finish_request')) {
            $response->send();
            fastcgi_finish_request();
        }

        $bot = new Bot(new TelegramService());
        $bot->handleUpdate($data);

        return $response;
    }
}
