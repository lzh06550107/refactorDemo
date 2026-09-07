<?php

declare(strict_types=1);

namespace app\theme\rendering;

use InvalidArgumentException;

final readonly class ViewContract
{
    /** @var list<string> */ private array $fields;

    /** @param list<string> $fields */
    public function __construct(array $fields)
    {
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $field) !== 1) {
                throw new InvalidArgumentException('View contract field is invalid.');
            }
            $normalized[$field] = true;
        }
        $this->fields = array_keys($normalized);
    }
    public function allows(string $field): bool { return in_array($field, $this->fields, true); }
    /** @return list<string> */ public function fields(): array { return $this->fields; }
}
