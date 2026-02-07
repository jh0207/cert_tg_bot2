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
        $this->logDebug('webhook_received', [
            'type' => is_array($data) ? (isset($data['callback_query']) ? 'callback' : 'message') : 'invalid',
        ]);

        $this->sendFastResponse();

        $bot = new Bot(new TelegramService());
        if (is_array($data)) {
            $bot->handleUpdate($data);
        } else {
            $this->logDebug('webhook_invalid_payload', ['payload' => $payload]);
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        return response('ok');
    }

    private function sendFastResponse(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache');
        }
        echo 'ok';
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_flush();
        @flush();
    }

    private function logDebug(string $message, array $context = []): void
    {
        $logFile = $this->resolveLogFile();
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function resolveLogFile(): string
    {
        $base = function_exists('root_path') ? root_path() : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $logDir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'tg_bot.log';
    }
}
