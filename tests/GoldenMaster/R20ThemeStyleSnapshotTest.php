<?php

declare(strict_types=1);

use modules\theme\compat\R20ThemeStyleSnapshotMapper;

$mapper = new R20ThemeStyleSnapshotMapper();
$snapshot = $mapper->map(
    ['id' => 3, 'name' => 'default', 'title' => 'Default', 'version' => '1.2'],
    ['id' => 11, 'uniacid' => 9, 'templateid' => 3, 'name' => 'Blue'],
    [
        ['templateid' => 3, 'styleid' => 11, 'variable' => 'logo', 'content' => 'first.png'],
        ['templateid' => 3, 'styleid' => 11, 'variable' => 'accent', 'content' => '#00f'],
        ['templateid' => 3, 'styleid' => 11, 'variable' => 'logo', 'content' => 'last.png'],
    ],
);
expectSame(3, $snapshot->legacyTemplateId(), 'legacy template id is preserved');
expectSame(11, $snapshot->legacyStyleId(), 'legacy style id is preserved');
expectSame(9, $snapshot->legacyUniacid(), 'legacy uniacid is preserved');
expectSame('1.2', $snapshot->templateVersion(), 'legacy template version is preserved');
expectSame('last.png', $snapshot->variables()['logo'], 'duplicate R20 style variable keys resolve to the last keyed value');
expectSame('#00f', $snapshot->variables()['accent'], 'legacy style variable values are preserved');
expectThrows(fn () => $mapper->map(['id' => 4, 'name' => 'x', 'title' => 'x', 'version' => '1'], ['id' => 11, 'uniacid' => 9, 'templateid' => 3, 'name' => 'Blue'], []), InvalidArgumentException::class, 'style/template mismatch is rejected');
