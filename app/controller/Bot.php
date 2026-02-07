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
        if ($this->handlePendingInput($user, $message, $chatId, $text)) {
            return;
        }
        $domainInput = $this->extractCommandArgument($text, '/domain');

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
                    '',
                    '📌 <b>状态说明</b>',
                    'created：订单未完成，需选择证书类型并提交主域名。',
                    'dns_wait：已生成 TXT 记录，需完成 DNS 解析后点击验证。',
                    'dns_verified：DNS 已验证，点击验证继续签发。',
                    'issued：证书已签发，可下载文件。',
                ]);
                $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
            } else {
                $help = implode("\n", [
                    '📖 <b>使用帮助</b>',
                    '',
                    'created：请选择证书类型并提交主域名。',
                    'dns_wait：按提示添加 TXT 记录后点击「我已完成解析（验证）」。',
                    'dns_verified：DNS 已验证，继续点击验证完成签发。',
                    'issued：证书已签发，使用下方按钮下载。',
                    '',
                    '提示：任何时候都可以通过订单列表继续或取消订单。',
                ]);
                $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
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
            $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
            $messageText .= "✅ <b>根域名证书</b>：仅保护 example.com，不包含子域名。\n";
            $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
            $messageText .= "📌 请务必输入主域名（根域名），不要输入 www.example.com 或 *.example.com。";
            $this->telegram->sendMessage($chatId, $messageText, $keyboard);
            return;
        }

        if (strpos($text, '/domain') === 0) {
            if ($domainInput === null) {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入主域名，例如 <b>example.com</b>。');
                return;
            }

            $result = $this->certService->createOrder($message['from'], $domainInput);
            $keyboard = $this->resolveOrderKeyboard($result);
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
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if (strpos($text, '/status') === 0) {
            $domain = trim(str_replace('/status', '', $text));
            if ($domain === '') {
                $this->setPendingAction($message['from']['id'], 'await_status_domain');
                $this->telegram->sendMessage($chatId, '⚠️ 请输入要查询的域名，例如 <b>example.com</b>。');
                return;
            }
            $result = $this->certService->status($message['from'], $domain);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
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
                $prompt .= "不要输入 *.example.com 或 www.example.com";
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
            $keyboard = $this->buildIssuedKeyboard($orderId);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return;
        }

        if ($action === 'file') {
            $fileKey = $parts[1] ?? '';
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->getDownloadFileInfo($userId, $orderId, $fileKey);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $keyboard = $this->buildIssuedKeyboard($orderId);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
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
            $keyboard = $this->buildIssuedKeyboard($orderId);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return;
        }

        if ($action === 'created') {
            $subAction = $parts[1] ?? '';
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }

            if ($subAction === 'type') {
                $this->telegram->answerCallbackQuery($callbackId, '请选择证书类型');
                $keyboard = $this->buildTypeKeyboard($orderId);
                $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
                $messageText .= "✅ <b>根域名证书</b>：仅保护 example.com，不包含子域名。\n";
                $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                $messageText .= "📌 请务必输入主域名（根域名），不要输入 www.example.com 或 *.example.com。";
                $this->telegram->sendMessage($chatId, $messageText, $keyboard);
                return;
            }

            if ($subAction === 'domain') {
                $result = $this->certService->requestDomainInput($userId, $orderId);
                $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
                $this->telegram->sendMessage($chatId, $result['message']);
                return;
            }

            if ($subAction === 'retry') {
                $result = $this->certService->retryDnsChallenge($userId, $orderId);
                $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }
        }

        if ($action === 'cancel') {
            $userId = $this->getUserIdByTgId($from);
            if (!$userId) {
                $this->telegram->answerCallbackQuery($callbackId, '用户不存在，请先发送 /start');
                return;
            }
            $result = $this->certService->cancelOrder($userId, $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $this->telegram->sendMessage($chatId, $result['message'], $this->buildMainMenuKeyboard());
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
                $messageText = "你正在申请 SSL 证书，请选择证书类型👇\n";
                $messageText .= "✅ <b>根域名证书</b>：仅保护 example.com，不包含子域名。\n";
                $messageText .= "✅ <b>通配符证书</b>：保护 *.example.com，并同时包含 example.com。\n";
                $messageText .= "📌 请务必输入主域名（根域名），不要输入 www.example.com 或 *.example.com。";
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
                        '',
                        '📌 <b>状态说明</b>',
                        'created：订单未完成，需选择证书类型并提交主域名。',
                        'dns_wait：已生成 TXT 记录，需完成 DNS 解析后点击验证。',
                        'dns_verified：DNS 已验证，点击验证继续签发。',
                        'issued：证书已签发，可下载文件。',
                    ]);
                    $this->telegram->answerCallbackQuery($callbackId, '帮助已发送');
                    $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
                } else {
                    $this->telegram->answerCallbackQuery($callbackId, '已发送使用提示');
                    $this->telegram->sendMessage(
                        $chatId,
                        "📖 <b>使用帮助</b>\n\n" .
                        "created：请选择证书类型并提交主域名。\n" .
                        "dns_wait：按提示添加 TXT 记录后点击「我已完成解析（验证）」。\n" .
                        "dns_verified：DNS 已验证，继续点击验证完成签发。\n" .
                        "issued：证书已签发，使用下方按钮下载。\n\n" .
                        "提示：任何时候都可以通过订单列表继续或取消订单。",
                        $this->buildMainMenuKeyboard()
                    );
                }
                return;
            }

            if ($menuAction === 'orders') {
                $userId = $this->getUserIdByTgId($from);
                if ($userId) {
                    $this->clearPendingAction($userId);
                }
                $result = $this->certService->listOrders($from);
                $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
                $this->sendBatchMessages($chatId, $result);
                return;
            }
        }
    }

    private function buildTypeKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '根域名证书（example.com）', 'callback_data' => "type:root:{$orderId}"],
            ],
            [
                ['text' => '通配符证书（*.example.com + example.com）', 'callback_data' => "type:wildcard:{$orderId}"],
            ],
        ];
    }

    private function buildDnsKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '我已完成解析（验证）', 'callback_data' => "verify:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildCreatedKeyboard(array $order): array
    {
        $orderId = $order['id'];
        $buttons = [];
        $certTypeMissing = empty($order['cert_type']) || !in_array($order['cert_type'], ['root', 'wildcard'], true);
        if ($certTypeMissing) {
            $buttons[] = [
                ['text' => '选择证书类型', 'callback_data' => "created:type:{$orderId}"],
            ];
        } else {
            if (($order['domain'] ?? '') === '') {
                $buttons[] = [
                    ['text' => '提交主域名', 'callback_data' => "created:domain:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '重新选择证书类型', 'callback_data' => "created:type:{$orderId}"],
                ];
            } else {
                $buttons[] = [
                    ['text' => '重新生成 DNS 记录', 'callback_data' => "created:retry:{$orderId}"],
                ];
            }
        }
        $buttons[] = [
            ['text' => '取消订单', 'callback_data' => "cancel:{$orderId}"],
        ];

        return $buttons;
    }

    private function buildIssuedKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => 'fullchain.cer', 'callback_data' => "file:fullchain:{$orderId}"],
                ['text' => 'cert.cer', 'callback_data' => "file:cert:{$orderId}"],
                ['text' => 'key', 'callback_data' => "file:key:{$orderId}"],
            ],
            [
                ['text' => '查看证书信息', 'callback_data' => "info:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
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

    private function resolveOrderKeyboard(array $result): ?array
    {
        if (!isset($result['order'])) {
            return null;
        }

        $status = $result['order']['status'] ?? '';
        if (in_array($status, ['dns_wait', 'dns_verified'], true)) {
            return $this->buildDnsKeyboard($result['order']['id']);
        }

        if ($status === 'created') {
            return $this->buildCreatedKeyboard($result['order']);
        }

        if ($status === 'issued') {
            return $this->buildIssuedKeyboard($result['order']['id']);
        }

        return null;
    }

    private function handlePendingInput(array $user, array $message, int $chatId, string $text): bool
    {
        if ($user['pending_action'] === '') {
            return false;
        }

        if ($user['pending_action'] === 'await_domain') {
            $domainInput = $this->extractCommandArgument($text, '/domain');
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->telegram->sendMessage($chatId, '⚠️ 请先输入主域名，例如 <b>example.com</b>。');
                return true;
            }

            $domain = $domainInput ?? $text;
            $result = $this->certService->submitDomain($user['id'], $domain);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        if ($user['pending_action'] === 'await_status_domain') {
            $domainInput = $this->extractCommandArgument($text, '/status');
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->telegram->sendMessage($chatId, '⚠️ 请输入要查询的域名，例如 <b>example.com</b>。');
                return true;
            }

            $domain = $domainInput ?? $text;
            $result = $this->certService->status($message['from'], $domain);
            $this->clearPendingAction($user['id']);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        return false;
    }

    private function sendBatchMessages(int $chatId, array $result): void
    {
        if (isset($result['messages']) && is_array($result['messages'])) {
            foreach ($result['messages'] as $message) {
                $text = $message['text'] ?? '';
                if ($text === '') {
                    continue;
                }
                $keyboard = $message['keyboard'] ?? null;
                $this->telegram->sendMessage($chatId, $text, $keyboard);
            }
            return;
        }

        if (isset($result['message'])) {
            $this->telegram->sendMessage($chatId, $result['message']);
        }
    }
}
