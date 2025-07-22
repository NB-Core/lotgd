<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

use Lotgd\Settings;

class DummySettingsExtra extends Settings
{
    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function getSetting(string|int $name, mixed $default = false): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function loadSettings(): void
    {
    }

    public function clearSettings(): void
    {
    }

    public function saveSetting(string|int $name, mixed $value): bool
    {
        $this->values[$name] = $value;
        return true;
    }

    public function getArray(): array
    {
        return $this->values;
    }
}
