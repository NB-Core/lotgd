<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

use Lotgd\Settings;

class ServerDummySettings extends Settings
{
    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function getSetting(string|int $settingname, mixed $default = false): mixed
    {
        return $this->values[$settingname] ?? $default;
    }

    public function loadSettings(): void
    {
    }

    public function clearSettings(): void
    {
    }

    public function saveSetting(string|int $settingname, mixed $value): bool
    {
        $this->values[$settingname] = $value;
        return true;
    }

    public function getArray(): array
    {
        return $this->values;
    }
}
