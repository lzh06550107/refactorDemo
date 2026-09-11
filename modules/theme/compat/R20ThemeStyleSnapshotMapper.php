<?php

declare(strict_types=1);

namespace modules\theme\compat;

use InvalidArgumentException;

final class R20ThemeStyleSnapshotMapper
{
    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $style
     * @param list<array<string,mixed>> $vars
     */
    public function map(array $template, array $style, array $vars): LegacyThemeStyleSnapshot
    {
        foreach (['id','name','title','version'] as $key) {
            if (!array_key_exists($key, $template)) {
                throw new InvalidArgumentException('R20 site_templates row missing ' . $key . '.');
            }
        }
        foreach (['id','uniacid','templateid','name'] as $key) {
            if (!array_key_exists($key, $style)) {
                throw new InvalidArgumentException('R20 site_styles row missing ' . $key . '.');
            }
        }
        if ((int) $template['id'] !== (int) $style['templateid']) {
            throw new InvalidArgumentException('R20 style/template identity mismatch.');
        }
        $values = [];
        foreach ($vars as $row) {
            if ((int) ($row['templateid'] ?? 0) !== (int) $template['id'] || (int) ($row['styleid'] ?? 0) !== (int) $style['id']) {
                continue;
            }
            $key = (string) ($row['variable'] ?? '');
            if ($key === '') {
                continue;
            }
            $values[$key] = (string) ($row['content'] ?? '');
        }
        return new LegacyThemeStyleSnapshot(
            (int) $template['id'],
            (int) $style['id'],
            (int) $style['uniacid'],
            (string) $template['name'],
            (string) $template['title'],
            (string) $template['version'],
            (string) $style['name'],
            $values,
        );
    }
}
