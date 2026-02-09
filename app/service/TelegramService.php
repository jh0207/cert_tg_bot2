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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
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
