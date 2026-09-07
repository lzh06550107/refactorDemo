<?php

declare(strict_types=1);

namespace app\module\compat;

use app\account\domain\AccountType;
use app\module\domain\AccountModuleConfig;
use app\module\domain\ModuleCustomPermission;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModuleLifecycleStatus;
use app\module\domain\ModuleSupportMatrix;
use InvalidArgumentException;

final class LegacyModuleAdapter
{
    private const LEGACY_SUPPORTED = 2;

    public function definition(array $moduleRow): ModuleDefinition
    {
        foreach (['name', 'title', 'version'] as $required) {
            if (trim((string) ($moduleRow[$required] ?? '')) === '') {
                throw new InvalidArgumentException('Legacy module snapshot missing ' . $required . '.');
            }
        }

        $support = [];
        $accountSupport = (int) ($moduleRow['account_support'] ?? 0) === self::LEGACY_SUPPORTED;
        if ($accountSupport) {
            $support[] = AccountType::OFFICIAL_ACCOUNT;
        }
        if ($accountSupport || (int) ($moduleRow['wxapp_support'] ?? 0) === self::LEGACY_SUPPORTED) {
            $support[] = AccountType::WECHAT_MINI_PROGRAM;
        }
        $fieldMap = [
            'webapp_support' => AccountType::WEBAPP,
            'phoneapp_support' => AccountType::PHONEAPP,
            'aliapp_support' => AccountType::ALIPAY_MINI_PROGRAM,
            'baiduapp_support' => AccountType::BAIDU_MINI_PROGRAM,
            'toutiaoapp_support' => AccountType::TOUTIAO_MINI_PROGRAM,
        ];
        foreach ($fieldMap as $field => $type) {
            if ((int) ($moduleRow[$field] ?? 0) === self::LEGACY_SUPPORTED) {
                $support[] = $type;
            }
        }

        $customPermissions = [];
        $rawPermissions = $moduleRow['permissions'] ?? [];
        if ($rawPermissions !== [] && !is_array($rawPermissions)) {
            throw new InvalidArgumentException('Legacy permissions must be decoded before domain mapping.');
        }
        foreach ($rawPermissions as $permission) {
            if (!is_array($permission)) {
                throw new InvalidArgumentException('Legacy permission entry must be an array.');
            }
            $customPermissions[] = new ModuleCustomPermission(
                (string) ($permission['title'] ?? ''),
                (string) ($permission['permission'] ?? ''),
                isset($permission['parent']) && trim((string) $permission['parent']) !== '' ? (string) $permission['parent'] : null,
            );
        }

        return new ModuleDefinition(
            name: (string) $moduleRow['name'],
            title: (string) $moduleRow['title'],
            version: (string) $moduleRow['version'],
            system: (bool) ($moduleRow['issystem'] ?? false),
            status: !empty($moduleRow['is_delete']) ? ModuleLifecycleStatus::RECYCLED : ModuleLifecycleStatus::ACTIVE,
            support: new ModuleSupportMatrix($support),
            settingsEnabled: !empty($moduleRow['settings']),
            ruleFieldsEnabled: !empty($moduleRow['isrulefields']),
            customPermissions: $customPermissions,
        );
    }

    public function accountConfig(
        string $accountId,
        string $tenantId,
        string $moduleName,
        ?array $legacyConfig,
    ): ?AccountModuleConfig {
        if ($legacyConfig === null) {
            return null;
        }
        $legacyModuleName = trim((string) ($legacyConfig['module'] ?? $moduleName));
        if ($legacyModuleName !== $moduleName) {
            throw new InvalidArgumentException('Legacy account module config belongs to a different module.');
        }
        $settings = $legacyConfig['settings'] ?? [];
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Legacy account module settings must be decoded before domain mapping.');
        }

        return new AccountModuleConfig(
            accountId: $accountId,
            tenantId: $tenantId,
            moduleName: $moduleName,
            enabled: !array_key_exists('enabled', $legacyConfig) || (bool) $legacyConfig['enabled'],
            displayOrder: (int) ($legacyConfig['displayorder'] ?? 0),
            shortcut: (bool) ($legacyConfig['shortcut'] ?? false),
            moduleShortcut: (bool) ($legacyConfig['module_shortcut'] ?? false),
            settings: $settings,
        );
    }
}
