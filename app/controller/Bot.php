<?php

namespace app\controller;

use app\service\AuthService;
use app\service\TelegramService;
use app\service\AcmeService;
use app\service\DnsService;
use app\service\CertService;
use app\model\TgUser;

class Bot
{
    private TelegramService $telegram;
    private AuthService $auth;
    private CertService $certService;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
        $this->auth = new AuthService();
        $this->certService = new CertService(new AcmeService(), new DnsService());
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        if (!$chatId || $text === '') {
            return;
        }

        $this->auth->startUser($message['from']);
        $userRecord = TgUser::where('tg_id', $message['from']['id'])->find();
        if (!$userRecord) {
            return;
        }
        $user = $userRecord->toArray();
        $domainInput = $this->extractCommandArgument($text, '/domain');
        if ($user['pending_action'] === 'await_domain') {
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入主域名，例如 <b>example.com</b>。');
                return;
            }

            $domain = $domainInput ?? $text;
            $result = $this->certService->submitDomain($user['id'], $domain);
            $keyboard = $this->resolveDnsKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return;
        }

        if ($user['pending_action'] === 'await_status_domain' && strpos($text, '/') !== 0) {
            $result = $this->certService->status($message['from'], $text);
            $this->clearPendingAction($user['id']);
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        if (strpos($text, '/start') === 0) {
            $role = $user['role'];
            $messageText = "👋 <b>欢迎使用证书机器人</b>\n";
            $messageText .= "当前角色：<b>{$role}</b>\n\n";
            $messageText .= "请选择操作👇";
            $this->telegram->sendMessage($chatId, $messageText, $this->buildMainMenuKeyboard());
            return;
        }

        if (strpos($text, '/help') === 0) {
            if ($this->auth->isAdmin($message['from']['id'])) {
                $help = implode("\n", [
                    '🛠️ <b>管理员指令大全</b>',
                    '',
                    '/new 申请证书（进入选择类型流程）',
                    '/domain example.com 快速申请根域名证书',
                    '/verify example.com DNS 解析完成后验证并签发',
                    '/status example.com 查看订单状态',
                    '/quota add <tg_id> <次数> 追加申请次数',
                ]);
                $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
            } else {
                $this->telegram->sendMessage($chatId, '✅ 请使用下方按钮菜单进行操作。', $this->buildMainMenuKeyboard());
            }
            return;
        }

        if (strpos($text, '/new') === 0) {
            $result = $this->certService->startOrder($message['from']);
            if (!$result['success']) {
                $this->telegram->sendMessage($chatId, $result['message']);
                return;
            }

            $orderId = $result['order']['id'];
            $keyboard = $this->buildTypeKeyboard($orderId);
            $messageText = "你正在申请 SSL 证书，请选择证书类型。\n";
            $messageText .= "✅ <b>根域名证书</b>：仅保护 example.com，不包含子域名。\n";
            $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
            $messageText .= "📌 通配符证书必须使用 DNS TXT 验证，当前系统仅支持 DNS 手动解析。";
            $this->telegram->sendMessage($chatId, $messageText, $keyboard);
            return;
        }

        if (strpos($text, '/domain') === 0) {
            if ($domainInput === null) {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入主域名，例如 <b>example.com</b>。');
                return;
            }

            $result = $this->certService->createOrder($message['from'], $domainInput);
            $keyboard = $this->resolveDnsKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return;
        }

        if (strpos($text, '/verify') === 0) {
            $domain = trim(str_replace('/verify', '', $text));
            if ($domain === '') {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入要验证的域名，例如 <b>example.com</b>。');
                return;
            }
            $result = $this->certService->verifyOrder($message['from'], $domain);
            if (($result['success'] ?? false) && isset($result['order'])) {
                $keyboard = $this->buildIssuedKeyboard($result['order']['id']);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if (strpos($text, '/status') === 0) {
            $domain = trim(str_replace('/status', '', $text));
            if ($domain === '') {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入要查询的域名，例如 <b>example.com</b>。');
                return;
            }
            $result = $this->certService->status($message['from'], $domain);
            if ($user['pending_action'] === 'await_status_domain') {
                $this->clearPendingAction($user['id']);
            }
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        if (strpos($text, '/quota') === 0) {
            if (!$this->auth->isAdmin($message['from']['id'])) {
                $this->telegram->sendMessage($chatId, '❌ 仅管理员可调整申请次数。');
                return;
            }

            $parts = preg_split('/\s+/', trim($text));
            if (count($parts) < 4 || $parts[1] !== 'add') {
                $this->telegram->sendMessage($chatId, '⚠️ 用法：/quota add <tg_id> <次数>');
                return;
            }

            $targetId = (int) $parts[2];
            $amount = (int) $parts[3];
            if ($targetId <= 0 || $amount <= 0) {
                $this->telegram->sendMessage($chatId, '⚠️ tg_id 和次数必须是正整数。');
                return;
            }

            $target = TgUser::where('tg_id', $targetId)->find();
            if (!$target) {
                $this->telegram->sendMessage($chatId, '❌ 用户不存在。');
                return;
            }

            $current = (int) $target['apply_quota'];
            $newQuota = $current + $amount;
            $target->save(['apply_quota' => $newQuota]);
            $this->telegram->sendMessage(
                $chatId,
                "✅ 已为用户 <b>{$targetId}</b> 增加 <b>{$amount}</b> 次申请额度（当前剩余 {$newQuota} 次）。"
            );
            return;
        }

        $this->telegram->sendMessage($chatId, '🤔 未知指令，点击下方菜单或发送 /help 查看指令。', $this->buildMainMenuKeyboard());
    }

    private function handleCallback(array $callback): void
    {
        $data = $callback['data'] ?? '';
        $from = $callback['from'] ?? [];
        $chatId = $callback['message']['chat']['id'] ?? null;
        $callbackId = $callback['id'] ?? '';

        if (!$chatId || $data === '') {
            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $orderId = isset($parts[2]) ? (int) $parts[2] : (isset($parts[1]) ? (int) $parts[1] : 0);

        if ($action === 'type') {
            $type = $parts[1] ?? 'root';
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->setOrderType($userId, $orderId, $type);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            if ($result['success']) {
                $prompt = "📝 请输入主域名，例如 <b>example.com</b>。\n";
                $prompt .= "不要输入 http:// 或 https://\n";
                $prompt .= "不要输入 *.example.com";
                $this->telegram->sendMessage($chatId, $prompt);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if ($action === 'verify') {
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->verifyOrderById($userId, $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            if (($result['success'] ?? false) && isset($result['order'])) {
                $keyboard = $this->buildIssuedKeyboard($result['order']['id']);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if ($action === 'later') {
            $this->telegram->answerCallbackQuery($callbackId, '已记录，你可稍后再验证。');
            $this->telegram->sendMessage($chatId, '✅ 好的，稍后完成解析后再点击验证即可。');
            return;
        }

        if ($action === 'download') {
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->getDownloadInfo($userId, $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        if ($action === 'info') {
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->getCertificateInfo($userId, $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        if ($action === 'menu') {
            $menuAction = $parts[1] ?? '';
            if ($menuAction === 'new') {
                $result = $this->certService->startOrder($from);
                $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
                if (!$result['success']) {
                    $this->telegram->sendMessage($chatId, $result['message']);
                    return;
                }

                $keyboard = $this->buildTypeKeyboard($result['order']['id']);
                $messageText = "你正在申请 SSL 证书，请选择证书类型。\n";
                $messageText .= "✅ <b>根域名证书</b>：仅保护 example.com，不包含子域名。\n";
                $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                $messageText .= "📌 通配符证书必须使用 DNS TXT 验证，当前系统仅支持 DNS 手动解析。";
                $this->telegram->sendMessage($chatId, $messageText, $keyboard);
                return;
            }

            if ($menuAction === 'status') {
                $this->setPendingAction($from['id'], 'await_status_domain');
                $this->telegram->answerCallbackQuery($callbackId, '请输入要查询的域名');
                $this->telegram->sendMessage($chatId, '🔎 请输入要查询的域名，例如 <b>example.com</b>。');
                return;
            }

            if ($menuAction === 'help') {
                if ($this->auth->isAdmin($from['id'])) {
                    $help = implode("\n", [
                        '🛠️ <b>管理员指令大全</b>',
                        '',
                        '/new 申请证书（进入选择类型流程）',
                        '/domain example.com 快速申请根域名证书',
                        '/verify example.com DNS 解析完成后验证并签发',
                        '/status example.com 查看订单状态',
                        '/quota add <tg_id> <次数> 追加申请次数',
                    ]);
                    $this->telegram->answerCallbackQuery($callbackId, '帮助已发送');
                    $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
                } else {
                    $this->telegram->answerCallbackQuery($callbackId, '请使用按钮菜单');
                    $this->telegram->sendMessage($chatId, '✅ 请使用下方按钮菜单进行操作。', $this->buildMainMenuKeyboard());
                }
                return;
            }

            if ($menuAction === 'orders') {
                $result = $this->certService->listOrders($from);
                $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
                $this->telegram->sendMessage($chatId, $result['message']);
                return;
            }
        }
    }

    private function buildTypeKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '仅根域名证书（example.com）', 'callback_data' => "type:root:{$orderId}"],
            ],
            [
                ['text' => '通配符证书（*.example.com，包含根域名）', 'callback_data' => "type:wildcard:{$orderId}"],
            ],
        ];
    }

    private function buildDnsKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '我已完成解析', 'callback_data' => "verify:{$orderId}"],
                ['text' => '稍后再说', 'callback_data' => "later:{$orderId}"],
            ],
        ];
    }

    private function buildIssuedKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '下载证书', 'callback_data' => "download:{$orderId}"],
                ['text' => '查看证书信息', 'callback_data' => "info:{$orderId}"],
            ],
        ];
    }

    private function buildMainMenuKeyboard(): array
    {
        return [
            [
                ['text' => '🆕 申请证书', 'callback_data' => 'menu:new'],
                ['text' => '🔎 查询状态', 'callback_data' => 'menu:status'],
            ],
            [
                ['text' => '📂 订单记录', 'callback_data' => 'menu:orders'],
                ['text' => '📖 使用帮助', 'callback_data' => 'menu:help'],
            ],
        ];
    }

    private function extractCommandArgument(string $text, string $command): ?string
    {
        if (strpos($text, $command) !== 0) {
            return null;
        }

        $argument = trim(substr($text, strlen($command)));
        return $argument === '' ? null : $argument;
    }

    private function setPendingAction(int $userId, string $action): void
    {
        $user = TgUser::where('tg_id', $userId)->find();
        if (!$user) {
            return;
        }

        $user->save(['pending_action' => $action, 'pending_order_id' => 0]);
    }

    private function clearPendingAction(int $userId): void
    {
        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return;
        }

        $user->save(['pending_action' => '', 'pending_order_id' => 0]);
    }

    private function getUserIdByTgId(array $from): ?int
    {
        if (!isset($from['id'])) {
            return null;
        }

        $this->auth->startUser($from);
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return null;
        }

        return (int) $user['id'];
    }

    private function resolveDnsKeyboard(array $result): ?array
    {
        if (!isset($result['order'])) {
            return null;
        }

        $status = $result['order']['status'] ?? '';
        if ($status === 'dns_wait') {
            return $this->buildDnsKeyboard($result['order']['id']);
        }

        return null;
    }
}
