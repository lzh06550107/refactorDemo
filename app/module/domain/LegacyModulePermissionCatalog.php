<?php

declare(strict_types=1);

namespace app\module\domain;

final class LegacyModulePermissionCatalog
{
    /** @param list<ModuleBinding> $bindings @return list<ModulePermission> */
    public function build(ModuleDefinition $module, array $bindings): array
    {
        $name = $module->name();
        $result = [];

        if ($module->settingsEnabled()) {
            $result[] = new ModulePermission('参数设置', $name . '_settings');
        }
        if ($module->ruleFieldsEnabled()) {
            $result[] = new ModulePermission('回复规则列表', $name . '_rule');
        }

        foreach ([
            [ModuleBindingType::HOME, '微站首页导航', '_home'],
            [ModuleBindingType::PROFILE, '个人中心导航', '_profile'],
            [ModuleBindingType::SHORTCUT, '快捷菜单', '_shortcut'],
        ] as [$type, $title, $suffix]) {
            if ($this->hasType($bindings, $type)) {
                $result[] = new ModulePermission($title, $name . $suffix);
            }
        }

        foreach ($bindings as $binding) {
            if ($binding->entryType() === ModuleBindingType::COVER) {
                $result[] = new ModulePermission($binding->title(), $name . '_cover_' . $binding->do());
            }
        }

        $menuByDo = [];
        foreach ($bindings as $binding) {
            if ($binding->entryType() === ModuleBindingType::MENU && !$binding->multilevel()) {
                $menuByDo[$binding->do()] = $binding;
            }
        }

        $subByParent = [];
        foreach ($module->customPermissions() as $custom) {
            if ($custom->parent() !== null && isset($menuByDo[$custom->parent()])) {
                $subByParent[$custom->parent()][] = new ModulePermission(
                    $custom->title(),
                    $name . '_menu_' . $custom->parent() . '_' . $custom->permission(),
                );
            }
        }
        foreach ($menuByDo as $do => $binding) {
            $result[] = new ModulePermission(
                $binding->title(),
                $name . '_menu_' . $do,
                $subByParent[$do] ?? [],
            );
        }

        foreach ($module->customPermissions() as $custom) {
            $result[] = new ModulePermission($custom->title(), $name . '_permission_' . $custom->permission());
        }

        return $result;
    }

    /** @param list<ModuleBinding> $bindings */
    private function hasType(array $bindings, ModuleBindingType $type): bool
    {
        foreach ($bindings as $binding) {
            if ($binding->entryType() === $type) {
                return true;
            }
        }
        return false;
    }
}
