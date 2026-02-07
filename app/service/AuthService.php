<?php

namespace app\service;

use app\model\TgUser;

class AuthService
{
    public function startUser(array $from): array
    {
        $tgId = $from['id'];
        $user = TgUser::where('tg_id', $tgId)->find();
        if ($user) {
            return $user->toArray();
        }

        $role = 'user';
        if (TgUser::count() === 0) {
            $role = 'owner';
        }

        $data = [
            'tg_id' => $tgId,
            'username' => $from['username'] ?? '',
            'first_name' => $from['first_name'] ?? '',
            'last_name' => $from['last_name'] ?? '',
            'role' => $role,
            'apply_quota' => 1,
        ];
        TgUser::create($data);

        return $data;
    }

    public function promote(int $operatorId, int $targetId): array
    {
        if (!$this->isAdmin($operatorId)) {
            return ['success' => false, 'message' => '权限不足'];
        }

        $user = TgUser::where('tg_id', $targetId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if ($user['role'] === 'owner') {
            return ['success' => false, 'message' => 'Owner 不可修改'];
        }

        $user->save(['role' => 'admin']);
        return ['success' => true, 'message' => '已提升为管理员'];
    }

    public function demote(int $operatorId, int $targetId): array
    {
        if (!$this->isOwner($operatorId)) {
            return ['success' => false, 'message' => '仅 Owner 可降权'];
        }

        $user = TgUser::where('tg_id', $targetId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if ($user['role'] === 'owner') {
            return ['success' => false, 'message' => 'Owner 不可降权'];
        }

        $user->save(['role' => 'user']);
        return ['success' => true, 'message' => '已降级为普通用户'];
    }

    public function isOwner(int $tgId): bool
    {
        $user = TgUser::where('tg_id', $tgId)->find();
        return $user && $user['role'] === 'owner';
    }

    public function isAdmin(int $tgId): bool
    {
        $user = TgUser::where('tg_id', $tgId)->find();
        return $user && in_array($user['role'], ['owner', 'admin'], true);
    }
}
