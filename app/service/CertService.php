<?php

namespace app\service;

use app\model\ActionLog;
use app\model\CertOrder;
use app\model\TgUser;
use app\validate\DomainValidate;

class CertService
{
    private AcmeService $acme;
    private DnsService $dns;

    public function __construct(AcmeService $acme, DnsService $dns)
    {
        $this->acme = $acme;
        $this->dns = $dns;
    }

    public function createOrder(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => '❌ 域名格式错误，请检查后重试。'];
        }
        $typeError = $this->validateDomainByType($domain, 'root');
        if ($typeError) {
            return ['success' => false, 'message' => $typeError];
        }

        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        $existing = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->where('status', '<>', 'issued')
            ->find();
        if ($existing) {
            if ($existing['status'] !== 'created') {
                return [
                    'success' => false,
                    'message' => $this->buildOrderStatusMessage($existing, true),
                    'order' => $existing,
                ];
            }

            return [
                'success' => false,
                'message' => $this->buildOrderStatusMessage($existing, true),
                'order' => $existing,
            ];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => $domain,
            'status' => 'created',
        ]);

        $this->consumeQuota($user);

        return $this->issueOrder($user, $order);
    }

    public function startOrder(array $from): array
    {
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        $existing = CertOrder::where('tg_user_id', $user['id'])
            ->where('status', 'created')
            ->where('domain', '')
            ->find();
        if ($existing) {
            return ['success' => true, 'order' => $existing];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => '',
            'status' => 'created',
        ]);

        return ['success' => true, 'order' => $order];
    }

    public function setOrderType(int $userId, int $orderId, string $certType): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前状态不可选择类型。'];
        }

        if (!in_array($certType, ['root', 'wildcard'], true)) {
            return ['success' => false, 'message' => '❌ 证书类型不合法。'];
        }

        $order->save(['cert_type' => $certType]);

        $user = TgUser::where('id', $userId)->find();
        if ($user) {
            $user->save([
                'pending_action' => 'await_domain',
                'pending_order_id' => $orderId,
            ]);
        }

        return ['success' => true, 'order' => $order];
    }

    public function submitDomain(int $userId, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => '❌ 域名格式错误，请检查后重试。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 用户不存在。'];
        }
        if (!$this->hasQuota($user)) {
            return ['success' => false, 'message' => $this->quotaExhaustedMessage($user)];
        }

        if (!$user['pending_order_id']) {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '⚠️ 没有待处理的订单，请先申请证书。'];
        }

        $order = CertOrder::where('id', $user['pending_order_id'])
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '⚠️ 当前订单状态不可提交域名。'];
        }

        if ($order['domain'] !== '') {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
            return ['success' => false, 'message' => '⚠️ 该订单已提交域名。'];
        }

        $typeError = $this->validateDomainByType($domain, $order['cert_type']);
        if ($typeError) {
            return ['success' => false, 'message' => $typeError];
        }

        $duplicate = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $userId)
            ->where('status', '<>', 'issued')
            ->find();
        if ($duplicate) {
            return [
                'success' => false,
                'message' => $this->buildOrderStatusMessage($duplicate, true),
                'order' => $duplicate,
            ];
        }

        $order->save(['domain' => $domain]);
        $user->save(['pending_action' => '', 'pending_order_id' => 0]);
        $this->consumeQuota($user);

        return $this->issueOrder($user, $order);
    }

    public function verifyOrderById(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return $this->verifyOrderByOrder($order);
    }

    public function getCertificateInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $info = $this->readCertificateInfo($order['cert_path']);
        $typeText = $this->formatCertType($order['cert_type']);
        $issuedAt = $order['updated_at'] ?? '';
        $message = "📄 证书类型：{$typeText}";
        if ($issuedAt) {
            $message .= "\n签发时间：{$issuedAt}";
        }
        if ($info['expires_at']) {
            $message .= "\n有效期至：{$info['expires_at']}";
        }

        return ['success' => true, 'message' => $message];
    }

    public function getDownloadInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $typeText = $this->formatCertType($order['cert_type']);
        $issuedAt = $order['updated_at'] ?? '';
        $message = "✅ 证书已签发\n证书类型：{$typeText}\n";
        if ($issuedAt) {
            $message .= "签发时间：{$issuedAt}\n";
        }
        $message .= "已导出至服务器目录：\n{$this->getOrderExportPath($order)}\n\n";
        $message .= $this->buildDownloadFilesMessage($order);
        return ['success' => true, 'message' => $message];
    }

    public function getDownloadFileInfo(int $userId, int $orderId, string $fileKey): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '⚠️ 证书尚未签发。'];
        }

        $fileMap = [
            'fullchain' => 'fullchain.pem',
            'cert' => 'cert.pem',
            'key' => 'privkey.pem',
        ];
        if (!isset($fileMap[$fileKey])) {
            return ['success' => false, 'message' => '⚠️ 文件类型不正确。'];
        }

        $exportPath = $this->getOrderExportPath($order);
        $filename = $fileMap[$fileKey];
        $label = $fileKey === 'key' ? 'key' : "{$fileKey}.cer";
        $message = "📥 {$label} 下载路径：\n{$exportPath}{$filename}";
        return ['success' => true, 'message' => $message];
    }

    public function requestDomainInput(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前状态不可提交域名。'];
        }

        if (!$order['cert_type']) {
            return ['success' => false, 'message' => '⚠️ 请先选择证书类型。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if ($user) {
            $user->save([
                'pending_action' => 'await_domain',
                'pending_order_id' => $orderId,
            ]);
        }

        return ['success' => true, 'message' => '📝 请发送主域名，例如 <b>example.com</b>。'];
    }

    public function cancelOrder(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前订单无法取消。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if ($user && $user['pending_order_id'] === $orderId) {
            $user->save(['pending_action' => '', 'pending_order_id' => 0]);
        }

        $shouldRefund = $order['domain'] !== '' && !$this->isUnlimitedUser($user);
        if ($shouldRefund && $user) {
            $user->save(['apply_quota' => (int) $user['apply_quota'] + 1]);
        }

        $order->delete();
        $this->log($userId, 'order_cancel', (string) $orderId);

        return ['success' => true, 'message' => '✅ 订单已取消。'];
    }

    public function retryDnsChallenge(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        if ($order['status'] !== 'created' || $order['domain'] === '') {
            return ['success' => false, 'message' => '⚠️ 当前订单无需重新生成 DNS 记录。'];
        }

        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 用户不存在。'];
        }

        return $this->issueOrder($user, $order);
    }

    private function issueOrder($user, CertOrder $order): array
    {
        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '⚠️ 当前订单状态不可生成 TXT。'];
        }

        if ($order['domain'] === '') {
            return ['success' => false, 'message' => '⚠️ 请先提交域名。'];
        }

        $domain = $order['domain'];
        $domains = $this->getAcmeDomains($order);
        $dryRun = $this->acme->issueDryRun($domains);
        $this->log($user['id'], 'acme_issue_dry_run', $dryRun['output']);
        if (!$dryRun['success']) {
            $order->save(['status' => 'created', 'acme_output' => $dryRun['output']]);
            return ['success' => false, 'message' => '❌ acme.sh dry-run 失败：' . $dryRun['output']];
        }

        $txt = $this->dns->parseTxtRecord($dryRun['output']);
        $this->updateOrderStatus($user['id'], $order, 'dns_wait', [
            'txt_host' => $txt['name'] ?? '',
            'txt_value' => $txt['value'] ?? '',
            'acme_output' => $dryRun['output'],
        ]);

        $message = "🧾 <b>状态：dns_wait（等待 DNS TXT 解析）</b>\n";
        $message .= "请先添加下面的 TXT 记录，然后点击「我已完成解析（验证）」：\n";
        if ($txt) {
            $message .= $this->formatTxtRecordBlock($domain, $txt['name'], $txt['value']);
        } else {
            $message .= "⚠️ 无法解析 TXT 记录，请查看输出：\n" . $dryRun['output'];
        }

        $this->log($user['id'], 'order_create', $domain);

        return ['success' => true, 'message' => $message, 'order' => $order, 'txt' => $txt];
    }

    public function verifyOrder(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return $this->verifyOrderByOrder($order);
    }

    private function verifyOrderByOrder(CertOrder $order): array
    {
        $userId = $order['tg_user_id'];
        if (!in_array($order['status'], ['dns_wait', 'dns_verified'], true)) {
            return ['success' => false, 'message' => '⚠️ 当前状态不可验证，请先完成 DNS 解析。'];
        }

        if ($order['status'] === 'dns_wait') {
            if ($order['txt_host'] && $order['txt_value']) {
                if (!$this->dns->verifyTxt($order['txt_host'], $order['txt_value'])) {
                    return [
                        'success' => false,
                        'message' => '⏳ 当前未检测到 TXT 记录，DNS 可能仍在生效中。通常需要 1~10 分钟，部分 DNS 更久。',
                    ];
                }
            }

            $this->updateOrderStatus($userId, $order, 'dns_verified');
        }

        $domains = $this->getAcmeDomains($order);
        $renew = $this->acme->renew($domains);
        $this->log($userId, 'acme_renew', $renew['output']);
        if (!$renew['success']) {
            return ['success' => false, 'message' => '❌ 证书签发失败：' . $renew['output']];
        }

        $install = $this->acme->installCert($order['domain']);
        $this->log($userId, 'acme_install_cert', $install['output']);
        if (!$install['success']) {
            return ['success' => false, 'message' => '❌ 证书导出失败：' . $install['output']];
        }

        $exportPath = $this->getOrderExportPath($order);

        $this->updateOrderStatus($userId, $order, 'issued', [
            'cert_path' => $exportPath . 'cert.pem',
            'key_path' => $exportPath . 'privkey.pem',
            'fullchain_path' => $exportPath . 'fullchain.pem',
        ]);

        $this->log($userId, 'order_issued', $order['domain']);

        $info = $this->readCertificateInfo($exportPath . 'cert.pem');
        $typeText = $this->formatCertType($order['cert_type']);
        $issuedAt = date('Y-m-d H:i:s');
        $message = "🎉 <b>状态：issued（签发成功）</b>\n证书类型：{$typeText}\n签发时间：{$issuedAt}\n";
        $message .= "已导出到：{$exportPath}\n";
        $message .= $this->buildDownloadFilesMessage($order);
        if ($info['expires_at']) {
            $message .= "\n有效期至：{$info['expires_at']}";
        }

        return ['success' => true, 'message' => $message, 'order' => $order];
    }

    public function status(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        $message = $this->buildOrderStatusMessage($order, false);
        if (!in_array($order['status'], ['dns_wait', 'issued'], true)) {
            $message .= "\n\n⚠️ 该订单尚未完成，请继续下一步或取消订单。";
        }

        return ['success' => true, 'message' => $message, 'order' => $order];
    }

    public function statusByDomain(string $domain): array
    {
        $order = CertOrder::where('domain', $domain)->find();
        if (!$order) {
            return ['success' => false, 'message' => '❌ 订单不存在。'];
        }

        return ['success' => true, 'message' => $this->buildOrderStatusMessage($order, false)];
    }

    public function listOrders(array $from): array
    {
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '❌ 请先发送 /start 绑定账号。'];
        }

        $orders = CertOrder::where('tg_user_id', $user['id'])
            ->order('id', 'desc')
            ->select();
        if (!$orders || count($orders) === 0) {
            return ['success' => true, 'message' => '📂 暂无证书订单记录。'];
        }

        $messages = [
            [
                'text' => "📂 <b>证书订单记录</b>\n点击订单按钮查看/操作。",
                'keyboard' => null,
            ],
        ];

        foreach ($orders as $order) {
            $messages[] = $this->buildOrderCard($order);
        }

        return [
            'success' => true,
            'message' => '订单列表已发送',
            'messages' => $messages,
        ];
    }

    private function log(int $userId, string $action, string $detail): void
    {
        ActionLog::create([
            'tg_user_id' => $userId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }

    private function formatCertType(string $type): string
    {
        return $type === 'wildcard' ? '通配符证书' : '根域名证书';
    }

    private function getAcmeDomains(CertOrder $order): array
    {
        if ($order['cert_type'] === 'wildcard') {
            return [$order['domain'], '*.' . $order['domain']];
        }

        return [$order['domain']];
    }

    private function getOrderExportPath(CertOrder $order): string
    {
        $config = config('tg');
        return rtrim($config['cert_export_path'], '/') . '/' . $order['domain'] . '/';
    }

    private function readCertificateInfo(string $certPath): array
    {
        if (!is_file($certPath)) {
            return ['expires_at' => null];
        }

        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            return ['expires_at' => null];
        }

        $certData = openssl_x509_parse($certContent);
        if (!$certData || !isset($certData['validTo_time_t'])) {
            return ['expires_at' => null];
        }

        return ['expires_at' => date('Y-m-d H:i:s', $certData['validTo_time_t'])];
    }

    private function hasQuota(TgUser $user): bool
    {
        if ($this->isUnlimitedUser($user)) {
            return true;
        }

        return (int) $user['apply_quota'] > 0;
    }

    private function consumeQuota(TgUser $user): void
    {
        $current = (int) $user['apply_quota'];
        if ($current <= 0) {
            return;
        }

        $user->save(['apply_quota' => $current - 1]);
    }

    private function quotaExhaustedMessage(TgUser $user): string
    {
        if ($this->isUnlimitedUser($user)) {
            return '✅ 管理员不受申请次数限制。';
        }

        $quota = (int) $user['apply_quota'];
        return "🚫 <b>申请次数不足</b>（剩余 {$quota} 次）。请联系管理员添加次数。";
    }

    private function buildOrderStatusMessage(CertOrder $order, bool $withTips): string
    {
        $status = $order['status'];
        $domain = $order['domain'] !== '' ? $order['domain'] : '（未提交域名）';
        $typeText = $order['cert_type'] ? $this->formatCertType($order['cert_type']) : '（未选择）';
        $message = "📌 当前状态：<b>{$status}</b>\n域名：<b>{$domain}</b>\n证书类型：<b>{$typeText}</b>";

        if ($status === 'dns_wait') {
            $message .= "\n\n🧾 <b>状态：dns_wait</b>\n请添加 TXT 记录后点击「我已完成解析（验证）」。\n";
            if ($order['txt_host'] && $order['txt_value']) {
                $message .= $this->formatTxtRecordBlock($order['domain'], $order['txt_host'], $order['txt_value']);
            }
        } elseif ($status === 'dns_verified') {
            $message .= "\n\n✅ <b>状态：dns_verified</b>\nDNS 已验证，点击「我已完成解析（验证）」继续签发证书。";
        } elseif ($status === 'created' && $order['domain'] === '') {
            $message .= "\n\n📝 订单未完成，请继续选择证书类型并提交主域名。";
        } elseif ($status === 'created' && $order['domain'] !== '') {
            $message .= "\n\n⚠️ 订单未完成，请继续生成 DNS TXT 记录或取消订单后重新申请。";
        } elseif ($status === 'issued') {
            $issuedAt = $order['updated_at'] ?? '';
            $message .= "\n\n🎉 <b>状态：issued</b>\n";
            if ($issuedAt) {
                $message .= "签发时间：{$issuedAt}\n";
            }
            $message .= $this->buildDownloadFilesMessage($order);
        }

        return $message;
    }

    private function buildOrderCard(CertOrder $order): array
    {
        $status = $order['status'];
        $domain = $order['domain'] !== '' ? $order['domain'] : '（未提交域名）';
        $typeText = $order['cert_type'] ? $this->formatCertType($order['cert_type']) : '（未选择）';
        $message = "🔖 订单 #{$order['id']}\n域名：<b>{$domain}</b>\n证书类型：<b>{$typeText}</b>\n状态：<b>{$status}</b>";
        $keyboard = null;

        if ($status === 'created') {
            $message .= "\n📝 订单未完成，请继续下一步或取消。";
            $keyboard = $this->buildCreatedKeyboard($order);
        } elseif ($status === 'dns_wait') {
            $message .= "\n🧾 请添加 TXT 记录后点击验证：\n";
            if ($order['txt_host'] && $order['txt_value']) {
                $message .= $this->formatTxtRecordBlock($order['domain'], $order['txt_host'], $order['txt_value']);
            }
            $keyboard = [
                [
                    ['text' => '我已完成解析（验证）', 'callback_data' => "verify:{$order['id']}"],
                ],
                [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ],
            ];
        } elseif ($status === 'dns_verified') {
            $message .= "\n✅ DNS 已验证，点击下方按钮继续签发证书。";
            $keyboard = [
                [
                    ['text' => '我已完成解析（验证）', 'callback_data' => "verify:{$order['id']}"],
                ],
                [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ],
            ];
        } elseif ($status === 'issued') {
            $issuedAt = $order['updated_at'] ?? '';
            $message .= "\n🎉 已签发完成";
            if ($issuedAt) {
                $message .= "\n签发时间：{$issuedAt}";
            }
            $message .= "\n" . $this->buildDownloadFilesMessage($order);
            $keyboard = [
                [
                    ['text' => 'fullchain.cer', 'callback_data' => "file:fullchain:{$order['id']}"],
                    ['text' => 'cert.cer', 'callback_data' => "file:cert:{$order['id']}"],
                    ['text' => 'key', 'callback_data' => "file:key:{$order['id']}"],
                ],
                [
                    ['text' => '查看证书信息', 'callback_data' => "info:{$order['id']}"],
                ],
                [
                    ['text' => '返回订单列表', 'callback_data' => 'menu:orders'],
                ],
            ];
        }

        return [
            'text' => $message,
            'keyboard' => $keyboard,
        ];
    }

    private function formatTxtRecordBlock(string $domain, string $host, string $value): string
    {
        $recordName = $this->normalizeTxtHost($domain, $host);
        $lines = [
            $recordName,
            'TXT',
            $value,
        ];
        $message = "<pre>" . implode("\n", $lines) . "</pre>";
        $message .= "\n说明：请在 DNS 中添加 TXT 记录，记录名通常是 <b>{$recordName}</b>。";
        return $message;
    }

    private function buildDownloadFilesMessage(CertOrder $order): string
    {
        $exportPath = $this->getOrderExportPath($order);
        $lines = [
            '下载文件：',
            "fullchain.cer -> {$exportPath}fullchain.pem",
            "cert.cer -> {$exportPath}cert.pem",
            "key -> {$exportPath}privkey.pem",
        ];
        return "<pre>" . implode("\n", $lines) . "</pre>";
    }

    private function buildCreatedKeyboard(CertOrder $order): array
    {
        $buttons = [];
        $certTypeMissing = !$order['cert_type'] || !in_array($order['cert_type'], ['root', 'wildcard'], true);
        if ($certTypeMissing) {
            $buttons[] = [
                ['text' => '选择证书类型', 'callback_data' => "created:type:{$order['id']}"],
            ];
        } else {
            if ($order['domain'] === '') {
                $buttons[] = [
                    ['text' => '提交主域名', 'callback_data' => "created:domain:{$order['id']}"],
                ];
                $buttons[] = [
                    ['text' => '重新选择证书类型', 'callback_data' => "created:type:{$order['id']}"],
                ];
            } else {
                $buttons[] = [
                    ['text' => '重新生成 DNS 记录', 'callback_data' => "created:retry:{$order['id']}"],
                ];
            }
        }
        $buttons[] = [
            ['text' => '取消订单', 'callback_data' => "cancel:{$order['id']}"],
        ];

        return $buttons;
    }

    private function normalizeTxtHost(string $domain, string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return "_acme-challenge.{$domain}";
        }

        $normalizedHost = rtrim($host, '.');
        if (strpos($normalizedHost, $domain) !== false) {
            return $normalizedHost;
        }

        return "{$normalizedHost}.{$domain}";
    }

    private function isUnlimitedUser(?TgUser $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array($user['role'], ['owner', 'admin'], true);
    }

    private function validateDomainByType(string $domain, ?string $certType): ?string
    {
        if (strpos($domain, '*') !== false) {
            return '❌ 请不要输入通配符格式（*.example.com），只需要输入主域名，例如 <b>example.com</b>。';
        }

        if (!$certType) {
            return null;
        }

        $labels = explode('.', $domain);
        if (count($labels) > 2) {
            if ($certType === 'wildcard') {
                return '⚠️ 通配符证书请输入主域名（根域名），例如 <b>example.com</b>，不要输入子域名。';
            }

            return '⚠️ 根域名证书请输入主域名（根域名），例如 <b>example.com</b>，不要输入子域名。';
        }

        return null;
    }

    private function updateOrderStatus(int $userId, CertOrder $order, string $status, array $extra = []): void
    {
        $fromStatus = $order['status'];
        $payload = array_merge(['status' => $status], $extra);
        $order->save($payload);
        $this->logStatusTransition($userId, $order['domain'], $fromStatus, $status);
    }

    private function logStatusTransition(int $userId, string $domain, string $from, string $to): void
    {
        $detail = "{$domain} {$from} -> {$to}";
        $this->log($userId, 'order_status_change', $detail);
    }
}
