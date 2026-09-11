<?php

declare(strict_types=1);

namespace modules\module\infrastructure;

use modules\account\domain\LegacyAccountMapping;
use app\legacy\contract\LegacyDatabase;
use app\legacy\support\LegacySerializedValueDecoder;
use modules\module\compat\LegacyModuleAdapter;
use modules\module\contract\ModuleRuntimeRepository;
use modules\module\domain\AccountModuleConfig;
use modules\module\domain\ModuleBinding;
use modules\module\domain\ModuleBindingType;
use modules\module\domain\ModuleDefinition;
use modules\module\domain\ModulePluginRelation;
use InvalidArgumentException;

final class R20ModuleRuntimeRepository implements ModuleRuntimeRepository
{
    private const LEGACY_SUPPORTED = 2;
    private const RECYCLE_INSTALL_DISABLED = 1;
    private const RECYCLE_UNINSTALL_IGNORE = 2;

    /** @var list<string> */
    private const SUPPORT_FIELDS = [
        'account_support',
        'wxapp_support',
        'welcome_support',
        'webapp_support',
        'phoneapp_support',
        'aliapp_support',
        'baiduapp_support',
        'toutiaoapp_support',
    ];

    public function __construct(
        private readonly LegacyDatabase $database,
        private readonly LegacySerializedValueDecoder $decoder,
        private readonly LegacyModuleAdapter $adapter,
    ) {
    }

    public function definition(string $moduleName): ?ModuleDefinition
    {
        $row = $this->database->fetchOne('modules', ['name' => $moduleName]);
        if ($row === null) {
            return null;
        }

        $row['permissions'] = $this->decoder->array($row['permissions'] ?? null);
        $row['is_delete'] = $this->isFullyRecycled($row, $this->database->fetchAll('modules_recycle', ['name' => $moduleName]));

        return $this->adapter->definition($row);
    }

    public function accountConfig(LegacyAccountMapping $mapping, string $moduleName): ?AccountModuleConfig
    {
        $row = $this->database->fetchOne('uni_account_modules', [
            'uniacid' => $mapping->uniacid(),
            'module' => $moduleName,
        ]);
        if ($row !== null) {
            $row['settings'] = $this->decoder->array($row['settings'] ?? null);
        }

        return $this->adapter->accountConfig(
            $mapping->accountId(),
            $mapping->tenantId(),
            $moduleName,
            $row,
        );
    }

    public function pluginRelation(string $moduleName): ?ModulePluginRelation
    {
        $row = $this->database->fetchOne('modules_plugin', ['name' => $moduleName]);
        if ($row === null) {
            return null;
        }

        return new ModulePluginRelation(
            (string) ($row['main_module'] ?? ''),
            (string) ($row['name'] ?? ''),
        );
    }

    public function bindings(string $moduleName): array
    {
        $bindings = [];
        foreach ($this->database->fetchAll('modules_bindings', ['module' => $moduleName]) as $row) {
            $entry = ModuleBindingType::tryFrom((string) ($row['entry'] ?? ''));
            if ($entry === null) {
                throw new InvalidArgumentException('Unsupported R20 module binding entry: ' . (string) ($row['entry'] ?? ''));
            }

            $do = (string) ($row['do'] ?? '');
            $legacyUrl = trim((string) ($row['url'] ?? ''));
            $routePath = $legacyUrl !== ''
                ? $legacyUrl
                : ($do !== '' ? $this->canonicalRoute($moduleName, $entry, $do) : null);
            $legacyCall = trim((string) ($row['call'] ?? ''));

            $bindings[] = new ModuleBinding(
                moduleName: $moduleName,
                entryType: $entry,
                do: $do,
                title: (string) ($row['title'] ?? ''),
                routePath: $routePath,
                legacyCall: $legacyCall === '' ? null : $legacyCall,
                multilevel: (bool) ($row['multilevel'] ?? false),
                parent: trim((string) ($row['parent'] ?? '')) === '' ? null : (string) $row['parent'],
                displayOrder: (int) ($row['displayorder'] ?? 0),
            );
        }

        return $bindings;
    }

    private function canonicalRoute(string $moduleName, ModuleBindingType $entry, string $do): string
    {
        return '/module/' . rawurlencode($moduleName) . '/' . $entry->value . '/' . rawurlencode($do);
    }

    /** @param list<array<string,mixed>> $recycleRows */
    private function isFullyRecycled(array $moduleRow, array $recycleRows): bool
    {
        if ($recycleRows === []) {
            return false;
        }

        $byType = [];
        foreach ($recycleRows as $row) {
            $byType[(int) ($row['type'] ?? 0)] = $row;
        }

        foreach (self::SUPPORT_FIELDS as $support) {
            if ((int) ($moduleRow[$support] ?? 0) !== self::LEGACY_SUPPORTED) {
                continue;
            }
            $ignored = !empty($byType[self::RECYCLE_UNINSTALL_IGNORE][$support]);
            $disabled = !empty($byType[self::RECYCLE_INSTALL_DISABLED][$support]);
            if (!$ignored && !$disabled) {
                return false;
            }
        }

        return true;
    }
}
