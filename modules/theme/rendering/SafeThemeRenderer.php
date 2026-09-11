<?php

declare(strict_types=1);

namespace modules\theme\rendering;

use InvalidArgumentException;

final class SafeThemeRenderer
{
    /** @param array<string,mixed> $viewModel */
    public function render(string $template, ViewContract $contract, array $viewModel): string
    {
        foreach (['<?', '{php', '{hook', '{template', '{data', '{if', '{elseif', '{else}', '{loop', '{/if', '{/loop'] as $forbidden) {
            if (stripos($template, $forbidden) !== false) {
                throw new InvalidArgumentException('Template contains forbidden server-execution directive.');
            }
        }
        foreach ($contract->fields() as $field) {
            if (!array_key_exists($field, $viewModel)) {
                throw new InvalidArgumentException('View model is missing required field: ' . $field);
            }
        }
        foreach ($viewModel as $key => $value) {
            if (!$contract->allows((string) $key) || !(is_scalar($value) || $value === null)) {
                throw new InvalidArgumentException('View model contains undeclared or non-scalar field.');
            }
        }
        $result = preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*\}\}/', function (array $match) use ($contract, $viewModel): string {
            $field = $match[1];
            if (!$contract->allows($field) || !array_key_exists($field, $viewModel)) {
                throw new InvalidArgumentException('Template references undeclared or missing view field: ' . $field);
            }
            $value = $viewModel[$field];
            if (!(is_scalar($value) || $value === null)) {
                throw new InvalidArgumentException('Template view field must be scalar/null: ' . $field);
            }
            return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }, $template);
        if ($result === null) {
            throw new InvalidArgumentException('Template could not be rendered.');
        }
        if (preg_match('/\{\{.*?\}\}/s', $result) === 1) {
            throw new InvalidArgumentException('Template contains unsupported placeholder syntax.');
        }
        return $result;
    }
}
