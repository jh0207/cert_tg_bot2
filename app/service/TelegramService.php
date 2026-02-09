<?php

namespace app\service;

class TelegramService
{
    private string $token;
    private string $apiBase;

    public function __construct()
    {
        $config = config('tg');
        $this->token = $config['token'];
        $this->apiBase = rtrim($config['api_base'], '/');
    }

    public function sendMessage(int $chatId, string $text, ?array $inlineKeyboard = null, bool $disablePreview = true): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
        ];
        if ($inlineKeyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        $this->executeRequest('sendMessage', $url, $payload);
    }

    public function sendMessageWithReplyKeyboard(int $chatId, string $text, array $keyboard, bool $disablePreview = true): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $this->executeRequest('sendMessage', $url, $payload);
    }

    public function sendMessageWithReplyKeyboard(int $chatId, string $text, array $keyboard, bool $disablePreview = true): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => $disablePreview,
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }

    public function answerCallbackQuery(string $callbackId, string $text = ''): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/answerCallbackQuery';
        $payload = [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ];

        $this->executeRequest('answerCallbackQuery', $url, $payload);
    }

    public function setMyCommands(array $commands): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/setMyCommands';
        $payload = [
            'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE),
        ];

        $this->executeRequest('setMyCommands', $url, $payload);
    }

    private function executeRequest(string $action, string $url, array $payload): void
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logApiResult($action, $payload, $response, $httpCode, $curlErrno, $curlError);
    }

    private function logApiResult(
        string $action,
        array $payload,
        $response,
        int $httpCode,
        int $curlErrno,
        string $curlError
    ): void {
        $shouldLog = $curlErrno !== 0 || $httpCode >= 400;
        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['ok']) && $decoded['ok'] === false) {
                $shouldLog = true;
            }
        }

        if (!$shouldLog) {
            return;
        }

        $context = [
            'action' => $action,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'payload' => $payload,
        ];
        if (is_array($decoded)) {
            $context['response'] = $decoded;
        } elseif (is_string($response)) {
            $context['response_raw'] = $response;
        }

        $this->logDebug('telegram_api_error', $context);
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

    public function setMyCommands(array $commands): void
    {
        $url = $this->apiBase . '/bot' . $this->token . '/setMyCommands';
        $payload = [
            'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE),
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }
}
