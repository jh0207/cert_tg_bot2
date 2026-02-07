<?php

namespace app\controller;

use app\service\AuthService;
use app\service\TelegramService;
use app\service\AcmeService;
use app\service\DnsService;
use app\service\CertService;
use app\model\ActionLog;
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
        try {
            $this->logDebug('update_received', [
                'type' => isset($update['callback_query']) ? 'callback' : (isset($update['message']) ? 'message' : 'other'),
                'update_id' => $update['update_id'] ?? null,
            ]);
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
            $this->logDebug('message_received', [
                'chat_id' => $chatId,
                'tg_id' => $message['from']['id'] ?? null,
                'text' => $text,
            ]);
            $user = $userRecord->toArray();
            if ($this->handlePendingInput($user, $message, $chatId, $text)) {
                return;
            }

            if ($this->handleFallbackDomainInput($user, $message, $chatId, $text)) {
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
                        '/diag 查看诊断信息（Owner 专用）',
                        '/quota add <tg_id> <次数> 追加申请次数',
                        '',
                        '📌 <b>常用按钮</b>',
                        '🆕 申请证书 / 🔎 查询状态 / 📂 订单记录 / 📖 使用帮助',
                        'created 阶段：选择证书类型、提交主域名、提交生成 DNS 记录任务、取消订单',
                        'dns_wait 阶段：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单',
                        'dns_verified 阶段：等待后台签发 / 刷新状态',
                        'issued 阶段：下载文件、查看证书信息、查看文件路径/重新导出',
                        '',
                        '📌 <b>状态说明</b>',
                        'created：订单未完成，需选择证书类型并提交主域名。',
                        'dns_wait：已生成 TXT 记录，需完成 DNS 解析后点击验证。',
                        'dns_verified：DNS 已验证，系统自动签发，等待完成。',
                        'issued：证书已签发，可下载文件。',
                    ]);
                    $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
                } else {
                    $help = implode("\n", [
                        '📖 <b>使用帮助</b>',
                        '',
                        '📌 <b>常用按钮</b>',
                        '🆕 申请证书 / 🔎 查询状态 / 📂 订单记录 / 📖 使用帮助',
                        'created：选择证书类型、提交主域名、提交生成 DNS 记录任务、取消订单',
                        'dns_wait：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单',
                        'dns_verified：🔄 刷新状态',
                        'issued：下载文件、查看证书信息、查看文件路径/重新导出',
                        '',
                        'created：请选择证书类型并提交主域名。',
                        'dns_wait：按提示添加 TXT 记录后点击「我已完成解析（验证）」。',
                        'dns_verified：DNS 已验证，系统自动签发，请稍后刷新状态。',
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

                $this->sendProcessingMessage($chatId, '✅ 任务已提交，稍后展示 DNS TXT 记录。');
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
                $this->sendVerifyProcessingMessageByDomain($chatId, $user['id'], $domain);
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

            if (strpos($text, '/diag') === 0) {
                if (!$this->auth->isOwner($message['from']['id'])) {
                    $this->telegram->sendMessage($chatId, '❌ 仅 Owner 可使用该命令。');
                    return;
                }
                $diag = $this->buildDiagMessage($user['id']);
                $this->telegram->sendMessage($chatId, $diag, $this->buildMainMenuKeyboard());
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
        } catch (\Throwable $e) {
            $this->logDebug('message_exception', [
                'update_id' => $update['update_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $message = $update['message'] ?? [];
            $chatId = $message['chat']['id'] ?? null;
            $from = $message['from'] ?? [];
            $userRecord = isset($from['id']) ? TgUser::where('tg_id', $from['id'])->find() : null;
            if ($userRecord) {
                $pendingOrderId = (int) ($userRecord['pending_order_id'] ?? 0);
                if ($pendingOrderId > 0) {
                    $this->certService->recordOrderError((int) $userRecord['id'], $pendingOrderId, $e->getMessage());
                } else {
                    $latestOrder = $this->certService->findLatestOrder((int) $userRecord['id']);
                    if ($latestOrder) {
                        $this->certService->recordOrderError((int) $userRecord['id'], (int) $latestOrder['id'], $e->getMessage());
                    }
                }
            }
            if ($chatId) {
                $this->telegram->sendMessage($chatId, '❌ 系统异常，请稍后重试或联系管理员。');
            }
        }
    }

    private function handleCallback(array $callback): void
    {
        $data = $callback['data'] ?? '';
        $from = $callback['from'] ?? [];
        $chatId = $callback['message']['chat']['id'] ?? null;
        $callbackId = $callback['id'] ?? '';

        $callbackState = ['answered' => false];
        $this->answerCallbackOnce($callbackId, '✅ 已收到，正在处理…', $callbackState);

        if (!$chatId || $data === '') {
            return;
        }

        $this->logDebug('callback_received', ['data' => $data]);

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $orderId = isset($parts[2]) ? (int) $parts[2] : (isset($parts[1]) ? (int) $parts[1] : 0);
        try {
            if ($action === 'type') {
                $type = $parts[1] ?? 'root';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->setOrderType($userId, $orderId, $type);
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
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $this->sendVerifyProcessingMessageById($chatId, $userId, $orderId);
                $result = $this->certService->verifyOrderById($userId, $orderId);
                if (($result['success'] ?? false) && isset($result['order'])) {
                    $keyboard = $this->resolveOrderKeyboard($result);
                    $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                } else {
                    $this->telegram->sendMessage($chatId, $result['message']);
                }
                return;
            }

            if ($action === 'later') {
                $this->telegram->sendMessage($chatId, '✅ 好的，稍后完成解析后再点击验证即可。');
                return;
            }

            if ($action === 'download') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->getDownloadInfo($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'reinstall') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $this->sendProcessingMessage($chatId, '✅ 重新导出任务已提交，请稍后查看。');
                $result = $this->certService->reinstallCert($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'file') {
                $fileKey = $parts[1] ?? '';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->getDownloadFileInfo($userId, $orderId, $fileKey);
                $keyboard = $this->buildIssuedKeyboard($orderId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'info') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->getCertificateInfo($userId, $orderId);
                $keyboard = $this->buildIssuedKeyboard($orderId);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'status') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->statusById($userId, $orderId);
                $keyboard = $this->resolveOrderKeyboard($result);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                return;
            }

            if ($action === 'created') {
                $subAction = $parts[1] ?? '';
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }

                if ($subAction === 'type') {
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
                    $this->telegram->sendMessage($chatId, $result['message']);
                    return;
                }

                if ($subAction === 'retry') {
                    $this->sendProcessingMessage($chatId, '✅ 任务已提交，稍后展示 DNS TXT 记录。');
                    $result = $this->certService->retryDnsChallenge($userId, $orderId);
                    $keyboard = $this->resolveOrderKeyboard($result);
                    $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
                    return;
                }
            }

            if ($action === 'cancel') {
                $userId = $this->getUserIdByTgId($from);
                if (!$userId) {
                    $this->telegram->sendMessage($chatId, '❌ 用户不存在，请先发送 /start');
                    return;
                }
                $result = $this->certService->cancelOrder($userId, $orderId);
                $this->telegram->sendMessage($chatId, $result['message'], $this->buildMainMenuKeyboard());
                return;
            }

            if ($action === 'menu') {
                $menuAction = $parts[1] ?? '';
                if ($menuAction === 'new') {
                    $result = $this->certService->startOrder($from);
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
                            '/diag 查看诊断信息（Owner 专用）',
                            '/quota add <tg_id> <次数> 追加申请次数',
                            '',
                            '📌 <b>常用按钮</b>',
                            '🆕 申请证书 / 📂 我的订单 / 📖 使用帮助',
                            'created 阶段：选择证书类型、提交主域名、提交生成 DNS 记录任务、取消订单',
                            'dns_wait 阶段：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单',
                            'dns_verified 阶段：等待后台签发 / 刷新状态',
                            'issued 阶段：下载文件、查看证书信息、重新导出',
                            '',
                            '📌 <b>状态说明</b>',
                            'created：订单未完成，需选择证书类型并提交主域名。',
                            'dns_wait：已生成 TXT 记录，需完成 DNS 解析后点击验证。',
                            'dns_verified：DNS 已验证，系统自动签发，等待完成。',
                            'issued：证书已签发，可下载文件。',
                        ]);
                        $this->telegram->sendMessage($chatId, $help, $this->buildMainMenuKeyboard());
                    } else {
                        $this->telegram->sendMessage(
                            $chatId,
                            "📖 <b>使用帮助</b>\n\n" .
                            "📌 <b>常用按钮</b>\n" .
                            "🆕 申请证书 / 📂 我的订单 / 📖 使用帮助\n" .
                            "created：选择证书类型、提交主域名、提交生成 DNS 记录任务、取消订单\n" .
                            "dns_wait：✅ 我已解析，开始验证 / 🔁 重新生成DNS记录 / ❌ 取消订单\n" .
                            "dns_verified：🔄 刷新状态 / ❌ 取消订单\n" .
                            "issued：下载文件、查看证书信息、重新导出\n\n" .
                            "created：请选择证书类型并提交主域名。\n" .
                            "dns_wait：按提示添加 TXT 记录后点击「✅ 我已解析，开始验证」。\n" .
                            "dns_verified：DNS 已验证，系统自动签发，请稍后刷新状态。\n" .
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
                    $this->sendBatchMessages($chatId, $result);
                    return;
                }
            }

            $this->logDebug('callback_unknown', ['data' => $data]);
            $this->telegram->sendMessage($chatId, '⚠️ 按钮已过期或无法识别，请返回订单列表重试。', $this->buildMainMenuKeyboard());
        } catch (\Throwable $e) {
            $this->logDebug('callback_exception', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);
            $userId = $this->getUserIdByTgId($from);
            if ($userId && $orderId) {
                $this->certService->recordOrderError($userId, $orderId, $e->getMessage());
                $order = $this->certService->findOrderById($userId, $orderId);
                $keyboard = $order ? $this->resolveOrderKeyboard(['order' => $order]) : $this->buildMainMenuKeyboard();
                $this->telegram->sendMessage($chatId, "❌ 操作失败：{$e->getMessage()}\n请重试或取消订单。", $keyboard);
                return;
            }
            $this->telegram->sendMessage($chatId, "❌ 操作失败：{$e->getMessage()}\n请稍后重试。", $this->buildMainMenuKeyboard());
        }

        $this->telegram->sendMessage($chatId, '⚠️ 未识别的操作，请返回菜单重试。', $this->buildMainMenuKeyboard());
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

    private function buildDnsKeyboard(int $orderId, string $status): array
    {
        if ($status === 'dns_verified') {
            return [
                [
                    ['text' => '🔄 刷新状态', 'callback_data' => "status:{$orderId}"],
                ],
                [
                    ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
                ],
                [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ],
            ];
        }

        return [
            [
                ['text' => '✅ 我已解析，开始验证', 'callback_data' => "verify:{$orderId}"],
            ],
            [
                ['text' => '🔁 重新生成DNS记录', 'callback_data' => "created:retry:{$orderId}"],
                ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
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
            if ((int) ($order['need_dns_generate'] ?? 0) === 1) {
                $buttons[] = [
                    ['text' => '🔄 刷新状态', 'callback_data' => "status:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ];
                return $buttons;
            }
            if (($order['domain'] ?? '') === '') {
                $buttons[] = [
                    ['text' => '提交主域名', 'callback_data' => "created:domain:{$orderId}"],
                ];
                $buttons[] = [
                    ['text' => '重新选择证书类型', 'callback_data' => "created:type:{$orderId}"],
                ];
            } else {
                $buttons[] = [
                    ['text' => '提交生成 DNS 记录任务', 'callback_data' => "created:retry:{$orderId}"],
                ];
            }
        }
        $buttons[] = [
            ['text' => '重新申请证书', 'callback_data' => 'menu:new'],
        ];
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
                ['text' => 'key.key', 'callback_data' => "file:key:{$orderId}"],
                ['text' => 'ca.cer', 'callback_data' => "file:ca:{$orderId}"],
            ],
            [
                ['text' => '查看证书信息', 'callback_data' => "info:{$orderId}"],
                ['text' => '查看文件路径', 'callback_data' => "download:{$orderId}"],
            ],
            [
                ['text' => '重新导出', 'callback_data' => "reinstall:{$orderId}"],
            ],
            [
                ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
            ],
        ];
    }

    private function buildFailedKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '🆕 重新申请证书', 'callback_data' => 'menu:new'],
            ],
            [
                ['text' => '❌ 取消订单', 'callback_data' => "cancel:{$orderId}"],
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
                ['text' => '📂 我的订单', 'callback_data' => 'menu:orders'],
            ],
            [
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

        $order = $this->normalizeOrder($result['order']);
        if ($order === []) {
            return null;
        }

        $status = $order['status'] ?? '';
        if (in_array($status, ['dns_wait', 'dns_verified'], true)) {
            return $this->buildDnsKeyboard($order['id'], $status);
        }

        if ($status === 'created') {
            return $this->buildCreatedKeyboard($order);
        }

        if ($status === 'issued') {
            return $this->buildIssuedKeyboard($order['id']);
        }

        if ($status === 'failed') {
            return $this->buildFailedKeyboard($order['id']);
        }

        return null;
    }

    private function normalizeOrder($order): array
    {
        if (is_array($order)) {
            return $order;
        }

        if (is_object($order)) {
            if (method_exists($order, 'toArray')) {
                $array = $order->toArray();
                return is_array($array) ? $array : [];
            }

            if ($order instanceof \ArrayAccess) {
                $array = [];
                foreach ($order as $key => $value) {
                    $array[$key] = $value;
                }
                return $array;
            }
        }

        return [];
    }

    private function handlePendingInput(array $user, array $message, int $chatId, string $text): bool
    {
        if ($user['pending_action'] === '') {
            return false;
        }

        $this->logDebug('pending_action_hit', [
            'user_id' => $user['id'],
            'action' => $user['pending_action'],
            'text' => $text,
        ]);

        if ($user['pending_action'] === 'await_domain') {
            $domainInput = $this->extractCommandArgument($text, '/domain');
            if ($domainInput === null && strpos($text, '/') === 0) {
                $this->telegram->sendMessage($chatId, '⚠️ 请先输入主域名，例如 <b>example.com</b>。');
                return true;
            }

            $domain = $domainInput ?? $text;
            $this->sendProcessingMessage($chatId, '✅ 任务已提交，稍后展示 DNS TXT 记录。');
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

    private function handleFallbackDomainInput(array $user, array $message, int $chatId, string $text): bool
    {
        if ($user['pending_action'] !== '') {
            return false;
        }

        if (strpos($text, '/') === 0) {
            return false;
        }

        $domain = strtolower(trim($text));
        if ($domain === '' || strpos($domain, '.') === false) {
            return false;
        }

        $order = $this->certService->findLatestPendingDomainOrder($user['id']);
        if ($order) {
            $this->logDebug('fallback_domain_submit', [
                'user_id' => $user['id'],
                'order_id' => $order['id'],
                'domain' => $domain,
            ]);
            $this->sendProcessingMessage($chatId, '✅ 任务已提交，稍后展示 DNS TXT 记录。');
            $result = $this->certService->submitDomain($user['id'], $domain);
            $keyboard = $this->resolveOrderKeyboard($result);
            $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            return true;
        }

        $status = $this->certService->status($message['from'], $domain);
        if ($status['success'] ?? false) {
            $keyboard = $this->resolveOrderKeyboard($status);
            $this->telegram->sendMessage($chatId, $status['message'], $keyboard);
            return true;
        }

        $this->telegram->sendMessage($chatId, "❌ 未找到域名 <b>{$domain}</b> 的订单。\n你可以点击下方按钮重新申请证书。", $this->buildMainMenuKeyboard());
        return true;
    }

    private function buildDiagMessage(int $userId): string
    {
        $user = TgUser::where('id', $userId)->find();
        $pendingAction = $user['pending_action'] ?? '';
        $pendingOrderId = $user['pending_order_id'] ?? 0;
        $latestOrder = $this->certService->findLatestOrder($userId);
        $lastError = $latestOrder['last_error'] ?? '';

        $logs = ActionLog::where('tg_user_id', $userId)
            ->order('id', 'desc')
            ->limit(5)
            ->select();
        $logLines = [];
        foreach ($logs as $log) {
            $logLines[] = "{$log['created_at']} {$log['action']} {$log['detail']}";
        }
        if ($logLines === []) {
            $logLines[] = '（无记录）';
        }

        $message = "<b>🧪 诊断信息</b>\n";
        $message .= "pending_action：<b>{$pendingAction}</b>\n";
        $message .= "pending_order_id：<b>{$pendingOrderId}</b>\n";
        $message .= "最近错误：<b>{$lastError}</b>\n\n";
        $message .= "最近 5 条 ActionLog：\n<pre>" . implode("\n", $logLines) . "</pre>";
        return $message;
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

    private function answerCallbackOnce(string $callbackId, string $message, array &$state): void
    {
        if ($callbackId === '' || ($state['answered'] ?? false)) {
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId, $message);
        $state['answered'] = true;
    }

    private function sendProcessingMessage(int $chatId, string $message): void
    {
        $this->telegram->sendMessage($chatId, $message);
    }

    private function sendVerifyProcessingMessageById(int $chatId, int $userId, int $orderId): void
    {
        $order = $this->certService->findOrderById($userId, $orderId);
        if ($order && $order['status'] === 'dns_verified') {
            $this->sendProcessingMessage($chatId, '⏳ 正在签发证书，请稍后刷新状态…');
            return;
        }
        $this->sendProcessingMessage($chatId, '⏳ 正在验证 DNS 解析，这可能需要几十秒…');
    }

    private function sendVerifyProcessingMessageByDomain(int $chatId, int $userId, string $domain): void
    {
        $order = $this->certService->findOrderByDomain($userId, $domain);
        if ($order && $order['status'] === 'dns_verified') {
            $this->sendProcessingMessage($chatId, '⏳ 正在签发证书，请稍后刷新状态…');
            return;
        }
        $this->sendProcessingMessage($chatId, '⏳ 正在验证 DNS 解析，这可能需要几十秒…');
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
